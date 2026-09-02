<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test B2: Import/export workflow JSON.
 *
 * @internal
 */
final class WorkflowImportExportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    private function seedUser(): array
    {
        $email = 'io_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'       => 'IO User',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name'       => 'IO WS',
            'slug'       => 'ws-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws,
            'user_id'      => $uid,
            'role'         => 'owner',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return ['user_id' => $uid, 'workspace_id' => $ws, 'email' => $email];
    }

    private function login(array $seed): void
    {
        $this->withSession([
            'user_id'      => $seed['user_id'],
            'user_role'    => 'owner',
            'user_name'    => 'IO User',
            'user_email'   => $seed['email'],
            'workspace_id' => $seed['workspace_id'],
        ]);

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $_COOKIE['csrf_cookie_name'] = $this->extractToken($csrf->getBody());
    }

    private function headers(): array
    {
        return [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $_COOKIE['csrf_cookie_name'] ?? '',
        ];
    }

    private function makeWorkflow(array $seed, array $nodes, array $connections): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => $seed['workspace_id'],
            'name'         => 'WF IO ' . uniqid(),
            'status'       => 'draft',
            'active'       => 0,
            'version'      => 3,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();

        foreach ($nodes as $n) {
            $this->db->table('workflow_nodes')->insert([
                'workflow_id'     => $wf,
                'node_id'         => $n['id'],
                'node_type'       => $n['type'],
                'name'            => $n['name'],
                'position_x'      => $n['position']['x'],
                'position_y'      => $n['position']['y'],
                'parameters_json' => json_encode($n['parameters'] ?? []),
                'disabled'        => 0,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
        }

        foreach ($connections as $c) {
            $this->db->table('workflow_connections')->insert([
                'workflow_id'     => $wf,
                'source_node'     => $c['source'],
                'source_output'   => $c['source_output'],
                'target_node'     => $c['target'],
                'target_input'    => $c['target_input'],
                'connection_type' => 'main',
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        }

        return $wf;
    }

    public function testExportReturnsGraph(): void
    {
        $seed = $this->seedUser();
        $this->login($seed);

        $wf = $this->makeWorkflow($seed, [
            ['id' => 'n1', 'name' => 'Manual', 'type' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'parameters' => []],
            ['id' => 'n2', 'name' => 'HTTP', 'type' => 'http_request', 'position' => ['x' => 200, 'y' => 0], 'parameters' => ['method' => 'GET', 'url' => 'https://example.com']],
        ], [
            ['source' => 'n1', 'source_output' => 'main', 'target' => 'n2', 'target_input' => 'main'],
        ]);

        $result = $this->withHeaders($this->headers())->get("api/workflows/{$wf}/export");

        $this->assertSame(200, $result->response()->getStatusCode());
        $body = $result->getBody();

        $this->assertStringContainsString('"name":', $body);
        $this->assertStringContainsString('manual_trigger', $body);
        $this->assertStringContainsString('http_request', $body);
        $this->assertStringContainsString('"version":3', $body);
    }

    public function testImportFromOwnFormat(): void
    {
        $seed = $this->seedUser();
        $this->login($seed);

        $payload = [
            'name'        => 'WF Impor ' . uniqid(),
            'description' => 'dari unit test',
            'nodes'       => [
                ['id' => 'a', 'name' => 'Manual', 'type' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['name' => 'Manual', 'parameters' => []]],
                ['id' => 'b', 'name' => 'Set', 'type' => 'set', 'position' => ['x' => 200, 'y' => 0], 'data' => ['name' => 'Set', 'parameters' => ['fields' => ['x' => 'y']]]],
            ],
            'connections' => [
                ['source' => 'a', 'sourceHandle' => 'main', 'target' => 'b', 'targetHandle' => 'main'],
            ],
        ];

        $result = $this->withHeaders($this->headers())->post('api/workflows/import', $payload);

        $this->assertSame(201, $result->response()->getStatusCode());
        $this->assertMatchesRegularExpression('/"id":[0-9]+/', $result->getBody());
        preg_match('/"id":([0-9]+)/', $result->getBody(), $m);
        $newId = (int) $m[1];

        $nodes = $this->db->table('workflow_nodes')->where('workflow_id', $newId)->get()->getResultArray();
        $conns = $this->db->table('workflow_connections')->where('workflow_id', $newId)->get()->getResultArray();

        $this->assertCount(2, $nodes);
        $this->assertCount(1, $conns);
        $this->assertSame('a', $conns[0]['source_node']);
        $this->assertSame('b', $conns[0]['target_node']);

        $row = $this->db->table('workflows')->where('id', $newId)->get()->getRowArray();
        $this->assertSame(0, (int) $row['active']);
    }

    public function testImportFromN8nFormat(): void
    {
        $seed = $this->seedUser();
        $this->login($seed);

        $payload = [
            'name' => 'WF n8n ' . uniqid(),
            'nodes' => [
                [
                    'id'           => '11111111-2222-3333-4444-555555555555',
                    'name'         => 'Webhook',
                    'type'         => 'n8n-nodes-base.webhook',
                    'typeVersion'  => 1,
                    'position'     => [0, 0],
                    'parameters'   => ['path' => 'tessst', 'method' => 'POST'],
                ],
                [
                    'id'          => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                    'name'        => 'Set Data',
                    'type'        => 'n8n-nodes-base.set',
                    'typeVersion' => 1,
                    'position'    => [250, 0],
                    'parameters'  => ['assignments' => [['name' => 'ok', 'value' => '1']]],
                ],
            ],
            'connections' => [
                'Webhook' => [
                    'main' => [
                        [['node' => 'Set Data', 'type' => 'main', 'index' => 0]],
                    ],
                ],
            ],
            'settings' => ['executionOrder' => 'v1'],
        ];

        $result = $this->withHeaders($this->headers())->post('api/workflows/import', $payload);

        $this->assertSame(201, $result->response()->getStatusCode());
        preg_match('/"id":([0-9]+)/', $result->getBody(), $m);
        $newId = (int) $m[1];

        $nodes = $this->db->table('workflow_nodes')->where('workflow_id', $newId)->orderBy('id', 'ASC')->get()->getResultArray();
        $conns = $this->db->table('workflow_connections')->where('workflow_id', $newId)->get()->getResultArray();

        $this->assertCount(2, $nodes);
        $this->assertSame('webhook', $nodes[0]['node_type']);
        $this->assertSame('set', $nodes[1]['node_type']);
        $this->assertCount(1, $conns);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $conns[0]['source_node']);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $conns[0]['target_node']);

        $settings = $this->db->table('workflows')->where('id', $newId)->get()->getRowArray();
        $this->assertStringContainsString('v1', $settings['settings_json']);
    }

    public function testExportImportRoundTrip(): void
    {
        $seed = $this->seedUser();
        $this->login($seed);

        $wf = $this->makeWorkflow($seed, [
            ['id' => 'n1', 'name' => 'Manual', 'type' => 'manual_trigger', 'position' => ['x' => 5, 'y' => 10], 'parameters' => []],
            ['id' => 'n2', 'name' => 'Filter', 'type' => 'filter', 'position' => ['x' => 300, 'y' => 10], 'parameters' => ['condition' => 'a == 1']],
        ], [
            ['source' => 'n1', 'source_output' => 'main', 'target' => 'n2', 'target_input' => 'main'],
        ]);

        $exported = $this->withHeaders($this->headers())->get("api/workflows/{$wf}/export");
        $this->assertSame(200, $exported->response()->getStatusCode());

        $body = $exported->getBody();
        if (preg_match('/\{"success":.*\}/s', $body, $em) !== 1) {
            $this->fail('Respons export tidak berupa JSON: ' . $body);
        }
        $data = json_decode($em[0], true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);

        $imported = $this->withHeaders($this->headers())->post('api/workflows/import', ['workflow' => $data['data']]);
        $this->assertSame(201, $imported->response()->getStatusCode());
        preg_match('/"id":([0-9]+)/', $imported->getBody(), $m);
        $newId = (int) $m[1];

        $conns = $this->db->table('workflow_connections')->where('workflow_id', $newId)->get()->getResultArray();
        $this->assertCount(1, $conns);
        $this->assertSame('n1', $conns[0]['source_node']);

        $nodes = $this->db->table('workflow_nodes')->where('workflow_id', $newId)->get()->getResultArray();
        $names = array_column($nodes, 'node_type');
        $this->assertContains('manual_trigger', $names);
        $this->assertContains('filter', $names);
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
