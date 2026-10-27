<?php
/**
 * Dashboard View
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wit-dashboard">
    <h1><?php _e('WPML Imagina Translate - Dashboard', 'wpml-imagina-translate'); ?></h1>

    <!-- Statistics -->
    <div class="wit-stats">
        <div class="wit-stat-box">
            <h3><?php echo esc_html($stats['total']); ?></h3>
            <p><?php _e('Total Traducciones', 'wpml-imagina-translate'); ?></p>
        </div>
        <div class="wit-stat-box">
            <h3><?php echo esc_html($stats['successful']); ?></h3>
            <p><?php _e('Exitosas', 'wpml-imagina-translate'); ?></p>
        </div>
        <div class="wit-stat-box">
            <h3><?php echo esc_html($stats['failed']); ?></h3>
            <p><?php _e('Fallidas', 'wpml-imagina-translate'); ?></p>
        </div>
        <div class="wit-stat-box">
            <h3><?php echo esc_html($stats['recent']); ?></h3>
            <p><?php _e('Últimos 7 días', 'wpml-imagina-translate'); ?></p>
        </div>
        <div class="wit-stat-box">
            <h3><?php echo esc_html(number_format_i18n($stats['tokens_30d'])); ?></h3>
            <p><?php esc_html_e('Tokens de API (30 días)', 'wpml-imagina-translate'); ?></p>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="wit-filter-section">
        <h2><?php _e('Seleccionar Posts para Traducir', 'wpml-imagina-translate'); ?></h2>

        <form method="get" action="">
            <input type="hidden" name="page" value="wpml-ia-translate">

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="target_lang"><?php _e('Idioma Destino', 'wpml-imagina-translate'); ?></label>
                    </th>
                    <td>
                        <select name="target_lang" id="target_lang" required>
                            <option value=""><?php _e('Seleccionar idioma...', 'wpml-imagina-translate'); ?></option>
                            <?php foreach ($languages as $lang): ?>
                                <?php if ($lang['code'] !== $default_language): ?>
                                    <option value="<?php echo esc_attr($lang['code']); ?>" <?php selected($target_language, $lang['code']); ?>>
                                        <?php echo esc_html($lang['name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php _e('Tipos de Post', 'wpml-imagina-translate'); ?></label>
                    </th>
                    <td>
                        <?php
                        // $selected_post_types is sanitised and validated by
                        // WIT_Translation_Dashboard::render_dashboard().
                        $post_types = get_post_types(array('public' => true), 'objects');
                        foreach ($post_types as $post_type):
                            if (in_array($post_type->name, array('attachment', 'revision', 'nav_menu_item'))) {
                                continue;
                            }
                        ?>
                            <label style="margin-right: 15px;">
                                <input type="checkbox"
                                       name="post_types[]"
                                       value="<?php echo esc_attr($post_type->name); ?>"
                                       <?php checked(in_array($post_type->name, $selected_post_types)); ?>>
                                <?php echo esc_html($post_type->label); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php _e('Buscar Posts Pendientes', 'wpml-imagina-translate'); ?>
                </button>
            </p>
        </form>
    </div>

    <?php if ($target_language && $listing) : ?>
        <?php
        $status_labels = array(
            'missing'  => array('wit-status-pending', __('Pendiente', 'wpml-imagina-translate')),
            'outdated' => array('wit-status-pending', __('Desactualizada', 'wpml-imagina-translate')),
            'current'  => array('wit-status-success', __('Al día', 'wpml-imagina-translate')),
            'unknown'  => array('wit-status-muted', __('Traducida (hecha fuera del plugin)', 'wpml-imagina-translate')),
        );
        $base_args = array(
            'page'        => 'wpml-ia-translate',
            'target_lang' => $target_language,
            'post_types'  => $selected_post_types,
            'wit_s'       => $search !== '' ? $search : null,
        );
        ?>
        <div class="wit-posts-section">
            <h2><?php esc_html_e('Contenidos', 'wpml-imagina-translate'); ?></h2>

            <ul class="subsubsub wit-status-tabs">
                <?php
                $tabs = array();
                foreach ($filters as $key => $label) {
                    $count  = isset($listing['counts'][$key]) ? $listing['counts'][$key] : 0;
                    $url    = add_query_arg(array_merge($base_args, array('wit_status' => $key)), admin_url('admin.php'));
                    $tabs[] = sprintf(
                        '<li><a href="%s"%s>%s <span class="count">(%s)</span></a></li>',
                        esc_url($url),
                        $key === $status_filter ? ' class="current" aria-current="page"' : '',
                        esc_html($label),
                        esc_html(number_format_i18n($count))
                    );
                }
                echo implode(' | ', $tabs); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
                ?>
            </ul>

            <form method="get" class="wit-search-box">
                <input type="hidden" name="page" value="wpml-ia-translate">
                <input type="hidden" name="target_lang" value="<?php echo esc_attr($target_language); ?>">
                <input type="hidden" name="wit_status" value="<?php echo esc_attr($status_filter); ?>">
                <?php foreach ($selected_post_types as $type) : ?>
                    <input type="hidden" name="post_types[]" value="<?php echo esc_attr($type); ?>">
                <?php endforeach; ?>
                <label class="screen-reader-text" for="wit-search"><?php esc_html_e('Buscar contenidos', 'wpml-imagina-translate'); ?></label>
                <input type="search" id="wit-search" name="wit_s" value="<?php echo esc_attr($search); ?>">
                <button type="submit" class="button"><?php esc_html_e('Buscar', 'wpml-imagina-translate'); ?></button>
            </form>

            <?php if ($listing['truncated']) : ?>
                <p class="description"><?php
                    printf(
                        /* translators: %s: number of posts */
                        esc_html__('Este sitio tiene muchos contenidos: se revisan los %s modificados más recientemente. Usa la búsqueda para encontrar los demás.', 'wpml-imagina-translate'),
                        esc_html(number_format_i18n(WIT_Translation_Dashboard::SCAN_LIMIT))
                    );
                ?></p>
            <?php endif; ?>

            <?php
            $queue_status = WIT_Queue::instance()->status();
            if ($queue_status['pending'] > 0 || $queue_status['processing'] > 0) :
                ?>
                <div class="notice notice-info inline" style="margin:12px 0;">
                    <p>
                        <strong><?php esc_html_e('Hay una traducción en curso.', 'wpml-imagina-translate'); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: pending items, 2: completed items */
                            esc_html__('%1$s pendientes, %2$s completados.', 'wpml-imagina-translate'),
                            esc_html(number_format_i18n($queue_status['pending'] + $queue_status['processing'])),
                            esc_html(number_format_i18n($queue_status['done']))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (empty($pending_posts)) : ?>
                <div class="notice notice-success inline">
                    <p><?php
                        echo $status_filter === 'pending'
                            ? esc_html__('¡Excelente! No hay nada pendiente de traducir a este idioma.', 'wpml-imagina-translate')
                            : esc_html__('No hay contenidos en esta vista.', 'wpml-imagina-translate');
                    ?></p>
                </div>
            <?php else : ?>
                <div class="wit-batch-actions">
                    <button type="button" id="wit-select-all" class="button">
                        <?php esc_html_e('Seleccionar todos', 'wpml-imagina-translate'); ?>
                    </button>
                    <button type="button" id="wit-translate-selected" class="button button-primary" disabled>
                        <?php esc_html_e('Traducir seleccionados', 'wpml-imagina-translate'); ?>
                    </button>
                    <span id="wit-selected-count"><?php printf(esc_html__('%d seleccionados', 'wpml-imagina-translate'), 0); ?></span>
                </div>
                <p class="description">
                    <?php esc_html_e('La traducción por lotes se procesa en el servidor. Puedes cerrar esta página mientras se completa.', 'wpml-imagina-translate'); ?>
                </p>

                <table class="wp-list-table widefat fixed striped table-view-list wit-posts-table">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="wit-check-all">
                            </td>
                            <th scope="col" class="manage-column column-title column-primary"><?php esc_html_e('Título', 'wpml-imagina-translate'); ?></th>
                            <th scope="col" class="manage-column column-type"><?php esc_html_e('Tipo', 'wpml-imagina-translate'); ?></th>
                            <th scope="col" class="manage-column column-status"><?php esc_html_e('Traducción', 'wpml-imagina-translate'); ?></th>
                            <th scope="col" class="manage-column column-actions"><?php esc_html_e('Acciones', 'wpml-imagina-translate'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_posts as $post) :
                            $type_object = get_post_type_object($post['type']);
                            $label       = isset($status_labels[$post['status']]) ? $status_labels[$post['status']] : $status_labels['missing'];
                            $exists      = $post['translation_id'] > 0;
                            ?>
                            <tr data-post-id="<?php echo esc_attr($post['id']); ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" class="wit-post-checkbox"
                                           value="<?php echo esc_attr($post['id']); ?>"
                                           data-edited="<?php echo $post['edited'] ? '1' : '0'; ?>">
                                </th>
                                <td class="column-title column-primary">
                                    <strong><?php echo esc_html($post['title']); ?></strong>
                                    <button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e('Mostrar más detalles', 'wpml-imagina-translate'); ?></span></button>
                                </td>
                                <td class="column-type" data-colname="<?php esc_attr_e('Tipo', 'wpml-imagina-translate'); ?>">
                                    <?php echo esc_html($type_object ? $type_object->labels->singular_name : $post['type']); ?>
                                </td>
                                <td class="column-status" data-colname="<?php esc_attr_e('Traducción', 'wpml-imagina-translate'); ?>">
                                    <span class="wit-status-label <?php echo esc_attr($label[0]); ?>"><?php echo esc_html($label[1]); ?></span>
                                    <?php if ($post['edited']) : ?>
                                        <span class="wit-badge wit-badge-edited" title="<?php esc_attr_e('Alguien la modificó después de traducirla. Traducir de nuevo reemplazaría esos cambios.', 'wpml-imagina-translate'); ?>">
                                            <?php esc_html_e('Editada a mano', 'wpml-imagina-translate'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($exists && $post['translation_status'] === 'draft') : ?>
                                        <span class="wit-badge"><?php esc_html_e('Borrador', 'wpml-imagina-translate'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions" data-colname="<?php esc_attr_e('Acciones', 'wpml-imagina-translate'); ?>">
                                    <button type="button"
                                            class="button button-small button-primary wit-translate-single"
                                            data-post-id="<?php echo esc_attr($post['id']); ?>"
                                            data-target-lang="<?php echo esc_attr($target_language); ?>"
                                            data-exists="<?php echo $exists ? '1' : '0'; ?>"
                                            data-edited="<?php echo $post['edited'] ? '1' : '0'; ?>">
                                        <?php echo $exists ? esc_html__('Re-Traducir', 'wpml-imagina-translate') : esc_html__('Traducir ahora', 'wpml-imagina-translate'); ?>
                                    </button>
                                    <a href="<?php echo esc_url($post['edit_url']); ?>" class="button button-small" target="_blank" rel="noopener">
                                        <?php esc_html_e('Ver original', 'wpml-imagina-translate'); ?>
                                    </a>
                                    <?php if ($exists && $post['translation_edit_url']) : ?>
                                        <a href="<?php echo esc_url($post['translation_edit_url']); ?>" class="button button-small wit-edit-translation-link" target="_blank" rel="noopener">
                                            <?php esc_html_e('Editar traducción', 'wpml-imagina-translate'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($listing['pages'] > 1) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php
                                printf(
                                    /* translators: %s: number of items */
                                    esc_html(_n('%s elemento', '%s elementos', $listing['total'], 'wpml-imagina-translate')),
                                    esc_html(number_format_i18n($listing['total']))
                                );
                            ?></span>
                            <?php
                            echo paginate_links(array( // phpcs:ignore WordPress.Security.EscapeOutput -- core output.
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'current'   => $listing['page'],
                                'total'     => $listing['pages'],
                                'prev_text' => '&lsaquo;',
                                'next_text' => '&rsaquo;',
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div id="wit-progress" style="display: none;">
                    <h3><?php esc_html_e('Progreso de traducción', 'wpml-imagina-translate'); ?></h3>
                    <div class="wit-progress-bar">
                        <div class="wit-progress-fill" style="width: 0%"></div>
                    </div>
                    <p class="wit-progress-text">0 / 0</p>
                    <div id="wit-progress-log"></div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
