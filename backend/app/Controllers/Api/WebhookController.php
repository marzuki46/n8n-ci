<?php

namespace App\Controllers\Api;

use App\Nodes\FormTriggerNode;
use App\Services\Workflow\NodeRegistry;
use App\Services\Workflow\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Endpoint publik untuk webhook. Tidak butuh autentikasi session.
 * URL: {baseURL}/webhook/{path}
 * Node webhook: terima panggilan lalu lanjutkan workflow.
 * Node form_trigger: GET = tampilkan form, POST = jalankan workflow.
 */
class WebhookController extends BaseApiController
{
    /**
     * Update arsip inspector setelah validasi (valid/note saja —
     * workspace & workflow sudah diisi saat arsip dibuat).
     */
    private function markInspector(?int $id, ?string $note = null): void
    {
        if (! $id) {
            return;
        }
        try {
            $data = ['valid' => $note === null ? 1 : 0];
            if ($note !== null) {
                $data['note'] = mb_substr($note, 0, 191);
            }
            \Config\Database::connect()
                ->table('webhook_requests')->where('id', $id)->update($data);
        } catch (\Throwable $e) {
            // diam — inspeksi tidak boleh mengganggu eksekusi.
        }
    }

    /**
     * Retention: simpan maksimal ±500 baris terbaru (dijalankan ~10% request).
     */
    private function trimInspector(): void
    {
        if (random_int(1, 10) !== 1) {
            return;
        }
        try {
            $minId = (int) (\Config\Database::connect()
                ->query('SELECT COALESCE(MAX(id),0)-500 AS m FROM webhook_requests')
                ->getRow()->m ?? 0);
            if ($minId > 0) {
                \Config\Database::connect()
                    ->table('webhook_requests')->where('id <', $minId)->delete();
            }
        } catch (\Throwable $e) {
            // diam
        }
    }

