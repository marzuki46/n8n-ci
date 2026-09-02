<?php

use App\Services\CredentialService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Paket 1 — Default credential per proyek.
 * Service findDefault/setDefault + endpoint toggle is_default.
 *
 * @internal
 */
final class CredentialDefaultTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanupCredIds = [];

    private ?CredentialService $service = null;

    protected function tearDown(): void
    {
        foreach ($this->cleanupCredIds as $id) {
            $this->db->table('credentials')->where('id', $id)->delete();
        }
        $this->cleanupCredIds = [];
        $this->service = null;

        parent::tearDown();
    }

    private function svc(): CredentialService
    {
        if ($this->service === null) {
            $this->service = new CredentialService();
        }

        return $this->service;
    }

    /**
     * Seed user + workspace owner, kembalikan id-nya.
     */
    private function seedWorkspace(): array
    {
        $email = 'creddef_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'       => 'Cred Owner',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name'       => 'WS CredDef ' . uniqid(),
            'slug'       => 'cd-' . uniqid(),
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

    /**
     * Pastikan tipe credential 'openai' ada di DB test, kembalikan id-nya.
     */
    private function typeIdOpenAi(): int
    {
        $row = $this->db->table('credential_types')->where('slug', 'openai')->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('credential_types')->insert([
            'name'        => 'OpenAI',
            'slug'        => 'openai',
            'schema_json' => '{"api_key":"password"}',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeCredential(array $ws, int $typeId, string $name, int $isDefault = 0): int
    {
        $data = [
            'user_id'            => $ws['user_id'],
            'workspace_id'       => $ws['workspace_id'],
            'credential_type_id' => $typeId,
            'name'               => $name . ' ' . uniqid(),
            'data'               => $this->svc()->encryptData(['api_key' => 'sk-test']),
            'status'             => 'active',
            'is_default'         => $isDefault,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ];
        $this->db->table('credentials')->insert($data);
        $id = (int) $this->db->insertID();
        $this->cleanupCredIds[] = $id;

        return $id;
    }

    // =========================================================================
    // Service: findDefault / setDefault
    // =========================================================================

    public function testFindDefaultReturnsNullWhenNoneMarked(): void
    {
        $ws     = $this->seedWorkspace();
        $typeId = $this->typeIdOpenAi();
        $this->makeCredential($ws, $typeId, 'Bukan Default');

        $this->assertNull($this->svc()->findDefault($ws['workspace_id'], $typeId));
    }

    public function testFindDefaultReturnsRowWithDecryptedData(): void
    {
        $ws     = $this->seedWorkspace();
        $typeId = $this->typeIdOpenAi();
        $id     = $this->makeCredential($ws, $typeId, 'Default Saya', 1);

        $found = $this->svc()->findDefault($ws['workspace_id'], $typeId);

        $this->assertNotNull($found);
        $this->assertSame($id, (int) $found['id']);
        $this->assertSame(['api_key' => 'sk-test'], $found['data']);
    }

    public function testFindDefaultScopedToTypeAndWorkspace(): void
    {
        $ws      = $this->seedWorkspace();
        $openai  = $this->typeIdOpenAi();

        // Default tipe lain (github) tidak boleh kebaca untuk tipe openai.
        $ghRow = $this->db->table('credential_types')->where('slug', 'github')->get()->getRowArray();
        if (! $ghRow) {
            $now = date('Y-m-d H:i:s');
            $this->db->table('credential_types')->insert([
                'name' => 'GitHub', 'slug' => 'github', 'schema_json' => '{}',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $gh = (int) $this->db->insertID();
        } else {
            $gh = (int) $ghRow['id'];
        }
        $this->makeCredential($ws, $gh, 'Default GitHub', 1);

        $this->assertNull($this->svc()->findDefault($ws['workspace_id'], $openai));
    }

    public function testSetDefaultResetsOtherCredentialsSameType(): void
    {
        $ws     = $this->seedWorkspace();
        $typeId = $this->typeIdOpenAi();
        $a      = $this->makeCredential($ws, $typeId, 'A');
        $b      = $this->makeCredential($ws, $typeId, 'B');

        $this->assertTrue($this->svc()->setDefault($a, true));

        $this->assertSame(1, (int) $this->db->table('credentials')->where('id', $a)->get()->getRowArray()['is_default']);
        $this->assertSame(0, (int) $this->db->table('credentials')->where('id', $b)->get()->getRowArray()['is_default']);

        // Pindah default ke B → A otomatis di-reset.
        $this->assertTrue($this->svc()->setDefault($b, true));
        $this->assertSame(0, (int) $this->db->table('credentials')->where('id', $a)->get()->getRowArray()['is_default']);
        $this->assertSame($b, (int) $this->svc()->findDefault($ws['workspace_id'], $typeId)['id']);
    }

    public function testUnsetDefaultLeavesNoDefault(): void
    {
        $ws     = $this->seedWorkspace();
        $typeId = $this->typeIdOpenAi();
        $a      = $this->makeCredential($ws, $typeId, 'A');

        $this->svc()->setDefault($a, true);
        $this->svc()->setDefault($a, false);

        $this->assertNull($this->svc()->findDefault($ws['workspace_id'], $typeId));
    }

    public function testSetDefaultReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->svc()->setDefault(99999999, true));
    }

    // =========================================================================
    // API: listForApi & toggle via endpoint
    // =========================================================================

    public function testListForApiExposesIsDefault(): void
    {
        $ws     = $this->seedWorkspace();
        $typeId = $this->typeIdOpenAi();
        $a      = $this->makeCredential($ws, $typeId, 'API List A');
        $this->svc()->setDefault($a, true);

        $rows = $this->svc()->listForApi($ws['workspace_id']);
        $mine = array_values(array_filter($rows, static fn ($r) => (int) $r['id'] === $a));

        $this->assertCount(1, $mine);
        $this->assertSame(1, (int) $mine[0]['is_default']);
        $this->assertArrayNotHasKey('data', $mine[0], 'Data rahasia tidak boleh bocor di list');
    }

    public function testEndpointUpdateTogglesIsDefault(): void
    {
        $ws     = $this->seedWorkspace();
        $typeId = $this->typeIdOpenAi();
        $a      = $this->makeCredential($ws, $typeId, 'Endpoint Toggle');
        $b      = $this->makeCredential($ws, $typeId, 'Endpoint Lain');

        $this->withSession([
            'user_id'      => $ws['user_id'],
            'user_role'    => 'owner',
            'user_name'    => 'Cred Owner',
            'user_email'   => $ws['email'],
            'workspace_id' => $ws['workspace_id'],
        ]);

        $csrf   = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $token  = $this->extractToken($csrf->getBody());
        $_COOKIE['csrf_cookie_name'] = $token;

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $token,
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['is_default' => true]))->put("api/credentials/{$a}");

        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(
            200,
            $res->response()->getStatusCode(),
            'Toggle default harus sukses: ' . $res->getBody()
        );
        $this->assertSame($a, (int) $this->svc()->findDefault($ws['workspace_id'], $typeId)['id']);

        // b tetap bukan default
        $rowB = $this->db->table('credentials')->where('id', $b)->get()->getRowArray();
        $this->assertSame(0, (int) $rowB['is_default']);
    }

    public function testEndpointIndexIncludesIsDefaultField(): void
    {
        $ws     = $this->seedWorkspace();
        $typeId = $this->typeIdOpenAi();
        $a      = $this->makeCredential($ws, $typeId, 'Index Field');
        $this->svc()->setDefault($a, true);

        $this->withSession([
            'user_id'      => $ws['user_id'],
            'user_role'    => 'owner',
            'user_email'   => $ws['email'],
            'workspace_id' => $ws['workspace_id'],
        ]);

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/credentials');
        $this->assertSame(200, $res->response()->getStatusCode());

        $body = $res->getBody();
        $this->assertStringContainsString('is_default', $body);
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
