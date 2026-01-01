<?php
/**
 * Logs View
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wit-logs">
    <h1><?php _e('Translation Logs', 'wpml-imagina-translate'); ?></h1>

    <?php if (empty($logs)): ?>
        <div class="notice notice-info">
            <p><?php _e('No hay logs de traducción todavía.', 'wpml-imagina-translate'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Post', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('De', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('A', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('Proveedor', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('Estado', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('Mensaje', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('Fecha', 'wpml-imagina-translate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($log['post_title']); ?></strong>
                            <br>
                            <small>ID: <?php echo esc_html($log['post_id']); ?></small>
                        </td>
                        <td><?php echo esc_html(strtoupper($log['source_lang'])); ?></td>
                        <td><?php echo esc_html(strtoupper($log['target_lang'])); ?></td>
                        <td><?php echo esc_html($log['ai_provider']); ?></td>
                        <td>
                            <?php if ($log['status'] === 'success'): ?>
                                <span class="wit-status-success">✓ <?php _e('Exitoso', 'wpml-imagina-translate'); ?></span>
                            <?php else: ?>
                                <span class="wit-status-error">✗ <?php _e('Error', 'wpml-imagina-translate'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($log['message']); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
