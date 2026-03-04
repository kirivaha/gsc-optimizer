<?php
/**
 * Порівнює кліки двох 7-денних діапазонів та повертає
 * список сторінок із падінням понад заданий поріг.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSC_Opt_Comparator
{

    /**
     * @param array $current   [url => clicks]  — поточний тиждень
     * @param array $previous  [url => clicks]  — попередній тиждень
     * @param float $threshold  Поріг у відсотках (наприклад 10.0 = -10%)
     *
     * @return array  Список сторінок із падінням:
     *   [ ['url', 'clicks_current', 'clicks_previous', 'delta_pct'], ... ]
     */
    public static function compare(array $current, array $previous, float $threshold = 10.0): array
    {
        $declined = [];

        foreach ($current as $url => $clicks_cur) {
            $clicks_prev = $previous[$url] ?? 0;

            // Пропускаємо нові сторінки (яких не було в попередній период)
            if ($clicks_prev === 0) {
                continue;
            }

            $delta_pct = (($clicks_cur - $clicks_prev) / $clicks_prev) * 100;

            // Падіння більше порогу (delta_pct від'ємний)
            if ($delta_pct <= -$threshold) {
                $declined[] = [
                    'url' => $url,
                    'clicks_current' => $clicks_cur,
                    'clicks_previous' => $clicks_prev,
                    'delta_pct' => round($delta_pct, 1),
                ];
            }
        }

        // Сортуємо від найбільшого падіння до найменшого
        usort($declined, fn($a, $b) => $a['delta_pct'] <=> $b['delta_pct']);

        return $declined;
    }

    /**
     * Порівнює дані і повертає ВСІ топ-20 сторінок з дельтою (для Dashboard).
     */
    public static function all_with_delta(array $current, array $previous): array
    {
        $result = [];

        foreach ($current as $url => $clicks_cur) {
            $clicks_prev = $previous[$url] ?? 0;
            $delta_pct = $clicks_prev > 0
                ? round((($clicks_cur - $clicks_prev) / $clicks_prev) * 100, 1)
                : null;

            $result[] = [
                'url' => $url,
                'clicks_current' => $clicks_cur,
                'clicks_previous' => $clicks_prev,
                'delta_pct' => $delta_pct,
            ];
        }

        return $result;
    }
}
