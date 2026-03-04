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
            // Екрануємо слеш для regex (treba/faq-block → treba\/faq-block)
            $escaped = preg_quote($block_name, '/');

            // Шаблон: <!-- wp:treba/... --> ... <!-- /wp:treba/... -->
            $pattern = '/<!--\s*wp:' . $escaped . '[\s\S]*?<!--\s*\/wp:' . $escaped . '\s*-->/i';

            $content = preg_replace_callback($pattern, function ($matches) use (&$extracted, &$index) {
                $placeholder = '%%GSC_PROTECTED_BLOCK_' . $index . '%%';
                $extracted[$placeholder] = $matches[0];
                $index++;
                return $placeholder;
            }, $content);
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

        $original_tag = $matches[0]; // весь <p>...</p>
        $original_text = strip_tags($matches[1]);

        if (mb_strlen($original_text) < 30) {
            return $content; // Занадто короткий — пропускаємо
        }

        $prompt = "Ти — SEO-копірайтер. Перепиши наступний абзац іншими словами, зберігши точний зміст, тон і ключові слова. "
            . "Заголовок сторінки: «{$post_title}». "
            . "Абзац для переписування:\n\n{$original_text}\n\n"
            . "Відповідь — тільки новий текст абзацу, без пояснень і лапок.";

        $new_text = $this->ask_ai($prompt);

        if (empty($new_text)) {
            return $content;
        }

        $new_tag = '<p>' . esc_html($new_text) . '</p>';
        $new_content = str_replace($original_tag, $new_tag, $safe_content);

        // Повертаємо захищені блоки на місце
        return $this->restore_protected_blocks($new_content, $extracted);
    }

    /**
     * Вставляє HTML-таблицю з важливими фактами в середину контенту.
     */
    public function insert_table_in_middle(string $content, string $post_title): string
    {
        // Витягуємо захищені блоки перед обробкою
        [$safe_content, $extracted] = $this->extract_protected_blocks($content);

        $prompt = "Ти — SEO-копірайтер. Створи HTML-таблицю з 4-6 рядками важливих фактів або характеристик, "
            . "яка стосується теми: «{$post_title}». "
            . "Таблиця має мати два стовпці: «Характеристика» і «Значення / Деталі». "
            . "Таблиця має бути у тегах <table class=\"gsc-opt-table\">...</table>. "
            . "Відповідь — тільки HTML-таблиця, без пояснень.";

        $table_html = $this->ask_ai($prompt);

        if (empty($table_html) || strpos($table_html, '<table') === false) {
            return $content;
        }

        // Знаходимо всі <p> в безпечному контенті (без захищених блоків)
        preg_match_all('/<p[^>]*>.*?<\/p>/is', $safe_content, $all_p);
        $p_count = count($all_p[0]);

        if ($p_count < 2) {
            $new_content = $safe_content . "\n\n" . $table_html;
        } else {
            $mid_index = (int) floor($p_count / 2);
            $target_p = $all_p[0][$mid_index];
            $new_content = str_replace($target_p, $target_p . "\n\n" . $table_html, $safe_content);
        }

        // Повертаємо захищені блоки на місце
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
