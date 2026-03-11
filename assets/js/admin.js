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

            const targetLanguage = $('#target_lang').val();
            WitAdmin.processBatch(selectedPosts, targetLanguage);
        },

        translateSingle: function(e) {
            e.preventDefault();

            const $button = $(this);
            const postId = $button.data('post-id');
            const targetLanguage = $button.data('target-lang');
            const $row = $button.closest('tr');

            $button.addClass('wit-translating').prop('disabled', true).text(witAdmin.strings.translating);

            WitAdmin.translatePost(postId, targetLanguage, function(success, data) {
                $button.removeClass('wit-translating').prop('disabled', false);

                if (success) {
                    // Show debug log in console
                    if (data.debug && data.debug.length > 0) {
                        console.group('Translation Debug Info - Post #' + postId);
                        data.debug.forEach(function(log) {
                            console.log(log);
                        });
                        console.groupEnd();
                    }

                    $button.text('✓ ' + witAdmin.strings.success).removeClass('button-primary').addClass('button-secondary');

                    // Update row to show translation status
                    const $statusCell = $row.find('td:eq(2)');
                    $statusCell.html('<span class="wit-status-success">✓ Traducido</span>');

                    // Add edit translation link if not exists
                    if (data.edit_url) {
                        var existingLinks = $row.find('.wit-edit-translation-link');
                        if (existingLinks.length === 0) {
                            var $editLink = $('<a>')
                                .attr('href', data.edit_url)
                                .attr('target', '_blank')
                                .addClass('button button-small wit-edit-translation-link')
                                .text('Editar Traducción');
                            $button.after($editLink);
                        }
                    }

                    // Change button text to Re-Traducir
                    setTimeout(function() {
                        $button.text('Re-Traducir').removeClass('button-secondary').addClass('button-primary');
                    }, 2000);

                } else {
                    // Show debug log on error too
                    if (data.debug && data.debug.length > 0) {
                        console.group('Translation Debug (ERROR) - Post #' + postId);
                        data.debug.forEach(function(log) {
                            console.log(log);
                        });
                        console.groupEnd();
                    }
                    console.error('Translation error:', data.message);
                    $button.text('Error - Reintentar');
                    alert(witAdmin.strings.error + ': ' + (data.message || 'Error desconocido'));
                }
            });
        },

        processBatch: function(postIds, targetLanguage) {
            let processed = 0;
            const total = postIds.length;
            const results = [];

            // Show progress bar
            $('#wit-progress').show();
            $('#wit-progress-log').html('');
            this.updateProgress(0, total);

            // Disable action buttons
            $('#wit-translate-selected, #wit-select-all').prop('disabled', true);

            // Process posts sequentially
            const processNext = () => {
                if (processed >= total) {
                    // All done
                    this.onBatchComplete(results);
                    return;
                }

                const postId = postIds[processed];
                const $row = $('tr[data-post-id="' + postId + '"]');
                const postTitle = $row.find('strong').text();

                this.addLogEntry('Traduciendo: ' + postTitle, 'processing');

                this.translatePost(postId, targetLanguage, (success, data) => {
                    processed++;

                    results.push({
                        postId: postId,
                        title: postTitle,
                        success: success,
                        message: data.message
                    });

                    if (success) {
                        this.addLogEntry('✓ ' + postTitle + ' - Traducido exitosamente', 'success');
                        $row.fadeOut(500, function() { $(this).remove(); });
                    } else {
                        this.addLogEntry('✗ ' + postTitle + ' - Error: ' + data.message, 'error');
                    }

                    this.updateProgress(processed, total);

                    // Process next after a small delay
                    setTimeout(processNext, 500);
                });
            };

            processNext();
        },

        translatePost: function(postId, targetLanguage, callback) {
            $.ajax({
                url: witAdmin.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wit_translate_post',
                    nonce: witAdmin.nonce,
                    post_id: postId,
                    target_language: targetLanguage
                },
                success: function(response) {
                    var data = (response && response.data) ? response.data : {};
                    if (response && response.success) {
                        callback(true, data);
                    } else {
                        if (!data.message) {
                            data.message = witAdmin.strings.error || 'Error desconocido';
                        }
                        callback(false, data);
                    }
                },
                error: function(xhr, status, error) {
                    var msg = error || (xhr.status ? 'HTTP ' + xhr.status : witAdmin.strings.error || 'Error de red');
                    callback(false, { message: msg });
                }
            });
        },

        updateProgress: function(current, total) {
            const percentage = Math.round((current / total) * 100);
            $('.wit-progress-fill').css('width', percentage + '%');
            $('.wit-progress-text').text(current + ' / ' + total);
        },

        addLogEntry: function(message, type) {
            const $log = $('#wit-progress-log');
            const timestamp = new Date().toLocaleTimeString();
            const entry = $('<div>')
                .addClass('wit-log-entry')
                .addClass(type)
                .text('[' + timestamp + '] ' + message);

            $log.append(entry);
            $log.scrollTop($log[0].scrollHeight);
        },

        onBatchComplete: function(results) {
            const successful = results.filter(r => r.success).length;
            const failed = results.filter(r => !r.success).length;

            this.addLogEntry('', 'success');
            this.addLogEntry('=== PROCESO COMPLETADO ===', 'success');
            this.addLogEntry('Total: ' + results.length, 'success');
            this.addLogEntry('Exitosos: ' + successful, 'success');
            this.addLogEntry('Fallidos: ' + failed, failed > 0 ? 'error' : 'success');

            // Re-enable buttons
            $('#wit-translate-selected, #wit-select-all').prop('disabled', false);
            $('#wit-check-all').prop('checked', false);
            this.updateSelectedCount();

            // Show completion alert
            alert('Traducción completada!\n\nExitosos: ' + successful + '\nFallidos: ' + failed);
        }
    };

    // -----------------------------------------------------------------------
    // Dynamic model loader for Settings page
    // Populates <select> elements with real models from each provider's API.
    // Auto-loads on page open if an API key is already saved.
    // -----------------------------------------------------------------------
    const WitModels = {

        init: function() {
            // Auto-load models for every provider that already has a key saved
            $('.wit-model-select').each(function() {
                const $select   = $(this);
                const keyField  = $select.data('key-field');
                const apiKey    = $('#' + keyField).val().trim();
                if (apiKey) {
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

        /**
         * Load models from the API and populate the <select>.
         * @param {jQuery} $select  The .wit-model-select element
         */
        load: function($select) {
            const provider  = $select.data('provider');
            const keyField  = $select.data('key-field');
            const savedVal  = $select.data('saved');
            const $status   = $select.siblings('.wit-models-status');
            const $btn      = $select.siblings('.wit-refresh-models');
            const apiKey    = $('#' + keyField).val().trim();

            if (!apiKey) {
                $status.css('color', '#cc0000').text('Introduce la API key primero y guarda los ajustes.');
                return;
            }

            $btn.prop('disabled', true).text('Cargando...');
            $select.prop('disabled', true);
            $status.css('color', '#666').text('Consultando API...');

            $.ajax({
                url: witAdmin.ajax_url,
                type: 'POST',
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
                        $status.css('color', '#cc0000').text('Error: ' + (response.data ? response.data.message : 'Error desconocido'));
                        return;
                    }

                    const models = response.data.models;
                    if (!models || models.length === 0) {
                        $status.css('color', '#cc0000').text('No se encontraron modelos para este proveedor.');
                        return;
                    }

                    // Rebuild <select> options with the full model list
                    $select.empty();
                    var currentVal = savedVal || '';
                    var matched = false;

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

                    // If the previously saved model is no longer in the list, add it at the top
                    if (!matched && currentVal) {
                        $select.prepend(
                            $('<option>').val(currentVal).prop('selected', true)
                                .text(currentVal + ' (guardado — puede estar deprecado)')
                        );
                    }

                    $status.css('color', '#007017')
                           .text(models.length + ' modelos disponibles.');
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
