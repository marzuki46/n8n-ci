<?php

namespace App\Controllers\Api;

use App\Services\Workflow\ExecutionManager;
use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

class ExecutionController extends BaseApiController
{
    use WorkflowPublication;

    public function index(): ResponseInterface
    {
        $input = $this->request->getGet();
        $manager = new ExecutionManager();

        $workspaceId = $this->currentWorkspaceId();

        $executions = $manager->list([
            'workspace_id' => $workspaceId,
            'workflow_id'  => $input['workflow_id'] ?? null,
            'status'       => $input['status'] ?? null,
        ], (int) ($input['limit'] ?? 50), (int) ($input['offset'] ?? 0));

        return $this->success($executions);
    }

    public function execute(int $workflowId): ResponseInterface
    {
        $denied = $this->requirePermissionOnWorkflow('workflows:execute', $workflowId);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $workflow = $db->table('workflows')->where('id', $workflowId)->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow tidak ditemukan.', 404);
        }
        if ((int) ($workflow['active'] ?? 0) !== 1) {
            return $this->fail('Workflow nonaktif — aktifkan dulu sebelum dieksekusi.', 409);
        }

        $input = $this->input();
        $payload = $input['payload'] ?? null;

        // Mode background: buat antrian dan kembalikan segera.
        if (! empty($input['queued'])) {
            $queueService = new \App\Services\Workflow\ExecutionQueueService();
            $queued = $queueService->enqueue(
                $workflowId,
                'manual',
                $this->buildTriggerInput($payload)
            );

            return $this->success([
                'execution_id' => $queued['execution_id'],
                'queue_id'     => $queued['queue_id'],
                'status'       => 'queued',
            ], 'Eksekusi masuk antrian', 202);
        }

        $engine = new WorkflowEngine();
        $result = $engine->run($workflow, $this->buildTriggerInput($payload), 'manual');

        unset($result['outputs'], $result['order']);

        return $this->success($result, 'Eksekusi selesai');
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

    public function stats(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $workspaceId = $this->currentWorkspaceId();

        $totals = $db->table('executions e')
            ->select('e.status, COUNT(*) as total')
            ->join('workflows w', 'w.id = e.workflow_id')
            ->where('w.workspace_id', $workspaceId)
            ->groupBy('e.status')
            ->get()
            ->getResultArray();

        $stats = ['success' => 0, 'error' => 0, 'running' => 0, 'other' => 0];
        foreach ($totals as $row) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = (int) $row['total'];
            } else {
                $stats['other'] += (int) $row['total'];
            }
        }

        return $this->success($stats);
    }

    /**
     * POST /executions/:id/stop
     */
    public function stop(int $id): ResponseInterface
    {
        $manager = new ExecutionManager();
        $execution = $manager->getExecution($id);
        if (! $execution) {
            return $this->fail('Eksekusi tidak ditemukan.', 404);
        }
        if (! $this->hasAccessToWorkflow((int) $execution['workflow_id'])) {
            return $this->fail('Tidak punya akses.', 403);
        }
        if (! in_array($execution['status'], ['running', 'paused', 'waiting'], true)) {
            return $this->fail('Eksekusi tidak sedang berjalan.', 409);
        }

        $db = \Config\Database::connect();
        $db->table('executions')->where('id', $id)->update(['control_flag' => 'stop']);

        return $this->success(['status' => 'stop_requested'], 'Sinyal stop terkirim');
    }

    /**
     * POST /executions/:id/pause
     */
    public function pause(int $id): ResponseInterface
    {
        $manager = new ExecutionManager();
        $execution = $manager->getExecution($id);
        if (! $execution) {
            return $this->fail('Eksekusi tidak ditemukan.', 404);
        }
        if (! $this->hasAccessToWorkflow((int) $execution['workflow_id'])) {
            return $this->fail('Tidak punya akses.', 403);
        }
        if ($execution['status'] !== 'running') {
            return $this->fail('Hanya bisa menjeda eksekusi yang sedang berjalan.', 409);
        }

        $db = \Config\Database::connect();
        $db->table('executions')->where('id', $id)->update(['control_flag' => 'pause']);

        return $this->success(['status' => 'pause_requested'], 'Sinyal pause terkirim');
    }

    /**
     * POST /executions/:id/resume
     */
    public function resume(int $id): ResponseInterface
    {
        $manager = new ExecutionManager();
        $execution = $manager->getExecution($id);
        if (! $execution) {
            return $this->fail('Eksekusi tidak ditemukan.', 404);
        }
        if (! $this->hasAccessToWorkflow((int) $execution['workflow_id'])) {
            return $this->fail('Tidak punya akses.', 403);
        }
        if ($execution['status'] !== 'paused') {
            return $this->fail('Eksekusi tidak sedang dijeda.', 409);
        }

        $db = \Config\Database::connect();
        $db->table('executions')->where('id', $id)->update(['control_flag' => 'resume']);

        return $this->success(['status' => 'resume_requested'], 'Sinyal resume terkirim');
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
