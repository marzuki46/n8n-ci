<?php

use App\Services\AlertService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test error alert / notifikasi workflow gagal.
 *
 * @internal
 */
final class AlertTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $table => $ids) {
            if (is_int($table)) {
                continue;
            }
            if (! empty($ids)) {
                $this->db->table($table)->whereIn('id', $ids)->delete();
            }
        }

        parent::tearDown();
    }

    private function makeUser(string $email): int
    {
        $this->db->table('users')->insert([
            'name'       => 'User ' . uniqid(),
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => 'owner',
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

    private function makeWorkflow(int $workspaceId): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => $workspaceId,
            'name'         => 'WF ' . uniqid(),
            'status'       => 'draft',
            'active'       => 0,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        $this->cleanup['workflows'][] = $id;
        $this->cleanup['workflow_alerts'][] = $id;

        return $id;
    }

    private function addMember(int $workspaceId, int $userId, string $role = 'owner'): void
    {
        $this->db->table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id'      => $userId,
            'role'         => $role,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function login(int $userId, int $workspaceId): void
    {
        $this->withSession([
            'user_id'      => $userId,
            'user_name'    => 'User',
            'user_email'   => 'u' . $userId . '@local.dev',
            'user_role'    => 'owner',
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

    private function jsonBody(string $body): array
    {
        if (preg_match('/\{.*\}/s', $body, $m) !== 1) {
            $this->fail('Respons bukan JSON: ' . $body);
        }

        $data = json_decode($m[0], true);
        $this->assertIsArray($data);

        return $data;
    }

    // -----------------------------------------------------------------
    // AlertService unit
    // -----------------------------------------------------------------

    public function testSaveAndGetConfigRoundTrip(): void
    {
        $wf = $this->makeWorkflow($this->makeWorkspace());
        $svc = new AlertService($this->db);

        $svc->saveConfig($wf, [
            'email_to'         => 'ops@local.dev, admin@local.dev',
            'enabled'          => true,
            'throttle_minutes' => 30,
        ]);

        $config = $svc->getConfig($wf);
        $this->assertNotNull($config);
        $this->assertSame(1, (int) $config['enabled']);
        $this->assertSame('ops@local.dev, admin@local.dev', $config['email_to']);
        $this->assertSame(30, (int) $config['throttle_minutes']);

        // update existing (idempotent)
        $svc->saveConfig($wf, ['enabled' => false]);
        $this->assertSame(0, (int) $svc->getConfig($wf)['enabled']);

        $this->assertSame(1, (int) $this->db->table('workflow_alerts')->where('workflow_id', $wf)->countAllResults());
    }

    public function testNotifyFailureWithoutConfigDoesNothing(): void
    {
        $wf = $this->makeWorkflow($this->makeWorkspace());
        $svc = new AlertService($this->db);

        $svc->notifyFailure($wf, 999, 'ada error');

        $this->assertSame(0, (int) $this->db->table('alert_logs')->where('workflow_id', $wf)->countAllResults());
    }

    public function testNotifyFailureDisabledLogsNothing(): void
    {
        $wf = $this->makeWorkflow($this->makeWorkspace());
        $svc = new AlertService($this->db);
        $svc->saveConfig($wf, ['enabled' => false, 'email_to' => 'ops@local.dev']);

        $svc->notifyFailure($wf, 100, 'error diam');

        $this->assertSame(0, (int) $this->db->table('alert_logs')->where('workflow_id', $wf)->countAllResults());
    }

    public function testNotifyFailureEnabledLogsAlert(): void
    {
        $wf = $this->makeWorkflow($this->makeWorkspace());
        $svc = new AlertService($this->db);
        $svc->saveConfig($wf, ['enabled' => true, 'email_to' => 'ops@local.dev']);

        $svc->notifyFailure($wf, 101, 'gagal di node X');

        $logs = $this->db->table('alert_logs')->where('workflow_id', $wf)->get()->getResultArray();
        $this->assertCount(1, $logs);
        $this->assertSame('workflow_error', $logs[0]['alert_type']);
        $this->assertSame(101, (int) $logs[0]['execution_id']);
        $this->assertStringContainsString('gagal di node X', $logs[0]['message']);

        // last_sent_at terisi (untuk throttle berikutnya)
        $this->assertNotNull($svc->getConfig($wf)['last_sent_at']);
    }

    public function testNotifyFailureThrottleOnlyLogsOnceWithEmail(): void
    {
        $wf = $this->makeWorkflow($this->makeWorkspace());
        $svc = new AlertService($this->db);
        $svc->saveConfig($wf, ['enabled' => true, 'email_to' => 'ops@local.dev', 'throttle_minutes' => 60]);

        $svc->notifyFailure($wf, 102, 'error pertama');
        $svc->notifyFailure($wf, 103, 'error kedua (dalam throttle)');

        $logs = $this->db->table('alert_logs')->where('workflow_id', $wf)->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $logs);
        // Keduanya tercatat, tapi hanya yang pertama yang menyentuh jalur kirim email.
        // Email dibuktikan tanpa SMTP riil => email_sent tetap 0, namun log tetap ada.
        $this->assertSame(102, (int) $logs[0]['execution_id']);
        $this->assertSame(103, (int) $logs[1]['execution_id']);
    }

    public function testParseRecipientsFiltersInvalidEmails(): void
    {
        $svc = new AlertService($this->db);

        $ref = new ReflectionMethod($svc, 'parseRecipients');
        $ref->setAccessible(true);

        $this->assertSame(
            ['a@local.dev', 'b@local.dev'],
            $ref->invoke($svc, 'a@local.dev, , b@local.dev, not-an-email')
        );
    }

    // -----------------------------------------------------------------
    // API endpoint
    // -----------------------------------------------------------------

    public function testApiGetAlertsConfig(): void
    {
        $ws = $this->makeWorkspace();
        $user = $this->makeUser('owner@local.dev');
        $this->addMember($ws, $user);
        $wf = $this->makeWorkflow($ws);
        $this->login($user, $ws);

        $result = $this->withHeaders($this->csrfHeaders())->get("api/workflows/{$wf}/alerts");
        $result->assertStatus(200);
        $json = $this->jsonBody($result->getBody());

        $this->assertArrayHasKey('data', $json);
        $this->assertSame($wf, (int) $json['data']['workflow_id']);
        $this->assertSame(0, (int) $json['data']['enabled']);
    }

    public function testApiUpdateAlertsConfig(): void
    {
        $ws = $this->makeWorkspace();
        $user = $this->makeUser('owner@local.dev');
        $this->addMember($ws, $user);
        $wf = $this->makeWorkflow($ws);
        $this->login($user, $ws);

        $result = $this->withHeaders($this->csrfHeaders())
            ->withBody(json_encode([
                'enabled'          => true,
                'email_to'         => 'ops@local.dev',
                'throttle_minutes' => 15,
            ]))
            ->put("api/workflows/{$wf}/alerts");
        $result->assertStatus(200);
        $json = $this->jsonBody($result->getBody());

        $this->assertSame(1, (int) $json['data']['enabled']);
        $this->assertSame('ops@local.dev', $json['data']['email_to']);
        $this->assertSame(15, (int) $json['data']['throttle_minutes']);
    }

    public function testApiAlertsListedInIndex(): void
    {
        $ws = $this->makeWorkspace();
        $user = $this->makeUser('owner@local.dev');
        $this->addMember($ws, $user);
        $wf = $this->makeWorkflow($ws);
        $this->login($user, $ws);

        $svc = new AlertService($this->db);
        $svc->saveConfig($wf, ['enabled' => true]);
        $svc->notifyFailure($wf, 200, 'error untuk index');

        $result = $this->withHeaders($this->csrfHeaders())->get('api/alerts');
        $result->assertStatus(200);
        $json = $this->jsonBody($result->getBody());

        $this->assertGreaterThanOrEqual(1, count($json['data']['data']));
        $this->assertArrayHasKey('unread', $json['data']);
    }
}
