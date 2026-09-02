<?php

use App\Nodes\MemoryNode;
use App\Nodes\WorkflowContext;
use App\Services\GoogleOauthService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Penutupan sisa backlog: Memory node, Vector browser API, Google OAuth.
 *
 * @internal
 */
final class BacklogFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh = false;

    private array $featSession = [];
    private array $keys = [];

    protected function tearDown(): void
    {
        if (! empty($this->keys)) {
            foreach (['ai_memories' => 'memory_key', 'api_keys' => 'id'] as $t => $c) {
                // noop placeholder; pembersihan spesifik di bawah
            }
        }
        // Bersihkan memory test keys.
        $this->db->table('ai_memories')->like('memory_key', 'mem-test-')->delete();
        $this->db->table('ai_vectors')->where('namespace', 'kb-test')->delete();
        if (! empty($this->keys)) {
            $this->db->table('api_keys')->whereIn('id', $this->keys)->delete();
        }
        if (! empty($this->featSession['user_id'])) {
            $uid = $this->featSession['user_id'];
            $this->db->table('workspace_users')->where('user_id', $uid)->delete();
            $wids = array_column(
                $this->db->table('workspaces')->select('id')->like('name', 'WS BL')->get()->getResultArray(),
                'id'
            );
            foreach ($wids as $wid) {
                $this->db->table('workspaces')->where('id', $wid)->delete();
            }
            $this->db->table('users')->where('id', $uid)->delete();
        }
        // Hapus user oauth stub.
        $this->db->table('users')->like('email', '@gmail-backlog.test')->delete();

        $this->featSession = [];
        parent::tearDown();
    }

    private function seedSession(): int
    {
        if ($this->featSession !== []) {
            return $this->featSession['workspace_id'];
        }
        $email = 'bl_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'BL Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();

        $this->db->table('workspaces')->insert([
            'name' => 'WS BL ' . uniqid(), 'slug' => 'bl-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ws = (int) $this->db->insertID();

        $this->db->table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->withSession(['user_id' => $uid, 'user_role' => 'owner', 'workspace_id' => $ws]);
        $this->featSession = ['user_id' => $uid, 'workspace_id' => $ws];

        return $ws;
    }

    private function csrfHeaders(): array
    {
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        return [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ];
    }

    private function unsetCsrf(): void
    {
        unset($_COOKIE['csrf_cookie_name']);
    }

    // =================================================================
    // Memory node
    // =================================================================

    public function testMemorySaveLoadClearRoundtrip(): void
    {
        $node = new MemoryNode();
        $ctx  = new WorkflowContext(['id' => 1]);
        $key  = 'mem-test-' . uniqid();

        // Save dua pesan.
        foreach (['pesan pertama', 'pesan kedua'] as $msg) {
            $node->execute(
                [['json' => ['text' => $msg]]],
                ['operation' => 'save', 'memory_key' => $key, 'content_field' => 'text', 'role' => 'user'],
                $ctx
            );
        }

        // Load â†’ urut lama ke baru.
        $out = $node->execute(
            [['json' => []]],
            ['operation' => 'load', 'memory_key' => $key, 'limit' => 20, 'as_field' => 'history'],
            $ctx
        );
        $hist = $out['main'][0]['json']['history'];
        $this->assertCount(2, $hist);
        $this->assertSame('pesan pertama', $hist[0]['content']);
        $this->assertSame('user', $hist[0]['role']);

        // Clear.
        $outC = $node->execute(
            [['json' => []]],
            ['operation' => 'clear', 'memory_key' => $key],
            $ctx
        );
        $this->assertTrue((bool) ($outC['main'][0]['json']['cleared'] ?? false));
        $this->assertGreaterThanOrEqual(2, $outC['main'][0]['json']['cleared_count']);
    }

    public function testMemoryRequiresKey(): void
    {
        $node = new MemoryNode();
        $ctx  = new WorkflowContext(['id' => 1]);

        try {
            $node->execute([['json' => []]], ['operation' => 'load', 'memory_key' => '   '], $ctx);
            $this->fail('Harusnya error tanpa key.');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('key', $e->getMessage());
        }
    }

    public function testAgentAndMemoryShareTable(): void
    {
        // MemoryNode save â†’ AI Agent load harus melihat pesan yang sama.
        $key = 'mem-test-shared-' . uniqid();

        (new MemoryNode())->execute(
            [['json' => ['text' => 'konteks bersama']]],
            ['operation' => 'save', 'memory_key' => $key, 'role' => 'user'],
            new WorkflowContext(['id' => 0])
        );

        $stub = new class extends \App\Nodes\AiAgentNode {
            public array $responses = [[ 'choices' => [[ 'message' => [ 'role' => 'assistant', 'content' => 'siap' ]]]]];
            public ?array $lastPayload = null;
            protected function llmPostJson(string $url, array $payload, string $apiKey): string
            {
                $this->lastPayload = $payload;
                return json_encode($this->responses[0]);
            }
        };

        $ctx = new WorkflowContext(['id' => 0]);
        $ctx->parameters['credential'] = ['api_key' => 'sk-x'];
        $stub->execute([['json' => []]], ['prompt' => 'cek memori', 'memory_key' => $key], $ctx);

        $contents = array_column($stub->lastPayload['messages'], 'content');
        $this->assertContains('konteks bersama', $contents);

        $this->db->table('ai_memories')->where('memory_key', $key)->delete();
    }

    // =================================================================
    // Vector browser API
    // =================================================================

    public function testVectorSummaryAndDeleteEndpoint(): void
    {
        $ws = $this->seedSession();

        // Seed vektor milik workspace ini.
        $now = date('Y-m-d H:i:s');
        foreach ([1, 2] as $i) {
            $this->db->table('ai_vectors')->insert([
                'workspace_id' => $ws, 'namespace' => 'kb-test', 'source' => null,
                'content' => 'dokumen uji ' . $i, 'vector' => '[1,0]', 'dims' => 2,
                'created_at' => $now,
            ]);
        }

        $h = ['X-Requested-With' => 'xmlhttprequest'];

        $csrf = $this->withHeaders($h)->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $sum = $this->withHeaders($h)->get('api/vectors/summary');
        $this->assertSame(200, $sum->response()->getStatusCode());
        $body = json_decode((string) $sum->response()->getBody(), true);
        $namespaces = array_column($body['data'] ?? [], 'namespace');
        $this->assertContains('kb-test', $namespaces);

        $del = $this->withHeaders(array_merge($h, ['X-CSRF-TOKEN' => $m[1] ?? '']))
            ->delete('api/vectors/namespace/kb-test');
        unset($_COOKIE['csrf_cookie_name']);
        $this->assertSame(200, $del->response()->getStatusCode(), $del->response()->getBody());

        $left = $this->db->table('ai_vectors')->where('namespace', 'kb-test')->countAllResults();
        $this->assertSame(0, $left);
    }

    // =================================================================
    // Google OAuth
    // =================================================================

    public function testOauthStateSignVerify(): void
    {
        $svc = new GoogleOauthService();
        $state = $svc->issueState()['state'];

        $this->assertTrue($svc->verifyState($state));
        $this->assertFalse($svc->verifyState($state . 'x'));
        $this->assertFalse($svc->verifyState('bogus'));
        $this->assertFalse($svc->verifyState(''));
    }

    public function testLoginWithGoogleProfileCreatesMemberOnFirstWorkspace(): void
    {
        // Workspace pertama yang ada di DB test.
        $first = $this->db->table('workspaces')->orderBy('id', 'ASC')->limit(1)->get()->getRowArray();
        if (! $first) {
            $this->markTestSkipped('Tidak ada workspace di DB test.');
        }

        $svc = new GoogleOauthService();
        $email = 'oauth_' . uniqid() . '@gmail-backlog.test';

        $profile = ['email' => $email, 'name' => 'Google User'];

// Login pertama: buat akun baru sebagai member (force auto-create via flag).
        $user1 = $svc->loginWithGoogleProfile($profile, true);
        $this->assertNotNull($user1);
        $this->assertSame('member', $user1['role']);

        // Terdaftar di workspace pertama.
        $inWs = $this->db->table('workspace_users')
            ->where('user_id', $user1['id'])
            ->where('workspace_id', (int) $first['id'])
            ->countAllResults();
        $this->assertSame(1, $inWs);

        // Login kedua: pakai akun yang sama (tidak dobel).
        $user2 = $svc->loginWithGoogleProfile(['email' => strtoupper($email), 'name' => 'Google User']);
        $this->assertSame((int) $user1['id'], (int) $user2['id']);

        $this->db->table('workspace_users')->where('user_id', $user1['id'])->delete();
        $this->db->table('users')->where('id', $user1['id'])->delete();
    }

    public function testOauthStatusEndpointPublic(): void
    {
        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get('api/auth/oauth/status');

        $this->assertSame(200, $res->response()->getStatusCode());
        $body = json_decode((string) $res->response()->getBody(), true);
        $this->assertArrayHasKey('google_enabled', $body['data'] ?? []);
    }
}

