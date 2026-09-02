<?php

namespace App\Controllers\Api;

use App\Services\ApiKeyService;
use CodeIgniter\HTTP\ResponseInterface;

class ApiKeyController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $service = new ApiKeyService();

        return $this->success($service->listForUser($this->userId()));
    }

    public function create(): ResponseInterface
    {
        $denied = $this->requirePermission('apikeys:manage', $this->currentWorkspaceId());
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $label = trim((string) ($input['label'] ?? 'API Key'));
        if ($label === '') {
            return $this->fail('Label API key wajib diisi.');
        }

        $expiresAt = null;
        if (! empty($input['expires_at'])) {
            $ts = strtotime((string) $input['expires_at']);
            if ($ts === false) {
                return $this->fail('Format expires_at tidak valid.');
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        // Binding opsional ke satu proyek (workspace).
        $workspaceId = null;
        $workspaceIdInput = (int) ($input['workspace_id'] ?? 0);
        if ($workspaceIdInput > 0) {
            $role = (new \App\Services\RbacService())->roleInWorkspace($this->userId(), $workspaceIdInput);
            if ($role === 'none' || $role === null || $role === '') {
                return $this->fail('Anda bukan anggota proyek tersebut.', 403);
            }
            $workspaceId = $workspaceIdInput;
        }

        $service = new ApiKeyService();
        $key = $service->generate($label, $this->userId(), $expiresAt, $workspaceId);

        return $this->success([
            'id'         => $key['id'],
            'label'      => $key['label'],
            'key_prefix' => $key['key_prefix'],
            'api_key'    => $key['api_key'],
            'expires_at' => $key['expires_at'],
            'created_at' => $key['created_at'],
            'workspace_id' => $key['workspace_id'],
        ], 'API key dibuat. Simpan salinannya — tidak akan ditampilkan lagi.', 201);
    }

    public function revoke(int $id): ResponseInterface
    {
        $service = new ApiKeyService();
        if (! $service->getOwned($id, $this->userId())) {
            return $this->fail('API key tidak ditemukan.', 404);
        }

        $denied = $this->requirePermission('apikeys:manage', $this->currentWorkspaceId());
        if ($denied) {
            return $denied;
        }

        $service->revoke($id);

        return $this->success(null, 'API key dicabut.');
    }

    public function delete(int $id): ResponseInterface
    {
        $service = new ApiKeyService();
        if (! $service->getOwned($id, $this->userId())) {
            return $this->fail('API key tidak ditemukan.', 404);
        }

        $denied = $this->requirePermission('apikeys:manage', $this->currentWorkspaceId());
        if ($denied) {
            return $denied;
        }

        $service->delete($id);

        return $this->success(null, 'API key dihapus.');
    }
}
