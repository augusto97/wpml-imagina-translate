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
                        $selected_post_types = isset($_GET['post_types']) ? (array)$_GET['post_types'] : array('post', 'page');
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

    <?php if ($target_language && !empty($pending_posts)): ?>
        <!-- Posts List -->
        <div class="wit-posts-section">
            <h2><?php _e('Posts para Traducción', 'wpml-imagina-translate'); ?></h2>
            <p class="description"><?php _e('Muestra todos los posts del idioma principal. Los que ya tienen traducción pueden ser re-traducidos.', 'wpml-imagina-translate'); ?></p>

            <div class="wit-batch-actions">
                <button type="button" id="wit-select-all" class="button">
                    <?php _e('Seleccionar Todos', 'wpml-imagina-translate'); ?>
                </button>
                <button type="button" id="wit-translate-selected" class="button button-primary" disabled>
                    <?php _e('Traducir Seleccionados', 'wpml-imagina-translate'); ?>
                </button>
                <span id="wit-selected-count">0 seleccionados</span>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column">
                            <input type="checkbox" id="wit-check-all">
                        </td>
                        <th><?php _e('Título', 'wpml-imagina-translate'); ?></th>
                        <th><?php _e('Tipo', 'wpml-imagina-translate'); ?></th>
                        <th><?php _e('Estado Traducción', 'wpml-imagina-translate'); ?></th>
                        <th><?php _e('Acciones', 'wpml-imagina-translate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_posts as $post): ?>
                        <tr data-post-id="<?php echo esc_attr($post['id']); ?>">
                            <th class="check-column">
                                <input type="checkbox" class="wit-post-checkbox" value="<?php echo esc_attr($post['id']); ?>">
                            </th>
                            <td>
                                <strong><?php echo esc_html($post['title']); ?></strong>
                            </td>
                            <td><?php echo esc_html($post['type']); ?></td>
                            <td>
                                <?php if ($post['translation_exists']): ?>
                                    <span class="wit-status-success">✓ <?php _e('Traducido', 'wpml-imagina-translate'); ?></span>
                                <?php else: ?>
                                    <span class="wit-status-pending">⚠ <?php _e('Pendiente', 'wpml-imagina-translate'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button"
                                        class="button button-small button-primary wit-translate-single"
                                        data-post-id="<?php echo esc_attr($post['id']); ?>"
                                        data-target-lang="<?php echo esc_attr($target_language); ?>">
                                    <?php echo $post['translation_exists'] ? __('Re-Traducir', 'wpml-imagina-translate') : __('Traducir Ahora', 'wpml-imagina-translate'); ?>
                                </button>
                                <a href="<?php echo esc_url($post['edit_url']); ?>" class="button button-small" target="_blank">
                                    <?php _e('Ver Original', 'wpml-imagina-translate'); ?>
                                </a>
                                <?php if ($post['translation_exists'] && $post['translation_edit_url']): ?>
                                    <a href="<?php echo esc_url($post['translation_edit_url']); ?>" class="button button-small" target="_blank">
                                        <?php _e('Editar Traducción', 'wpml-imagina-translate'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div id="wit-progress" style="display: none;">
                <h3><?php _e('Progreso de Traducción', 'wpml-imagina-translate'); ?></h3>
                <div class="wit-progress-bar">
                    <div class="wit-progress-fill" style="width: 0%"></div>
                </div>
                <p class="wit-progress-text">0 / 0</p>
                <div id="wit-progress-log"></div>
            </div>
        </div>

    <?php elseif ($target_language): ?>
        <div class="notice notice-success">
            <p><?php _e('¡Excelente! No hay posts pendientes de traducción para este idioma.', 'wpml-imagina-translate'); ?></p>
        </div>
    <?php endif; ?>
</div>