    public function handle(string $path): ResponseInterface
    {
        $db = \Config\Database::connect();
        $method = $this->request->getMethod();

        // Cari webhook & workspace-nya lebih dulu — arsip inspector
        // perlu workspace_id agar scoped antar proyek.
        $webhook = $db->table('webhooks')
            ->where('path', $path)
            ->where('method', $method)
            ->where('active', 1)
            ->get()
            ->getRowArray();

        $workflowRow = null;
        if ($webhook) {
            $workflowRow = $db->table('workflows')
                ->where('id', $webhook['workflow_id'])
                ->get()
                ->getRowArray();
        }

        // ===== Webhook Inspector: arsip request masuk (termasuk yang gagal).
        $rawBody = (string) $this->request->getBody();
        $inspectorId = null;
        try {
            $headers = [];
            foreach ($this->request->getHeaders() as $hk => $hv) {
                if ($hv->getValue() !== '') {
                    $headers[$hk] = $hv->getValueLine();
                }
            }
            $db->table('webhook_requests')->insert([
                'path'         => mb_substr($path, 0, 191),
                'method'       => strtoupper($method),
                'ip'           => substr($this->request->getIPAddress(), 0, 45),
                'workspace_id' => $workflowRow ? (int) $workflowRow['workspace_id'] : null,
                'workflow_id'  => $workflowRow ? (int) $workflowRow['id'] : null,
                'headers_json' => json_encode($headers, JSON_UNESCAPED_SLASHES),
                'query_json'   => json_encode($this->request->getGet() ?: [], JSON_UNESCAPED_SLASHES),
                'body_text'    => substr($rawBody, 0, 100000),
                'valid'        => 0,
                'received_at'  => date('Y-m-d H:i:s'),
            ]);
            $inspectorId = (int) $db->insertID();
            $this->trimInspector();
        } catch (\Throwable $e) {
            log_message('error', '[Inspector] Gagal arsip webhook: ' . $e->getMessage());
        }

        if (! $webhook) {
            return $this->respondJson([
                'success' => false,
                'message' => 'Webhook tidak ditemukan.',
            ], 404);
        }

        // Cari node trigger (webhook / form_trigger) milik workflow ini
        $triggerNode = $db->table('workflow_nodes')
            ->select('id, node_id, node_type, name, parameters_json')
            ->where('workflow_id', $webhook['workflow_id'])
            ->whereIn('node_type', ['webhook', 'form_trigger'])
            ->get()
            ->getRowArray();

        $params = [];
        if ($triggerNode) {
            $params = json_decode($triggerNode['parameters_json'] ?? '{}', true) ?: [];
        }

        // Validasi token jika ada
        $authToken = $params['auth_token'] ?? '';
        if ($authToken !== '') {
            $given = $this->request->getHeaderLine('X-Webhook-Token');
            if ($given === '') {
                $given = $this->request->getGet('token') ?? '';
            }
            if (! hash_equals((string) $authToken, (string) $given)) {
                $this->markInspector($inspectorId, 'Token webhook salah');
                return $this->respondJson([
                    'success' => false,
                    'message' => 'Token webhook tidak valid.',
                ], 401);
            }
        }

        $workflow = $db->table('workflows')->where('id', $webhook['workflow_id'])->get()->getRowArray();
        if (! $workflow) {
            $this->markInspector($inspectorId, 'Workflow tidak ditemukan');
            return $this->respondJson([
                'success' => false,
                'message' => 'Workflow tidak ditemukan.',
            ], 404);
        }
        $this->markInspector($inspectorId);

        // GET pada form_trigger: render halaman form
        if ($triggerNode && $triggerNode['node_type'] === 'form_trigger' && strtoupper($method) === 'GET') {
            $node = (new NodeRegistry())->get('form_trigger');
            $baseUrl = rtrim((string) (config('App')->baseURL ?? '/'), '/') . '/webhook/' . $path;
            if ($this->request->getGet('sent') === '1' && $node instanceof FormTriggerNode) {
                $html = $node->renderForm($params, $baseUrl);
                $html = str_replace(
                    '.msg{display:none;',
                    '.msg{display:block;',
                    $html
                );
                return $this->response->setContentType('text/html; charset=UTF-8')->setBody($html);
            }
            if ($node instanceof FormTriggerNode) {
                return $this->response->setContentType('text/html; charset=UTF-8')->setBody($node->renderForm($params, $baseUrl));
            }
        }

        $body = $this->request->getJSON(true);
        if ($body === null) {
            $body = $this->request->getPost() ?: $this->request->getGet() ?: [];
        }

        $webhookData = [
            'body'    => is_array($body) ? $body : ['raw' => $this->request->getBody()],
            'query'   => $this->request->getGet() ?: [],
            'headers' => $this->request->getHeaders() ? $this->request->getHeaders() : [],
            'method'  => $method,
            'path'    => $path,
        ];

        $engine = new WorkflowEngine();
        $result = $engine->run($workflow, [], 'webhook', ['webhookData' => $webhookData]);

        // Respond to Webhook: bila ada node respond yang sukses, kembalikan
        // body-nya ke pemanggil HTTP (setara fitur n8n).
        $respondNode = $db->table('workflow_nodes')
            ->select('node_id')
            ->where('workflow_id', $webhook['workflow_id'])
            ->where('node_type', 'respond_to_webhook')
            ->get()
            ->getRowArray();
        if ($respondNode && $result['status'] === 'success'
            && isset($result['outputs'][$respondNode['node_id']]['main'][0]['json'])) {
            $payload = $result['outputs'][$respondNode['node_id']]['main'][0]['json'];
            if (($payload['__webhook_response__'] ?? false) === true) {
                $statusCode = max(200, min(599, (int) ($payload['status_code'] ?? 200)));
                $body       = $payload['body'];

                return $this->respondJson(
                    ['success' => $statusCode < 400, 'data' => $body],
                    $statusCode
                );
            }
        }

        $responseMode = $webhook['response_mode'] ?? 'respond';

        if ($responseMode === 'respond') {
            return $this->respondJson([
                'success' => $result['status'] === 'success',
                'message' => $result['status'],
                'execution_id' => $result['execution_id'],
            ], $result['status'] === 'success' ? 200 : 500);
        }

        // lastNode / none: tetap 200 agar panggil tidak di-retry oleh pengirim
        return $this->respondJson([
            'success' => true,
            'message' => 'Webhook diproses.',
            'execution_id' => $result['execution_id'],
        ], 202);
    }
}
