<?php

namespace App\Controllers\Api;

use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * MCP Server (Model Context Protocol) — setara fitur n8n 2.5.x.
 * Expose workflow sebagai callable "tools" untuk AI eksternal
 * (Claude Desktop, klien MCP lain) via JSON-RPC 2.0.
 *
 * Transport: POST /api/v1/mcp (auth X-API-Key / Bearer).
 *
 * Method yang didukung:
 *   initialize    → handshake + info server
 *   tools/list    → daftar workflow sebagai tools (wf_{id})
 *   tools/call    → jalankan workflow; arguments dikirim sebagai payload
 *   ping          → keepalive
 */
class McpController extends BaseApiController
{
    public function handle(): ResponseInterface
    {
        $body = $this->request->getJSON(true);
        if (! is_array($body)) {
            return $this->rpcError(null, -32700, 'Parse error: body bukan JSON valid.');
        }

        $id     = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');

        switch ($method) {
            case 'initialize':
                return $this->rpcResult($id, [
                    'protocolVersion' => '2025-06-18',
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => [
                        'name'    => 'n8n-ci-mcp',
                        'version' => '1.0.0',
                    ],
                ]);

            case 'notifications/initialized':
                // Notifikasi tanpa respons isi.
                return $this->rpcResult(null, []);

            case 'ping':
                return $this->rpcResult($id, []);

            case 'tools/list':
                return $this->rpcResult($id, ['tools' => $this->listTools()]);

            case 'tools/call':
                $params = $body['params'] ?? [];
                return $this->callTool(
                    (string) ($params['name'] ?? ''),
                    (array) ($params['arguments'] ?? []),
                    $id
                );

            case '':
                return $this->rpcError($id, -32600, 'Invalid Request: method kosong.');

            default:
                return $this->rpcError($id, -32601, "Method not found: {$method}");
        }
    }

    // ==================================================================

    /**
     * Workflow milik workspace API key → daftar tools.
     */
    private function listTools(): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('workflows')
            ->select('id, name, description, active')
            ->where('workspace_id', $this->currentWorkspaceId())
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $tools = [];
        foreach ($rows as $w) {
            $tools[] = [
                'name'        => 'wf_' . $w['id'],
                'description' => trim(($w['name'] ?: ('Workflow #' . $w['id']))
                    . ($w['description'] ? ' — ' . $w['description'] : '')),
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'data' => [
                            'type'        => 'object',
                            'description' => 'Payload trigger untuk workflow ini.',
                        ],
                    ],
                ],
            ];
        }

        return $tools;
    }

    private function callTool(string $name, array $args, $id): ResponseInterface
    {
        if (! preg_match('/^wf_(\d+)$/', $name, $m)) {
            return $this->toolError($id, "Nama tool tidak dikenal: {$name}. Gunakan wf_<workflow_id>.");
        }

        $workflowId = (int) $m[1];
        if (! $this->hasAccessToWorkflow($workflowId)) {
            return $this->toolError($id, 'Workflow tidak ditemukan atau tidak punya akses.');
        }

        $workflow = \Config\Database::connect()
            ->table('workflows')->where('id', $workflowId)->get()->getRowArray();
        if ((int) ($workflow['active'] ?? 0) !== 1) {
            return $this->toolError($id, 'Workflow nonaktif — aktifkan dulu di dashboard.');
        }

        try {
            $engine = new WorkflowEngine();
            $result = $engine->run(
                $workflow,
                [['json' => (object) ($args['data'] ?? [])]],
                'api'
            );
        } catch (\Throwable $e) {
            return $this->toolError($id, 'Eksekusi gagal: ' . $e->getMessage());
        }

        $ok = ($result['status'] ?? '') === 'success';

        return $this->rpcResult($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'status'       => $result['status'],
                    'execution_id' => $result['execution_id'],
                    'node_states'  => $result['node_states'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => ! $ok,
        ]);
    }

    // ==================================================================
    // JSON-RPC helpers
    // ==================================================================

    private function rpcResult($id, array $result): ResponseInterface
    {
        return $this->respondJson([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ]);
    }

    private function toolError($id, string $message): ResponseInterface
    {
        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ]);
    }

    private function rpcError($id, int $code, string $message): ResponseInterface
    {
        return $this->respondJson([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ]);
    }
}
