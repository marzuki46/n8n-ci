<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Rate-limit sederhana untuk endpoint publik yang sensitif (mis. login).
 * Memakai Cache (file) per IP+identifier; pada keberhasilan (200) counter
 * di-reset agar user sah tidak terkunci.
 */
class ThrottleFilter implements FilterInterface
{
    /**
     * Jumlah maksimum percobaan dalam satu jendela waktu.
     * Dapat disesuaikan lewat .env: THROTTLE_MAX_ATTEMPTS.
     */
    protected function maxAttempts(): int
    {
        $value = env('THROTTLE_MAX_ATTEMPTS');
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return ($value !== false && $value > 0) ? $value : 5;
    }

    /**
     * Jendela waktu (detik) untuk rate-limit.
     * Dapat disesuaikan lewat .env: THROTTLE_WINDOW.
     */
    protected function window(): int
    {
        $value = env('THROTTLE_WINDOW');
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return ($value !== false && $value > 0) ? $value : 900;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $cache    = service('cache');
        $attempts = (int) $cache->get($this->key($request));

        if ($attempts >= $this->maxAttempts()) {
            return service('response')
                ->setStatusCode(429)
                ->setContentType('application/json')
                ->setHeader('Retry-After', (string) $this->window())
                ->setBody(json_encode([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.',
                ], JSON_UNESCAPED_UNICODE));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $cache = service('cache');
        $key   = $this->key($request);

        if ($response->getStatusCode() === 200) {
            // Berhasil → reset percobaan
            $cache->delete($key);

            return;
        }

        if ($response->getStatusCode() === 401) {
            // Login gagal → naikkan counter
            $attempts = (int) $cache->get($key);
            $cache->save($key, $attempts + 1, $this->window());
        }
    }

    protected function key(RequestInterface $request): string
    {
        $identifier = '';
        if ($request->getHeaderLine('Content-Type') !== '' && strpos($request->getHeaderLine('Content-Type'), 'json') !== false) {
            $json       = $request->getJSON(true);
            $identifier = is_array($json) ? (string) ($json['email'] ?? '') : '';
        }
        if ($identifier === '') {
            $identifier = (string) $request->getPost('email');
        }

        return 'throttle_' . sha1($request->getIPAddress() . '|' . strtolower(trim($identifier)));
    }
}
