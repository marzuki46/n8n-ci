<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

class ApiKeyService
{
    protected $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function generate(string $label, int $userId, ?string $expiresAt = null, ?int $workspaceId = null): array
    {
        $full = 'ak_' . bin2hex(random_bytes(16));

        $key = [
            'user_id'      => $userId,
            'workspace_id' => $workspaceId,
            'label'        => mb_substr(trim($label), 0, 191),
            'key_prefix'   => substr($full, 0, 10) . '...',
            'key_hash'     => $this->hash($full),
            'status'       => 'active',
            'expires_at'   => $expiresAt,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $this->db->table('api_keys')->insert($key);
        $key['id'] = (int) $this->db->insertID();

        // Hanya dikirim sekali ke klien.
        $key['api_key'] = $full;
        unset($key['key_hash']);

        return $key;
    }

    public function verify(?string $given): ?array
    {
        if ($given === null || $given === '') {
            return null;
        }

        $hash = $this->hash($given);

        $row = $this->db->table('api_keys')
            ->where('key_hash', $hash)
            ->where('status', 'active')
            ->get()
            ->getRowArray();

        if (! $row) {
            return null;
        }

        if (! empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return null;
        }

        $this->db->table('api_keys')->where('id', $row['id'])->update([
            'last_used_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return $row;
    }

    public function revoke(int $id): void
    {
        $this->db->table('api_keys')->where('id', $id)->update([
            'status'     => 'revoked',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function listForUser(int $userId): array
    {
        return $this->db->table('api_keys k')
            ->select('k.id, k.label, k.key_prefix, k.status, k.expires_at, k.last_used_at, k.created_at,
                      k.workspace_id, w.name AS workspace_name')
            ->join('workspaces w', 'w.id = k.workspace_id', 'left')
            ->where('k.user_id', $userId)
            ->orderBy('k.id', 'DESC')
            ->get()
            ->getResultArray();
    }

    public function getOwned(int $id, int $userId): ?array
    {
        $row = $this->db->table('api_keys')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    public function delete(int $id): void
    {
        $this->db->table('api_keys')->where('id', $id)->delete();
    }

    protected function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
