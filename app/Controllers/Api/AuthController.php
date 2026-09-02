<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

class AuthController extends BaseApiController
{
    /**
     * Login. Bila admin mengaktifkan custom login path (settings.login_slug),
     * endpoint ini HANYA menerima URL /api/auth/login/{slug}; panggilan ke
     * path default dibalas 404 agar scanner bot tidak menemukan target.
     */
    public function login(?string $slug = null): ResponseInterface
    {
        $requiredSlug = (new \App\Services\SettingService())->getLoginSlug();
        if ($requiredSlug !== '' && $slug !== $requiredSlug) {
            // Respons identik dengan route tidak ditemukan.
            return $this->respondJson([
                'success' => false,
                'message' => 'Route tidak ditemukan.',
            ], 404);
        }

        $input = $this->input();
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->fail('Email dan password wajib diisi.');
        }

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('email', $email)->get()->getRowArray();

        if (! $user || ! password_verify($password, $user['password'])) {
            return $this->fail('Email atau password salah.', 401);
        }

        session()->regenerate(); // cegah session fixation

        session()->set([
            'user_id'    => (int) $user['id'],
            'user_name'  => $user['name'],
            'user_email' => $user['email'],
            'user_role'  => $user['role'],
        ]);

        // Pilih workspace default (terkecil id)
        $workspace = $db->table('workspace_users')
            ->where('user_id', $user['id'])
            ->orderBy('workspace_id', 'ASC')
            ->get()
            ->getRowArray();

        if ($workspace) {
            session()->set('workspace_id', (int) $workspace['workspace_id']);
        }

        // Notifikasi email login (bisa dimatikan per user di Settings).
        try {
            (new \App\Services\LoginNotifyService())->notify(
                $user,
                $this->request->getIPAddress(),
                (string) $this->request->getUserAgent()
            );
        } catch (\Throwable $e) {
            log_message('error', '[Auth] Notifikasi login gagal: ' . $e->getMessage());
        }

