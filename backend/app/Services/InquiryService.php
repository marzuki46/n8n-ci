<?php

namespace App\Services;

/**
 * Profil publik & inquiry dari landing page.
 *
 * Keamanan form (berlapis):
 * 1. Honeypot field tersembunyi — bot yang mengisinya langsung ditolak.
 * 2. Captcha matematika bertanda tangan HMAC (tanpa state server) ATAU
 *    Google reCAPTCHA v2 bila kunci secret diisi di Settings.
 * 3. Rate limit per IP diterapkan lewat filter ratelimit pada route.
 */
class InquiryService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // =====================================================================
    // Profil publik
    // =====================================================================

    private const PROFILE_KEYS = [
        'profile_name', 'profile_tagline', 'profile_bio', 'profile_location',
        'contact_email', 'contact_whatsapp', 'contact_website',
        'recaptcha_site_key', 'recaptcha_secret_key',
    ];

    /**
     * Field yang aman diekspos ke publik (tanpa secret).
     */
    private const PUBLIC_FIELDS = [
        'profile_name', 'profile_tagline', 'profile_bio', 'profile_location',
        'contact_email', 'contact_whatsapp', 'contact_website',
    ];

    public function getProfile(): array
    {
        $settings = new SettingService();
        $out      = [];
        foreach (self::PROFILE_KEYS as $key) {
            $out[$key] = (string) ($settings->get($key, '') ?? '');
        }

        return $out;
    }

    /**
     * Profil untuk halaman publik: hanya field non-rahasia.
     */
    public function getPublicProfile(): array
    {
        $all  = $this->getProfile();
        $pub  = [];
        foreach (self::PUBLIC_FIELDS as $key) {
            $pub[$key] = $all[$key];
        }
        $pub['recaptcha_enabled'] = $all['recaptcha_site_key'] !== '' && $all['recaptcha_secret_key'] !== '';

        return $pub;
    }

    public function saveProfile(array $input): array
    {
        $settings = new SettingService();
        foreach (self::PROFILE_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $settings->set($key, trim((string) $input[$key]));
            }
        }

        return $this->getProfile();
    }

    // =====================================================================
    // Captcha matematika bertanda tangan (stateless)
    // Format token: base64url(payload) . "." . hmac(payload)
    // payload = {e: expiry, h: hmac_sha256(jawaban, key)}
    // =====================================================================

    private function signKey(): string
    {
        try {
            $key = service('encrypter')->getKey();

            return bin2hex((string) $key);
        } catch (\Throwable $e) {
            return 'insecure-dev-key';
        }
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    public function issueCaptcha(): array
    {
        $a       = random_int(2, 9);
        $b       = random_int(1, 9);
        $answer  = (string) ($a + $b);
        $payload = json_encode([
            'e' => time() + 600,
            'h' => hash_hmac('sha256', $answer, $this->signKey()),
        ]);

        $encoded = $this->b64url((string) $payload);
        $sig     = hash_hmac('sha256', $encoded, $this->signKey());

        return [
            'question' => "{$a} + {$b} = ?",
            'token'    => $encoded . '.' . $sig,
        ];
    }

    public function verifyCaptcha(string $token, string $answer): bool
    {
        if ($token === '' || $answer === '') {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$encoded, $sig] = $parts;
        if (! hash_equals(hash_hmac('sha256', $encoded, $this->signKey()), $sig)) {
            return false;
        }

        $payload = json_decode($this->b64urlDecode($encoded) ?? '', true);
        if (! is_array($payload) || ! isset($payload['e'], $payload['h'])) {
            return false;
        }
        if ((int) $payload['e'] < time()) {
            return false;
        }

        return hash_equals((string) $payload['h'], hash_hmac('sha256', trim($answer), $this->signKey()));
    }

    // =====================================================================
    // reCAPTCHA (opsional)
    // =====================================================================

    public function recaptchaEnabled(): bool
    {
        $p = $this->getProfile();

        return $p['recaptcha_site_key'] !== '' && $p['recaptcha_secret_key'] !== '';
    }

    protected function verifyRecaptcha(string $secret, string $token): bool
    {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $result = json_decode((string) $body, true);

        return is_array($result) && ($result['success'] ?? false) === true;
    }

    // =====================================================================
    // Simpan inquiry
    // =====================================================================

    /**
     * Validasi lengkap lalu simpan. Melempar InvalidArgumentException
     * dengan pesan siap tampil bila validasi gagal.
     */
    public function submitInquiry(array $input, string $ip, string $userAgent): int
    {
        // 0. Honeypot: field "website" harus kosong.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            throw new \InvalidArgumentException('Form tidak valid.');
        }

        // 1. Data wajib.
        $name    = trim((string) ($input['name'] ?? ''));
        $email   = trim((string) ($input['email'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $phone   = trim((string) ($input['phone'] ?? ''));

        if ($name === '' || mb_strlen($name) > 191) {
            throw new \InvalidArgumentException('Nama wajib diisi (maks 190 karakter).');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            throw new \InvalidArgumentException('Email tidak valid.');
        }
        if (mb_strlen($message) < 10 || mb_strlen($message) > 5000) {
            throw new \InvalidArgumentException('Pesan harus 10-5000 karakter.');
        }
        if ($phone !== '' && strlen($phone) > 32) {
            throw new \InvalidArgumentException('Nomor telepon terlalu panjang.');
        }

        // 2. Captcha.
        if ($this->recaptchaEnabled()) {
            $secret = $this->getProfile()['recaptcha_secret_key'];
            if (! $this->verifyRecaptcha($secret, (string) ($input['recaptcha_token'] ?? ''))) {
                throw new \InvalidArgumentException('Verifikasi reCAPTCHA gagal.');
            }
        } else {
            if (! $this->verifyCaptcha(
                (string) ($input['captcha_token'] ?? ''),
                (string) ($input['captcha_answer'] ?? '')
            )) {
                throw new \InvalidArgumentException('Jawaban captcha salah atau kedaluwarsa.');
            }
        }

        // 3. Simpan.
        $this->db->table('inquiries')->insert([
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone !== '' ? $phone : null,
            'message'    => $message,
            'ip'         => substr($ip, 0, 45),
            'user_agent' => substr($userAgent, 0, 255),
            'status'     => 'new',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    public function list(int $limit = 50): array
    {
        return $this->db->table('inquiries')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }
}
