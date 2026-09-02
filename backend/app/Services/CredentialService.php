<?php

namespace App\Services;

class CredentialService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Enkripsi data credential sebelum disimpan (AES-256-CTR + HMAC via CI Encrypter).
     * Hasil di-base64 karena kolom TEXT + koneksi utf8mb4 akan merusak byte biner
     * (byte invalid UTF-8 diganti '?'), yang membuat decrypt gagal.
     */
    public function encryptData(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return base64_encode(service('encrypter')->encrypt((string) $json));
    }

    /**
     * Dekripsi data credential. Mendukung format baru (base64 -> Encrypter),
     * format transisi (Encrypter mentah), dan format lama (base64 JSON).
     */
    public function decryptData(string $raw): array
    {
        $decoded = $this->decryptNewBase64($raw);
        if ($decoded !== null) {
            return $decoded;
        }

        $decoded = $this->decryptNew($raw);
        if ($decoded !== null) {
            return $decoded;
        }

        $legacy = json_decode(base64_decode($raw), true);

        return is_array($legacy) ? $legacy : [];
    }

    protected function decryptNewBase64(string $raw): ?array
    {
        $bin = base64_decode($raw, true);
        if ($bin === false || $bin === '') {
            return null;
        }

        return $this->tryDecrypt($bin);
    }

    protected function decryptNew(string $raw): ?array
    {
        return $this->tryDecrypt($raw);
    }

    protected function tryDecrypt(string $raw): ?array
    {
        try {
            $plain   = service('encrypter')->decrypt($raw);
            $decoded = json_decode((string) $plain, true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Load credential beserta data terdekripsi. Khusus internal engine.
     */
    public function loadForNode($credentialId): ?array
    {
        if (! $credentialId) {
            return null;
        }

        $row = $this->db->table('credentials')
            ->where('id', $credentialId)
            ->where('status', 'active')
            ->get()
            ->getRowArray();

        if (! $row) {
            return null;
        }

        $row['data'] = $this->decryptData($row['data'] ?? '');

        return $row;
    }

    /**
     * Ambil credential default (aktif) untuk satu workspace + tipe credential.
     * Dipakai engine saat node tidak memilih credential (fallback ke default proyek).
     */
    public function findDefault(int $workspaceId, int $typeId): ?array
    {
        $row = $this->db->table('credentials')
            ->where('workspace_id', $workspaceId)
            ->where('credential_type_id', $typeId)
            ->where('status', 'active')
            ->where('is_default', 1)
            ->orderBy('id', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if (! $row) {
            return null;
        }

        $row['data'] = $this->decryptData($row['data'] ?? '');

        return $row;
    }

    /**
     * Tandai satu credential sebagai default untuk (workspace, type)-nya;
     * credential lain di kombinasi sama di-reset.
     */
    public function setDefault(int $id, bool $isDefault): bool
    {
        $row = $this->db->table('credentials')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return false;
        }

        if ($isDefault) {
            $this->db->table('credentials')
                ->where('workspace_id', $row['workspace_id'])
                ->where('credential_type_id', $row['credential_type_id'])
                ->update(['is_default' => 0]);
        }

        $this->db->table('credentials')->where('id', $id)->update([
            'is_default' => $isDefault ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Daftar credential untuk API (tanpa membocorkan data rahasia).
     */
    public function listForApi(?int $workspaceId = null): array
    {
        $builder = $this->db->table('credentials c')
            ->select('c.id, c.user_id, c.workspace_id, c.credential_type_id, c.name, c.status, c.is_default, c.created_at, c.updated_at, ct.name as type_name, ct.slug as type_slug')
            ->join('credential_types ct', 'ct.id = c.credential_type_id', 'left');

        if ($workspaceId) {
            $builder->groupStart()
                ->where('c.workspace_id', $workspaceId)
                ->orWhere('c.workspace_id IS NULL')
                ->groupEnd();
        }

        return $builder->orderBy('c.name', 'ASC')->get()->getResultArray();
    }
}
