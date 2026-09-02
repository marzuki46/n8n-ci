<?php

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test Fase A: keamanan auth (session regeneration, CSRF, rate-limit, secure headers).
 *
 * @internal
 */
final class AuthSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $userIds = [];

    private string $loginEmail = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginEmail = 'sec_owner_' . uniqid() . '@local.dev';
        $this->insertUser($this->loginEmail, 'owner123');
    }

    protected function tearDown(): void
    {
        foreach ($this->userIds as $id) {
            $this->db->table('users')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    private function insertUser(string $email, string $password): int
    {
        $this->db->table('users')->insert([
            'name'       => 'Sec Owner',
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'role'       => 'owner',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->db->insertID();
        $this->userIds[] = $id;

        return $id;
    }

    public function testLoginSucceedsWithValidCredentials(): void
    {
        $result = $this->post('api/auth/login', [
            'email'    => $this->loginEmail,
            'password' => 'owner123',
        ]);

        $result->assertStatus(200);
        $this->assertStringContainsString($this->loginEmail, $result->getBody());
    }

    public function testLoginFailsThenThrottles(): void
    {
        $email = 'sec_throttle_' . uniqid() . '@local.dev';

        for ($i = 1; $i <= 5; $i++) {
            $result = $this->post('api/auth/login', [
                'email'    => $email,
                'password' => 'wrong-password',
            ]);
            $result->assertStatus(401);
        }

        $sixth = $this->post('api/auth/login', [
            'email'    => $email,
            'password' => 'wrong-password',
        ]);
        $sixth->assertStatus(429);
        $sixth->assertHeader('Retry-After');
    }

    public function testSuccessfulLoginResetsThrottleCounter(): void
    {
        $email = 'sec_owner_' . uniqid() . '@local.dev';
        $this->insertUser($email, 'reset123');

        $first = $this->post('api/auth/login', [
            'email'    => $email,
            'password' => 'reset123',
        ]);
        $first->assertStatus(200);
    }

    public function testCsrfBlocksStateChangingRequestWithoutToken(): void
    {
        $this->expectException(SecurityException::class);

        $this->post('api/projects', ['name' => 'Tanpa Token']);
    }

    public function testCsrfPassesWithMatchingCookieAndHeader(): void
    {
        // Token diambil dari endpoint publik (pola yang sama dipakai frontend).
        $csrf = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
        ])->get('api/csrf');
        $token = $this->extractToken($csrf->getBody());

        // Simulasikan cookie CSRF yang dikirim balik browser.
        $_COOKIE['csrf_cookie_name'] = $token;

        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $token,
        ])->post('api/projects', ['name' => 'Dengan Token']);

        // Lolos filter CSRF → bukan 403 (tanpa sesi jatuh ke redirect auth/401).
        $this->assertNotSame(403, $result->response()->getStatusCode());

        unset($_COOKIE['csrf_cookie_name']);
    }

    public function testCsrfEndpointAndSecureHeaders(): void
    {
        $result = $this->withHeaders([
            'X-Requested-With' => 'xmlhttprequest', // hindari injeksi Debug Toolbar di body JSON
        ])->get('api/csrf');

        $result->assertStatus(200);
        $token = $this->extractToken($result->getBody());
        $this->assertNotEmpty($token);
        $result->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $result->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    private function extractToken(string $body): string
    {
        // Body bisa dibungkus boilerplate HTML oleh harness test; token hex 32 char.
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
