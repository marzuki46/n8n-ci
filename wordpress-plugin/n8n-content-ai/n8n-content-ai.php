<?php
/**
 * Plugin Name: n8n-CI Content AI
 * Description: Generate & lanjutkan konten WordPress memakai AI dari n8n-CI (CodeIgniter). Scan konten pendek, buat draft baru, rewrite/expand/polish — semuanya lewat API n8n-CI Anda.
 * Version:     1.0.0
 * Author:      n8n-CI
 * Text Domain: n8n-ci-content-ai
 */

if (! defined('ABSPATH')) {
    exit;
}

define('NCAI_VERSION', '1.0.0');
define('NCAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NCAI_OPTION_KEY', 'ncai_settings');

require_once NCAI_PLUGIN_DIR . 'includes/class-ncai-api.php';
require_once NCAI_PLUGIN_DIR . 'includes/class-ncai-admin.php';

/**
 * Aktivasi: isi default opsi.
 */
function ncai_activate(): void
{
    $existing = get_option(NCAI_OPTION_KEY, []);
    if (! is_array($existing)) {
        $existing = [];
    }

    $defaults = [
        'api_url'         => '',
        'api_key'         => '',
        'min_words'       => 600,
        'language'        => 'auto',
        'company_profile' => '',
    ];

    update_option(NCAI_OPTION_KEY, array_merge($defaults, $existing));
}
register_activation_hook(__FILE__, 'ncai_activate');

/**
 * Ambil seluruh setting.
 */
function ncai_settings(): array
{
    $settings = get_option(NCAI_OPTION_KEY, []);
    if (! is_array($settings)) {
        $settings = [];
    }

    $defaults = [
        'api_url'         => '',
        'api_key'         => '',
        'min_words'       => 600,
        'language'        => 'auto',
        'company_profile' => '',
    ];

    return wp_parse_args($settings, $defaults);
}

/**
 * Bahasa efektif: auto = ikut locale WP (id_ID → id, en_US → en).
 */
function ncai_effective_language(array $s): string
{
    if (($s['language'] ?? 'auto') !== 'auto') {
        return $s['language'] === 'en' ? 'en' : 'id';
    }

    return str_starts_with(get_locale(), 'en') ? 'en' : 'id';
}

/**
 * Hitung jumlah kata lokal (tanpa API).
 */
function ncai_count_words(string $html): int
{
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) $html)));

    return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
}

NCAI_Admin::init();
