<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Rate-limit generik per IP untuk endpoint publik (webhook, API v1).
 * Memakai Cache (file). Argument filter: "max:window" mis. "60:60"
 * (60 request per 60 detik). Default: 60 req / 60 detik per IP.
 *
 * Terapkan per-grup rute:
 *   $routes->group('webhook', ['filter' => ['cors', 'ratelimit:60:60']], ...)
 */
class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        [$max, $window] = $this->parseArguments($arguments);

        $cache = service('cache');
        $key   = 'ratelimit_' . sha1($request->getIPAddress() . '|' . $request->getPath());

        $current = (int) $cache->get($key);
        $now     = time();

        // Simpan [hitung, mulai_waktu] agar jendela geser sederhana:
        // bila sudah lewat window, mulai ulang.
        $stored = $cache->get($key . '_meta');
        $start  = $stored ? (int) $stored : $now;

        if (($now - $start) >= $window) {
            $start = $now;
            $current = 0;
            $cache->save($key . '_meta', $start, $window);
        }

        if ($current >= $max) {
            return service('response')
                ->setStatusCode(429)
                ->setContentType('application/json')
                ->setHeader('Retry-After', (string) max(1, $start + $window - $now))
                ->setBody(json_encode([
                    'success' => false,
                    'message' => 'Terlalu banyak permintaan. Coba lagi nanti.',
                ], JSON_UNESCAPED_UNICODE));
        }

        $cache->save($key . '_meta', $start, $window);
        $cache->save($key, $current + 1, $window);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    protected function parseArguments($arguments): array
    {
        $max    = 60;
        $window = 60;

        if (is_string($arguments) && $arguments !== '') {
            $parts = explode(':', $arguments);
            if (isset($parts[0]) && ctype_digit($parts[0])) {
                $max = (int) $parts[0];
            }
            if (isset($parts[1]) && ctype_digit($parts[1])) {
                $window = (int) $parts[1];
            }
        }

        return [max(1, $max), max(1, $window)];
    }
}
