/* global: gscOpt = { ajax_url, nonce } */

(function ($) {
    'use strict';

    const statusMsg = (selector, text, type) => {
        const $el = $(selector);
        $el.removeClass('success error loading').addClass(type).text(text).show();
    };

    // ── Тестувати підключення до GSC ─────────────────────────────────────────
    $('#gsc-test-connection').on('click', function () {
        statusMsg('#gsc-connection-status', '⏳ Перевіряємо підключення...', 'loading');

        $.post(gscOpt.ajax_url, {
            action: 'gsc_opt_test_connection',
            nonce: gscOpt.nonce
        })
            .done(function (res) {
                if (res.success) {
                    statusMsg('#gsc-connection-status', res.data, 'success');
                } else {
                    statusMsg('#gsc-connection-status', res.data, 'error');
                }
            })
            .fail(function () {
                statusMsg('#gsc-connection-status', '❌ Помилка мережі.', 'error');
            });
    });

    // ── Запустити AI-оновлення всіх сторінок що просіли ──────────────────────
    $('#gsc-run-check').on('click', function () {
        if (!confirm('Запустити AI-оновлення всіх сторінок із падінням кліків? Це може зайняти кілька хвилин.')) {
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true);
        statusMsg('#gsc-run-status', '⏳ Виконується... (не закривайте сторінку)', 'loading');

        $.post(gscOpt.ajax_url, {
            action: 'gsc_opt_run_check',
            nonce: gscOpt.nonce
        })
            .done(function (res) {
                if (res.success) {
                    statusMsg('#gsc-run-status', '✅ ' + res.data, 'success');
                    // Оновити сторінку через 2 секунди щоб показати нові логи
                    setTimeout(() => location.reload(), 2000);
                } else {
                    statusMsg('#gsc-run-status', '❌ ' + res.data, 'error');
                }
            })
            .fail(function () {
                statusMsg('#gsc-run-status', '❌ Помилка мережі під час виконання.', 'error');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    // ── Оновити одну сторінку вручну ─────────────────────────────────────────
    $(document).on('click', '.gsc-update-post', function () {
        const $btn = $(this);
        const postUrl = $btn.data('url');

        if (!confirm('Оновити сторінку через AI?\n' + postUrl)) return;

        $btn.prop('disabled', true).text('⏳ Оновлення...');

        $.post(gscOpt.ajax_url, {
            action: 'gsc_opt_update_post',
            nonce: gscOpt.nonce,
            post_url: postUrl
        })
            .done(function (res) {
                if (res.success) {
                    $btn.closest('tr').find('td:last').html(
                        '<span style="color:#2e7d32;">✅ Оновлено</span>'
                    );
                } else {
                    alert('❌ Помилка: ' + res.data);
                    $btn.prop('disabled', false).text('🔁 Оновити');
                }
            })
            .fail(function () {
                alert('❌ Помилка мережі.');
                $btn.prop('disabled', false).text('🔁 Оновити');
            });
    });

}(jQuery));
