<?php
/**
 * Dashboard page — показує топ-20 сторінок з порівнянням кліків.
 */

if (!defined('ABSPATH')) {
    exit;
}

$options = get_option('gsc_optimizer_settings', []);
$has_config = !empty($options['sa_json']) && !empty($options['site_url']);
$table_data = [];
$fetch_error = '';

if ($has_config && isset($_GET['run']) && $_GET['run'] === '1') {
    try {
        $gsc = new GSC_Opt_API($options['sa_json'], $options['site_url']);
        $end_cur = date('Y-m-d', strtotime('-1 day'));
        $start_cur = date('Y-m-d', strtotime('-7 days'));
        $end_prev = date('Y-m-d', strtotime('-8 days'));
        $start_prev = date('Y-m-d', strtotime('-14 days'));

        $current = $gsc->get_clicks_by_page($start_cur, $end_cur, 20);
        $previous = $gsc->get_clicks_by_page($start_prev, $end_prev, 20);
        $table_data = GSC_Opt_Comparator::all_with_delta($current, $previous);

        // Кешуємо в трансієнт на 6 годин
        set_transient('gsc_opt_dashboard_data', $table_data, 6 * HOUR_IN_SECONDS);
    } catch (Exception $e) {
        $fetch_error = $e->getMessage();
    }
} else {
    $cached = get_transient('gsc_opt_dashboard_data');
    if ($cached) {
        $table_data = $cached;
    }
}

// Отримуємо лог оновлень
global $wpdb;
$log_table = $wpdb->prefix . 'gsc_optimizer_log';
$logs = $wpdb->get_results("SELECT * FROM {$log_table} ORDER BY created_at DESC LIMIT 20", ARRAY_A);

$threshold = (float) ($options['threshold'] ?? 10.0);
?>
<div class="wrap gsc-opt-wrap">
    <h1>📊 GSC Optimizer — Dashboard</h1>

    <?php if (!$has_config): ?>
        <div class="notice notice-warning">
            <p>Спочатку налаштуйте плагін: <a href="<?= admin_url('admin.php?page=gsc-optimizer-settings') ?>">Перейти до
                    налаштувань →</a></p>
        </div>
    <?php else: ?>

        <div class="gsc-opt-actions">
            <a href="<?= esc_url(admin_url('admin.php?page=gsc-optimizer&run=1')) ?>"
                class="button button-primary gsc-opt-btn-refresh">
                🔄 Завантажити дані GSC
            </a>
            <button id="gsc-run-check" class="button button-secondary">
                🤖 Запустити AI-оновлення сторінок
            </button>
            <span id="gsc-run-status" class="gsc-opt-status-msg"></span>
        </div>

        <?php if ($fetch_error): ?>
            <div class="notice notice-error">
                <p>❌
                    <?= esc_html($fetch_error) ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($table_data)): ?>
            <h2>Топ-20 сторінок за кліками (останні 7 днів vs попередні 7 днів)</h2>
            <p style="color:#888;">Поріг для автооновлення: <strong>-
                    <?= $threshold ?>%
                </strong></p>

            <table class="wp-list-table widefat fixed striped gsc-opt-table">
                <thead>
                    <tr>
                        <th style="width:42%">URL сторінки</th>
                        <th class="text-center">Кліки (7 днів)</th>
                        <th class="text-center">Кліки (попередні 7 днів)</th>
                        <th class="text-center">Зміна %</th>
                        <th class="text-center">Дія</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_data as $row):
                        $delta = $row['delta_pct'];
                        $delta_cls = $delta === null ? '' : ($delta >= 0 ? 'gsc-delta-up' : ($delta <= -$threshold ? 'gsc-delta-down gsc-alert' : 'gsc-delta-down'));
                        $delta_str = $delta === null ? '–' : ($delta > 0 ? '+' : '') . $delta . '%';
                        ?>
                        <tr>
                            <td>
                                <a href="<?= esc_url($row['url']) ?>" target="_blank" title="<?= esc_attr($row['url']) ?>">
                                    <?= esc_html(parse_url($row['url'], PHP_URL_PATH) ?: $row['url']) ?>
                                </a>
                            </td>
                            <td class="text-center">
                                <?= (int) $row['clicks_current'] ?>
                            </td>
                            <td class="text-center">
                                <?= (int) $row['clicks_previous'] ?>
                            </td>
                            <td class="text-center <?= $delta_cls ?>">
                                <?= $delta_str ?>
                            </td>
                            <td class="text-center">
                                <?php if ($delta !== null && $delta <= -$threshold): ?>
                                    <button class="button gsc-update-post" data-url="<?= esc_attr($row['url']) ?>">
                                        🔁 Оновити
                                    </button>
                                <?php else: ?>
                                    <span style="color:#aaa;">–</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (empty($fetch_error)): ?>
            <p style="color:#999; margin-top:20px;">Натисніть «Завантажити дані GSC» щоб побачити статистику.</p>
        <?php endif; ?>

        <!-- Лог оновлень -->
        <?php if (!empty($logs)): ?>
            <h2 style="margin-top:40px;">📋 Лог оновлень</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>URL</th>
                        <th>Кліки (пот / поп)</th>
                        <th>Дельта %</th>
                        <th>Дія</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><a href="<?= esc_url($log['post_url']) ?>" target="_blank">
                                    <?= esc_html(parse_url($log['post_url'], PHP_URL_PATH)) ?>
                                </a></td>
                            <td>
                                <?= (int) $log['clicks_cur'] ?> /
                                <?= (int) $log['clicks_prev'] ?>
                            </td>
                            <td class="gsc-delta-down">
                                <?= $log['delta_pct'] ?>%
                            </td>
                            <td>
                                <?= esc_html($log['action_taken']) ?>
                            </td>
                            <td>
                                <?= esc_html($log['created_at']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($has_config): ?>
        <!-- ── Debug / Ручне оновлення ────────────────────────────────────── -->
        <div class="gsc-opt-debug-box"
            style="margin-top:40px; border:1px solid #ddd; border-radius:6px; padding:20px; background:#fafafa;">
            <h2 style="margin-top:0;">🛠 Debug / Ручне оновлення</h2>
            <p style="color:#666; margin-top:0;">Вставте URL сторінки, щоб перевірити її контент або запустити AI-оновлення
                вручну.</p>

            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="url" id="gsc-debug-url" placeholder="https://hmarno.v.ua/..."
                    style="flex:1; min-width:300px; padding:6px 10px; border:1px solid #ccc; border-radius:4px;" />
                <button id="gsc-debug-content" class="button button-secondary">🔍 Debug Content</button>
                <button id="gsc-preview-rewrite" class="button button-secondary">🔬 Preview Rewrite</button>
                <button id="gsc-manual-update" class="button button-primary">🤖 Оновити через AI</button>
            </div>

            <div id="gsc-debug-result" style="display:none; margin-top:20px;"></div>
        </div>
    <?php endif; ?>

</div>