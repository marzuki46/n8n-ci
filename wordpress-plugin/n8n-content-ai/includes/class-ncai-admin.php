<?php
/**
 * Halaman admin + AJAX handlers.
 *
 * @package ncai
 */

if (! defined('ABSPATH')) {
    exit;
}

class NCAI_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);

        add_action('wp_ajax_ncai_status', [__CLASS__, 'ajaxStatus']);
        add_action('wp_ajax_ncai_save_settings', [__CLASS__, 'ajaxSaveSettings']);
        add_action('wp_ajax_ncai_scan', [__CLASS__, 'ajaxScan']);
        add_action('wp_ajax_ncai_continue_one', [__CLASS__, 'ajaxContinueOne']);
        add_action('wp_ajax_ncai_generate', [__CLASS__, 'ajaxGenerate']);
        add_action('wp_ajax_ncai_publish', [__CLASS__, 'ajaxPublish']);
        add_action('wp_ajax_ncai_update_post', [__CLASS__, 'ajaxUpdatePost']);
    }

    public static function menu(): void
    {
        add_menu_page('n8n-CI Content AI', 'Content AI', 'manage_options', 'ncai', [__CLASS__, 'pageSettings'], 'dashicons-superhero-alt');
        add_submenu_page('ncai', 'Settings', 'Settings', 'manage_options', 'ncai', [__CLASS__, 'pageSettings']);
        add_submenu_page('ncai', 'Scan Konten', 'Scan Konten', 'manage_options', 'ncai-scan', [__CLASS__, 'pageScan']);
        add_submenu_page('ncai', 'Buat Konten', 'Buat Konten', 'manage_options', 'ncai-create', [__CLASS__, 'pageCreate']);
        add_submenu_page('ncai', 'Lanjutkan Konten', 'Lanjutkan Konten', 'manage_options', 'ncai-continue', [__CLASS__, 'pageContinue']);
    }

    public static function assets($hook): void
    {
        if (strpos($hook, 'ncai') === false) {
            return;
        }
        wp_add_inline_style('wp-admin', file_get_contents(NCAI_PLUGIN_DIR . 'assets/admin.css'));
        wp_add_inline_script('jquery', file_get_contents(NCAI_PLUGIN_DIR . 'assets/admin.js'));
    }

    // =====================================================================
    // AJAX
    // =====================================================================

    private static function guard(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Tidak diizinkan.'], 403);
        }
        check_ajax_referer('ncai_nonce', 'nonce');
    }

    public static function ajaxStatus(): void
    {
        self::guard();

        $res = NCAI_Api::status();
        $s   = ncai_settings();

        wp_send_json_success([
            'api'      => $res,
            'settings' => [
                'min_words' => (int) $s['min_words'],
                'language'  => $s['language'],
            ],
        ]);
    }

    public static function ajaxSaveSettings(): void
    {
        self::guard();

        $s = ncai_settings();
        $s['api_url']         = esc_url_raw(wp_unslash($_POST['api_url'] ?? ''));
        $s['api_key']         = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        $s['min_words']       = max(50, (int) ($_POST['min_words'] ?? 600));
        $s['language']        = in_array($_POST['language'] ?? '', ['auto', 'id', 'en'], true) ? $_POST['language'] : 'auto';
        $s['company_profile'] = sanitize_textarea_field(wp_unslash($_POST['company_profile'] ?? ''));

        update_option(NCAI_OPTION_KEY, $s);

        $status = NCAI_Api::status();

        wp_send_json_success([
            'message' => 'Pengaturan disimpan.',
            'status'  => $status,
        ]);
    }

    /**
     * Scan konten: post, page, produk WooCommerce (bila ada), tag & kategori.
     */
    public static function ajaxScan(): void
    {
        self::guard();

        $types = ['post', 'page'];
        if (post_type_exists('product')) {
            $types[] = 'product';
        }

        $q = new WP_Query([
            'post_type'      => $types,
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 200,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);

        $rows = [];
        foreach ($q->posts as $p) {
            $words = ncai_count_words((string) $p->post_content);
            $rows[] = [
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'type'   => $p->post_type,
                'words'  => $words,
                'short'  => $words < (int) ncai_settings()['min_words'],
                'edit'   => get_edit_post_link($p->ID, 'raw'),
            ];
        }
        wp_reset_postdata();

        $terms = [];
        foreach (get_tags(['hide_empty' => false]) as $t) {
            /* @var WP_Term $t */
            $terms[] = ['id' => (int) $t->term_id, 'title' => $t->name, 'type' => 'tag', 'taxonomy' => 'post_tag'];
        }
        foreach (get_categories(['hide_empty' => false]) as $t) {
            $terms[] = ['id' => (int) $t->term_id, 'title' => $t->name, 'type' => 'category', 'taxonomy' => 'category'];
        }

        wp_send_json_success(['posts' => $rows, 'terms' => $terms]);
    }

    /**
     * Jalankan Satu: /wp/continue untuk satu post.
     * Body: {post_id, action}
     */
    public static function ajaxContinueOne(): void
    {
        self::guard();

        $postId = (int) ($_POST['post_id'] ?? 0);
        $action = in_array($_POST['action_kind'] ?? '', ['rewrite', 'expand', 'polish'], true)
            ? $_POST['action_kind'] : 'expand';

        $post = get_post($postId);
        if (! $post) {
            wp_send_json_error(['message' => 'Post tidak ditemukan.'], 404);
        }

        $s = ncai_settings();
        $res = NCAI_Api::call('wp/continue', [
            'existing_content' => (string) $post->post_content,
            'existing_title'   => (string) $post->post_title,
            'language'         => ncai_effective_language($s),
            'company_profile'  => $s['company_profile'],
            'instructions'     => '',
            'action'           => $action,
        ]);

        if (! $res['ok']) {
            wp_send_json_error(['message' => $res['message'] ?: 'API gagal.'], 500);
        }

        wp_send_json_success([
            'content'    => $res['data']['content'] ?? '',
            'word_count' => (int) ($res['data']['word_count'] ?? 0),
        ]);
    }

    /**
     * Simpan hasil continue ke post.
     */
    public static function ajaxUpdatePost(): void
    {
        self::guard();

        $postId  = (int) ($_POST['post_id'] ?? 0);
        $content = wp_kses_post(wp_unslash($_POST['content'] ?? ''));

        $updated = wp_update_post([
            'ID'           => $postId,
            'post_content' => $content,
        ], true);

        if (is_wp_error($updated)) {
            wp_send_json_error(['message' => $updated->get_error_message()], 500);
        }

        wp_send_json_success([
            'word_count' => ncai_count_words($content),
            'edit_link'  => get_edit_post_link($postId, 'raw'),
        ]);
    }

    /**
     * Generate konten baru → preview.
     */
    public static function ajaxGenerate(): void
    {
        self::guard();

        $s = ncai_settings();

        $res = NCAI_Api::call('wp/generate', [
            'topic'           => sanitize_text_field(wp_unslash($_POST['topic'] ?? '')),
            'content_type'    => in_array($_POST['content_type'] ?? '', ['post', 'page', 'product'], true) ? $_POST['content_type'] : 'post',
            'language'        => ncai_effective_language($s),
            'min_words'       => max(50, (int) ($_POST['min_words'] ?? $s['min_words'])),
            'company_profile' => $s['company_profile'],
            'instructions'    => sanitize_textarea_field(wp_unslash($_POST['instructions'] ?? '')),
        ]);

        if (! $res['ok']) {
            wp_send_json_error(['message' => $res['message'] ?: 'API gagal.'], 500);
        }

        wp_send_json_success($res['data']);
    }

    /**
     * Terbitkan/ draft-kan hasil generate.
     */
    public static function ajaxPublish(): void
    {
        self::guard();

        $title   = sanitize_text_field(wp_unslash($_POST['title'] ?? 'Tanpa judul'));
        $content = wp_kses_post(wp_unslash($_POST['content'] ?? ''));
        $type    = in_array($_POST['post_type'] ?? '', ['post', 'page', 'product'], true) ? $_POST['post_type'] : 'post';
        $status  = ($_POST['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft';

        $args = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => $type === 'product' && post_type_exists('product') ? 'product' : $type,
            'post_author'  => get_current_user_id(),
        ];

        $postId = wp_insert_post($args, true);
        if (is_wp_error($postId)) {
            wp_send_json_error(['message' => $postId->get_error_message()], 500);
        }

        wp_send_json_success([
            'post_id'   => (int) $postId,
            'edit_link' => get_edit_post_link($postId, 'raw'),
            'view_link' => get_permalink($postId),
        ]);
    }

    // =====================================================================
    // Halaman admin
    // =====================================================================

    private static function nonceField(): string
    {
        return '<input type="hidden" id="ncai-nonce" value="' . esc_attr(wp_create_nonce('ncai_nonce')) . '" />';
    }

    public static function pageSettings(): void
    {
        $s = ncai_settings();
        ?>
        <div class="wrap" id="ncai-app">
            <h1>n8n-CI Content AI — Settings</h1>
            <?php echo self::nonceField(); // phpcs:ignore ?>
            <table class="form-table">
                <tr>
                    <th>URL API</th>
                    <td><input type="url" id="ncai-api-url" class="regular-text" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="https://app.domainku.id/api/v1" /></td>
                </tr>
                <tr>
                    <th>API Key</th>
                    <td><input type="password" id="ncai-api-key" class="regular-text" value="<?php echo esc_attr($s['api_key']); ?>" autocomplete="new-password" /></td>
                </tr>
                <tr>
                    <th>Min. Kata</th>
                    <td><input type="number" id="ncai-min-words" value="<?php echo esc_attr((string) $s['min_words']); ?>" min="50" step="10" /></td>
                </tr>
                <tr>
                    <th>Bahasa</th>
                    <td>
                        <select id="ncai-language">
                            <option value="auto" <?php selected($s['language'], 'auto'); ?>>Auto (ikut locale WP)</option>
                            <option value="id" <?php selected($s['language'], 'id'); ?>>Bahasa Indonesia</option>
                            <option value="en" <?php selected($s['language'], 'en'); ?>>English</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Company Profile</th>
                    <td><textarea id="ncai-company" rows="4" class="large-text"><?php echo esc_textarea($s['company_profile']); ?></textarea></td>
                </tr>
            </table>
            <p>
                <button class="button button-primary" id="ncai-save">Simpan &amp; Cek Status</button>
            </p>
            <div id="ncai-status-box"></div>
        </div>
        <?php
    }

    public static function pageScan(): void
    {
        ?>
        <div class="wrap" id="ncai-scan">
            <h1>Scan Konten Pendek</h1>
            <?php echo self::nonceField(); // phpcs:ignore ?>
            <p>Aksi per item: <select id="ncai-action"><option value="expand">Expand</option><option value="rewrite">Rewrite</option><option value="polish">Polish</option></select></p>
            <p><button class="button button-primary" id="ncai-scan-btn">Muat Daftar Konten</button></p>
            <div id="ncai-progress"></div>
            <table class="widefat striped" id="ncai-table">
                <thead><tr><th>ID</th><th>Judul</th><th>Tipe</th><th>Kata</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <?php
    }

    public static function pageCreate(): void
    {
        $s = ncai_settings();
        ?>
        <div class="wrap" id="ncai-create">
            <h1>Buat Konten Baru</h1>
            <?php echo self::nonceField(); // phpcs:ignore ?>
            <table class="form-table">
                <tr><th>Topik</th><td><input type="text" id="ncai-topic" class="large-text" /></td></tr>
                <tr><th>Tipe</th><td>
                    <select id="ncai-type">
                        <option value="post">Post</option>
                        <option value="page">Page</option>
                        <?php if (post_type_exists('product')) : ?><option value="product">Produk WooCommerce</option><?php endif; ?>
                    </select>
                </td></tr>
                <tr><th>Target Kata</th><td><input type="number" id="ncai-target" value="<?php echo esc_attr((string) $s['min_words']); ?>" min="50" step="50" /></td></tr>
                <tr><th>Instruksi</th><td><textarea id="ncai-instructions" rows="3" class="large-text"></textarea></td></tr>
            </table>
            <p><button class="button button-primary" id="ncai-generate">Generate Preview</button></p>
            <div id="ncai-preview"></div>
        </div>
        <?php
    }

    public static function pageContinue(): void
    {
        ?>
        <div class="wrap" id="ncai-continue">
            <h1>Lanjutkan Konten</h1>
            <?php echo self::nonceField(); // phpcs:ignore ?>
            <table class="form-table">
                <tr><th>Post ID</th><td><input type="number" id="ncai-post-id" /></td></tr>
                <tr><th>Aksi</th><td>
                    <select id="ncai-c-action">
                        <option value="rewrite">Rewrite</option>
                        <option value="expand" selected>Expand</option>
                        <option value="polish">Polish</option>
                    </select>
                </td></tr>
            </table>
            <p><button class="button button-primary" id="ncai-run-one">Jalankan</button></p>
            <div id="ncai-diff"></div>
        </div>
        <?php
    }
}
