<?php
/**
 * GSC API — отримує дані кліків з Google Search Console
 * через Service Account авторизацію (без OAuth redirect).
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSC_Opt_API
{

    private string $site_url;
    private $client;

    /**
     * @param string $sa_json_string  Вміст Service Account JSON файлу
     * @param string $site_url        Наприклад: "sc-domain:hmarno.v.ua" або "https://hmarno.v.ua/"
     */
    public function __construct(string $sa_json_string, string $site_url)
    {
        $this->site_url = $site_url;
        $this->client = $this->build_client($sa_json_string);
    }

    /**
     * Повертає масив [page_url => clicks], відсортований за кліками (спадання).
     */
    public function get_clicks_by_page(string $start_date, string $end_date, int $limit = 20): array
    {
        $service = new \Google\Service\SearchConsole($this->client);

        $request = new \Google\Service\SearchConsole\SearchAnalyticsQueryRequest();
        $request->setStartDate($start_date);
        $request->setEndDate($end_date);
        $request->setDimensions(['page']);
        $request->setRowLimit($limit);

        $response = $service->searchanalytics->query($this->site_url, $request);
        $rows = $response->getRows() ?? [];

        // Збираємо в масив [url => clicks]
        $result = [];
        foreach ($rows as $row) {
            $keys = $row->getKeys();
            $url = $keys[0] ?? '';
            $result[$url] = (int) $row->getClicks();
        }

        // Сортуємо за кліками (спадання) — GSC не завжди повертає в правильному порядку
        arsort($result);

        return $result;
    }

    // ── Приватні методи ───────────────────────────────────────────────────────

    private function build_client(string $sa_json_string): \Google\Client
    {
        // Підключаємо autoloader якщо ще не завантажено
        if (!class_exists('\Google\Client')) {
            $autoload = GSC_OPT_DIR . 'vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            } else {
                throw new \RuntimeException(
                    'Google API Client не знайдено. Виконайте: composer install в папці плагіну.'
                );
            }
        }

        $sa_credentials = json_decode($sa_json_string, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($sa_credentials)) {
            throw new \InvalidArgumentException('Service Account JSON невалідний.');
        }

        $client = new \Google\Client();
        $client->setApplicationName('GSC Optimizer WP Plugin');
        $client->setScopes([\Google\Service\SearchConsole::WEBMASTERS_READONLY]);
        $client->setAuthConfig($sa_credentials);

        return $client;
    }
}
