<?php

namespace App\Services;

/**
 * Notifikasi email saat user berhasil login (IP + perangkat).
 * Bisa dimatikan per-user lewat kolom users.login_notify.
 *
 * sendEmail() sengaja protected agar test bisa membuat stub subclass.
 */
class LoginNotifyService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Panggil setelah login sukses. Best-effort: kegagalan email tidak
     * boleh menggagalkan login.
     */
    public function notify(array $user, string $ip, string $userAgent): void
    {
        if (! (int) ($user['login_notify'] ?? 1)) {
            return;
        }
        if (empty($user['email'])) {
            return;
        }

        $when  = date('d M Y, H:i') . ' UTC' . date('P');
        $device = $this->describeDevice($userAgent);
        $browserLabel = $device['browser'] . ' — ' . $device['os'];

        $body =
            "Login baru ke akun FlowForge Anda.\n\n" .
            "- Akun   : {$user['email']}\n" .
            "- Waktu  : {$when}\n" .
            "- IP     : {$ip}\n" .
            "- Perangkat: {$browserLabel}\n\n" .
            "Bila ini bukan Anda, segera ganti password dan periksa aktivitas akun.\n";

        try {
            $this->sendEmail((string) $user['email'], '[FlowForge] Login baru terdeteksi', $body);
        } catch (\Throwable $e) {
            log_message('error', '[LoginNotify] Gagal kirim email: ' . $e->getMessage());
        }
    }

    /**
     * Kirim email via CI Email service (konfigurasi SMTP dari .env).
     */
    protected function sendEmail(string $to, string $subject, string $message): bool
    {
        $email = service('email');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage(nl2br(str_replace("\n", "\n", $message)));

        return (bool) $email->send();
    }

    /**
     * Deskripsi ringkas perangkat dari User-Agent.
     */
    public function describeDevice(string $ua): array
    {
        $ua = (string) $ua;

        // Browser
        if (stripos($ua, 'Edg/') !== false) {
            $browser = 'Edge';
        } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
            $browser = 'Opera';
        } elseif (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) {
            $browser = 'Chrome';
        } elseif (stripos($ua, 'Firefox/') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($ua, 'Safari/') !== false) {
            $browser = 'Safari';
        } elseif ($ua === '') {
            $browser = 'Tidak dikenal';
        } else {
            $browser = 'Lainnya';
        }

        // OS
        if (stripos($ua, 'Windows NT') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $os = 'iOS';
        } elseif (stripos($ua, 'Mac OS X') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        } else {
            $os = 'Tidak dikenal';
        }

        return ['browser' => $browser, 'os' => $os];
    }
}
