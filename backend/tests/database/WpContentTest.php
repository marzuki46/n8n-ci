<?php

use App\Services\ApiKeyService;
use App\Services\CredentialService;
use App\Services\WpContentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Stub service: menangkap payload LLM tanpa HTTP sungguhan.
 */
final class WpContentStubService extends WpContentService
{
    public array $captured = [];

    public function httpPostJson(string $url, array $payload, string $apiKey): string
    {
        $this->captured[] = ['url' => $url, 'payload' => $payload, 'api_key' => $apiKey];

        return json_encode([
            'choices' => [[
                'message' => ['content' => '<p>Ini konten hasil AI yang cukup panjang untuk dihitung katanya.</p>'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'openai/gpt-4o-mini',
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 120, 'total_tokens' => 170],
        ]);
    }
}

/**
 * Paket 3 — Content AI plugin WordPress.
 * Service (generate/continue dengan stub HTTP) + endpoint /api/v1/wp/*.
 *
 * @internal
 */
final class WpContentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanupCredIds = [];

    /** @var list<int> */
    private array $cleanupKeyIds = [];

    /** @var list<int> */
    private array $cleanupUsers = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupCredIds as $id) {
            $this->db->table('credentials')->where('id', $id)->delete();
        }
        foreach ($this->cleanupKeyIds as $id) {
            $this->db->table('api_keys')->where('id', $id)->delete();
        }
        foreach ($this->cleanupUsers as $uid) {
            $this->db->table('workspace_users')->where('user_id', $uid)->delete();
            $this->db->table('users')->where('id', $uid)->delete();
        }
        $this->cleanupCredIds = [];
        $this->cleanupKeyIds = [];
        $this->cleanupUsers = [];

