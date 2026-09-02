<?php

namespace App\Controllers\Api;

use App\Services\Workflow\ExecutionManager;
use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public API bergaya n8n. Autentikasi lewat header X-API-Key / Bearer
 * (diterapkan filter ApiKeyAuthFilter pada grup /api/v1).
 */
class ApiV1Controller extends BaseApiController
{
    public function workflows(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $rows = $db->table('workflows')
            ->select('id, name, status, active, version, created_at, updated_at')
            ->where('workspace_id', $this->currentWorkspaceId())
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->success($rows);
    }

    public function createExecution(): ResponseInterface
    {
        $input = $this->input();
        $workflowId = (int) ($input['workflow_id'] ?? 0);

        if ($workflowId <= 0 || ! $this->hasAccessToWorkflow($workflowId)) {
            return $this->fail('Workflow tidak ditemukan atau tidak punya akses.', 403);
        }

        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $workflowId)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }
        if ((int) ($workflow['active'] ?? 0) !== 1) {
            return $this->fail('Workflow nonaktif — aktifkan dulu sebelum dieksekusi via API.', 409);
        }

        $payload = $input['data'] ?? null;
        $engine = new WorkflowEngine();
        $result = $engine->run($workflow, $this->buildTriggerInput($payload), 'api');

        unset($result['outputs'], $result['order']);

        return $this->success($result, 'Eksekusi selesai', $result['status'] === 'success' ? 201 : 500);
    }

    public function executions(): ResponseInterface
    {
        $input = $this->request->getGet();
        $manager = new ExecutionManager();

        $rows = $manager->list([
            'workspace_id' => $this->currentWorkspaceId(),
            'workflow_id'  => $input['workflow_id'] ?? null,
            'status'       => $input['status'] ?? null,
        ], (int) ($input['limit'] ?? 50), (int) ($input['offset'] ?? 0));

        return $this->success($rows);
    }

    public function show(int $id): ResponseInterface
    {
        $manager = new ExecutionManager();
        $execution = $manager->getExecution($id);
        if (! $execution) {
            return $this->fail('Eksekusi tidak ditemukan.', 404);
        }

        if (! $this->hasAccessToWorkflow((int) $execution['workflow_id'])) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $nodes = $manager->getExecutionNodes($id);
        foreach ($nodes as &$node) {
            $node['input_data']  = $this->decodeJson($node['input_data']);
            $node['output_data'] = $this->decodeJson($node['output_data']);
        }
        unset($node);

        $execution['nodes']  = $nodes;
        $execution['errors'] = $manager->getExecutionErrors($id);
        $execution['logs']   = $manager->getExecutionLogs($id);

        return $this->success($execution);
    }

    protected function buildTriggerInput($payload): array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (is_array($payload) && count($payload) > 0) {
            return [$payload];
        }

        return [];
    }

    protected function decodeJson(?string $raw)
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
