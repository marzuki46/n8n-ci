<?php

use App\Services\AiUsageService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * AI Budget Guardrail: limit bulanan per workspace, mode warn/block.
 *
 * @internal
 */
final class AiBudgetGuardrailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh = false;

    private array $ids = [];

    protected function tearDown(): void
    {
        if (! empty($this->ids['wf'])) {
            $this->db->table('ai_usage')->where('workflow_id', $this->ids['wf'])->delete();
            $this->db->table('workflows')->where('id', $this->ids['wf'])->delete();
        }
        foreach (['ai_monthly_token_limit', 'ai_action_on_exceed'] as $k) {
            $this->db->table('settings')->where('key', $k)->delete();
        }
        if (! empty($this->ids['user'])) {
            $this->db->table('users')->where('id', $this->ids['user'])->delete();
        }
        $this->ids = [];
        parent::tearDown();
    }

    private function seedUsage(int $tokens): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('workspaces')->insert([
            'name' => 'WS Budget ' . uniqid(), 'slug' => 'bud-' . uniqid(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $ws = (int) $this->db->insertID();
        $this->db->table('workflows')->insert([
            'workspace_id' => $ws, 'name' => 'WF Bud ' . uniqid(), 'status' => 'active',
            'active' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $wf = (int) $this->db->insertID();

        $svc = new AiUsageService($this->db);
        // Pecah jadi beberapa baris agar sum teruji.
        while ($tokens > 0) {
            $chunk = min(500, $tokens);
            $svc->log($wf, null, 'test-model', ['prompt_tokens' => $chunk, 'completion_tokens' => 0, 'total_tokens' => $chunk]);
            $tokens -= $chunk;
        }

        $this->ids = ['workspace' => $ws, 'wf' => $wf];
    }

    public function testGuardWarnWhenExceededWithWarnAction(): void
    {
        $this->seedUsage(1200);
        $s = new \App\Services\SettingService($this->db);
        $s->set('ai_monthly_token_limit', '1000');
        $s->set('ai_action_on_exceed', 'warn');

        $svc = new AiUsageService($this->db);
        $g = $svc->guard($this->ids['workspace']);

        $this->assertTrue($g['exceeded']);
        $this->assertSame('warn', $g['action']);
        $this->assertNotNull($g['warning']); // warn → tidak throw
    }

    public function testGuardThrowsWhenExceededWithBlockAction(): void
    {
        $this->seedUsage(1500);
        $s = new \App\Services\SettingService($this->db);
        $s->set('ai_monthly_token_limit', '1000');
        $s->set('ai_action_on_exceed', 'block');

        $svc = new AiUsageService($this->db);

        try {
            $svc->guard($this->ids['workspace']);
            $this->fail('Harusnya throw saat block.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('kuota', $e->getMessage());
        }
    }

    public function testGuardPassesUnderLimit(): void
    {
        $this->seedUsage(400);
        $s = new \App\Services\SettingService($this->db);
        $s->set('ai_monthly_token_limit', '1000');
        $s->set('ai_action_on_exceed', 'block');

        $g = (new AiUsageService($this->db))->guard($this->ids['workspace']);
        $this->assertFalse($g['exceeded']);
        $this->assertNull($g['warning']);
    }

    public function testBudgetEndpointsSaveAndRead(): void
    {
        $email = 'budget_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'Bud Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();
        $this->ids['user'] = $uid;

        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $uid, 'user_role' => 'owner'])
            ->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $h = [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ];

        $put = $this->withHeaders($h)->withBody(json_encode(['limit' => 50000, 'action' => 'block']))
            ->put('api/system/ai-budget');
        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(200, $put->response()->getStatusCode(), $put->response()->getBody());

        $get = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $uid])
            ->get('api/system/ai-budget');
        $data = json_decode((string) $get->response()->getBody(), true)['data'] ?? [];

        $this->assertSame(50000, (int) ($data['limit'] ?? -1));
        $this->assertSame('block', $data['action'] ?? '');
    }
}
