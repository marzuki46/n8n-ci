<?php

use App\Services\SettingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Custom login path (keamanan): endpoint default 404, path kustom jalan.
 *
 * @internal
 */
final class LoginPathSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    private ?SettingService $settings = null;

    private array $owner = [];

    protected function tearDown(): void
    {
        // Pastikan slug selalu direset agar test lain tidak terkunci.
        $this->svc()->setLoginSlug('');
        $this->owner = [];

        parent::tearDown();
    }

    private function svc(): SettingService
    {
        if ($this->settings === null) {
            $this->settings = new SettingService();
        }

        return $this->settings;
    }

    private function seedOwnerSession(): void
    {
        if ($this->owner !== []) {
            $this->withSession([
                'user_id'   => $this->owner['id'],
                'user_role' => 'owner',
            ]);

            return;
        }

        $email = 'lpsec_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'LP Owner', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $uid = (int) $this->db->insertID();
        $this->owner = ['id' => $uid];

        $this->withSession(['user_id' => $uid, 'user_role' => 'owner']);
    }

    public function testSlugValidationRules(): void
    {
        $this->assertTrue($this->svc()->setLoginSlug('masuk-rahasia_99'));
        $this->assertSame('masuk-rahasia_99', $this->svc()->getLoginSlug());

        $this->assertFalse($this->svc()->setLoginSlug('ab'));           // terlalu pendek
        $this->assertFalse($this->svc()->setLoginSlug('Ada Spasi'));    // karakter invalid
        $this->assertFalse($this->svc()->setLoginSlug(str_repeat('x', 80)));

        $this->assertTrue($this->svc()->setLoginSlug(''));
        $this->assertSame('', $this->svc()->getLoginSlug());
    }

    public function testDefaultLoginWorksWhenNoSlug(): void
    {
        $this->svc()->setLoginSlug('');

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->post('api/auth/login', ['email' => 'x@y.z', 'password' => 'salah']);

        $this->assertContains(
            $res->response()->getStatusCode(),
            [400, 401],
            'Tanpa slug, endpoint default harus tetap hidup (balasan 401 utk kredensial salah)'
        );
    }

    public function testDefaultEndpointReturns404WhenSlugActive(): void
    {
        $user = $this->seedOwnerSession();
        unset($user);
        $this->svc()->setLoginSlug('pintu-rahasia');

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->post('api/auth/login', ['email' => 'x@y.z', 'password' => 'salah']);

        $this->assertSame(404, $res->response()->getStatusCode(), 'Endpoint default harus 404 saat slug aktif');

        // Slug salah juga 404.
        $resWrong = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->post('api/auth/login/salah-lho', ['email' => 'x@y.z', 'password' => 'salah']);
        $this->assertSame(404, $resWrong->response()->getStatusCode());

        // Slug benar → proses login berjalan (401 karena password salah, bukan 404).
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);

        $resOk = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->post('api/auth/login/pintu-rahasia', ['email' => 'x@y.z', 'password' => 'salah']);

        $this->assertSame(401, $resOk->response()->getStatusCode(), 'Slug benar harus sampai ke verifikasi kredensial');
    }

    public function testSaveLoginPathViaEndpointRequiresOwner(): void
    {
        $this->seedOwnerSession();
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        preg_match('/"token":"([0-9a-f]{32})"/i', $csrf->getBody(), $m);
        $_COOKIE['csrf_cookie_name'] = $m[1] ?? '';

        $h = [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $m[1] ?? '',
            'Content-Type'     => 'application/json',
        ];

        $put = $this->withHeaders($h)->withBody(json_encode(['slug' => 'gerbang-aman']))->put('api/security/login-path');
        unset($_COOKIE['csrf_cookie_name']);

        $this->assertSame(200, $put->response()->getStatusCode(), $put->getBody());
        $this->assertSame('gerbang-aman', $this->svc()->getLoginSlug());

        $get = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/security/login-path');
        $this->assertStringContainsString('gerbang-aman', $get->getBody());
    }
}
