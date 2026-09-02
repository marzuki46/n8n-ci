<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * MCP Server: workflow sebagai tools untuk AI eksternal via JSON-RPC.
 *
 * @internal
 */
final class McpServerTest extends CIUnitTestCase
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
            $this->db->table('workflow_connections')->where('workflow_id', $id)->delete();
            $this->db->table('workflow_nodes')->where('workflow_id', $id)->delete();
            $this->db->table('executions')->where('workflow_id', $id)->delete();
            $this->db->table('workflows')->where('id', $id)->delete();
        }
        if (! empty($this->ctx['key_id'])) {
            $this->db->table('api_keys')->where('id', $this->ctx['key_id'])->delete();
        }
        if (! empty($this->ctx['user_id'])) {
            $this->db->table('workspace_users')->where('user_id', $this->ctx['user_id'])->delete();
            $this->db->table('users')->where('id', $this->ctx['user_id'])->delete();
        }
        if (! empty($this->ctx['workspace_id'])) {
            $this->db->table('workspaces')->where('id', $this->ctx['workspace_id'])->delete();
        }
        parent::tearDown();
    }

    /**
     * Seed user + workspace + API key + workflow Set sederhana.
     */
    private function seedMcpContext(): array
    {
        if ($this->ctx !== []) {
            return $this->ctx;
        }

        $email = 'mcp_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'MCP Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name' => 'WS MCP ' . uniqid(), 'slug' => 'mcp-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $keyService = new \App\Services\ApiKeyService($this->db);
        $key = $keyService->generate('MCP Test', $uid);
        $this->ctx = [
            'user_id' => $uid, 'workspace_id' => $ws,
            'api_key' => $key['api_key'], 'key_id' => $key['id'],
        ];

        // Workflow tool: manual_trigger â†’ set (menambah field mcp=ok)
        $now = date('Y-m-d H:i:s');
        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'WF MCP Tool ' . uniqid(),
            'description'  => 'Tool uji MCP',
            'status' => 'active', 'active' => 1, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanupWf[] = $wf;

        $this->db->table('workflow_nodes')->insert([
            'workflow_id' => $wf, 'node_id' => 'trig', 'node_type' => 'manual_trigger',
            'name' => 'Mulai', 'parameters_json' => '{"payload":{}}',
        ]);
        $this->db->table('workflow_nodes')->insert([
            'workflow_id' => $wf, 'node_id' => 's', 'node_type' => 'set', 'name' => 'Set',
            'parameters_json' => '{"assignments":[{"field":"mcp","value":"ok"}]}',
        ]);
        $this->db->table('workflow_connections')->insert([
            'workflow_id' => $wf, 'source_node' => 'trig', 'source_output' => 'out-1',
            'target_node' => 's', 'target_input' => 'in-1', 'connection_type' => 'main',
        ]);

        $this->ctx['workflow_id'] = $wf;

        return $this->ctx;
    }

    private function rpc(array|string $body): \CodeIgniter\Test\TestResponse
    {
        $seed = $this->seedMcpContext();

        return $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $seed['api_key'],
            'Content-Type'     => 'application/json',
        ])->withBody(is_string($body) ? $body : json_encode($body))->post('api/v1/mcp');
    }

    private function resultOf(string $raw): array
    {
        $c = (string) $raw;
        $start = strpos($c, '{');
        $end   = strrpos($c, '}');

        return ($start !== false && $end !== false)
            ? (json_decode(substr($c, $start, $end - $start + 1), true) ?: [])
            : [];
    }

    public function testInitializeHandshake(): void
    {
        $res = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);

        $this->assertSame(200, $res->response()->getStatusCode());
        $body = $this->resultOf((string) $res->response()->getBody());

        $this->assertSame(1, $body['id'] ?? null);
        $this->assertSame('2.0', $body['jsonrpc'] ?? '');
        $this->assertSame('n8n-ci-mcp', $body['result']['serverInfo']['name'] ?? '');
        $this->assertSame('2025-06-18', $body['result']['protocolVersion'] ?? '');
    }

    public function testToolsListContainsWorkflows(): void
    {
        $seed = $this->seedMcpContext();

        $res = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
        ]);

        $body = $this->resultOf((string) $res->response()->getBody());
        $tools = $body['result']['tools'] ?? [];

        $names = array_column($tools, 'name');
        $this->assertContains('wf_' . $seed['workflow_id'], $names);

        $tool = $tools[array_search('wf_' . $seed['workflow_id'], $names, true)];
        $this->assertStringContainsString('Tool uji MCP', $tool['description']);
    }

    public function testToolsCallExecutesWorkflow(): void
    {
        $seed = $this->seedMcpContext();

        $res = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'wf_' . $seed['workflow_id'], 'arguments' => ['data' => ['x' => 1]]],
        ]);

        $this->cleanupExec[] = null; // executions dibersihkan via workflow cascade di tearDown

        $body = $this->resultOf((string) $res->response()->getBody());
        $text = $body['result']['content'][0]['text'] ?? '';
        $decoded = json_decode($text, true);

        $this->assertSame('success', $decoded['status'] ?? '', $text);
        $this->assertNotEmpty($decoded['execution_id'] ?? null);

        $this->cleanupExec[] = (int) $decoded['execution_id'];
    }

    public function testUnknownToolReturnsIsErrorContent(): void
    {
        $res = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'wf_99999999', 'arguments' => []],
        ]);

        $body = $this->resultOf((string) $res->response()->getBody());
        $this->assertTrue((bool) (($body['result']['isError'] ?? false)));
        $this->assertStringContainsStringIgnoringCase('akses', $body['result']['content'][0]['text'] ?? '');
    }

    public function testUnknownMethodReturnsRpcError(): void
    {
        $res = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'bukan/method',
        ]);

        $body = $this->resultOf((string) $res->response()->getBody());
        $this->assertSame(-32601, $body['error']['code'] ?? 0);
    }

    public function testRequiresApiKey(): void
    {
        $this->seedMcpContext();

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping']))
          ->post('api/v1/mcp');

        $this->assertSame(401, $res->response()->getStatusCode());
    }
}

