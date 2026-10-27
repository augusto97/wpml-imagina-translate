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
        <div class="wit-table-scroll">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Post', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('De', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('A', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('Proveedor', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('Estado', 'wpml-imagina-translate'); ?></th>
                    <th><?php _e('Mensaje', 'wpml-imagina-translate'); ?></th>
                    <th><?php esc_html_e('Cadenas', 'wpml-imagina-translate'); ?></th>
                    <th><?php esc_html_e('Tokens', 'wpml-imagina-translate'); ?></th>
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
                        <td><?php echo esc_html($log['ai_provider'] === 'mcp' ? 'Claude (MCP)' : $log['ai_provider']); ?></td>
                        <td>
                            <?php if ($log['status'] === 'success'): ?>
                                <span class="wit-status-success">✓ <?php _e('Exitoso', 'wpml-imagina-translate'); ?></span>
                            <?php else: ?>
                                <span class="wit-status-error">✗ <?php _e('Error', 'wpml-imagina-translate'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($log['message']); ?></td>
                        <td>
                            <?php
                            $ai       = isset($log['strings_ai']) ? (int) $log['strings_ai'] : 0;
                            $memory   = isset($log['strings_memory']) ? (int) $log['strings_memory'] : 0;
                            $glossary = isset($log['strings_glossary']) ? (int) $log['strings_glossary'] : 0;
                            if ($ai + $memory + $glossary > 0) {
                                printf(
                                    /* translators: 1: strings translated by the AI, 2: from memory, 3: from glossary */
                                    esc_html__('%1$s IA · %2$s memoria · %3$s glosario', 'wpml-imagina-translate'),
                                    esc_html(number_format_i18n($ai)),
                                    esc_html(number_format_i18n($memory)),
                                    esc_html(number_format_i18n($glossary))
                                );
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $in  = isset($log['input_tokens']) ? (int) $log['input_tokens'] : 0;
                            $out = isset($log['output_tokens']) ? (int) $log['output_tokens'] : 0;
                            echo $in + $out > 0
                                ? esc_html(number_format_i18n($in) . ' / ' . number_format_i18n($out))
                                : '—';
                            ?>
                        </td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="description"><?php esc_html_e('Tokens: entrada / salida, tal como los factura el proveedor. Multiplícalos por su tarifa vigente para saber el coste. Lo traducido por Claude (MCP) o resuelto por la memoria y el glosario no consume tokens de API.', 'wpml-imagina-translate'); ?></p>

        <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array( // phpcs:ignore WordPress.Security.EscapeOutput -- core output.
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '&lsaquo;',
                        'next_text' => '&rsaquo;',
                    ));
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
