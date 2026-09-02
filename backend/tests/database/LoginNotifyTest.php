<?php

use App\Services\LoginNotifyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Stub: tangkap email tanpa SMTP sungguhan.
 */
final class LoginNotifyStubService extends LoginNotifyService
{
    /** @var list<array{to:string,subject:string,message:string}> */
    public array $sent = [];

    protected function sendEmail(string $to, string $subject, string $message): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'message' => $message];

        return true;
    }
}

/**
 * Notifikasi email login sukses + preferensi per user.
 *
 * @internal
 */
final class LoginNotifyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    private ?LoginNotifyStubService $stub = null;

    /** @var list<int> */
    private array $cleanupUsers = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupUsers as $uid) {
            $this->db->table('users')->where('id', $uid)->delete();
        }
        $this->cleanupUsers = [];
        $this->stub = null;

        parent::tearDown();
    }

    private function stub(): LoginNotifyStubService
    {
        if ($this->stub === null) {
            $this->stub = new LoginNotifyStubService();
        }

        return $this->stub;
    }

    private function seedUser(int $loginNotify = 1): array
    {
        $email = 'notify_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name'         => 'Notif User',
            'email'        => $email,
            'password'     => password_hash('secret123', PASSWORD_DEFAULT),
            'role'         => 'owner',
            'login_notify' => $loginNotify,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();
        $this->cleanupUsers[] = $uid;

        return ['id' => $uid, 'email' => $email];
    }

    // =========================================================================
    // Service
    // =========================================================================

    public function testNotifySendsEmailWithIpAndDevice(): void
    {
        $user = ['id' => 1, 'email' => 'a@b.co', 'login_notify' => 1];

        $this->stub()->notify($user, '203.0.113.55', 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0');

        $this->assertCount(1, $this->stub()->sent);
        $mail = $this->stub()->sent[0];
        $this->assertSame('a@b.co', $mail['to']);
        $this->assertStringContainsString('203.0.113.55', $mail['message']);
        $this->assertStringContainsString('Chrome', $mail['message']);
        $this->assertStringContainsString('Windows', $mail['message']);
    }

    public function testNotifySkippedWhenDisabled(): void
    {
        $user = ['id' => 1, 'email' => 'a@b.co', 'login_notify' => 0];

        $this->stub()->notify($user, '10.0.0.9', 'Mozilla/5.0 Firefox/128.0');

        $this->assertSame([], $this->stub()->sent);
    }

    public function testDescribeDeviceParsesCommonAgents(): void
    {
        $svc = new LoginNotifyService();

        $d1 = $svc->describeDevice('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15');
        $this->assertSame(['browser' => 'Safari', 'os' => 'macOS'], $d1);

        $d2 = $svc->describeDevice('');
        $this->assertSame('Tidak dikenal', $d2['browser']);
    }

    // =========================================================================
    // Integrasi login + endpoint preferensi
    // =========================================================================

    public function testPreferencesEndpointsToggle(): void
    {
        $user = $this->seedUser(1);

        // Default: on
        $resGet = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->withSession(['user_id' => $user['id']])
            ->get('api/user/preferences');
        $this->assertSame(200, $resGet->response()->getStatusCode());
        $body = $resGet->getBody();
        $start = strpos($body, '{');
        $end = strrpos($body, '}');
        $data = json_decode(substr($body, (int) $start, (int) $end - (int) $start + 1), true)['data'] ?? [];
        $this->assertSame(1, (int) ($data['login_notify'] ?? 0));

        // Matikan
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $resPut = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ])->withBody(json_encode(['login_notify' => false]))->put('api/user/preferences');
        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(200, $resPut->response()->getStatusCode(), $resPut->getBody());

        $row = $this->db->table('users')->where('id', $user['id'])->get()->getRowArray();
        $this->assertSame(0, (int) $row['login_notify']);
    }
}
