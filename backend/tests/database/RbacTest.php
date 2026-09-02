<?php

use App\Services\RbacService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test C3: RBAC berbasis peran workspace + sharing anggota.
 *
 * @internal
 */
final class RbacTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $table => $ids) {
            if (is_int($table)) {
                continue;
            }
            if (! empty($ids)) {
                $this->db->table($table)->whereIn('id', $ids)->delete();
            }
        }

        parent::tearDown();
    }

    private function makeUser(string $email, string $role = 'member'): int
    {
        $this->db->table('users')->insert([
            'name'       => 'User ' . uniqid(),
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => $role,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function makeWorkspace(): int
    {
        $this->db->table('workspaces')->insert([
            'name'       => 'WS ' . uniqid(),
            'slug'       => 'ws-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        $this->cleanup['workspaces'][] = $id;

        return $id;
    }

    private function addMember(int $workspaceId, int $userId, string $role): void
    {
        $this->db->table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id'      => $userId,
            'role'         => $role,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function makeWorkflow(int $workspaceId): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => $workspaceId,
            'name'         => 'WF ' . uniqid(),
            'status'       => 'active',
            'active'       => 1,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        $this->cleanup['workflows'][] = $id;

        return $id;
    }

    private function login(int $userId, int $workspaceId): void
    {
        $this->withSession([
            'user_id'      => $userId,
            'user_name'    => 'User',
            'user_email'   => 'u' . $userId . '@local.dev',
            'user_role'    => 'member',
            'workspace_id' => $workspaceId,
        ]);
    }

    private function csrfHeaders(): array
    {
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $token = $this->extractToken($csrf->getBody());
        $_COOKIE['csrf_cookie_name'] = $token;

        return [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $token,
        ];
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    // -----------------------------------------------------------------
    // RbacService unit
    // -----------------------------------------------------------------

    public function testRoleMatrix(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner@local.dev', 'owner');
        $admin = $this->makeUser('admin@local.dev', 'admin');
        $member = $this->makeUser('member@local.dev', 'member');

        $this->addMember($ws, $owner, 'owner');
        $this->addMember($ws, $admin, 'admin');
        $this->addMember($ws, $member, 'member');

        $rbac = new RbacService($this->db);

        $this->assertTrue($rbac->can('workflows:write', $owner, $ws));
        $this->assertTrue($rbac->can('workflows:write', $admin, $ws));
        $this->assertFalse($rbac->can('workflows:write', $member, $ws));

        $this->assertTrue($rbac->can('workflows:read', $member, $ws));
        $this->assertTrue($rbac->can('workflows:execute', $member, $ws));
        $this->assertFalse($rbac->can('workflows:delete', $member, $ws));

        $this->assertTrue($rbac->can('members:manage', $owner, $ws));
        $this->assertFalse($rbac->can('members:manage', $admin, $ws));

        $this->assertTrue($rbac->can('workspaces:delete', $owner, $ws));
        $this->assertFalse($rbac->can('workspaces:delete', $admin, $ws));
        $this->assertFalse($rbac->can('workspaces:delete', $member, $ws));

        // User di luar workspace -> tidak bisa apa pun
        $outsider = $this->makeUser('outsider@local.dev', 'owner');
        $this->assertFalse($rbac->can('workflows:read', $outsider, $ws));
    }

    // -----------------------------------------------------------------
    // Endpoint enforcement
    // -----------------------------------------------------------------

    public function testMemberCannotCreateWorkflow(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner2@local.dev', 'owner');
        $member = $this->makeUser('member2@local.dev', 'member');
        $this->addMember($ws, $owner, 'owner');
        $this->addMember($ws, $member, 'member');

        $this->login($member, $ws);
        $result = $this->withHeaders($this->csrfHeaders())
            ->withBodyFormat('json')->post('api/workflows', ['name' => 'dilarang']);

        $this->assertSame(403, $result->response()->getStatusCode());
    }

    public function testMemberCannotDeleteOrSaveWorkflow(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner3@local.dev', 'owner');
        $member = $this->makeUser('member3@local.dev', 'member');
        $this->addMember($ws, $owner, 'owner');
        $this->addMember($ws, $member, 'member');
        $wf = $this->makeWorkflow($ws);

        $this->login($member, $ws);
        $delete = $this->withHeaders($this->csrfHeaders())
            ->delete("api/workflows/{$wf}");
        $this->assertSame(403, $delete->response()->getStatusCode());

        $save = $this->withHeaders($this->csrfHeaders())
            ->withBodyFormat('json')->post("api/workflows/{$wf}/save", ['name' => 'x', 'nodes' => [], 'connections' => []]);
        $this->assertSame(403, $save->response()->getStatusCode());
    }

    public function testMemberCanReadAndExecute(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner4@local.dev', 'owner');
        $member = $this->makeUser('member4@local.dev', 'member');
        $this->addMember($ws, $owner, 'owner');
        $this->addMember($ws, $member, 'member');
        $wf = $this->makeWorkflow($ws);

        $this->login($member, $ws);
        $show = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get("api/workflows/{$wf}");
        $this->assertSame(200, $show->response()->getStatusCode());

        $execute = $this->withHeaders($this->csrfHeaders())
            ->withBodyFormat('json')->post("api/workflows/{$wf}/execute", ['queued' => true]);
        $this->assertSame(202, $execute->response()->getStatusCode());
    }

    public function testMemberCannotCreateApiKey(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner5@local.dev', 'owner');
        $member = $this->makeUser('member5@local.dev', 'member');
        $this->addMember($ws, $owner, 'owner');
        $this->addMember($ws, $member, 'member');

        $this->login($member, $ws);
        $result = $this->withHeaders($this->csrfHeaders())
            ->withBodyFormat('json')->post('api/api-keys', ['label' => 'x']);

        $this->assertSame(403, $result->response()->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Sharing (member management) — owner only
    // -----------------------------------------------------------------

    public function testOwnerCanAddAndRemoveMember(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner6@local.dev', 'owner');
        $newbie = $this->makeUser('newbie6@local.dev', 'member');
        $this->addMember($ws, $owner, 'owner');

        $this->login($owner, $ws);

        $add = $this->withHeaders($this->csrfHeaders())
            ->withBodyFormat('json')->post("api/projects/{$ws}/members", [
                'email' => 'newbie6@local.dev',
                'role'  => 'member',
            ]);
        $this->assertSame(201, $add->response()->getStatusCode());

        $list = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get("api/projects/{$ws}/members");
        $this->assertSame(200, $list->response()->getStatusCode());
        $this->assertStringContainsString('newbie6@local.dev', $list->getBody());

        $del = $this->withHeaders($this->csrfHeaders())
            ->delete("api/projects/{$ws}/members/{$newbie}");
        $this->assertSame(200, $del->response()->getStatusCode());
    }

    public function testAdminCannotManageMembers(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner7@local.dev', 'owner');
        $admin = $this->makeUser('admin7@local.dev', 'admin');
        $this->addMember($ws, $owner, 'owner');
        $this->addMember($ws, $admin, 'admin');

        $this->login($admin, $ws);
        $result = $this->withHeaders($this->csrfHeaders())
            ->post("api/projects/{$ws}/members", ['email' => 'x@y.dev', 'role' => 'member']);

        $this->assertSame(403, $result->response()->getStatusCode());
    }

    public function testMemberCannotSeeOtherWorkspaceData(): void
    {
        $wsA = $this->makeWorkspace();
        $wsB = $this->makeWorkspace();
        $memberA = $this->makeUser('membera@local.dev', 'member');
        $this->addMember($wsA, $memberA, 'member');
        $wfB = $this->makeWorkflow($wsB);

        $this->login($memberA, $wsA);
        $show = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get("api/workflows/{$wfB}");

        $this->assertSame(403, $show->response()->getStatusCode());
    }

    public function testCannotDemoteLastOwner(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner8@local.dev', 'owner');
        $this->addMember($ws, $owner, 'owner');

        $this->login($owner, $ws);
        $result = $this->withHeaders($this->csrfHeaders())
            ->withBodyFormat('json')->put("api/projects/{$ws}/members/{$owner}", ['role' => 'member']);

        $this->assertSame(422, $result->response()->getStatusCode());
    }
}
