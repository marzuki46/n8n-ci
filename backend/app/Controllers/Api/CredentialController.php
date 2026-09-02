<?php

namespace App\Controllers\Api;

use App\Services\CredentialService;
use CodeIgniter\HTTP\ResponseInterface;

class CredentialController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $service = new CredentialService();

        return $this->success($service->listForApi($this->currentWorkspaceId()));
    }

    public function types(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $types = $db->table('credential_types')->orderBy('name', 'ASC')->get()->getResultArray();

        foreach ($types as &$type) {
            $type['schema'] = json_decode($type['schema_json'] ?? '[]', true) ?: [];
            unset($type['schema_json']);
        }

        return $this->success($types);
    }

    public function create(): ResponseInterface
    {
        $input = $this->input();
        $name = trim((string) ($input['name'] ?? ''));
        $typeId = (int) ($input['credential_type_id'] ?? 0);

        if ($name === '' || $typeId <= 0) {
            return $this->fail('Nama dan tipe credential wajib diisi.');
        }

        $db = \Config\Database::connect();
        $type = $db->table('credential_types')->where('id', $typeId)->get()->getRowArray();
        if (! $type) {
            return $this->fail('Tipe credential tidak ditemukan.', 404);
        }

        $schema = json_decode($type['schema_json'] ?? '[]', true) ?: [];
        $data = [];

        foreach ($schema as $field) {
            $key = $field['key'] ?? '';
            if ($key === '') {
                continue;
            }
            if (isset($input['data'][$key])) {
                $data[$key] = $input['data'][$key];
            } elseif (isset($input[$key])) {
                $data[$key] = $input[$key];
            }
        }

        $service = new CredentialService();
        $workspaceId = (int) ($input['workspace_id'] ?? $this->currentWorkspaceId());
        if (! $this->hasAccessToWorkspace($workspaceId)) {
            return $this->fail('Tidak punya akses ke projek ini.', 403);
        }

        $denied = $this->requirePermission('credentials:write', $workspaceId);
        if ($denied) {
            return $denied;
        }

        $db->table('credentials')->insert([
            'user_id'           => $this->userId(),
            'workspace_id'      => $workspaceId,
            'credential_type_id' => $typeId,
            'name'              => $name,
            'data'              => $service->encryptData($data),
            'status'            => 'active',
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ], true);
        $id = (int) $db->insertID();

        return $this->success(['id' => $id], 'Credential dibuat', 201);
    }

    public function update(int $id): ResponseInterface
    {
        $db = \Config\Database::connect();
        $credential = $db->table('credentials')->where('id', $id)->get()->getRowArray();
        if (! $credential) {
            return $this->fail('Credential tidak ditemukan.', 404);
        }

        $denied = $this->requirePermission('credentials:write', (int) $credential['workspace_id']);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $data = ['updated_at' => date('Y-m-d H:i:s')];

        if (isset($input['name'])) {
            $data['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $input['status'] ? 'active' : 'inactive';
        }
        if (array_key_exists('is_default', $input)) {
            (new CredentialService())->setDefault($id, (bool) $input['is_default']);
        }

        if (! empty($input['data']) && is_array($input['data'])) {
            $type = $db->table('credential_types')->where('id', $credential['credential_type_id'])->get()->getRowArray();
            $schema = $type ? (json_decode($type['schema_json'] ?? '[]', true) ?: []) : [];
            $existing = (new CredentialService())->decryptData($credential['data']);

            foreach ($schema as $field) {
                $key = $field['key'] ?? '';
                if ($key !== '' && array_key_exists($key, $input['data'])) {
                    $existing[$key] = $input['data'][$key];
                }
            }

            $data['data'] = (new CredentialService())->encryptData($existing);
        }

        $db->table('credentials')->where('id', $id)->update($data);

        return $this->success(['id' => $id], 'Credential diperbarui');
    }

    public function delete(int $id): ResponseInterface
    {
        $db = \Config\Database::connect();
        $credential = $db->table('credentials')->where('id', $id)->get()->getRowArray();
        if (! $credential) {
            return $this->fail('Credential tidak ditemukan.', 404);
        }

        $denied = $this->requirePermission('credentials:write', (int) $credential['workspace_id']);
        if ($denied) {
            return $denied;
        }

        // Cek masih dipakai node?
        $used = $db->table('workflow_nodes')->where('credential_id', $id)->countAllResults();
        if ($used > 0) {
            return $this->fail("Tidak bisa dihapus: masih dipakai {$used} node.");
        }

        $db->table('credentials')->where('id', $id)->delete();

        return $this->success(null, 'Credential dihapus');
    }
}
