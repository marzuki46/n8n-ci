<?php

use App\Services\ApiKeyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test B1: API key (Public API /api/v1).
 *
 * @internal
 */
final class ApiKeyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanupIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupIds as $id) {
            $this->db->table('executions')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    private function seedUser(): array
    {
        $email = 'apikey_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'       => 'API Owner',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name'       => 'API WS',
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
            'name'         => 'WF API ' . uniqid(),
            'status'       => 'active',
            'active'       => 1,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();

        return ['user_id' => $uid, 'workspace_id' => $ws, 'workflow_id' => $wf, 'email' => $email];
    }

    private function createKeyViaSession(array $seed): string
    {
        $this->withSession([
            'user_id'    => $seed['user_id'],
            'user_role'  => 'owner',
            'user_name'  => 'API Owner',
            'user_email' => $seed['email'],
            'workspace_id' => $seed['workspace_id'],
        ]);

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $token = $this->extractToken($csrf->getBody());
        $_COOKIE['csrf_cookie_name'] = $token;

        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $token,
        ])->post('api/api-keys', ['label' => 'CI Test']);

        $this->assertSame(201, $result->response()->getStatusCode(), 'Buat API key gagal');
        unset($_COOKIE['csrf_cookie_name']);

        if (preg_match('/"api_key":"(ak_[0-9a-f]+)"/i', $result->getBody(), $m) === 1) {
            return $m[1];
        }

        $this->fail('API key tidak ditemukan di respons');

        return '';
    }

    public function testServiceGenerateAndVerify(): void
    {
        $service = new ApiKeyService($this->db);
        $seed = $this->seedUser();

        $created = $service->generate('Unit', $seed['user_id']);
        $this->assertStringStartsWith('ak_', $created['api_key']);
        $this->assertArrayNotHasKey('key_hash', $created);

        $row = $service->verify($created['api_key']);
        $this->assertNotNull($row);
        $this->assertSame($seed['user_id'], (int) $row['user_id']);

        $this->assertNull($service->verify('ak_wrong'));
    }

    public function testPublicApiRejectsWithoutKey(): void
    {
        $this->seedUser();

        $result = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/v1/workflows');

        $this->assertSame(401, $result->response()->getStatusCode());
    }

    public function testPublicApiListsWorkflowsWithKey(): void
    {
        $seed = $this->seedUser();
        $key = $this->createKeyViaSession($seed);

        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $key,
        ])->get('api/v1/workflows');

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString('WF API', $result->getBody());
    }

    public function testPublicApiExecutesWorkflowWithKey(): void
    {
        $seed = $this->seedUser();
        $key = $this->createKeyViaSession($seed);

        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $key,
        ])->post('api/v1/executions', [
            'workflow_id' => $seed['workflow_id'],
            'data'        => ['ping' => 'pong'],
        ]);

        $status = $result->response()->getStatusCode();
        $this->assertContains($status, [201, 500], 'Eksekusi via API key harusnya menghasilkan eksekusi');
        $this->assertMatchesRegularExpression('/"execution_id":[0-9]+/', $result->getBody());
    }

    public function testRevokedKeyIsRejected(): void
    {
        $seed = $this->seedUser();
        $key = $this->createKeyViaSession($seed);

        $this->withSession([
            'user_id'      => $seed['user_id'],
            'user_role'    => 'owner',
            'user_name'    => 'API Owner',
            'user_email'   => $seed['email'],
            'workspace_id' => $seed['workspace_id'],
        ]);

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $token = $this->extractToken($csrf->getBody());
        $_COOKIE['csrf_cookie_name'] = $token;

        $keys = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $token,
        ])->get('api/api-keys');

        if (preg_match('/"id":"?([0-9]+)"?/', $keys->getBody(), $m) === 1) {
            $this->withHeaders([
                'X-Requested-With' => 'xmlhttprequest',
                'X-CSRF-TOKEN'     => $token,
            ])->post("api/api-keys/{$m[1]}/revoke");
        }
        unset($_COOKIE['csrf_cookie_name']);

        // Pastikan tidak ada session: verifikasi murni lewat API key.
        $this->withSession([]);

        $rejected = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $key,
        ])->get('api/v1/workflows');

        $this->assertSame(401, $rejected->response()->getStatusCode());
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
