<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Paket 2 — endpoint "Coba Node" (POST api/nodes/test).
 *
 * @internal
 */
final class NodeTestEndpointTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanup = [];

    /** @var list<int> */
    private array $cleanupUsers = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $id) {
            foreach (['workflow_connections', 'workflow_nodes'] as $t) {
                $this->db->table($t)->where('workflow_id', $id)->delete();
            }
            $this->db->table('workflows')->where('id', $id)->delete();
            $this->db->table('workspace_users')->where('workspace_id', $id)->delete();
            $this->db->table('workspaces')->where('id', $id)->delete();
        }
        foreach ($this->cleanupUsers as $uid) {
            $this->db->table('users')->where('id', $uid)->delete();
        }
        $this->cleanup = [];
        $this->cleanupUsers = [];

        parent::tearDown();
    }

    private function seedSession(): array
    {
        $email = 'nodetest_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'       => 'Node Tester',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();
        $this->cleanupUsers[] = $uid;

        $this->db->table('workspaces')->insert([
            'name'       => 'WS NodeTest ' . uniqid(),
            'slug'       => 'nt-' . uniqid(),
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

        $this->withSession([
            'user_id'      => $uid,
            'user_role'    => 'owner',
            'user_name'    => 'Node Tester',
            'user_email'   => $email,
            'workspace_id' => $ws,
        ]);

        return ['user_id' => $uid, 'workspace_id' => $ws, 'email' => $email];
    }

    private function seedWorkflow(int $wsId): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => $wsId,
            'name'         => 'WF Test Node ' . uniqid(),
            'status'       => 'active',
            'active'       => 1,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();
        $this->cleanup[] = $wf;

        return $wf;
    }

    private function postTest(array $payload): \CodeIgniter\Test\TestResponse
    {
        $csrf  = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $token = $this->extractToken($csrf->getBody());
        $_COOKIE['csrf_cookie_name'] = $token;

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $token,
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode($payload))->post('api/nodes/test');

        unset($_COOKIE['csrf_cookie_name']);

        return $res;
    }

    /**
     * Ambil envelope {success,message,data} dari body respons.
     * (Body feature-test kadang terbungkus HTML doctype, jadi diekstrak manual.)
     */
    private function dataOf(\CodeIgniter\Test\TestResponse $res): array
    {
        $body = $res->getBody();
        $start = strpos($body, '{');
        $end   = strrpos($body, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $decoded = json_decode(substr($body, $start, $end - $start + 1), true);

        return is_array($decoded) ? ($decoded['data'] ?? []) : [];
    }

    public function testSetNodeSucceedsWithSampleData(): void
    {
        $seed = $this->seedSession();

        $res = $this->postTest([
            'node_type'  => 'set',
            'parameters' => [
                'assignments'  => json_encode([['field' => 'status', 'value' => 'aktif']]),
                'removeFields' => json_encode([]),
            ],
            'sample_data' => ['email' => 'riski@example.com'],
        ]);

        $this->assertSame(200, $res->response()->getStatusCode(), $res->getBody());

        $data = $this->dataOf($res);
        $this->assertTrue($data['ok'] ?? false, 'ok harus true: ' . json_encode($data));

        $items = $data['output']['main'][0]['json'] ?? null;
        $this->assertNotNull($items);
        $this->assertSame('aktif', $items['status']);
        $this->assertSame('riski@example.com', $items['email']);
    }

    public function testFilterNodeRoutesMatchedItems(): void
    {
        $seed = $this->seedSession();

        $res = $this->postTest([
            'node_type'  => 'filter',
            'parameters' => [
                'conditions' => json_encode([[
                    'left' => '{{$json.stok}}', 'operator' => '>', 'right' => 0,
                ]]),
            ],
            'sample_data' => ['produk' => 'Laptop', 'stok' => 5],
        ]);

        $this->assertSame(200, $res->response()->getStatusCode());

        $data = $this->dataOf($res);
        $this->assertTrue($data['ok'] ?? false, json_encode($data));
        $this->assertCount(1, $data['output']['main'] ?? []);
    }

    public function testUnknownNodeTypeReturnsOkFalse(): void
    {
        $seed = $this->seedSession();

        $res = $this->postTest([
            'node_type'  => 'node_tidak_ada_999',
            'parameters' => [],
        ]);

        $this->assertSame(200, $res->response()->getStatusCode());
        $data = $this->dataOf($res);
        $this->assertFalse($data['ok'] ?? true);
        $this->assertStringContainsString('tidak terdaftar', $data['error'] ?? '');
    }

    public function testTriggerNodeIsRejected(): void
    {
        $seed = $this->seedSession();

        $res = $this->postTest([
            'node_type'  => 'schedule_trigger',
            'parameters' => ['cron' => '* * * * *'],
        ]);

        $this->assertSame(200, $res->response()->getStatusCode());
        $data = $this->dataOf($res);
        $this->assertFalse($data['ok'] ?? true);
        $this->assertStringContainsStringIgnoringCase('trigger', $data['error'] ?? '');
    }

    public function testFailingNodeReturnsOkFalseWithErrorMessage(): void
    {
        $seed = $this->seedSession();

        // HTTP request ke host/port yang pasti gagal (port tertutup).
        $res = $this->postTest([
            'node_type'  => 'http_request',
            'parameters' => [
                'method'  => 'GET',
                'url'     => 'http://127.0.0.1:59999/halus-tidak-ada',
                'timeout' => 2,
                'onError' => 'fail',
            ],
            // HTTP node beriterasi per item input; tanpa sample data curl tidak pernah jalan.
            'sample_data' => ['ping' => 1],
        ]);

        $this->assertSame(200, $res->response()->getStatusCode());
        $data = $this->dataOf($res);
        $this->assertFalse($data['ok'] ?? true, 'Harusnya gagal: ' . json_encode($data));
        $this->assertNotSame('', $data['error'] ?? '');
    }

    public function testNonMemberOfWorkflowGets403(): void
    {
        // Workflow milik user A
        $ownerA = $this->seedSession();
        $wfA    = $this->seedWorkflow($ownerA['workspace_id']);

        // User B dengan workspace sendiri (bukan anggota workspace A)
        $emailB = 'intruder_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'Intruder', 'email' => $emailB,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'member',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uidB = (int) $this->db->insertID();
        $this->cleanupUsers[] = $uidB;

        $this->db->table('workspaces')->insert([
            'name' => 'WS B ' . uniqid(), 'slug' => 'wsb-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $wsB = (int) $this->db->insertID();
        $this->cleanup[] = $wsB;

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $wsB, 'user_id' => $uidB, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->withSession([
            'user_id' => $uidB, 'user_role' => 'owner', 'user_email' => $emailB,
            'workspace_id' => $wsB,
        ]);

        $res = $this->postTest([
            'node_type'   => 'set',
            'parameters'  => [],
            'workflow_id' => $wfA,
        ]);

        $this->assertSame(
            403,
            $res->response()->getStatusCode(),
            'User B tidak boleh test-node di workflow A'
        );
    }

    public function testMissingNodeTypeReturnsOkFalse(): void
    {
        $seed = $this->seedSession();

        $res = $this->postTest(['parameters' => []]);

        $this->assertSame(200, $res->response()->getStatusCode());
        $data = $this->dataOf($res);
        $this->assertFalse($data['ok'] ?? true);
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
