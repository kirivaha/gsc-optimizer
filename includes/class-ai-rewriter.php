<?php
/**
 * AI Rewriter — переписує перший абзац та генерує таблицю
 * через Google Gemini API (або OpenAI як резервний варіант).
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSC_Opt_AI_Rewriter
{

    private string $provider;
    private string $api_key;

    /**
     * Блоки Gutenberg, які не можна редагувати через AI.
     * Формат: назва типу блоку (після wp:)
     */
    private array $protected_blocks = [
        'carbon-fields/treba-happybirthday-block',
        'carbon-fields/treba-faq-block',
        'carbon-fields/treba-important-block',
        'carbon-fields/treba-important-list',
    ];

    public function __construct(string $provider, string $api_key)
    {
        $this->provider = $provider; // 'gemini' | 'openai'
        $this->api_key = $api_key;
    }

    // ── Захист блоків ─────────────────────────────────────────────────────────

    /**
     * Витягує захищені блоки з контенту, замінює на плейсхолдери.
     * Повертає [контент_з_плейсхолдерами, масив_витягнутих_блоків]
     */
    private function extract_protected_blocks(string $content): array
    {
        $extracted = [];
        $index = 0;

        foreach ($this->protected_blocks as $block_name) {
            $escaped = preg_quote($block_name, '/');

            // Самозакривний блок: <!-- wp:carbon-fields/name {"attrs"} /-->
            // Використовуємо .*? замість [^-]*, щоб атрибути з дефісами теж оброблялись
            $pattern_self = '/<!--\s*wp:' . $escaped . '\b.*?\/-->/s';

            // Блок з вмістом: <!-- wp:carbon-fields/name ... --> ... <!-- /wp:carbon-fields/name -->
            $pattern_pair = '/<!--\s*wp:' . $escaped . '\b.*?-->[\s\S]*?<!--\s*\/wp:' . $escaped . '\s*-->/';

            foreach ([$pattern_self, $pattern_pair] as $pattern) {
                $content = preg_replace_callback($pattern, function ($matches) use (&$extracted, &$index) {
                    $placeholder = '%%GSC_PROTECTED_BLOCK_' . $index . '%%';
                    $extracted[$placeholder] = $matches[0];
                    $index++;
                    return $placeholder;
                }, $content);
            }
        }

        return [$content, $extracted];
    }

    /**
     * Повертає захищені блоки на своє місце.
     */
    private function restore_protected_blocks(string $content, array $extracted): string
    {
        foreach ($extracted as $placeholder => $block_html) {
            $content = str_replace($placeholder, $block_html, $content);
        }
        return $content;
    }

    /**
     * Очищує відповідь AI від будь-яких Gutenberg-коментарів та HTML-тегів.
     * Залишає лише чистий текст.
     */
    private function strip_gutenberg_markup(string $text): string
    {
        // Прибираємо Gutenberg коментарі <!-- wp:... --> та <!-- /wp:... -->
        $text = preg_replace('/<!--.*?-->/s', '', $text);
        // Прибираємо всі HTML-теги
        $text = strip_tags($text);
        // Прибираємо зайві пробіли
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    // ── Публічні методи ───────────────────────────────────────────────────────

    /**
     * Переписує перший абзац у WordPress-контенті іншими словами.
     */
    public function rewrite_first_paragraph(string $content, string $post_title): string
    {
        // Витягуємо захищені блоки перед обробкою
        [$safe_content, $extracted] = $this->extract_protected_blocks($content);

        // Знаходимо перший <p>...</p> (вже без захищених блоків)
        if (!preg_match('/<p[^>]*>(.*?)<\/p>/is', $safe_content, $matches)) {
            return $content; // Немає абзацу — залишаємо як є
        }

        $original_tag = $matches[0];
        $original_text = strip_tags($matches[1]);

        if (mb_strlen($original_text) < 30) {
            return $content; // Занадто короткий — пропускаємо
        }

        $prompt = "Ти — SEO-копірайтер. Перепиши наступний абзац іншими словами, зберігаючи зміст абзацу. "
            . "Заголовок сторінки: «{$post_title}». "
            . "Абзац для переписування:\n\n{$original_text}\n\n"
            . "Відповідь — ТІЛЬКИ новий текст абзацу. Без HTML, без markdown, без пояснень.";

        $new_text = $this->ask_ai($prompt);

        if (empty($new_text)) {
            return $content;
        }

        // Очищаємо відповідь AI від будь-якої розмітки
        $new_text = $this->strip_gutenberg_markup($new_text);
        $new_tag = '<p>' . $new_text . '</p>';
        $new_content = str_replace($original_tag, $new_tag, $safe_content);

        // Повертаємо захищені блоки на місце
        return $this->restore_protected_blocks($new_content, $extracted);
    }


    /**
     * Додає таблицю з корисною інформацією та блок Питання-Відповіді
     * в кінець статті (перед захищеними блоками).
     */
    public function append_seo_content(string $content, string $post_title): string
    {
        // Витягуємо захищені блоки перед обробкою
        [$safe_content, $extracted] = $this->extract_protected_blocks($content);

        // ── Таблиця ───────────────────────────────────────────────────────────
        $table_prompt = "Ти — SEO-копірайтер. Створи HTML-таблицю з 5-7 рядками корисної інформації по темі: «{$post_title}». "
            . "Таблиця має мати два стовпці: перший — назва характеристики або факту, другий — значення або пояснення. "
            . "Використай теги: <table class=\"gsc-opt-table\"><thead><tr><th>...</th><th>...</th></tr></thead><tbody>...</tbody></table>. "
            . "Відповідь — ТІЛЬКИ HTML-таблиця. Без пояснень, без markdown, без зайвого тексту.";

        $table_html = $this->ask_ai($table_prompt);

        // ── FAQ (Питання-Відповіді) ───────────────────────────────────────────
        $faq_prompt = "Ти — SEO-копірайтер. Склади 4-5 питань і відповідей (FAQ) по темі: «{$post_title}». "
            . "Формат відповіді — тільки HTML, без markdown. Структура: "
            . "<div class=\"gsc-opt-faq\"><h3>Питання і відповіді</h3>"
            . "<div class=\"gsc-opt-faq-item\"><h4>Питання?</h4><p>Відповідь.</p></div>...</div>. "
            . "Відповідь — ТІЛЬКИ HTML. Без пояснень, без markdown.";

        $faq_html = $this->ask_ai($faq_prompt);

        // ── Додаємо в кінець (з очищенням від Gutenberg-коментарів) ─────────
        $append = '';

        if (!empty($table_html)) {
            // Видаляємо Gutenberg-коментарі, але залишаємо HTML-теги таблиці
            $table_html = preg_replace('/<!--.*?-->/s', '', $table_html);
            $table_html = trim($table_html);
            if (strpos($table_html, '<table') !== false) {
                $append .= "\n\n" . $table_html;
            }
        }

        if (!empty($faq_html)) {
            // Видаляємо Gutenberg-коментарі, але залишаємо HTML-теги
            $faq_html = preg_replace('/<!--.*?-->/s', '', $faq_html);
            $faq_html = trim($faq_html);
            if (strpos($faq_html, '<div') !== false || strpos($faq_html, '<h') !== false) {
                $append .= "\n\n" . $faq_html;
            }
        }

        if (empty($append)) {
            return $content;
        }

        $new_content = $safe_content . $append;

        // Повертаємо захищені блоки на місце (вони залишаються в самому кінці)
        return $this->restore_protected_blocks($new_content, $extracted);
    }

    // ── Приватні методи ───────────────────────────────────────────────────────

    private function ask_ai(string $prompt): string
    {
        return $this->provider === 'openai'
            ? $this->ask_openai($prompt)
            : $this->ask_gemini($prompt);
    }

    private function ask_gemini(string $prompt): string
    {
        if (empty($this->api_key)) {
            throw new \RuntimeException('Gemini API key не вказано в налаштуваннях.');
        }

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key='
            . $this->api_key;

        $body = wp_json_encode([
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048,
            ],
        ]);

        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Gemini HTTP error: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    private function ask_openai(string $prompt): string
    {
        if (empty($this->api_key)) {
            throw new \RuntimeException('OpenAI API key не вказано в налаштуваннях.');
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => wp_json_encode([
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.7,
                'max_tokens' => 2048,
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('OpenAI HTTP error: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return trim($data['choices'][0]['message']['content'] ?? '');
    }
}
