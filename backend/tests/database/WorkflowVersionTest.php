<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test versi workflow: snapshot saat save + daftar versi + restore.
 *
 * @internal
 */
final class WorkflowVersionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    private function seed(): array
    {
        $this->db->table('users')->insert([
            'name'       => 'WV User',
            'email'      => 'wv_' . uniqid() . '@local.dev',
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name'       => 'WV WS',
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

        $this->db->table('workflows')->insert([
            'workspace_id' => $ws,
            'name'         => 'WF WV ' . uniqid(),
            'status'       => 'draft',
            'active'       => 0,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();

        $this->withSession([
            'user_id'      => $uid,
            'user_role'    => 'owner',
            'user_name'    => 'WV User',
            'user_email'   => 'wv@local.dev',
            'workspace_id' => $ws,
        ]);

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $_COOKIE['csrf_cookie_name'] = $this->extractToken($csrf->getBody());

        return ['user_id' => $uid, 'workspace_id' => $ws, 'workflow_id' => $wf];
    }

    private function headers(): array
    {
        return [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $_COOKIE['csrf_cookie_name'] ?? '',
        ];
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private function jsonBody(string $body): array
    {
        if (preg_match('/\{.*\}/s', $body, $m) !== 1) {
            $this->fail('Respons bukan JSON: ' . $body);
        }

        $data = json_decode($m[0], true);
        $this->assertIsArray($data);

        return $data;
    }

    private function nodes(string $name): array
    {
        return [[
            'id'       => 'n_' . uniqid(),
            'type'     => 'manual_trigger',
            'name'     => $name,
            'position' => ['x' => 0, 'y' => 0],
            'data'     => ['parameters' => []],
        ]];
    }

    private function save(int $wf, array $nodes): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders($this->headers())
            ->withBody(json_encode([
                'name'        => 'WF WV',
                'nodes'       => $nodes,
                'connections' => [],
                'active'      => false,
            ]))
            ->post("api/workflows/{$wf}/save");
    }

    public function testSaveCreatesVersionSnapshot(): void
    {
        $seed = $this->seed();
        $nodeA = $this->nodes('Versi A')[0];

        $resp = $this->save($seed['workflow_id'], [$nodeA]);
        $this->assertSame(200, $resp->response()->getStatusCode());

        $wf = $this->db->table('workflows')->where('id', $seed['workflow_id'])->get()->getRowArray();
        $this->assertSame(2, (int) $wf['version']);

        $snap = $this->db->table('workflow_versions')->where('workflow_id', $seed['workflow_id'])->get()->getResultArray();
        $this->assertCount(1, $snap);
        $this->assertSame(2, (int) $snap[0]['version']);

        $nodes = json_decode($snap[0]['nodes_json'], true);
        $this->assertSame($nodeA['id'], $nodes[0]['id']);
        $this->assertSame($nodeA['name'], $nodes[0]['name']);
    }

    public function testVersionsEndpointListsSnapshots(): void
    {
        $seed = $this->seed();
        $this->save($seed['workflow_id'], [$this->nodes('V1')[0]]);
        $this->save($seed['workflow_id'], [$this->nodes('V2')[0]]);

        $resp = $this->withHeaders($this->headers())->get("api/workflows/{$seed['workflow_id']}/versions");
        $this->assertSame(200, $resp->response()->getStatusCode());

        $json = $this->jsonBody($resp->getBody());
        $this->assertSame(3, (int) $json['data']['current_version']);
        $this->assertCount(2, $json['data']['versions']);
        $this->assertSame(3, (int) $json['data']['versions'][0]['version']);
        $this->assertSame(2, (int) $json['data']['versions'][1]['version']);
    }

    public function testRestoreRollsBackGraphAndBumpsVersion(): void
    {
        $seed = $this->seed();

        $nodeA = $this->nodes('Node Awal')[0];
        $nodeB = $this->nodes('Node Baru')[0];

        $this->save($seed['workflow_id'], [$nodeA]);
        $this->save($seed['workflow_id'], [$nodeA, $nodeB]);

        // Saat ini versi 3 (2 node). Restore ke versi 2 (1 node).
        $resp = $this->withHeaders($this->headers())
            ->post("api/workflows/{$seed['workflow_id']}/versions/2/restore");
        $this->assertSame(200, $resp->response()->getStatusCode());

        $wf = $this->db->table('workflows')->where('id', $seed['workflow_id'])->get()->getRowArray();
        $this->assertSame(4, (int) $wf['version']);

        $rows = $this->db->table('workflow_nodes')->where('workflow_id', $seed['workflow_id'])->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($nodeA['id'], $rows[0]['node_id']);
        $this->assertSame('Node Awal', $rows[0]['name']);

        // Snapshot baru tercatat (append-only).
        $snaps = $this->db->table('workflow_versions')
            ->where('workflow_id', $seed['workflow_id'])
            ->orderBy('version', 'DESC')
            ->get()
            ->getResultArray();
        $this->assertCount(3, $snaps);
        $this->assertSame(4, (int) $snaps[0]['version']);
    }

    public function testRestoreNotFoundVersionReturns404(): void
    {
        $seed = $this->seed();
        $this->save($seed['workflow_id'], [$this->nodes('V1')[0]]);

        $resp = $this->withHeaders($this->headers())
            ->post("api/workflows/{$seed['workflow_id']}/versions/99/restore");
        $this->assertSame(404, $resp->response()->getStatusCode());
    }

    public function testVersionsRequiresPermission(): void
    {
        $this->db->table('users')->insert([
            'name'       => 'WV Member',
            'email'      => 'wv_m_' . uniqid() . '@local.dev',
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name'       => 'WV WS2',
            'slug'       => 'ws-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        // Member bukan anggota workspace -> tidak punya akses.
        $this->db->table('workflows')->insert([
            'workspace_id' => $ws,
            'name'         => 'WF Private',
            'status'       => 'draft',
            'active'       => 0,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();

        $this->withSession([
            'user_id'      => $uid,
            'user_role'    => 'member',
            'user_name'    => 'WV Member',
            'user_email'   => 'wv_m@local.dev',
            'workspace_id' => $ws,
        ]);

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $_COOKIE['csrf_cookie_name'] = $this->extractToken($csrf->getBody());

        $resp = $this->withHeaders($this->headers())->get("api/workflows/{$wf}/versions");
        $this->assertSame(403, $resp->response()->getStatusCode());

        $restore = $this->withHeaders($this->headers())
            ->post("api/workflows/{$wf}/versions/1/restore");
        $this->assertSame(403, $restore->response()->getStatusCode());
    }
}
