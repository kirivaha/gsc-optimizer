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


    // ── Debug Content ─────────────────────────────────────────────────────────
    $('#gsc-debug-content').on('click', function () {
        const url = $('#gsc-debug-url').val().trim();
        if (!url) { alert('Введіть URL сторінки.'); return; }

        const $result = $('#gsc-debug-result');
        $result.show().html('<p style="color:#888;">⏳ Завантажуємо...</p>');

        $.post(gscOpt.ajax_url, {
            action: 'gsc_opt_debug_content',
            nonce: gscOpt.nonce,
            post_url: url
        })
            .done(function (res) {
                if (res.success) {
                    const d = res.data;
                    const blocksHtml = d.found_blocks.map(b => '<li>' + b + '</li>').join('');
                    $result.html(
                        '<p><strong>Post ID:</strong> ' + d.post_id + ' | ' +
                        '<strong>Заголовок:</strong> ' + d.title + ' | ' +
                        '<strong>Довжина контенту:</strong> ' + d.content_length + ' символів</p>' +
                        '<p><strong>Захищені блоки:</strong></p><ul>' + blocksHtml + '</ul>' +
                        '<p><strong>Перші 3000 символів raw-контенту:</strong></p>' +
                        '<textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">' +
                        d.raw_preview + '</textarea>'
                    );
                } else {
                    $result.html('<p style="color:red;">❌ ' + res.data + '</p>');
                }
            })
            .fail(function () {
                $result.html('<p style="color:red;">❌ Помилка мережі.</p>');
            });
    });

    // ── Ручне AI-оновлення по URL ─────────────────────────────────────────────
    $('#gsc-manual-update').on('click', function () {
        const url = $('#gsc-debug-url').val().trim();
        if (!url) { alert('Введіть URL сторінки.'); return; }
        if (!confirm('Оновити сторінку через AI?\n' + url)) return;

        const $btn = $(this);
        const $result = $('#gsc-debug-result');
        $btn.prop('disabled', true);
        $result.show().html('<p style="color:#888;">⏳ AI переписує контент... (може тривати до 30 сек)</p>');

        $.post(gscOpt.ajax_url, {
            action: 'gsc_opt_update_post',
            nonce: gscOpt.nonce,
            post_url: url
        })
            .done(function (res) {
                if (res.success) {
                    $result.html('<p style="color:green;">✅ ' + res.data + '</p>');
                } else {
                    $result.html('<p style="color:red;">❌ ' + res.data + '</p>');
                }
            })
            .fail(function () {
                $result.html('<p style="color:red;">❌ Помилка мережі.</p>');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

}(jQuery));
