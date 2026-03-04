<?php
/**
 * Post Updater — оновлює контент поста та дату публікації через WordPress API.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSC_Opt_Post_Updater
{

    /**
     * Оновлює пост: новий контент + дата публікації = сьогодні.
     *
     * @param int    $post_id     ID поста WordPress
     * @param string $new_content Новий HTML-контент
     *
     * @return bool  true якщо успішно, false якщо помилка
     */
    public function update_post(int $post_id, string $new_content): bool
    {
        $now_local = current_time('mysql');   // "2026-03-02 11:22:44"
        $now_gmt = current_time('mysql', 1); // GMT версія

        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => $new_content,
            'post_modified' => $now_local,
            'post_modified_gmt' => $now_gmt,
            // Оновлення дати редагування також оновлює відображувану дату у більшості тем
        ], true);

        if (is_wp_error($result)) {
            error_log('[GSC Optimizer] wp_update_post error for post ' . $post_id . ': ' . $result->get_error_message());
            return false;
        }

        // Додатково примусово оновлюємо дату публікації (post_date)
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            [
                'post_date' => $now_local,
                'post_date_gmt' => $now_gmt,
            ],
            ['ID' => $post_id],
            ['%s', '%s'],
            ['%d']
        );

        // Скидаємо кеш для цього поста
        clean_post_cache($post_id);

        return true;
    }
}
