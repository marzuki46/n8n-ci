<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Webhook Inspector: arsip request masuk + list/detail/replay.
 *
 * @internal
 */
final class WebhookInspectorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh = false;

    private array $ctx = [];

    /** @var list<int> */
    private array $cleanupWf = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupWf as $id) {
            $this->db->table('webhook_requests')->where('workflow_id', $id)->delete();
            $this->db->table('webhook_requests')->where('path', $this->ctx['path'] ?? 'x')->delete();
            $this->db->table('executions')->where('workflow_id', $id)->delete();
            $this->db->table('workflow_connections')->where('workflow_id', $id)->delete();
            $this->db->table('workflow_nodes')->where('workflow_id', $id)->delete();
            $this->db->table('webhooks')->where('workflow_id', $id)->delete();
            $this->db->table('workflows')->where('id', $id)->delete();
        }
        if (! empty($this->ctx['key_id'])) {
            $this->db->table('api_keys')->where('id', $this->ctx['key_id'])->delete();
        }
        if (! empty($this->ctx['user_id'])) {
            $uid = $this->ctx['user_id'];
            $wids = array_column(
                $this->db->table('workspace_users')->select('workspace_id')->where('user_id', $uid)->get()->getResultArray(),
                'workspace_id'
            );
            $this->db->table('workspace_users')->where('user_id', $uid)->delete();
            foreach ($wids as $wid) {
                $this->db->table('workspaces')->where('id', $wid)->delete();
            }
            $this->db->table('users')->where('id', $uid)->delete();
        }
        parent::tearDown();
    }

    /**
     * Seed webhook workflow dengan token, kembalikan konteks.
     */
    private function seedWebhookWorkflow(): array
    {
        if ($this->ctx !== []) {
            return $this->ctx;
        }

        $email = 'insp_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'Insp Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name' => 'WS Insp ' . uniqid(), 'slug' => 'ins-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $keyService = new \App\Services\ApiKeyService($this->db);
        $key = $keyService->generate('Insp Key', $uid);

        $now = date('Y-m-d H:i:s');
        $path = 'insp-' . uniqid();
        $token = 'secret-token-' . uniqid();

        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'WF Insp ' . uniqid(), 'status' => 'active',
            'active' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanupWf[] = $wf;

        $this->db->table('webhooks')->insert([
            'workflow_id' => $wf, 'path' => $path, 'method' => 'POST', 'active' => 1,
        ]);
        $this->db->table('workflow_nodes')->insert([
            'workflow_id' => $wf, 'node_id' => 'trig', 'node_type' => 'webhook',
            'name' => 'WH', 'parameters_json' => json_encode(['path' => $path, 'auth_token' => $token]),
        ]);

        $this->ctx = [
            'user_id' => $uid, 'workspace_id' => $ws,
            'api_key' => $key['api_key'], 'key_id' => $key['id'],
            'workflow_id' => $wf, 'path' => $path, 'token' => $token,
        ];

        return $this->ctx;
    }

    public function testIncomingRequestIsArchivedAndReplayable(): void
    {
        $c = $this->seedWebhookWorkflow();

        // Kirim webhook valid via HTTP nyata ke server dev? Dev server single-
        // thread deadlock; gunakan FeatureTestTrait POST langsung.
        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-Webhook-Token'  => $c['token'],
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['orderId' => 555]))->post('webhook/' . $c['path']);

        $this->assertContains(
            $res->response()->getStatusCode(),
            [200, 202],
            'Webhook harus diproses: ' . substr((string) $res->response()->getBody(), 0, 200)
        );

        // Arsip tercatat sebagai valid.
        $row = $this->db->table('webhook_requests')
            ->where('path', $c['path'])->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['valid']);
        $this->assertSame((int) $c['workflow_id'], (int) $row['workflow_id']);
        $this->assertStringContainsString('555', (string) $row['body_text']);

        // List endpoint (auth session owner).
        $list = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $c['api_key'],
        ])->withSession([
            'user_id'      => $c['user_id'],
            'user_role'    => 'owner',
            'workspace_id' => $c['workspace_id'],
        ])->get('api/webhook-requests');
        $this->assertSame(200, $list->response()->getStatusCode());
        $listBody = json_decode((string) $list->response()->getBody(), true);
        $ids = array_column($listBody['data'] ?? [], 'id');
        $this->assertContains((int) $row['id'], array_map('intval', $ids));

        // Replay dari arsip â†’ eksekusi baru sukses.
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $c['user_id'], 'user_role' => 'owner'])
            ->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $rp = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode([]))->post("api/webhook-requests/{$row['id']}/replay");
        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(200, $rp->response()->getStatusCode(), $rp->response()->getBody());
        $rb = json_decode((string) $rp->response()->getBody(), true)['data'];
        $this->assertSame('success', $rb['status'] ?? '');

        // Bersihkan executions replay.
        if (! empty($rb['execution_id'])) {
            $this->db->table('execution_nodes')->where('execution_id', $rb['execution_id'])->delete();
            $this->db->table('execution_errors')->where('execution_id', $rb['execution_id'])->delete();
            $this->db->table('executions')->where('id', $rb['execution_id'])->delete();
        }
    }

    public function testInvalidTokenStillArchivedAsInvalid(): void
    {
        $c = $this->seedWebhookWorkflow();

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-Webhook-Token'  => 'SALAH',
        ])->withBody(json_encode(['a' => 1]))->post('webhook/' . $c['path']);
        $this->assertSame(401, $res->response()->getStatusCode());

        $row = $this->db->table('webhook_requests')
            ->where('path', $c['path'])->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['valid']);
        $this->assertStringContainsStringIgnoringCase('token', (string) $row['note']);
    }
}
