<?php

namespace App\Services;

/**
 * Google OAuth2 login (SSO ringan).
 *
 * Alur: start() buat URL consent + state bertanda tangan HMAC (10 menit),
 * callback tukar code → id_token, decode email, lalu login/buat akun.
 *
 * exchangeCode() protected agar test bisa membuat stub.
 */
class GoogleOauthService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function isConfigured(): bool
    {
        $s = new SettingService();

        return trim((string) $s->get('oauth_google_client_id', '')) !== ''
            && trim((string) $s->get('oauth_google_client_secret', '')) !== '';
    }

    public function credentials(): array
    {
        $s = new SettingService();

        return [
            'client_id'     => trim((string) $s->get('oauth_google_client_id', '')),
            'client_secret' => trim((string) $s->get('oauth_google_client_secret', '')),
        ];
    }

    // ==================================================================
    // State HMAC (stateless)
    // ==================================================================

    private function signKey(): string
    {
        try {
            return bin2hex((string) service('encrypter')->getKey());
        } catch (\Throwable $e) {
            return 'insecure-dev-key';
        }
    }

    public function issueState(): array
    {
        $nonce   = bin2hex(random_bytes(8));
        $payload = json_encode(['n' => $nonce, 'e' => time() + 600]);
        $encoded = rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
        $sig     = hash_hmac('sha256', $encoded, $this->signKey());

        return ['state' => $encoded . '.' . $sig];
    }

    public function verifyState(string $state): bool
    {
        if ($state === '' || substr_count($state, '.') !== 1) {
            return false;
        }

        [$encoded, $sig] = explode('.', $state);
        if (! hash_equals(hash_hmac('sha256', $encoded, $this->signKey()), $sig)) {
            return false;
        }

        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);

        return is_array($payload) && (int) ($payload['e'] ?? 0) >= time();
    }

    // ==================================================================
    // Token & profil
    // ==================================================================

    /**
     * Tukar authorization code menjadi profil user Google.
     * Protected agar bisa di-stub di test.
     */
    protected function exchangeCode(string $code, string $redirectUri): ?array
    {
        $cred = $this->credentials();

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'client_id'     => $cred['client_id'],
                'client_secret' => $cred['client_secret'],
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ]),
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            log_message('error', '[OAuth] token: ' . $err);

            return null;
        }

        $token = json_decode((string) $body, true);
        $idToken = $token['id_token'] ?? null;
        if (! $idToken) {
            return null;
        }

        // Decode payload JWT (bagian tengah).
        $parts = explode('.', (string) $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);

        return json_decode((string) $payloadJson, true);
    }

    /**
     * Mode registrasi: 'off' = hanya email terdaftar; 'member-auto' =
     * akun Google baru otomatis dibuat sebagai member workspace pertama.
     */
    public function autoRegisterEnabled(): bool
    {
        return (new SettingService())->get('oauth_registration_mode', 'off') === 'member-auto';
    }

    /**
     * Login / buat akun dari profil Google. Kembalikan baris users.
     */
    public function loginWithGoogleProfile(array $profile, ?bool $autoRegister = null): ?array
    {
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = $this->db->table('users')->where('email', $email)->get()->getRowArray();

        if ($user && (int) ($user['status'] ?? 1) === 0) {
            return null; // akun dinonaktifkan admin.
        }

        if (! $user) {
            // Registrasi baru hanya bila mode auto terbuka.
            if ($autoRegister === null) {
                $autoRegister = $this->autoRegisterEnabled();
            }
            if (! $autoRegister) {
                return null;
            }

            $name = trim((string) ($profile['name'] ?? '')) ?: explode('@', $email)[0];
            $this->db->table('users')->insert([
                'name'       => mb_substr($name, 0, 190),
                'email'      => $email,
                'password'   => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'role'       => 'member',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $uid = (int) $this->db->insertID();

            // Masukkan ke workspace pertama yang ada (agar langsung punya akses dasar).
            $firstWs = $this->db->table('workspaces')->orderBy('id', 'ASC')->limit(1)->get()->getRowArray();
            if ($firstWs) {
                $this->db->table('workspace_users')->insert([
                    'workspace_id' => (int) $firstWs['id'],
                    'user_id'      => $uid,
                    'role'         => 'member',
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
            }

            $user = $this->db->table('users')->where('id', $uid)->get()->getRowArray();
        }

        return $user ?: null;
    }

    /**
     * URL consent Google.
     */
    public function authUrl(string $redirectUri, string $state): string
    {
        $clientId = $this->credentials()['client_id'];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }
}