        parent::tearDown();
    }

    private function typeId(string $slug): ?int
    {
        $row = $this->db->table('credential_types')->where('slug', $slug)->get()->getRowArray();

        return $row ? (int) $row['id'] : null;
    }

    /**
     * Seed user+workspace+credential AI default. Kembalikan konteks lengkap.
     */
    private function seedWorkspaceWithAi(bool $withAi = true): array
    {
        $email = 'wp_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'       => 'WP Owner',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();
        $this->cleanupUsers[] = $uid;

        $this->db->table('workspaces')->insert([
            'name'       => 'WS WP ' . uniqid(),
            'slug'       => 'wp-' . uniqid(),
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

        if ($withAi) {
            $typeId = $this->typeId('openai');
            if (! $typeId) {
                $now = date('Y-m-d H:i:s');
                $this->db->table('credential_types')->insert([
                    'name' => 'OpenAI', 'slug' => 'openai', 'schema_json' => '{}',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $typeId = (int) $this->db->insertID();
            }

            $svc = new CredentialService();
            $this->db->table('credentials')->insert([
                'user_id'            => $uid,
                'workspace_id'       => $ws,
                'credential_type_id' => $typeId,
                'name'               => 'AI Default ' . uniqid(),
                'data'               => $svc->encryptData(['api_key' => 'sk-test-wp', 'base_url' => 'https://ai.example.com/v1']),
                'status'             => 'active',
                'is_default'         => 1,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
            $this->cleanupCredIds[] = (int) $this->db->insertID();
        }

        return ['user_id' => $uid, 'workspace_id' => $ws, 'email' => $email];
    }

    private function makeApiKey(array $ws): string
    {
        $service = new ApiKeyService($this->db);
        $created = $service->generate('WP Plugin', $ws['user_id']);
        if (! empty($created['id'])) {
            $this->cleanupKeyIds[] = (int) $created['id'];
        } else {
            // fallback: cari id dari hash
            $row = $this->db->table('api_keys')
                ->where('user_id', $ws['user_id'])->orderBy('id', 'DESC')->get()->getRowArray();
            if ($row) {
                $this->cleanupKeyIds[] = (int) $row['id'];
            }
        }

        return $created['api_key'];
    }

    // =========================================================================
    // Service level (stub HTTP)
    // =========================================================================

    public function testGenerateBuildsPromptAndReturnsContent(): void
    {
        $ws     = $this->seedWorkspaceWithAi(true);
        $svc    = new WpContentStubService();
        $credSvc = new CredentialService();
        $cred   = $svc->findAiCredential($ws['workspace_id']);

        $this->assertNotNull($cred, 'Credential AI default harus ditemukan');

        $result = $svc->generate($cred['data'], [
            'topic'           => 'Kopi Gayo',
            'content_type'    => 'post',
            'language'        => 'id',
            'min_words'       => 300,
            'company_profile' => 'Toko Kopi Nusantara',
            'instructions'    => 'Sebutkan asal biji',
        ]);

        $this->assertSame(1, count($svc->captured));
        $captured = $svc->captured[0];
        $this->assertSame('https://ai.example.com/v1/chat/completions', $captured['url']);
        $this->assertSame('sk-test-wp', $captured['api_key']);

        $system = $captured['payload']['messages'][0]['content'];
        $prompt = $captured['payload']['messages'][1]['content'];

        // Bahasa & profil & instruksi harus masuk prompt/system.
        $this->assertStringContainsString('Bahasa Indonesia', $system);
        $this->assertStringContainsString('Toko Kopi Nusantara', $system);
        $this->assertStringContainsString('Kopi Gayo', $prompt);
        $this->assertStringContainsString('asal biji', $prompt);

        // Hasil terstruktur.
        $this->assertStringContainsString('konten hasil AI', $result['content']);
        $this->assertGreaterThan(0, $result['word_count']);
        $this->assertSame('openai/gpt-4o-mini', $result['model']);
        $this->assertSame(170, $result['usage']['total_tokens']);
    }

    public function testContinueRequiresExistingContent(): void
    {
        $ws  = $this->seedWorkspaceWithAi(true);
        $svc = new WpContentStubService();
        $cred = $svc->findAiCredential($ws['workspace_id']);

        $this->expectException(\InvalidArgumentException::class);
        $svc->continueContent($cred['data'], ['existing_content' => '']);
    }

    public function testContinueSendsActionAndLanguage(): void
    {
        $ws     = $this->seedWorkspaceWithAi(true);
        $svc    = new WpContentStubService();
        $cred   = $svc->findAiCredential($ws['workspace_id']);

        $svc->continueContent($cred['data'], [
            'existing_content' => '<p>Konten lama.</p>',
            'language'         => 'en',
            'action'           => 'polish',
        ]);

        $captured = $svc->captured[0];
        $this->assertStringContainsString('English', $captured['payload']['messages'][0]['content']);
        $this->assertStringContainsString('Konten lama.', $captured['payload']['messages'][1]['content']);
    }

    public function testCountWordsStripsHtml(): void
    {
        $svc = new WpContentStubService();
        $this->assertSame(3, $svc->countWords('<h2>Satu dua</h2><p>tiga</p>'));
        $this->assertSame(0, $svc->countWords('<div></div>'));
    }

    // =========================================================================
    // Endpoint level (/api/v1/wp/*)
    // =========================================================================

    public function testStatusEndpointReportsAiReadiness(): void
    {
        $ws  = $this->seedWorkspaceWithAi(true);
        $key = $this->makeApiKey($ws);

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $key,
        ])->get('api/v1/wp/status');

        $this->assertSame(200, $res->response()->getStatusCode(), $res->getBody());
        $body    = $res->getBody();
        $start   = strpos($body, '{');
        $end     = strrpos($body, '}');
        $decoded = $start !== false && $end !== false && $end > $start
            ? json_decode(substr($body, $start, $end - $start + 1), true)
            : null;
        $data = $decoded['data'] ?? [];

        $this->assertTrue($data['valid'] ?? false);
        $this->assertTrue($data['ai_credential_ready'] ?? false);
        $this->assertNotNull($data['workspace_name'] ?? null);
    }

    public function testGenerateEndpointRejectsWithoutAiCredential(): void
    {
        $ws  = $this->seedWorkspaceWithAi(false);
        $key = $this->makeApiKey($ws);

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $key,
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['topic' => 'Topik apa saja']))->post('api/v1/wp/generate');

        $this->assertSame(500, $res->response()->getStatusCode(), $res->getBody());
        $this->assertStringContainsString('Default', $res->getBody());
    }

    public function testGenerateEndpointValidatesTopic(): void
    {
        $ws  = $this->seedWorkspaceWithAi(true);
        $key = $this->makeApiKey($ws);

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => $key,
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['topic' => '   ']))->post('api/v1/wp/generate');

        $this->assertSame(400, $res->response()->getStatusCode());
        $this->assertStringContainsString('Topik', $res->getBody());
    }

    public function testEndpointsRejectInvalidApiKey(): void
    {
        $this->seedWorkspaceWithAi(true);

        $res = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => 'ak_tidak_ada',
        ])->get('api/v1/wp/status');

        $this->assertSame(401, $res->response()->getStatusCode());

        $resPost = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-API-Key'        => 'ak_tidak_ada',
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['topic' => 'x']))->post('api/v1/wp/generate');

        $this->assertSame(401, $resPost->response()->getStatusCode());
    }
}
