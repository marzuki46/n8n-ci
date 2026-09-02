<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Endpoint status engine/runtime.
 *
 * @internal
 */
final class SystemRuntimeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    private ?int $uid = null;

    protected function tearDown(): void
    {
        if ($this->uid) {
            $this->db->table('users')->where('id', $this->uid)->delete();
            $this->uid = null;
        }
        parent::tearDown();
    }

    private function loginSession(): void
    {
        $email = 'runtime_' . uniqid() . '@local.dev';
        $this->db->table('users')->insert([
            'name' => 'RT User', 'email' => $email,
            'password' => password_hash('x123', PASSWORD_DEFAULT), 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->uid = (int) $this->db->insertID();

        $this->withSession(['user_id' => $this->uid, 'user_role' => 'owner']);
    }

    public function testRequiresAuth(): void
    {
        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get('api/system/runtimes');

        $this->assertSame(401, $res->response()->getStatusCode());
    }

    public function testReturnsRuntimeStatuses(): void
    {
        $this->loginSession();

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])
            ->get('api/system/runtimes');

        $this->assertSame(200, $res->response()->getStatusCode(), substr((string) $res->response()->getBody(), 0, 300));

        $data = json_decode((string) $res->response()->getBody(), true)['data'] ?? [];

        // Struktur utama ada.
        foreach (['node', 'python', 'mysql', 'php'] as $key) {
            $this->assertArrayHasKey($key, $data['runtimes'] ?? []);
        }
        $this->assertSame(true, $data['runtimes']['php']['found']);
        $this->assertSame(true, $data['runtimes']['mysql']['found']);

        // Node & Python: key found harus boolean (aktif/tidak).
        $this->assertIsBool($data['runtimes']['node']['found']);
        $this->assertIsBool($data['runtimes']['python']['found']);

        // Versi node/python berisi pola versi bila ditemukan.
        if ($data['runtimes']['node']['found']) {
            $this->assertMatchesRegularExpression('/^v\d+/', (string) $data['runtimes']['node']['version']);
        }
        if ($data['runtimes']['python']['found']) {
            $this->assertMatchesRegularExpression('/Python\s*\d+/i', (string) $data['runtimes']['python']['version']);
        }

        // Ekstensi PHP penting dilaporkan.
        foreach (['curl', 'openssl', 'mbstring'] as $ext) {
            $this->assertArrayHasKey($ext, $data['runtimes']['extensions'] ?? []);
        }
    }
}
