<?php

namespace App\Controllers\Api;

use App\Services\AlertService;
use CodeIgniter\HTTP\ResponseInterface;

class AlertController extends BaseApiController
{
    /**
     * GET /api/alerts -> riwayat alert workspace saat ini.
     */
    public function index(): ResponseInterface
    {
        $workspaceId = $this->currentWorkspaceId();
        $service     = new AlertService();

        return $this->success([
            'data'   => $service->recentForWorkspace($workspaceId, 20),
            'unread' => $service->countUnreadForWorkspace($workspaceId),
        ]);
    }

    /**
     * GET /api/workflows/:id/alerts -> konfigurasi alert workflow.
     */
    public function show(int $workflowId): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:read', $workflowId);
        if ($denied) {
            return $denied;
        }

        $service = new AlertService();
        $config  = $service->getConfig($workflowId);

        return $this->success($config ?: [
            'workflow_id'      => $workflowId,
            'email_to'         => null,
            'enabled'          => 0,
            'throttle_minutes' => 60,
            'last_sent_at'     => null,
        ]);
    }

    /**
     * PUT /api/workflows/:id/alerts -> simpan konfigurasi.
     */
    public function update(int $workflowId): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:write', $workflowId);
        if ($denied) {
            return $denied;
        }

        $json    = $this->request->getJSON(true) ?: [];
        $service = new AlertService();
        $service->saveConfig($workflowId, $json);

        return $this->success($service->getConfig($workflowId), 'Konfigurasi alert disimpan.');
    }
}
