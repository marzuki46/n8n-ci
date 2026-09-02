<?php

use App\Services\ApiKeyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * API key per-proyek: workspace_id opsional, validasi keanggotaan,
 * list menampilkan nama proyek.
 *
 * @internal
 */
final class ApiKeyWorkspaceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanupKeys = [];

    private array $ownerA = [];

    private array $wsB = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupKeys as $id) {
            $this->db->table('api_keys')->where('id', $id)->delete();
        }
        if (! empty($this->wsB['workspace_id'])) {
            $this->db->table('workspace_users')->where('workspace_id', $this->wsB['workspace_id'])->delete();
            $this->db->table('workspaces')->where('id', $this->wsB['workspace_id'])->delete();
        }
        if (! empty($this->ownerA['user_id'])) {
            $this->db->table('users')->where('id', $this->ownerA['user_id'])->delete();
        }
        $this->cleanupKeys = [];
        $this->ownerA = [];
        $this->wsB = [];

        parent::tearDown();
    }

    /**
     * Owner A dengan 2 proyek (WS utama dari seedSession + WS B tambahan).
     */
    private function seedOwner(): array
    {
        if ($this->ownerA !== []) {
            return $this->ownerA;
        }

        $email = 'akws_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'AK WS Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name' => 'WS Utama', 'slug' => 'akw-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws1 = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name' => 'WS Seo B ' . uniqid(), 'slug' => 'aks-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws2 = (int) $this->db->insertID();
        $this->wsB = ['workspace_id' => $ws2];

        foreach ([[$ws1, 'owner'], [$ws2, 'owner']] as [$wid, $role]) {
            $this->db->table('workspace_users')->insert([
                'workspace_id' => $wid, 'user_id' => $uid, 'role' => $role,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->ownerA = ['user_id' => $uid, 'email' => $email, 'ws1' => $ws1, 'ws2' => $ws2];

        return $this->ownerA;
    }

    public function testGenerateWithWorkspaceBinding(): void
    {
        $o   = $this->seedOwner();
        $svc = new ApiKeyService($this->db);

        $key = $svc->generate('Key SEO', $o['user_id'], null, $o['ws2']);

        $this->cleanupKeys[] = $key['id'];
        $this->assertSame($o['ws2'], (int) $key['workspace_id']);

        // List harus menampilkan nama workspace.
        $list = $svc->listForUser($o['user_id']);
        $mine = array_values(array_filter($list, fn ($r) => (int) $r['id'] === $key['id']));
        $this->assertCount(1, $mine);
        $this->assertStringContainsString('WS Seo B', (string) $mine[0]['workspace_name']);
    }

    public function testGenerateWithoutWorkspaceStaysGlobal(): void
    {
        $o   = $this->seedOwner();
        $svc = new ApiKeyService($this->db);

        $key = $svc->generate('Global Key', $o['user_id']);
        $this->cleanupKeys[] = $key['id'];

        $row = $this->db->table('api_keys')->where('id', $key['id'])->get()->getRowArray();
        $this->assertNull($row['workspace_id']);
    }

    public function testCreateEndpointRejectsNonMemberWorkspace(): void
    {
        $o = $this->seedOwner();

        // Workspace milik orang lain (tanpa keanggotaan).
        $this->db->table('workspaces')->insert([
            'name' => 'WS Orang Lain', 'slug' => 'oth-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $foreign = (int) $this->db->insertID();

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $o['user_id'], 'user_role' => 'owner'])
            ->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['label' => 'X', 'workspace_id' => $foreign]))->post('api/api-keys');

        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(403, $res->response()->getStatusCode(), $res->getBody());
    }
}
