<?php
/**
 * Strings View - WPML String Translation strings, translated with AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

$base_args = array(
    'page'       => 'wpml-ia-translate-strings',
    'wit_lang'   => $language,
    'wit_domain' => $domain !== '' ? $domain : null,
    'wit_s'      => $search !== '' ? $search : null,
);
$status_class = array(
    'pending'      => array('wit-status-pending', __('Pendiente', 'wpml-imagina-translate')),
    'needs_update' => array('wit-status-pending', __('Necesita actualización', 'wpml-imagina-translate')),
    'translated'   => array('wit-status-success', __('Traducida', 'wpml-imagina-translate')),
);
?>

<div class="wrap wit-strings">
    <h1><?php esc_html_e('Cadenas de WPML', 'wpml-imagina-translate'); ?></h1>
    <p class="description" style="max-width:760px;">
        <?php esc_html_e('Textos del tema y de los plugins, widgets, textos de administración y el título del sitio: lo que sigue en el idioma original cuando todas las páginas ya están traducidas. El contenido de Elementor y Gutenberg no está aquí: se traduce con su página.', 'wpml-imagina-translate'); ?>
    </p>

    <?php foreach ($problems as $problem) : ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($problem); ?></p></div>
    <?php endforeach; ?>

    <form method="get" class="wit-strings-filter">
        <input type="hidden" name="page" value="wpml-ia-translate-strings">
        <input type="hidden" name="wit_status" value="<?php echo esc_attr($status); ?>">
        <label>
            <?php esc_html_e('Traducir al', 'wpml-imagina-translate'); ?>
            <select name="wit_lang" id="wit-strings-lang">
                <?php foreach ($languages as $lang) : ?>
                    <option value="<?php echo esc_attr($lang['code']); ?>" <?php selected($language, $lang['code']); ?>><?php echo esc_html($lang['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <?php esc_html_e('Dominio', 'wpml-imagina-translate'); ?>
            <select name="wit_domain" id="wit-strings-domain">
                <option value=""><?php esc_html_e('Todos', 'wpml-imagina-translate'); ?></option>
                <?php foreach ($domains as $d) : ?>
                    <option value="<?php echo esc_attr($d['domain']); ?>" <?php selected($domain, $d['domain']); ?>>
                        <?php echo esc_html(sprintf('%s (%s / %s)', $d['domain'], number_format_i18n($d['pending']), number_format_i18n($d['total']))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="screen-reader-text" for="wit-strings-search"><?php esc_html_e('Buscar cadenas', 'wpml-imagina-translate'); ?></label>
        <input type="search" id="wit-strings-search" name="wit_s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Buscar texto…', 'wpml-imagina-translate'); ?>">
        <button type="submit" class="button"><?php esc_html_e('Filtrar', 'wpml-imagina-translate'); ?></button>
    </form>

    <ul class="subsubsub wit-status-tabs">
        <?php
        $tabs = array();
        foreach ($statuses as $key => $label) {
            $tabs[] = sprintf(
                '<li><a href="%s"%s>%s</a></li>',
                esc_url(add_query_arg(array_merge($base_args, array('wit_status' => $key)), admin_url('admin.php'))),
                $key === $status ? ' class="current" aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo implode(' | ', $tabs); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        ?>
    </ul>

    <?php if (empty($listing['items'])) : ?>
        <div class="notice notice-success inline">
            <p><?php
                echo $status === 'pending'
                    ? esc_html__('No hay cadenas pendientes con este filtro.', 'wpml-imagina-translate')
                    : esc_html__('No hay cadenas en esta vista.', 'wpml-imagina-translate');
            ?></p>
        </div>
    <?php else : ?>
        <div class="wit-batch-actions">
            <button type="button" id="wit-strings-translate-selected" class="button button-primary" disabled>
                <?php esc_html_e('Traducir seleccionadas', 'wpml-imagina-translate'); ?>
            </button>
            <?php if ($status === 'pending' || $status === 'needs_update') : ?>
                <button type="button" id="wit-strings-translate-all" class="button"
                        data-lang="<?php echo esc_attr($language); ?>"
                        data-domain="<?php echo esc_attr($domain); ?>"
                        data-status="<?php echo esc_attr($status); ?>"
                        data-search="<?php echo esc_attr($search); ?>">
                    <?php
                    printf(
                        /* translators: %s: number of strings */
                        esc_html__('Traducir las %s de este filtro', 'wpml-imagina-translate'),
                        esc_html(number_format_i18n($listing['total']))
                    );
                    ?>
                </button>
            <?php endif; ?>
            <span id="wit-selected-count"><?php printf(esc_html__('%d seleccionados', 'wpml-imagina-translate'), 0); ?></span>
        </div>

        <div id="wit-progress" style="display:none;">
            <div class="wit-progress-bar"><div class="wit-progress-fill" style="width:0%"></div></div>
            <p class="wit-progress-text">0 / 0</p>
            <div id="wit-progress-log"></div>
        </div>

        <table class="wp-list-table widefat fixed striped table-view-list wit-strings-table" data-lang="<?php echo esc_attr($language); ?>">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column"><input type="checkbox" id="wit-check-all"></td>
                    <th scope="col" class="manage-column column-primary"><?php esc_html_e('Original', 'wpml-imagina-translate'); ?></th>
                    <th scope="col" class="manage-column column-translation"><?php esc_html_e('Traducción', 'wpml-imagina-translate'); ?></th>
                    <th scope="col" class="manage-column column-domain"><?php esc_html_e('Dominio', 'wpml-imagina-translate'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php esc_html_e('Estado', 'wpml-imagina-translate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($listing['items'] as $item) :
                    $label = $status_class[$item['status']];
                    ?>
                    <tr data-string-id="<?php echo esc_attr($item['id']); ?>">
                        <th scope="row" class="check-column">
                            <input type="checkbox" class="wit-post-checkbox wit-string-checkbox" value="<?php echo esc_attr($item['id']); ?>">
                        </th>
                        <td class="column-primary">
                            <code class="wit-string-text"><?php echo esc_html($item['text']); ?></code>
                            <span class="wit-badge"><?php echo esc_html(strtoupper($item['source_language'])); ?></span>
                            <button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e('Mostrar más detalles', 'wpml-imagina-translate'); ?></span></button>
                        </td>
                        <td class="column-translation" data-colname="<?php esc_attr_e('Traducción', 'wpml-imagina-translate'); ?>">
                            <span class="wit-string-translation"><?php echo $item['translation'] !== '' ? esc_html($item['translation']) : '—'; ?></span>
                            <?php if ($item['from_file']) : ?>
                                <span class="wit-badge" title="<?php esc_attr_e('Traducida por el fichero de idioma que trae el propio tema o plugin.', 'wpml-imagina-translate'); ?>">.mo</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-domain" data-colname="<?php esc_attr_e('Dominio', 'wpml-imagina-translate'); ?>"><?php echo esc_html($item['domain']); ?></td>
                        <td class="column-status" data-colname="<?php esc_attr_e('Estado', 'wpml-imagina-translate'); ?>">
                            <span class="wit-status-label <?php echo esc_attr($label[0]); ?>"><?php echo esc_html($label[1]); ?></span>
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
                            /* translators: %s: number of strings */
                            esc_html(_n('%s cadena', '%s cadenas', $listing['total'], 'wpml-imagina-translate')),
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
    <?php endif; ?>
</div>
