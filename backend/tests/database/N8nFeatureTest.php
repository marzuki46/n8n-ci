<?php

use App\Nodes\RespondToWebhookNode;
use App\Nodes\WorkflowContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Fitur ala n8n: Respond to Webhook node + Tag workflow.
 *
 * @internal
 */
final class N8nFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanup = [];

    private array $featSession = [];

    /** @var list<int> */
    private array $cleanupTags = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $id) {
            $this->db->table('workflow_tags')->where('workflow_id', $id)->delete();
            $this->db->table('workflow_connections')->where('workflow_id', $id)->delete();
            $this->db->table('workflow_nodes')->where('workflow_id', $id)->delete();
            $this->db->table('webhooks')->where('workflow_id', $id)->delete();
            $this->db->table('executions')->where('workflow_id', $id)->delete();
            $this->db->table('workflows')->where('id', $id)->delete();
        }
        foreach ($this->cleanupTags as $tagId) {
            $this->db->table('workflow_tags')->where('tag_id', $tagId)->delete();
            $this->db->table('tags')->where('id', $tagId)->delete();
        }
        $this->cleanup = [];
        $this->cleanupTags = [];

        parent::tearDown();
    }

    private function seedSession(): int
    {
        if ($this->featSession !== []) {
            return $this->featSession['workspace_id'];
        }

        $email = 'n8nfeat_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'Feat User', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name' => 'WS Feat ' . uniqid(), 'slug' => 'feat-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->featSession = ['user_id' => $uid, 'workspace_id' => $ws];

        $this->withSession([
            'user_id' => $uid, 'user_role' => 'owner', 'user_email' => $email,
            'workspace_id' => $ws,
        ]);

        return $ws;
    }

    private function seedWebhookWorkflow(): string
    {
        $ws = $this->seedSession();
        $path = 'feat-' . uniqid();

        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'WF Respond ' . uniqid(),
            'status' => 'active', 'active' => 1, 'version' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanup[] = $wf;

        $this->db->table('webhooks')->insert([
            'workflow_id' => $wf, 'path' => $path, 'method' => 'POST',
            'active' => 1, 'response_mode' => 'lastNode',
        ]);

        // webhook → respond_to_webhook
        $this->db->table('workflow_nodes')->insert([
            'workflow_id' => $wf, 'node_id' => 'wh', 'node_type' => 'webhook',
            'name' => 'Webhook', 'parameters_json' => json_encode(['path' => $path]),
        ]);
        $this->db->table('workflow_nodes')->insert([
            'workflow_id' => $wf, 'node_id' => 'resp', 'node_type' => 'respond_to_webhook',
            'name' => 'Respond', 'parameters_json' => json_encode([
                'mode' => 'custom', 'body' => '{"ok":true,"msg":"Order {{$json.body.orderId}} diterima"}',
                'status_code' => 201,
            ]),
        ]);
        $this->db->table('workflow_connections')->insert([
            'workflow_id' => $wf, 'source_node' => 'wh', 'source_output' => 'out-1',
            'target_node' => 'resp', 'target_input' => 'in-1', 'connection_type' => 'main',
        ]);

        return $path;
    }

    // =========================================================================
    // Respond to Webhook
    // =========================================================================

    public function testRespondToWebhookReturnsCustomBodyAndStatus(): void
    {
        $path = $this->seedWebhookWorkflow();

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withBody(json_encode(['orderId' => 777]))
            ->post("webhook/{$path}");

        $this->assertSame(201, $res->response()->getStatusCode(), $res->getBody());
        $this->assertStringContainsString('"msg":"Order 777 diterima"', $res->getBody());
    }

    public function testRespondToWebhookRegisteredInRegistry(): void
    {
        $registry = new \App\Services\Workflow\NodeRegistry();
        $node     = $registry->get('respond_to_webhook');

        $this->assertNotNull($node);
        $this->assertFalse($node->isTrigger());
    }

    public function testRespondNodeExecuteWrapsPayload(): void
    {
        $node = new RespondToWebhookNode();
        $ctx  = new WorkflowContext(['id' => 1]);

        $out = $node->execute(
            [['json' => ['a' => 1]]],
            ['mode' => 'custom', 'body' => '{"x":9}', 'status_code' => 202],
            $ctx
        );

        $item = $out['main'][0]['json'];
        $this->assertTrue((bool) ($item['__webhook_response__'] ?? false));
        $this->assertSame(202, $item['status_code']);
        $this->assertSame(['x' => 9], $item['body']);
    }

    // =========================================================================
    // Tags
    // =========================================================================

    public function testTagCrudAttachAndFilter(): void
    {
        $ws = $this->seedSession();
        $csrfToken = null;
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $csrfToken = $m[1] ?? '';
        $_COOKIE['csrf_cookie_name'] = $csrfToken;
        $h = [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $csrfToken,
            'Content-Type'     => 'application/json',
        ];

        // Buat tag (nama unik agar idempoten terhadap run sebelumnya)
        $tagName = 'Mkt' . uniqid();
        $_COOKIE['csrf_cookie_name'] = $csrfToken;
        $create = $this->withHeaders($h)->withBody(json_encode(['name' => $tagName]))->post('api/tags');
        unset($_COOKIE['csrf_cookie_name']);
        $this->assertSame(201, $create->response()->getStatusCode(), $create->getBody());

        $tagBody = $create->getBody();
        preg_match('/"data":\s*\{[^}]*"id":\s*(\d+)/', $tagBody, $tm);
        $tagId = (int) ($tm[1] ?? 0);
        $this->assertGreaterThan(0, $tagId);
        $this->cleanupTags[] = $tagId;

        // Workflow baru
        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'WF Tagged ' . uniqid(),
            'status' => 'active', 'active' => 0, 'version' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanup[] = $wf;

        $_COOKIE['csrf_cookie_name'] = $csrfToken;
        $attach = $this->withHeaders($h)->post("api/workflows/{$wf}/tags/{$tagId}");
        unset($_COOKIE['csrf_cookie_name']);
        $this->assertSame(200, $attach->response()->getStatusCode());

        // Filter list by tag
        $list = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get("api/workflows?tag_id={$tagId}");
        $this->assertSame(200, $list->response()->getStatusCode());
        $listBody = $list->getBody();

        $start2 = strpos($listBody, '{');
        $end2   = strrpos($listBody, '}');
        $decoded = json_decode(substr($listBody, (int) $start2, (int) $end2 - (int) $start2 + 1), true);
        $rows = $decoded['data'] ?? [];
        $this->assertNotEmpty($rows);
        $this->assertCount(1, $rows, 'Filter tag harus hanya mengembalikan workflow bertag itu');
        $this->assertSame($wf, (int) $rows[0]['id']);
        $this->assertSame($tagName, $rows[0]['tags'][0]['name'] ?? '');

        // Detach lalu list kosong
        $_COOKIE['csrf_cookie_name'] = $csrfToken;
        $this->withHeaders($h)->delete("api/workflows/{$wf}/tags/{$tagId}");
        unset($_COOKIE['csrf_cookie_name']);

        $list2 = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get("api/workflows?tag_id={$tagId}");
        $b2 = $list2->getBody();
        $s2 = strpos($b2, '{');
        $e2 = strrpos($b2, '}');
        $d2 = json_decode(substr($b2, (int) $s2, (int) $e2 - (int) $s2 + 1), true);
        $this->assertSame([], $d2['data'] ?? []);
    }
}
