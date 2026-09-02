<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Header keamanan untuk semua respons.
 * - X-Frame-Options SAMEORIGIN : cegah clickjacking
 * - X-Content-Type-Options     : cegah MIME-sniffing
 * - Referrer-Policy            : batas kebocoran URL internal
 * - Permissions-Policy         : matikan API browser yang tak dipakai
 * - HSTS                       : hanya saat request https
 */
class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Tidak ada pemrosesan before.
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
