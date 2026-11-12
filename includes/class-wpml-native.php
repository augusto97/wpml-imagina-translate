<?php
/**
 * WPML Native - what this plugin does when the real WPML is installed.
 *
 * Everything else in the plugin talks to WPML through its documented hooks,
 * which also run against the test double. This class is the part that only
 * makes sense with WPML itself, learned by reading WPML 5.2 and String
 * Translation 5.2 and running against them:
 *
 *   - Translation Management status. WPML records "this translation is
 *     complete, made from this version of the original" when a post that is
 *     already linked as a translation is saved. A translation is inserted
 *     before it can be linked, so on creation WPML never saw it: its dashboard
 *     showed no status at all, and it never flagged the translation as needing
 *     an update when the original changed. record_translation() asks WPML to
 *     record it, the same way WPML records a translation made in the native
 *     editor.
 *
 *   - Custom field preferences. WPML knows, per field, whether to translate,
 *     copy, copy once or ignore it — from the site's own choices and from the
 *     wpml-config.xml of every plugin (Yoast, Rank Math, ACF...). Fields WPML
 *     marks "translate" are translated; fields marked "ignore" are not copied.
 *
 *   - Translation modes of post types and taxonomies, read and — for the MCP
 *     tools — written through WPML's own settings helper. Its public action is
 *     registered only in wp-admin, so a REST request (the MCP endpoint) has to
 *     use the helper directly.
 *
 * Every method degrades to "unknown" or a no-op without WPML, so callers never
 * need to check first.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_WPML_Native {

    const MODE_IGNORE    = 0;
    const MODE_COPY      = 1;
    const MODE_TRANSLATE = 2;
    const MODE_COPY_ONCE = 3;

    /** @var array<string,int|null> Field preferences resolved this request. */
    private static $mode_cache = array();

    /**
     * The real WPML, not a stand-in that only answers its hooks.
     *
     * @return bool
     */
    public static function is_active() {
        return defined('ICL_SITEPRESS_VERSION') && class_exists('SitePress');
    }

    /**
     * WPML's Translation Management is loaded (it is part of WPML core since
     * 4.5, but can be absent on some licences).
     *
     * @return bool
     */
    public static function has_tm() {
        return self::is_active() && has_action('wpml_tm_save_post') !== false;
    }

    // -----------------------------------------------------------------------
    // Translation Management status
    // -----------------------------------------------------------------------

    /**
     * Have WPML record a translation as complete and current.
     *
     * WPML's own path for translations made in the native editor: it stores
     * status "complete", the translator, and a fingerprint of the original, so
     * its dashboard shows the translation as done and marks it "needs update"
     * when the original changes.
     *
     * @param int $translation_id
     */
    public static function record_translation($translation_id) {
        if (!self::has_tm()) {
            return;
        }

        $post = get_post($translation_id);

        if (!$post) {
            return;
        }

        try {
            do_action('wpml_tm_save_post', (int) $translation_id, $post, false);
        } catch (\Throwable $e) {
            // WPML's bookkeeping must never fail a translation that is
            // already written.
            error_log('WIT: WPML no pudo registrar la traducción #' . $translation_id . ': ' . $e->getMessage());
        }
    }

    /**
     * WPML's own view of a translation: status and whether it needs an update.
     *
     * @param int $translation_id
     * @return array{status:int,needs_update:bool}|null Null when WPML has no record.
     */
    public static function tm_status($translation_id) {
        global $wpdb;

        if (!self::is_active()) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ts.status, ts.needs_update
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->prefix}icl_translation_status ts ON ts.translation_id = t.translation_id
             WHERE t.element_id = %d AND t.element_type LIKE %s",
            (int) $translation_id,
            'post\\_%'
        ));

        return $row ? array('status' => (int) $row->status, 'needs_update' => (bool) $row->needs_update) : null;
    }

    // -----------------------------------------------------------------------
    // Custom fields
    // -----------------------------------------------------------------------

    /**
     * WPML's preference for a custom field, or null when it has none.
     *
     * @param string $meta_key
     * @return int|null One of the MODE_* constants.
     */
    public static function field_mode($meta_key) {
        $meta_key = (string) $meta_key;

        if (array_key_exists($meta_key, self::$mode_cache)) {
            return self::$mode_cache[$meta_key];
        }

        $mode = null;

        if (self::is_active()) {
            try {
                if (class_exists('\WPML\TM\Settings\PreferenceResolver')) {
                    // WPML 5.2+
                    $mode = \WPML\TM\Settings\PreferenceResolver::mode('post', $meta_key);
                } else {
                    global $sitepress;
                    $tm   = $sitepress ? $sitepress->get_setting('translation-management', array()) : array();
                    $mode = isset($tm['custom_fields_translation'][$meta_key]) ? (int) $tm['custom_fields_translation'][$meta_key] : null;
                }
            } catch (\Throwable $e) {
                $mode = null;
            }
        }

        return self::$mode_cache[$meta_key] = ($mode === null ? null : (int) $mode);
    }

    /**
     * Meta keys of a post that WPML says to translate.
     *
     * @param int $post_id
     * @return string[]
     */
    public static function fields_to_translate($post_id) {
        if (!self::is_active()) {
            return array();
        }

        $keys = array();

        foreach (array_keys((array) get_post_meta($post_id)) as $key) {
            if (self::field_mode($key) === self::MODE_TRANSLATE) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * Set WPML's preference for a custom field.
     *
     * @param string $meta_key
     * @param int    $mode
     * @return bool
     */
    public static function set_field_mode($meta_key, $mode) {
        if (!self::is_active() || !in_array((int) $mode, array(0, 1, 2, 3), true)) {
            return false;
        }

        $meta_key = sanitize_text_field((string) $meta_key);
        if ($meta_key === '') {
            return false;
        }

        try {
            if (class_exists('\WPML\TM\Settings\PreferenceWriter')) {
                \WPML\TM\Settings\PreferenceWriter::setModes('post', array($meta_key => (int) $mode));
            } elseif (function_exists('wpml_translation_management')) {
                wpml_translation_management()->wpml_save_custom_field_translation_option($meta_key, (int) $mode);
            } else {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        unset(self::$mode_cache[$meta_key]);

        return self::field_mode($meta_key) === (int) $mode;
    }

    // -----------------------------------------------------------------------
    // Post types and taxonomies
    // -----------------------------------------------------------------------

    /**
     * WPML's translation mode of a post type.
     *
     * @param string $post_type
     * @return string translate, display_as_translated, do_not_translate or unknown
     */
    public static function post_type_mode($post_type) {
        $translated = apply_filters('wpml_is_translated_post_type', null, $post_type);

        if ($translated === null) {
            return 'unknown';
        }

        if (!$translated) {
            return 'do_not_translate';
        }

        return apply_filters('wpml_is_display_as_translated_post_type', false, $post_type)
            ? 'display_as_translated'
            : 'translate';
    }

    /**
     * Change WPML's translation mode of a post type.
     *
     * @param string $post_type
     * @param string $mode translate, display_as_translated or do_not_translate
     * @return bool
     */
    public static function set_post_type_mode($post_type, $mode) {
        if (!self::is_active() || !function_exists('wpml_load_settings_helper') || !post_type_exists($post_type)) {
            return false;
        }

        $helper = wpml_load_settings_helper();

        switch ($mode) {
            case 'translate':
                $helper->set_post_type_translatable($post_type);
                break;
            case 'display_as_translated':
                $helper->set_post_type_display_as_translated($post_type);
                break;
            case 'do_not_translate':
                $helper->set_post_type_not_translatable($post_type);
                break;
            default:
                return false;
        }

        self::flush_wpml_caches();

        return self::post_type_mode($post_type) === $mode;
    }

    /**
     * Whether WPML translates a taxonomy. True when unknown: without WPML the
     * plugin has always translated terms.
     *
     * @param string $taxonomy
     * @return bool
     */
    public static function is_translated_taxonomy($taxonomy) {
        $translated = apply_filters('wpml_is_translated_taxonomy', null, $taxonomy);

        return $translated === null ? true : (bool) $translated;
    }

    private static function flush_wpml_caches() {
        global $sitepress;

        if (function_exists('icl_cache_clear')) {
            icl_cache_clear();
        }
        if ($sitepress && method_exists($sitepress, 'get_settings')) {
            wp_cache_delete('icl_sitepress_settings', 'options');
        }
        do_action('wpml_cache_clear');
    }

    // -----------------------------------------------------------------------
    // Health
    // -----------------------------------------------------------------------

    /**
     * Problems with the WPML setup that would make translations not show,
     * in plain words. Empty when everything is fine or WPML is absent.
     *
     * @return string[]
     */
    public static function problems() {
        $problems = array();

        if (!self::is_active()) {
            return $problems;
        }

        if (WIT_Strings::is_available() && !WIT_Strings::files_writable()) {
            $problems[] = sprintf(
                /* translators: %s: directory */
                __('String Translation guarda las cadenas traducidas en %s, y esa carpeta no existe o no se puede escribir: las cadenas de temas y plugins traducidas no aparecerán en el sitio, sin ningún aviso de WPML.', 'wpml-imagina-translate'),
                'wp-content/languages/wpml'
            );
        }

        return $problems;
    }
}
