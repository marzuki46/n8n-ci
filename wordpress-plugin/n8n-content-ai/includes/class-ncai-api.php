<?php
/**
 * Klien API n8n-CI (endpoint /api/v1/wp/*).
 *
 * @package ncai
 */

if (! defined('ABSPATH')) {
    exit;
}

class NCAI_Api
{
    /**
     * Panggil endpoint n8n-CI.
     *
     * @param string $path   mis. 'wp/generate'
     * @param array  $body   payload JSON
     * @param int    $timeout detik
     * @return array{ok:bool,data:array,message:string}
     */
    public static function call(string $path, array $body = [], string $method = 'POST', int $timeout = 120): array
    {
        $s   = ncai_settings();
        $url = untrailingslashit($s['api_url']);
        if ($url === '') {
            return ['ok' => false, 'data' => [], 'message' => 'URL API belum diisi di halaman Settings.'];
        }
        if (! preg_match('#/api/v1$#', $url)) {
            $url .= '/api/v1';
        }

        $args = [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'      => 'application/json',
                'X-API-Key'         => (string) $s['api_key'],
                'X-Requested-With'  => 'xmlhttprequest',
            ],
        ];

        if ($method === 'POST') {
            $args['body'] = wp_json_encode($body);
            $response = wp_remote_post($url . '/' . ltrim($path, '/'), $args);
        } else {
            $response = wp_remote_get($url . '/' . ltrim($path, '/'), $args);
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'data' => [], 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if (! is_array($json)) {
            return ['ok' => false, 'data' => [], 'message' => "Respons tidak valid (HTTP {$code}): " . substr($raw, 0, 200)];
        }

        return [
            'ok'      => ($json['success'] ?? false) === true && $code < 400,
            'data'    => (array) ($json['data'] ?? []),
            'message' => (string) ($json['message'] ?? ''),
            'code'    => $code,
        ];
    }

    /**
     * Status koneksi + credential AI.
     */
    public static function status(): array
    {
        return self::call('wp/status', [], 'GET', 30);
    }
}