        return $this->success([
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'workspace_id' => session()->get('workspace_id'),
        ], 'Login berhasil');
    }

    public function logout(): ResponseInterface
    {
        session()->destroy();

        return $this->success(null, 'Logout berhasil');
    }

    /**
     * Token CSRF untuk double-submit via header X-CSRF-TOKEN.
     * Cookie CSRF dibuat HttpOnly (konfigurasi Cookie), jadi frontend
     * mengambil nilainya lewat endpoint ini; CORS membatasi pembacaan
     * lintas origin sehingga pola ini tetap aman.
     */
    public function csrf(): ResponseInterface
    {
        $token = service('security')->getHash();
        if ($token === null) {
            $token = service('security')->generateHash();
        }

        return $this->success(['token' => $token]);
    }

    public function me(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();

        $workspaceId = session()->get('workspace_id');
        $workspaceRole = null;
        if ($workspaceId) {
            $workspaceRole = (new \App\Services\RbacService($db))->roleInWorkspace($userId, (int) $workspaceId);
        }

        return $this->success([
            'id'              => (int) $user['id'],
            'name'            => $user['name'],
            'email'           => $user['email'],
            'role'            => $user['role'],
            'workspace_id'    => $workspaceId,
            'workspace_role'  => $workspaceRole,
            'login_notify'    => (int) ($user['login_notify'] ?? 1),
        ]);
    }

    /**
     * Preferensi akun: GET api/user/preferences
     */
    public function preferences(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $user = \Config\Database::connect()
            ->table('users')->select('login_notify')->where('id', $userId)->get()->getRowArray();

        return $this->success([
            'login_notify' => (int) ($user['login_notify'] ?? 1),
        ]);
    }

    /**
     * Simpan preferensi akun: PUT api/user/preferences {login_notify: bool}
     */
    public function savePreferences(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $input = $this->input();
        if (! array_key_exists('login_notify', $input)) {
            return $this->fail('Tidak ada preferensi yang dikirim.');
        }

        \Config\Database::connect()
            ->table('users')
            ->where('id', $userId)
            ->update([
                'login_notify' => ! empty($input['login_notify']) ? 1 : 0,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

        return $this->success(['login_notify' => ! empty($input['login_notify']) ? 1 : 0], 'Preferensi disimpan');
    }

    /**
     * Keamanan: baca konfigurasi login path (GET api/security/login-path).
     */
    public function loginPath(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        return $this->success([
            'slug'      => (new \App\Services\SettingService())->getLoginSlug(),
            'protected' => (new \App\Services\SettingService())->getLoginSlug() !== '',
        ]);
    }

    /**
     * PUT api/user/password — ganti password (wajib password lama).
     */
    public function changePassword(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $input = $this->input();
        $current = (string) ($input['current_password'] ?? '');
        $new     = (string) ($input['new_password'] ?? '');

        if ($new === '' || strlen($new) < 8) {
            return $this->fail('Password baru minimal 8 karakter.');
        }
        if ($new === $current && $current !== '') {
            return $this->fail('Password baru tidak boleh sama dengan password lama.');
        }

        $db   = \Config\Database::connect();
        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        if (! $user || ! password_verify($current, $user['password'])) {
            return $this->fail('Password saat ini salah.', 403);
        }

        $db->table('users')->where('id', $userId)->update([
            'password'   => password_hash($new, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, 'Password berhasil diganti');
    }

    /**
     * POST api/user/email/request-change — ganti email dengan verifikasi.
     * Simpan pending_email + token HMAC (1 jam), kirim link verifikasi.
     */
    public function requestEmailChange(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $input = $this->input();
        $password  = (string) ($input['current_password'] ?? '');
        $newEmail  = strtolower(trim((string) ($input['new_email'] ?? '')));

        $db   = \Config\Database::connect();
        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();

        if (! $user || ! password_verify($password, $user['password'])) {
            return $this->fail('Password saat ini salah.', 403);
        }
        if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Email baru tidak valid.');
        }
        if ($db->table('users')->where('email', $newEmail)->countAllResults()) {
            return $this->fail('Email sudah dipakai akun lain.');
        }

        // Token HMAC stateless: {uid, email, exp} ditandatangani.
        $exp      = time() + 3600;
        $payloadV = json_encode(['u' => $userId, 'e' => $newEmail, 'x' => $exp]);
        try {
            $signKey = bin2hex((string) service('encrypter')->getKey());
        } catch (\Throwable $e) {
            $signKey = 'insecure-dev-key';
        }
        $payload = rtrim(strtr(base64_encode((string) $payloadV), '+/', '-_'), '=');
        $sig     = hash_hmac('sha256', $payload, $signKey);
        $token   = $payload . '.' . $sig;

        $db->table('users')->where('id', $userId)->update([
            'pending_email'         => $newEmail,
            'pending_email_token'   => $sig,
            'pending_email_expires' => date('Y-m-d H:i:s', $exp),
        ]);

        $link = rtrim(config('App')->baseURL ?: '', '/')
            . '/api/user/email/verify?token=' . urlencode($token);

        // Kirim ke email BARU (best-effort; link tetap dikembalikan untuk
        // self-hosted tanpa SMTP).
        $sent = false;
        try {
            $mailer = service('email');
            $mailer->setTo($newEmail);
            $mailer->setSubject('[FlowForge] Verifikasi ganti email');
            $mailer->setMessage("Klik tautan berikut untuk konfirmasi email baru Anda (berlaku 1 jam):\n\n" . $link);
            $sent = (bool) $mailer->send();
        } catch (\Throwable $e) {
            log_message('error', '[Auth] Kirim verifikasi email gagal: ' . $e->getMessage());
        }

        return $this->success([
            'verification_link' => $sent ? null : $link,
            'expires_at'        => date('Y-m-d H:i:s', $exp),
            'emailed'           => $sent,
        ], $sent
            ? 'Link verifikasi dikirim ke email baru Anda.'
            : 'SMTP belum terkonfigurasi — gunakan link verifikasi di bawah secara manual.');
    }

    /**
     * GET api/user/email/verify?token= — commit ganti email.
     */
    public function verifyEmailChange(): ResponseInterface
    {
        $token = (string) ($this->request->getGet('token') ?? '');
        if ($token === '' || substr_count($token, '.') !== 1) {
            return $this->fail('Token tidak valid.', 422);
        }

        [$payloadB64, $sig] = explode('.', $token);
        try {
            $signKey = bin2hex((string) service('encrypter')->getKey());
        } catch (\Throwable $e) {
            $signKey = 'insecure-dev-key';
        }
        if (! hash_equals(hash_hmac('sha256', $payloadB64, $signKey), $sig)) {
            return $this->fail('Token tidak valid.', 422);
        }

        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/') ?: '', true) ?: '', true);
        if (! is_array($payload) || (int) ($payload['x'] ?? 0) < time()) {
            return $this->fail('Token kedaluwarsa. Minta ulang ganti email.', 422);
        }

        $uid      = (int) ($payload['u'] ?? 0);
        $newEmail = strtolower(trim((string) ($payload['e'] ?? '')));
        if (! $uid || ! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Token tidak valid.', 422);
        }

        $db = \Config\Database::connect();
        if ($db->table('users')->where('email', $newEmail)->where('id !=', $uid)->countAllResults()) {
            return $this->fail('Email sudah dipakai akun lain.', 409);
        }

        $db->table('users')->where('id', $uid)->update([
            'email'                 => $newEmail,
            'pending_email'         => null,
            'pending_email_token'   => null,
            'pending_email_expires' => null,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        session()->set('user_email', $newEmail);

        return $this->respondJson([
            'success' => true,
            'message' => 'Email berhasil diverifikasi & diganti.',
        ]);
    }

    /**
     * Keamanan: atur custom login path (PUT api/security/login-path).
     * Body: {slug: "path-rahasia"} — kosongkan untuk kembali ke default.
     */
    public function saveLoginPath(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }
        if ((string) session()->get('user_role') !== 'owner') {
            return $this->fail('Hanya owner yang boleh mengubah pengaturan ini.', 403);
        }

        $input = $this->input();
        $svc   = new \App\Services\SettingService();

        if (! $svc->setLoginSlug((string) ($input['slug'] ?? ''))) {
            return $this->fail('Path tidak valid. Gunakan 4-64 karakter: huruf kecil, angka, dash, atau underscore.');
        }

        return $this->success([
            'slug'      => $svc->getLoginSlug(),
            'protected' => $svc->getLoginSlug() !== '',
        ], 'Login path disimpan');
    }

    // ==================================================================
    // Google OAuth (SSO ringan)
    // ==================================================================

    private function oauthBaseUri(): string
    {
        return rtrim(config('App')->baseURL ?: '', '/') . '/api/auth/oauth/google/callback';
    }

    /**
     * GET api/auth/oauth/status — dipakai halaman login (publik).
     */
    public function oauthStatus(): ResponseInterface
    {
        return $this->respondJson([
            'success' => true,
            'data'    => ['google_enabled' => (new \App\Services\GoogleOauthService())->isConfigured()],
        ]);
    }

    /**
     * GET api/security/oauth-google — baca kredensial OAuth (owner).
     */
    public function oauthSettings(): ResponseInterface
    {
        if ((string) session()->get('user_role') !== 'owner') {
            return $this->fail('Hanya owner yang boleh melihat pengaturan ini.', 403);
        }

        $svc = new \App\Services\SettingService();

        return $this->success([
            'oauth_google_client_id'     => (string) ($svc->get('oauth_google_client_id', '') ?? ''),
            'oauth_google_client_secret' => (string) ($svc->get('oauth_google_client_secret', '') ?? ''),
            'registration_mode'          => (string) ($svc->get('oauth_registration_mode', 'off') ?? 'off'),
            'redirect_uri'               => rtrim(config('App')->baseURL ?: '', '/') . '/api/auth/oauth/google/callback',
        ]);
    }

    /**
     * PUT api/security/oauth-google — simpan kredensial OAuth (owner).
     */
    public function saveOauthSettings(): ResponseInterface
    {
        if ((string) session()->get('user_role') !== 'owner') {
            return $this->fail('Hanya owner yang boleh mengubah pengaturan ini.', 403);
        }

        $input = $this->input();
        $svc   = new \App\Services\SettingService();
        $svc->set('oauth_google_client_id', trim((string) ($input['oauth_google_client_id'] ?? '')));
        $svc->set('oauth_google_client_secret', trim((string) ($input['oauth_google_client_secret'] ?? '')));
        $mode = in_array($input['registration_mode'] ?? '', ['off', 'member-auto'], true) ? $input['registration_mode'] : 'off';
        $svc->set('oauth_registration_mode', (string) $mode);

        return $this->success([
            'google_enabled' => (new \App\Services\GoogleOauthService())->isConfigured(),
            'registration_mode' => (new \App\Services\SettingService())->get('oauth_registration_mode', 'off'),
        ], 'Pengaturan Google OAuth disimpan');
    }

    /**
     * GET api/auth/oauth/google/start — redirect ke consent Google.
     */
    public function oauthStart(): ResponseInterface
    {
        $oauth = new \App\Services\GoogleOauthService();
        if (! $oauth->isConfigured()) {
            return $this->respondJson([
                'success' => false,
                'message' => 'Login Google belum dikonfigurasi di Pengaturan.',
            ], 404);
        }

        $state = $oauth->issueState()['state'];
        session()->set('oauth_state', $state);

        return $this->response
            ->redirect($oauth->authUrl($this->oauthBaseUri(), $state));
    }

    /**
     * GET api/auth/oauth/google/callback?code&state
     * Tukar code → profil → login → redirect ke SPA.
     */
    public function oauthCallback(): ResponseInterface
    {
        $oauth  = new \App\Services\GoogleOauthService();
        $spaUrl = rtrim(config('App')->baseURL ?: '/', '/') . '/';

        $fail = static function (string $msg) use ($spaUrl): ResponseInterface {
            return service('response')
                ->redirect($spaUrl . '#/login?oauth_error=' . rawurlencode($msg));
        };

        if (! $oauth->isConfigured()) {
            return $fail('Login Google belum dikonfigurasi.');
        }

        $state = (string) ($this->request->getGet('state') ?? '');
        $code  = (string) ($this->request->getGet('code') ?? '');

        if (! $oauth->verifyState($state)) {
            return $fail('State OAuth tidak valid atau kedaluwarsa.');
        }
        if ($code === '') {
            return $fail('Kode otorisasi tidak diterima.');
        }

        $profile = $oauth->exchangeCode($code, $this->oauthBaseUri());
        if (! $profile || empty($profile['email'])) {
            return $fail('Gagal memverifikasi akun Google.');
        }

        $user = $oauth->loginWithGoogleProfile(is_array($profile) ? $profile : []);
        if (! $user) {
            return $fail('Email Google belum terdaftar. Hubungi admin untuk dibuatkan akun.');
        }

        session()->regenerate();
        session()->set([
            'user_id'      => (int) $user['id'],
            'user_name'    => $user['name'],
            'user_email'   => $user['email'],
            'user_role'    => $user['role'],
        ]);

        $ws = \Config\Database::connect()
            ->table('workspace_users')->where('user_id', (int) $user['id'])
            ->orderBy('workspace_id', 'ASC')->get()->getRowArray();
        if ($ws) {
            session()->set('workspace_id', (int) $ws['workspace_id']);
        }

        return $this->response->redirect($spaUrl);
    }

    public function selectWorkspace(): ResponseInterface
    {
        $input = $this->input();
        $workspaceId = (int) ($input['workspace_id'] ?? 0);

        if (! $this->hasAccessToWorkspace($workspaceId)) {
            return $this->fail('Tidak punya akses ke projek ini.', 403);
        }

        session()->set('workspace_id', $workspaceId);

        $workspaceRole = (new \App\Services\RbacService())->roleInWorkspace($this->userId(), $workspaceId);

        return $this->success([
            'workspace_id'    => $workspaceId,
            'workspace_role'  => $workspaceRole,
        ]);
    }
}
