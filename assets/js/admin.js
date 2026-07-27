/**
 * WPML Imagina Translate - Admin JavaScript
 */

(function($) {
    'use strict';

    const WitAdmin = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Check all posts
            $('#wit-check-all').on('change', this.handleCheckAll);

            // Individual checkbox
            $('.wit-post-checkbox').on('change', this.handleCheckboxChange);

            // Select all button
            $('#wit-select-all').on('click', this.selectAll);

            // Translate selected
            $('#wit-translate-selected').on('click', this.translateSelected);

            // Translate single post
            $('.wit-translate-single').on('click', this.translateSingle);

            // Empty the translation memory (settings page)
            $(document).on('click', '#wit-clear-memory', this.clearMemory);
        },

        clearMemory: function(e) {
            e.preventDefault();

            if (!confirm('¿Vaciar la memoria de traducción? Las próximas traducciones volverán a pagarse.')) {
                return;
            }

            const $button = $(this);
            const $status = $('#wit-clear-memory-status');

            $button.prop('disabled', true);
            $status.text(' Vaciando…');

            $.ajax({
                url:  witAdmin.ajax_url,
                type: 'POST',
                data: { action: 'wit_clear_memory', nonce: witAdmin.nonce },
                success: function(response) {
                    $button.prop('disabled', false);
                    $status.css('color', '#007017').text(
                        response && response.success
                            ? ' Memoria vaciada (' + response.data.removed + ' entradas).'
                            : ' No se pudo vaciar.'
                    );
                },
                error: function() {
                    $button.prop('disabled', false);
                    $status.css('color', '#cc0000').text(' Error de red.');
                }
            });
        },

        handleCheckAll: function() {
            const isChecked = $(this).prop('checked');
            $('.wit-post-checkbox').prop('checked', isChecked);
            WitAdmin.updateSelectedCount();
        },

        handleCheckboxChange: function() {
            WitAdmin.updateSelectedCount();

            // Update check all state
            const totalCheckboxes = $('.wit-post-checkbox').length;
            const checkedCheckboxes = $('.wit-post-checkbox:checked').length;
            $('#wit-check-all').prop('checked', totalCheckboxes === checkedCheckboxes);
        },

        selectAll: function(e) {
            e.preventDefault();
            $('.wit-post-checkbox').prop('checked', true);
            $('#wit-check-all').prop('checked', true);
            WitAdmin.updateSelectedCount();
        },

        updateSelectedCount: function() {
            const count = $('.wit-post-checkbox:checked').length;
            $('#wit-selected-count').text(count + ' seleccionados');
            $('#wit-translate-selected').prop('disabled', count === 0);
        },

        translateSelected: function(e) {
            e.preventDefault();

            const selectedPosts = [];
            $('.wit-post-checkbox:checked').each(function() {
                selectedPosts.push($(this).val());
            });

            if (selectedPosts.length === 0) {
                alert(witAdmin.strings.error);
                return;
            }

            if (!confirm(witAdmin.strings.confirm_batch)) {
                return;
            }

            WitAdmin.enqueueBatch(selectedPosts, $('#target_lang').val());
        },

        /**
         * Hand the selection to the server-side queue.
         *
         * The previous implementation looped here in the browser, so closing
         * the tab abandoned the run. The queue is drained by WP-Cron instead;
         * this page only reports progress and can be closed at any time.
         */
        enqueueBatch: function(postIds, targetLanguage) {
            const $button = $('#wit-translate-selected');
            $button.prop('disabled', true);

            $('#wit-progress').show();
            $('#wit-progress-log').html('');
            WitAdmin.addLogEntry('Encolando ' + postIds.length + ' posts…', 'processing');

            $.ajax({
                url:  witAdmin.ajax_url,
                type: 'POST',
                data: {
                    action:          'wit_enqueue_batch',
                    nonce:           witAdmin.nonce,
                    post_ids:        postIds,
                    target_language: targetLanguage
                },
                success: function(response) {
                    if (!response || !response.success) {
                        const msg = (response && response.data && response.data.message)
                            ? response.data.message : 'Error desconocido';
                        WitAdmin.addLogEntry('✗ ' + msg, 'error');
                        $button.prop('disabled', false);
                        return;
                    }

                    const d = response.data;
                    WitAdmin.addLogEntry(
                        '✓ ' + d.queued + ' posts en cola' +
                        (d.skipped ? ' (' + d.skipped + ' omitidos)' : '') +
                        '. Puedes cerrar esta página: la traducción continúa en el servidor.',
                        'success'
                    );
                    WitAdmin.pollQueue(d.batch_id);
                },
                error: function(xhr) {
                    WitAdmin.addLogEntry('✗ Error de red (HTTP ' + xhr.status + ')', 'error');
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Report queue progress until nothing is left to do.
         */
        pollQueue: function(batchId) {
            let lastDone = -1;

            const tick = function() {
                $.ajax({
                    url:      witAdmin.ajax_url,
                    type:     'POST',
                    dataType: 'json',
                    timeout:  15000,
                    data: {
                        action:   'wit_queue_status',
                        nonce:    witAdmin.nonce,
                        batch_id: batchId
                    },
                    success: function(response) {
                        if (!response || !response.data) { return; }

                        const s    = response.data;
                        const done = s.done + s.error;

                        WitAdmin.updateProgress(done, s.total);

                        if (done !== lastDone) {
                            lastDone = done;
                            (s.recent || []).slice(0, 5).forEach(function(row) {
                                if (row.status === 'done') {
                                    WitAdmin.addLogEntry('✓ ' + row.title, 'success');
                                } else if (row.status === 'error') {
                                    WitAdmin.addLogEntry('✗ ' + row.title + ' — ' + row.message, 'error');
                                }
                            });
                        }

                        if (s.pending === 0 && s.processing === 0) {
                            WitAdmin.addLogEntry('=== COLA COMPLETADA ===', 'success');
                            WitAdmin.addLogEntry('Traducidos: ' + s.done + ' · Errores: ' + s.error,
                                s.error > 0 ? 'error' : 'success');
                            $('#wit-translate-selected').prop('disabled', false);
                            return;
                        }

                        setTimeout(tick, 5000);
                    },
                    error: function() {
                        setTimeout(tick, 10000); // transient failure: keep watching
                    }
                });
            };

            tick();
        },

        translateSingle: function(e) {
            e.preventDefault();

            const $button       = $(this);
            const postId        = $button.data('post-id');
            const targetLanguage = $button.data('target-lang');
            const $row          = $button.closest('tr');

            $button.addClass('wit-translating').prop('disabled', true).text(witAdmin.strings.translating);

            WitAdmin.translatePost(
                postId,
                targetLanguage,
                // ── completion callback ──────────────────────────────────────
                function(success, data) {
                    $button.removeClass('wit-translating wit-backgrounded').prop('disabled', false);
                    // Remove any hint text we injected
                    $button.siblings('.wit-bg-hint').remove();

                    if (success) {
                        if (data.debug && data.debug.length > 0) {
                            console.group('Translation Debug Info - Post #' + postId);
                            data.debug.forEach(function(log) { console.log(log); });
                            console.groupEnd();
                        }

                        $button.text('✓ ' + witAdmin.strings.success)
                               .removeClass('button-primary').addClass('button-secondary');

                        const $statusCell = $row.find('td:eq(2)');
                        $statusCell.html('<span class="wit-status-success">✓ Traducido</span>');

                        if (data.edit_url) {
                            if ($row.find('.wit-edit-translation-link').length === 0) {
                                $('<a>')
                                    .attr('href', data.edit_url)
                                    .attr('target', '_blank')
                                    .addClass('button button-small wit-edit-translation-link')
                                    .text('Editar Traducción')
                                    .insertAfter($button);
                            }
                        }

                        setTimeout(function() {
                            $button.text('Re-Traducir')
                                   .removeClass('button-secondary').addClass('button-primary');
                        }, 2000);

                    } else {
                        if (data.debug && data.debug.length > 0) {
                            console.group('Translation Debug (ERROR) - Post #' + postId);
                            data.debug.forEach(function(log) { console.log(log); });
                            console.groupEnd();
                        }
                        console.error('Translation error:', data.message);
                        $button.text('Error — Reintentar');
                        alert('Error en la traducción: ' + (data.message || 'Error desconocido'));
                    }
                },
                // ── backgrounded callback (called when the HTTP connection is cut) ──
                function() {
                    $button.addClass('wit-backgrounded').text('⏳ Procesando…');
                    // Small descriptive hint below the button
                    if ($button.siblings('.wit-bg-hint').length === 0) {
                        $('<span class="wit-bg-hint">')
                            .text('La IA está generando la traducción. Esto puede tardar varios minutos. No cierres esta página.')
                            .insertAfter($button);
                    }
                }
            );
        },

        /**
         * Translate a post and call `callback(success, data)` when done.
         *
         * If the server HTTP connection is cut before PHP finishes (gateway
         * timeout), `onBackgrounded()` is called so the UI can update, and
         * polling takes over.  The callback is still called exactly once when
         * the final result is available.
         *
         * Polling calls wp_ajax_wit_check_translation_status every 5 s and
         * resolves once the transient status is 'complete' or 'error'.
         * It gives up after 10 minutes and reports a timeout message.
         *
         * @param {number}   postId
         * @param {string}   targetLanguage
         * @param {Function} callback(success, data)
         * @param {Function} [onBackgrounded]  — called when HTTP connection drops
         */
        translatePost: function(postId, targetLanguage, callback, onBackgrounded) {
            let pollInterval  = null;
            let callbackFired = false;
            let pollAttempts  = 0;
            const MAX_POLLS   = 120; // 5 s × 120 = 10 min

            // Fire the callback exactly once and stop polling.
            const done = function(success, data) {
                if (callbackFired) return;
                callbackFired = true;
                if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
                callback(success, data);
            };

            // Begin polling the status endpoint every 5 seconds.
            const startPolling = function() {
                if (callbackFired || pollInterval) return;

                if (onBackgrounded) onBackgrounded();

                pollInterval = setInterval(function() {
                    if (callbackFired) { clearInterval(pollInterval); return; }

                    if (++pollAttempts > MAX_POLLS) {
                        clearInterval(pollInterval);
                        done(false, {
                            message: 'Tiempo de espera agotado (10 min). ' +
                                     'Revisa el log de traducciones — puede que se haya completado.'
                        });
                        return;
                    }

                    $.ajax({
                        url:      witAdmin.ajax_url,
                        type:     'POST',
                        dataType: 'json',
                        timeout:  10000,
                        data: {
                            action:          'wit_check_translation_status',
                            nonce:           witAdmin.nonce,
                            post_id:         postId,
                            target_language: targetLanguage
                        },
                        success: function(response) {
                            if (!response || !response.data) return;
                            const d = response.data;
                            if (d.status === 'complete') {
                                done(true, d);
                            } else if (d.status === 'error') {
                                done(false, d);
                            }
                            // 'processing' or 'not_found' → keep polling
                        }
                        // Ignore individual poll errors — just retry on next tick
                    });
                }, 5000);
            };

            // ── Main translation AJAX call ──────────────────────────────────
            // No client-side timeout: the server/proxy will cut the connection
            // if it runs too long (typically 504 after 60-120 s depending on
            // the server). When that happens we fall through to polling.
            $.ajax({
                url:      witAdmin.ajax_url,
                type:     'POST',
                dataType: 'json',
                data: {
                    action:          'wit_translate_post',
                    nonce:           witAdmin.nonce,
                    post_id:         postId,
                    target_language: targetLanguage
                },
                success: function(response) {
                    var data = (response && response.data) ? response.data : {};
                    if (response && response.success) {
                        done(true, data);
                    } else {
                        if (!data.message) {
                            data.message = witAdmin.strings.error || 'Error desconocido';
                        }
                        done(false, data);
                    }
                },
                error: function(xhr, status) {
                    // Gateway timeout or proxy timeout — PHP is still running
                    // thanks to ignore_user_abort(true). Switch to polling.
                    if (xhr.status === 504 || xhr.status === 502 || status === 'timeout') {
                        startPolling();
                    } else {
                        // Genuine network / server error — report immediately.
                        var msg = xhr.status
                            ? 'HTTP ' + xhr.status
                            : (witAdmin.strings.error || 'Error de red');
                        done(false, { message: msg });
                    }
                }
            });

            // Also start polling after 30 s even if the main connection is
            // still open — this keeps the UI informed and handles cases where
            // the proxy cuts silently without sending a 504.
            setTimeout(function() {
                if (!callbackFired && !pollInterval) {
                    startPolling();
                }
            }, 30000);
        },

        updateProgress: function(current, total) {
            const percentage = total > 0 ? Math.round((current / total) * 100) : 0;
            $('.wit-progress-fill').css('width', percentage + '%');
            $('.wit-progress-text').text(current + ' / ' + total);
        },

        addLogEntry: function(message, type) {
            const $log      = $('#wit-progress-log');
            const timestamp = new Date().toLocaleTimeString();
            const entry     = $('<div>')
                .addClass('wit-log-entry')
                .addClass(type)
                .text('[' + timestamp + '] ' + message);

            $log.append(entry);
            $log.scrollTop($log[0].scrollHeight);
        }

    };

    // -----------------------------------------------------------------------
    // Dynamic model loader for Settings page
    // -----------------------------------------------------------------------
    const WitModels = {

        init: function() {
            // Auto-load models for every provider that already has a key.
            // The key field is intentionally rendered empty, so a saved key is
            // detected from its placeholder state instead of its value; the
            // server falls back to the stored key when none is submitted.
            $('.wit-model-select').each(function() {
                const $select  = $(this);
                const keyField = $select.data('key-field');
                const $key     = $('#' + keyField);
                const hasKey   = $key.val().trim() !== '' || $key.siblings('p').find('input[type=checkbox]').length > 0;
                if (hasKey) {
                    WitModels.load($select);
                }
            });

            // Manual refresh button
            $(document).on('click', '.wit-refresh-models', function(e) {
                e.preventDefault();
                const targetId = $(this).data('target');
                WitModels.load($('#' + targetId));
            });
        },

        load: function($select) {
            const provider = $select.data('provider');
            const keyField = $select.data('key-field');
            const savedVal = $select.data('saved');
            const $status  = $select.siblings('.wit-models-status');
            const $btn     = $select.siblings('.wit-refresh-models');
            const $key     = $('#' + keyField);
            const apiKey   = $key.val().trim();
            // An empty field is fine when a key is already saved: the server
            // uses the stored one. The checkbox only exists when a key exists.
            const hasSaved = $key.siblings('p').find('input[type=checkbox]').length > 0;

            if (!apiKey && !hasSaved) {
                $status.css('color', '#cc0000').text('Introduce la API key primero y guarda los ajustes.');
                return;
            }

            $btn.prop('disabled', true).text('Cargando...');
            $select.prop('disabled', true);
            $status.css('color', '#666').text('Consultando API...');

            $.ajax({
                url:     witAdmin.ajax_url,
                type:    'POST',
                timeout: 20000,
                data: {
                    action:   'wit_fetch_models',
                    nonce:    witAdmin.nonce,
                    provider: provider,
                    api_key:  apiKey
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('↻ Actualizar lista');
                    $select.prop('disabled', false);

                    if (!response.success) {
                        $status.css('color', '#cc0000')
                               .text('Error: ' + (response.data ? response.data.message : 'Error desconocido'));
                        return;
                    }

                    const models = response.data.models;
                    if (!models || models.length === 0) {
                        $status.css('color', '#cc0000').text('No se encontraron modelos para este proveedor.');
                        return;
                    }

                    $select.empty();
                    var currentVal = savedVal || '';
                    var matched    = false;

                    models.forEach(function(model) {
                        var label = (model.name && model.name !== model.id)
                            ? model.name + '  (' + model.id + ')'
                            : model.id;
                        var $opt = $('<option>').val(model.id).text(label);
                        if (model.id === currentVal) {
                            $opt.prop('selected', true);
                            matched = true;
                        }
                        $select.append($opt);
                    });

                    if (!matched && currentVal) {
                        $select.prepend(
                            $('<option>').val(currentVal).prop('selected', true)
                                .text(currentVal + ' (guardado — puede estar deprecado)')
                        );
                    }

                    $status.css('color', '#007017').text(models.length + ' modelos disponibles.');
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false).text('↻ Actualizar lista');
                    $select.prop('disabled', false);
                    $status.css('color', '#cc0000').text('Error de red: ' + error);
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        WitAdmin.init();
        WitModels.init();
    });

})(jQuery);
