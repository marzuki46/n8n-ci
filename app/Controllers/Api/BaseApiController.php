<?php

namespace App\Controllers\Api;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base controller untuk REST API. Menjawab JSON dengan format konsisten.
 */
abstract class BaseApiController extends Controller
{
    protected function respondJson($data, int $status = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setContentType('application/json')
            ->setBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function success($data = null, string $message = 'ok', int $status = 200): ResponseInterface
    {
        return $this->respondJson([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    protected function fail(string $message, int $status = 400, $errors = null): ResponseInterface
    {
        return $this->respondJson([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    protected function input(): array
    {
        $json = $this->request->getJSON(true);

        return is_array($json) ? $json : $this->request->getPost();
    }

    protected function userId(): int
    {
        return (int) session()->get('user_id');
    }

    protected function currentWorkspaceId(): int
    {
        $id = session()->get('workspace_id');
        if ($id && $id !== 'all') {
            return (int) $id;
        }

        $db = \Config\Database::connect();
        $row = $db->table('workspace_users')
            ->where('user_id', $this->userId())
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        return $row ? (int) $row['workspace_id'] : 0;
    }

    protected function hasAccessToWorkspace(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        $db = \Config\Database::connect();

        return (bool) $db->table('workspace_users')
            ->where('user_id', $this->userId())
            ->where('workspace_id', $workspaceId)
            ->get()
            ->getRowArray();
    }

    protected function hasAccessToWorkflow(int $workflowId): bool
    {
        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $workflowId)->get()->getRowArray();
        if (! $workflow) {
            return false;
        }

        return $this->hasAccessToWorkspace((int) $workflow['workspace_id']);
    }

    /**
     * Cek peran (RBAC). Bila user bukan anggota workspace -> false.
     */
    protected function rbac(): \App\Services\RbacService
    {
        return new \App\Services\RbacService();
    }

    protected function roleInWorkspace(int $workspaceId): string
    {
        if ($workspaceId <= 0 || ! $this->hasAccessToWorkspace($workspaceId)) {
            return 'none';
        }

        return $this->rbac()->roleInWorkspace($this->userId(), $workspaceId);
    }

    protected function can(string $permission, int $workspaceId): bool
    {
        if ($workspaceId <= 0 || ! $this->hasAccessToWorkspace($workspaceId)) {
            return false;
        }

        return $this->rbac()->can($permission, $this->userId(), $workspaceId);
    }

    protected function requirePermission(string $permission, int $workspaceId): ?ResponseInterface
    {
        if ($this->can($permission, $workspaceId)) {
            return null;
        }

        return $this->fail('Tidak punya izin untuk aksi ini.', 403);
    }

    protected function requirePermissionOnWorkflow(string $permission, int $workflowId): ?ResponseInterface
    {
        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $workflowId)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }

        return $this->requirePermission($permission, (int) $workflow['workspace_id']);
    }
}
