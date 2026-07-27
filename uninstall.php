<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the WordPress admin, never on
 * deactivation. Removes the settings option (which stores API keys), the
 * translation log table and any leftover job transients.
 *
 * Translated posts and their WPML links are deliberately left alone: they are
 * real content that the site owner may still want.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove all plugin data for a single site.
 */
function wit_uninstall_site() {
    global $wpdb;

    delete_option('wit_settings');
    delete_option('wit_db_version');

    wp_clear_scheduled_hook('wit_process_queue');

    foreach (array('wit_translation_logs', 'wit_translation_memory', 'wit_queue') as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }

    // Job status transients are named wit_job_{post_id}_{lang}. They expire on
    // their own, but deleting the plugin should not leave rows behind.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_wit\_job\_%'
            OR option_name LIKE '\_transient\_timeout\_wit\_job\_%'"
    );
}

if (is_multisite()) {
    $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));

    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        wit_uninstall_site();
        restore_current_blog();
    }
} else {
    wit_uninstall_site();
}
