<?php

namespace App\Controllers\Api;

use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Webhook Inspector API: arsip & replay request webhook.
 */
class WebhookInspectorController extends BaseApiController
{
    /**
     * Daftar workspace yang boleh dilihat user ini.
     */
    private function visibleWorkspaces(int $userId): array
    {
        return array_map('intval', array_column(
            \Config\Database::connect()
                ->table('workspace_users')->select('workspace_id')->where('user_id', $userId)
                ->get()->getResultArray(),
            'workspace_id'
        ));
    }

    private function scopeFilter(int $userId)
    {
        $ids = $this->visibleWorkspaces($userId);

        return \Config\Database::connect()->table('webhook_requests')
            ->whereIn('workspace_id', $ids === [] ? [0] : $ids);
    }

    public function index(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $input = $this->request->getGet();
        $b = $this->scopeFilter($this->userId())
            ->select('id, path, method, ip, valid, note, received_at')
            ->orderBy('id', 'DESC')
            ->limit(min(200, max(1, (int) ($input['limit'] ?? 100))));

        if (! empty($input['path'])) {
            $b->like('path', (string) $input['path']);
        }
        if (isset($input['valid']) && $input['valid'] !== '') {
            $b->where('valid', (int) $input['valid']);
        }

        return $this->success($b->get()->getResultArray());
    }

    public function show(int $id): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $row = $this->scopeFilter($this->userId())->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->fail('Request tidak ditemukan.', 404);
        }

        foreach (['headers_json', 'query_json'] as $k) {
            $row[$k] = json_decode((string) ($row[$k] ?? ''), true);
        }
        unset($row['body_text']);

        $full = \Config\Database::connect()->table('webhook_requests')->select('body_text')->where('id', $id)->get()->getRowArray();
        $row['body_text'] = $full['body_text'] ?? null;

        return $this->success($row);
    }

    /**
     * POST api/webhook-requests/(:num)/replay — jalankan ulang workflow
     * dengan body/query yang tersimpan (hanya untuk request yang valid).
     */
    public function replay(int $id): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $db = \Config\Database::connect();
        $req = $this->scopeFilter($this->userId())->where('id', $id)->get()->getRowArray();
        if (! $req) {
            return $this->fail('Request tidak ditemukan.', 404);
        }
        if ((int) $req['valid'] !== 1 || empty($req['workflow_id'])) {
            return $this->fail('Hanya request valid yang bisa direplay.', 400);
        }

        $workflow = $db->table('workflows')->where('id', $req['workflow_id'])->get()->getRowArray();
        if (! $workflow) {
            return $this->fail('Workflow sudah tidak ada.', 404);
        }

        $bodyData = json_decode((string) $req['body_text'], true);
        if (! is_array($bodyData)) {
            $bodyData = ['raw' => substr((string) $req['body_text'], 0, 5000)];
        }
        $queryData = json_decode((string) ($req['query_json'] ?? '[]'), true) ?: [];

        $engine = new WorkflowEngine();
        $result = $engine->run($workflow, [], 'webhook', [
            'webhookData' => [
                'body'    => $bodyData,
                'query'   => $queryData,
                'headers' => json_decode((string) ($req['headers_json'] ?? '{}'), true) ?: [],
                'method'  => $req['method'],
                'path'    => $req['path'],
                'replayed_from_request_id' => (int) $id,
            ],
        ]);

        unset($result['outputs'], $result['order']);

        return $this->success(array_merge($result, ['replayed_from' => (int) $id]), 'Replay webhook selesai');
    }
}
