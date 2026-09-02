<?php

use App\Services\InquiryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Keamanan form inquiry publik: captcha HMAC, honeypot, validasi, rate-limit.
 *
 * @internal
 */
final class InquirySecurityTest extends CIUnitTestCase
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
            $this->db->table('inquiries')->where('id', $id)->delete();
        }
        $this->cleanupIds = [];
        $settings = new \App\Services\SettingService();
        $settings->set('recaptcha_site_key', '');
        $settings->set('recaptcha_secret_key', '');

        parent::tearDown();
    }

    private function validPayload(array $overrides = []): array
    {
        $svc     = new InquiryService();
        $cap     = $svc->issueCaptcha();

        return array_merge([
            'name'           => 'Calon Klien',
            'email'          => 'klien@example.com',
            'message'        => 'Saya butuh website company profile + SEO.',
            'website'        => '', // honeypot harus kosong
            'captcha_token'  => $cap['token'],
            'captcha_answer' => $this->answerOf($cap),
        ], $overrides);
    }

    private function answerOf(array $captcha): string
    {
        // "7 + 5 = ?" → "12"
        preg_match('/(\d+)\s*\+\s*(\d+)/', $captcha['question'], $m);

        return (string) ((int) $m[1] + (int) $m[2]);
    }

    public function testCaptchaRoundTripValid(): void
    {
        $svc = new InquiryService();
        $cap = $svc->issueCaptcha();
        $this->assertTrue($svc->verifyCaptcha($cap['token'], $this->answerOf($cap)));
    }

    public function testCaptchaRejectsWrongAnswerAndBadToken(): void
    {
        $svc = new InquiryService();
        $cap = $svc->issueCaptcha();

        $this->assertFalse($svc->verifyCaptcha($cap['token'], '9999'));
        $this->assertFalse($svc->verifyCaptcha('palsu.palsu', '5'));
        $this->assertFalse($svc->verifyCaptcha('', ''));
    }

    public function testSubmitStoresInquiryWithValidCaptcha(): void
    {
        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest', 'Content-Type' => 'application/json'])
            ->withBody(json_encode($this->validPayload()))
            ->post('api/public/inquiry');

        $this->assertSame(201, $res->response()->getStatusCode(), $res->getBody());

        $row = $this->db->table('inquiries')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->cleanupIds[] = (int) $row['id'];
        $this->assertSame('Calon Klien', $row['name']);
        $this->assertSame('new', $row['status']);
    }

    public function testHoneypotFilledIsRejected(): void
    {
        $payload = $this->validPayload(['website' => 'http://spam.example']);

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest', 'Content-Type' => 'application/json'])
            ->withBody(json_encode($payload))
            ->post('api/public/inquiry');

        $this->assertSame(400, $res->response()->getStatusCode());
        $count = $this->db->table('inquiries')->where('name', 'Calon Klien')->countAllResults();
        $this->assertSame(0, $count, 'Bot honeypot tidak boleh tersimpan');
    }

    public function testWrongCaptchaRejected(): void
    {
        $payload = $this->validPayload(['captcha_answer' => '0000']);

        $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest', 'Content-Type' => 'application/json'])
            ->withBody(json_encode($payload))
            ->post('api/public/inquiry');

        $this->assertSame(400, $res->response()->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('captcha', $res->getBody());
    }

    public function testInvalidEmailAndShortMessageRejected(): void
    {
        $svc   = new InquiryService();
        $short = $this->validPayload(['message' => 'pendek']);
        $badEmail = $this->validPayload(['email' => 'bukan-email']);

        foreach ([$short, $badEmail] as $payload) {
            $res = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest', 'Content-Type' => 'application/json'])
                ->withBody(json_encode($payload))
                ->post('api/public/inquiry');
            $this->assertSame(400, $res->response()->getStatusCode());
        }
    }

    public function testPublicProfileExposesNoSecrets(): void
    {
        $settings = new \App\Services\SettingService();
        $settings->set('recaptcha_secret_key', 'RAHASIA-123');

        $pub = (new InquiryService())->getPublicProfile();

        $this->assertArrayNotHasKey('recaptcha_secret_key', $pub);
        $this->assertArrayHasKey('profile_name', $pub);
    }
}
