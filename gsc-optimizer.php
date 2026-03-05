<?php
/**
 * Plugin Name: GSC Optimizer
 * Plugin URI:  https://hmarno.v.ua
 * Description: Порівнює кліки GSC за 7 днів, і автоматично оновлює сторінки, де кліки падають: переписує перший абзац через AI, додає таблицю, оновлює дату.
 * Version:     1.0.0
 * Author:      Hmara
 * Text Domain: gsc-optimizer
 * License:     GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GSC_OPT_VERSION', '1.0.0');
define('GSC_OPT_DIR', plugin_dir_path(__FILE__));
define('GSC_OPT_URL', plugin_dir_url(__FILE__));

// ── Autoload includes ─────────────────────────────────────────────────────────
require_once GSC_OPT_DIR . 'includes/class-gsc-api.php';
require_once GSC_OPT_DIR . 'includes/class-comparator.php';
require_once GSC_OPT_DIR . 'includes/class-ai-rewriter.php';
require_once GSC_OPT_DIR . 'includes/class-post-updater.php';

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook(__FILE__, 'gsc_opt_activate');
register_deactivation_hook(__FILE__, 'gsc_opt_deactivate');

function gsc_opt_activate()
{
    global $wpdb;
    $table = $wpdb->prefix . 'gsc_optimizer_log';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id      BIGINT UNSIGNED NOT NULL,
        post_url     VARCHAR(500)    NOT NULL,
        clicks_cur   INT             NOT NULL DEFAULT 0,
        clicks_prev  INT             NOT NULL DEFAULT 0,
        delta_pct    FLOAT           NOT NULL DEFAULT 0,
        action_taken VARCHAR(200)    NOT NULL DEFAULT '',
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    if (!wp_next_scheduled('gsc_opt_daily_check')) {
        wp_schedule_event(strtotime('tomorrow 06:00:00'), 'daily', 'gsc_opt_daily_check');
    }
}

function gsc_opt_deactivate()
{
    wp_clear_scheduled_hook('gsc_opt_daily_check');
}

// ── Cron handler ──────────────────────────────────────────────────────────────
add_action('gsc_opt_daily_check', 'gsc_opt_run_check');

function gsc_opt_run_check()
{
    $options = get_option('gsc_optimizer_settings', []);
    if (empty($options['sa_json']) || empty($options['site_url'])) {
        return;
    }

    try {
        $gsc = new GSC_Opt_API($options['sa_json'], $options['site_url']);

        // Діапазони з налаштувань (за замовчуванням: 7 і 7 днів)
        $cur_days = max(1, (int) ($options['period_current'] ?? 7));
        $prev_days = max(1, (int) ($options['period_compare'] ?? 7));

        $end_cur = date('Y-m-d', strtotime('-1 day'));
        $start_cur = date('Y-m-d', strtotime('-' . $cur_days . ' days'));
        $end_prev = date('Y-m-d', strtotime('-' . ($cur_days + 1) . ' days'));
        $start_prev = date('Y-m-d', strtotime('-' . ($cur_days + $prev_days) . ' days'));

        $current = $gsc->get_clicks_by_page($start_cur, $end_cur, 20);
        $previous = $gsc->get_clicks_by_page($start_prev, $end_prev, 20);

        $threshold = isset($options['threshold']) ? (float) $options['threshold'] : 10.0;
        $declined = GSC_Opt_Comparator::compare($current, $previous, $threshold);

        if (empty($declined)) {
            return;
        }

        $ai_provider = $options['ai_provider'] ?? 'openai';
        $ai_key = $options['ai_api_key'] ?? '';
        $rewriter = new GSC_Opt_AI_Rewriter($ai_provider, $ai_key);
        $updater = new GSC_Opt_Post_Updater();

        foreach ($declined as $item) {
            $post_id = url_to_postid($item['url']);
            if (!$post_id) {
                continue;
            }
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $new_content = $rewriter->rewrite_first_paragraph($post->post_content, $post->post_title);
            $new_content = $rewriter->append_seo_content($new_content, $post->post_title);

            $updated = $updater->update_post($post_id, $new_content);

            if ($updated) {
                global $wpdb;
                $wpdb->insert($wpdb->prefix . 'gsc_optimizer_log', [
                    'post_id' => $post_id,
                    'post_url' => $item['url'],
                    'clicks_cur' => $item['clicks_current'],
                    'clicks_prev' => $item['clicks_previous'],
                    'delta_pct' => $item['delta_pct'],
                    'action_taken' => 'rewrote paragraph + added table + updated date',
                ]);
            }
        }
    } catch (Exception $e) {
        error_log('[GSC Optimizer] Error: ' . $e->getMessage());
    }
}

// ── Admin Menu ────────────────────────────────────────────────────────────────
add_action('admin_menu', 'gsc_opt_admin_menu');

function gsc_opt_admin_menu()
{
    add_menu_page(
        'GSC Optimizer',
        'GSC Optimizer',
        'manage_options',
        'gsc-optimizer',
        'gsc_opt_dashboard_page',
        'dashicons-chart-line',
        80
    );
    add_submenu_page(
        'gsc-optimizer',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'gsc-optimizer',
        'gsc_opt_dashboard_page'
    );
    add_submenu_page(
        'gsc-optimizer',
        'Налаштування',
        'Налаштування',
        'manage_options',
        'gsc-optimizer-settings',
        'gsc_opt_settings_page'
    );
}

function gsc_opt_dashboard_page()
{
    require_once GSC_OPT_DIR . 'admin/dashboard-page.php';
}

function gsc_opt_settings_page()
{
    require_once GSC_OPT_DIR . 'admin/settings-page.php';
}

// ── Admin assets ──────────────────────────────────────────────────────────────
add_action('admin_enqueue_scripts', 'gsc_opt_admin_assets');

function gsc_opt_admin_assets($hook)
{
    if (strpos($hook, 'gsc-optimizer') === false) {
        return;
    }
    wp_enqueue_style('gsc-opt-admin', GSC_OPT_URL . 'admin/admin.css', [], GSC_OPT_VERSION);
    wp_enqueue_script('gsc-opt-admin', GSC_OPT_URL . 'admin/admin.js', ['jquery'], GSC_OPT_VERSION, true);
    wp_localize_script('gsc-opt-admin', 'gscOpt', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gsc_opt_nonce'),
    ]);
}

// ── AJAX: Preview rewrite (dry run) ──────────────────────────────────────────
add_action('wp_ajax_gsc_opt_preview_rewrite', 'gsc_opt_ajax_preview_rewrite');

function gsc_opt_ajax_preview_rewrite()
{
    check_ajax_referer('gsc_opt_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden');
    }

    $post_url = esc_url_raw($_POST['post_url'] ?? '');
    $post_id = url_to_postid($post_url);
    if (!$post_id) {
        wp_send_json_error('Пост не знайдено для URL: ' . $post_url);
    }

    $post = get_post($post_id);
    $options = get_option('gsc_optimizer_settings', []);

    try {
        $rewriter = new GSC_Opt_AI_Rewriter(
            $options['ai_provider'] ?? 'gemini',
            $options['ai_api_key'] ?? ''
        );
        $result = $rewriter->get_diagnostic_info($post->post_content, $post->post_title);

        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success($result);

    } catch (Exception $e) {
        wp_send_json_error('Помилка: ' . $e->getMessage());
    }
}
// ── AJAX: Run check manually ──────────────────────────────────────────────────
add_action('wp_ajax_gsc_opt_run_check', 'gsc_opt_ajax_run_check');

function gsc_opt_ajax_run_check()
{
    check_ajax_referer('gsc_opt_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden');
    }
    gsc_opt_run_check();
    wp_send_json_success('Перевірку виконано!');
}

// ── AJAX: Test GSC connection ─────────────────────────────────────────────────
add_action('wp_ajax_gsc_opt_test_connection', 'gsc_opt_ajax_test_connection');

function gsc_opt_ajax_test_connection()
{
    check_ajax_referer('gsc_opt_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden');
    }

    $options = get_option('gsc_optimizer_settings', []);
    if (empty($options['sa_json']) || empty($options['site_url'])) {
        wp_send_json_error('Заповніть Service Account JSON та Site URL.');
    }

    try {
        $gsc = new GSC_Opt_API($options['sa_json'], $options['site_url']);
        $data = $gsc->get_clicks_by_page(date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('-1 day')), 1);
        wp_send_json_success('✅ З\'єднання успішне. Отримано ' . count($data) . ' URL(s).');
    } catch (Exception $e) {
        wp_send_json_error('❌ Помилка: ' . $e->getMessage());
    }
}

// ── AJAX: Update single post ──────────────────────────────────────────────────
add_action('wp_ajax_gsc_opt_update_post', 'gsc_opt_ajax_update_post');

function gsc_opt_ajax_update_post()
{
    check_ajax_referer('gsc_opt_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden');
    }

    $post_url = esc_url_raw($_POST['post_url'] ?? '');
    if (!$post_url) {
        wp_send_json_error('URL не вказано.');
    }

    $post_id = url_to_postid($post_url);
    if (!$post_id) {
        wp_send_json_error('Пост не знайдено для URL: ' . $post_url);
    }

    $post = get_post($post_id);
    $options = get_option('gsc_optimizer_settings', []);

    try {
        $rewriter = new GSC_Opt_AI_Rewriter($options['ai_provider'] ?? 'gemini', $options['ai_api_key'] ?? '');
        $updater = new GSC_Opt_Post_Updater();
        $new_content = $rewriter->rewrite_first_paragraph($post->post_content, $post->post_title);
        $new_content = $rewriter->append_seo_content($new_content, $post->post_title);
        $updater->update_post($post_id, $new_content);
        wp_send_json_success('Пост #' . $post_id . ' оновлено!');
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

// ── AJAX: Debug — показати сирий контент поста ────────────────────────────────
add_action('wp_ajax_gsc_opt_debug_content', 'gsc_opt_ajax_debug_content');

function gsc_opt_ajax_debug_content()
{
    check_ajax_referer('gsc_opt_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden');
    }

    $post_url = esc_url_raw($_POST['post_url'] ?? '');
    $post_id = url_to_postid($post_url);

    if (!$post_id) {
        wp_send_json_error('Пост не знайдено для URL: ' . $post_url);
    }

    $post = get_post($post_id);

    // Показуємо перші 3000 символів сирого контенту
    $raw_preview = mb_substr($post->post_content, 0, 3000);

    // Шукаємо carbon-fields блоки
    $protected_names = [
        'carbon-fields/treba-happybirthday-block',
        'carbon-fields/treba-faq-block',
        'carbon-fields/treba-important-block',
        'carbon-fields/treba-important-list',
    ];

    $found_blocks = [];
    foreach ($protected_names as $name) {
        $escaped = preg_quote($name, '/');
        if (preg_match('/<!--\s*wp:' . $escaped . '\b/i', $post->post_content)) {
            $found_blocks[] = $name . ' — знайдено ✅';
        } else {
            $found_blocks[] = $name . ' — НЕ знайдено ❌';
        }
    }

    wp_send_json_success([
        'post_id' => $post_id,
        'title' => $post->post_title,
        'content_length' => mb_strlen($post->post_content),
        'raw_preview' => htmlspecialchars($raw_preview),
        'found_blocks' => $found_blocks,
    ]);
}
