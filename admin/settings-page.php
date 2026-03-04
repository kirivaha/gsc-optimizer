<?php
/**
 * Settings page — налаштування GSC Optimizer.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Зберігаємо налаштування
if (isset($_POST['gsc_opt_save']) && check_admin_referer('gsc_opt_settings_save')) {
    $settings = [
        'site_url' => sanitize_text_field($_POST['site_url'] ?? ''),
        'sa_json' => wp_unslash($_POST['sa_json'] ?? ''),
        'ai_provider' => in_array($_POST['ai_provider'] ?? '', ['gemini', 'openai'], true) ? $_POST['ai_provider'] : 'gemini',
        'ai_api_key' => sanitize_text_field($_POST['ai_api_key'] ?? ''),
        'threshold' => max(1, min(99, (float) ($_POST['threshold'] ?? 10))),
        'auto_run' => isset($_POST['auto_run']) ? 1 : 0,
    ];
    update_option('gsc_optimizer_settings', $settings);
    echo '<div class="notice notice-success"><p>✅ Налаштування збережено!</p></div>';
}

$opts = get_option('gsc_optimizer_settings', []);
$sa_json_preview = !empty($opts['sa_json'])
    ? '(JSON збережено — ' . mb_strlen($opts['sa_json']) . ' символів)'
    : '';
?>
<div class="wrap gsc-opt-wrap">
    <h1>⚙️ GSC Optimizer — Налаштування</h1>

    <form method="post" action="">
        <?php wp_nonce_field('gsc_opt_settings_save'); ?>

        <table class="form-table" role="presentation">
            <!-- GSC Site URL -->
            <tr>
                <th scope="row"><label for="site_url">GSC Site URL</label></th>
                <td>
                    <input type="text" id="site_url" name="site_url" class="regular-text"
                        value="<?= esc_attr($opts['site_url'] ?? '') ?>"
                        placeholder="sc-domain:hmarno.v.ua або https://hmarno.v.ua/">
                    <p class="description">
                        Формат: <code>sc-domain:example.com</code> (Domain property)
                        або <code>https://example.com/</code> (URL prefix property)
                    </p>
                </td>
            </tr>

            <!-- Service Account JSON -->
            <tr>
                <th scope="row"><label for="sa_json">Service Account JSON</label></th>
                <td>
                    <textarea id="sa_json" name="sa_json" rows="8" class="large-text code"
                        placeholder='{"type": "service_account", "project_id": "...", ...}'><?= esc_textarea($opts['sa_json'] ?? '') ?></textarea>
                    <?php if ($sa_json_preview): ?>
                        <p class="description" style="color:#2e7d32;">
                            <?= esc_html($sa_json_preview) ?>
                        </p>
                    <?php endif; ?>
                    <p class="description">
                        Вставте вміст JSON-файлу Service Account з Google Cloud Console.<br>
                        <a href="https://developers.google.com/search/apis/indexing-api/v3/prereqs" target="_blank">
                            Як отримати Service Account →
                        </a>
                    </p>
                    <button type="button" id="gsc-test-connection" class="button" style="margin-top:8px;">
                        🔌 Тестувати підключення до GSC
                    </button>
                    <span id="gsc-connection-status" class="gsc-opt-status-msg"></span>
                </td>
            </tr>

            <!-- AI Provider -->
            <tr>
                <th scope="row"><label for="ai_provider">AI Провайдер</label></th>
                <td>
                    <select id="ai_provider" name="ai_provider">
                        <option value="gemini" <?= selected($opts['ai_provider'] ?? 'gemini', 'gemini', false) ?>>
                            Google Gemini (за замовчуванням)
                        </option>
                        <option value="openai" <?= selected($opts['ai_provider'] ?? '', 'openai', false) ?>>
                            OpenAI GPT-4o
                        </option>
                    </select>
                </td>
            </tr>

            <!-- AI API Key -->
            <tr>
                <th scope="row"><label for="ai_api_key">AI API Key</label></th>
                <td>
                    <input type="password" id="ai_api_key" name="ai_api_key" class="regular-text"
                        value="<?= esc_attr($opts['ai_api_key'] ?? '') ?>" placeholder="AIza... або sk-...">
                    <p class="description">
                        Gemini: отримати на <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI
                            Studio →</a><br>
                        OpenAI: отримати на <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI
                            Platform →</a>
                    </p>
                </td>
            </tr>

            <!-- Threshold -->
            <tr>
                <th scope="row"><label for="threshold">Поріг падіння кліків (%)</label></th>
                <td>
                    <input type="number" id="threshold" name="threshold" class="small-text"
                        value="<?= esc_attr($opts['threshold'] ?? 10) ?>" min="1" max="99" step="1">
                    <span> %</span>
                    <p class="description">
                        При падінні кліків більше ніж на це значення — сторінка буде оновлена автоматично.<br>
                        Рекомендовано: <strong>10-20%</strong>.
                    </p>
                </td>
            </tr>

            <!-- Auto run -->
            <tr>
                <th scope="row">Автоматичний запуск</th>
                <td>
                    <label>
                        <input type="checkbox" name="auto_run" value="1" <?= checked($opts['auto_run'] ?? 0, 1, false) ?>>
                        Перевіряти щодня о 06:00 автоматично (WP Cron)
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button('💾 Зберегти налаштування', 'primary', 'gsc_opt_save'); ?>
    </form>
</div>