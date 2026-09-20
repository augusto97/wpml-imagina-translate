<?php
/**
 * Plugin Name: WPML stub (test double)
 * Description: Emulates the WPML hooks WPML Imagina Translate relies on, backed by a real icl_translations table with WPML's own UNIQUE constraints. Test-only.
 * Version: 4.7.0-stub
 */

// Same constant the real plugin defines; the plugin under test gates on it.
define('ICL_SITEPRESS_VERSION', '4.7.0');

class WIT_WPML_Stub {

    /** Site languages. Spanish default, mirroring the agency's real setup. */
    private $languages = array(
        'es' => array('native_name' => 'Español',  'translated_name' => 'Spanish',  'default_locale' => 'es_ES'),
        'en' => array('native_name' => 'English',  'translated_name' => 'English',  'default_locale' => 'en_US'),
        'fr' => array('native_name' => 'Français', 'translated_name' => 'French',   'default_locale' => 'fr_FR'),
    );

    private $default = 'es';

    public function __construct() {
        global $wpdb;

        // WPML's real table, with its real unique keys. A duplicate
        // (element_type, element_id) or (trid, language_code) is rejected by
        // the database exactly as it would be on a live site.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}icl_translations (
            translation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            element_type varchar(60) NOT NULL DEFAULT 'post_post',
            element_id bigint(20) unsigned DEFAULT NULL,
            trid bigint(20) unsigned NOT NULL,
            language_code varchar(7) NOT NULL,
            source_language_code varchar(7) DEFAULT NULL,
            PRIMARY KEY (translation_id),
            UNIQUE KEY el_type_id (element_type, element_id),
            UNIQUE KEY trid_lang (trid, language_code),
            KEY trid (trid)
        ) " . $wpdb->get_charset_collate());

        add_filter('wpml_active_languages',       array($this, 'active_languages'), 10, 2);
        add_filter('wpml_default_language',       array($this, 'default_language'));
        add_filter('wpml_element_type',           array($this, 'element_type'));
        add_filter('wpml_element_language_code',  array($this, 'element_language_code'), 10, 2);
        add_filter('wpml_element_trid',           array($this, 'element_trid'), 10, 3);
        add_filter('wpml_object_id',              array($this, 'object_id'), 10, 4);
        add_action('wpml_set_element_language_details', array($this, 'set_element_language_details'));

        // WPML assigns a language to every new post and term itself. The
        // plugin under test must then override that with the right trid.
        add_action('wp_insert_post', array($this, 'auto_assign_post'), 10, 3);
        add_action('created_term',   array($this, 'auto_assign_term'), 10, 3);
    }

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'icl_translations';
    }

    public function active_languages($null, $args = array()) {
        $out = array();
        foreach ($this->languages as $code => $lang) {
            $out[$code] = array_merge($lang, array(
                'code'          => $code,
                'id'            => array_search($code, array_keys($this->languages)) + 1,
                'active'        => $code === $this->default ? '1' : '0',
                'missing'       => 0,
                'url'           => home_url($code === $this->default ? '/' : '/' . $code . '/'),
                'country_flag_url' => '',
                'tag'           => $code,
            ));
        }
        return $out;
    }

    public function default_language($null) {
        return $this->default;
    }

    public function element_type($type) {
        // Same order as SitePress::get_wp_element_type(): registered names
        // first, so 'post_tag' becomes 'tax_post_tag' and is not mistaken for
        // an already-prefixed post type.
        if (taxonomy_exists($type)) {
            return 'tax_' . $type;
        }
        if (post_type_exists($type)) {
            return 'post_' . $type;
        }
        return $type;
    }

    public function element_language_code($null, $args) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT language_code FROM {$this->table()} WHERE element_type = %s AND element_id = %d",
            $this->element_type($args['element_type']), (int) $args['element_id']
        ));
    }

    public function element_trid($null, $element_id, $element_type) {
        global $wpdb;
        $trid = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$this->table()} WHERE element_type = %s AND element_id = %d",
            $this->element_type($element_type), (int) $element_id
        ));
        return $trid ? (int) $trid : null;
    }

    public function object_id($element_id, $element_type, $return_original = false, $language = null) {
        global $wpdb;

        if (!$element_id) {
            return null;
        }

        $type   = $this->element_type($element_type);
        $is_tax = strpos($type, 'tax_') === 0;

        // Real WPML: wpml_object_id takes and returns term_id for taxonomies,
        // but icl_translations stores term_taxonomy_id. The mapping happens
        // here, inside WPML — callers of the OTHER hooks must pass tt_id.
        $lookup_id = $element_id;
        if ($is_tax) {
            $term = get_term($element_id, substr($type, 4));
            if (!$term || is_wp_error($term)) {
                return $return_original ? $element_id : null;
            }
            $lookup_id = $term->term_taxonomy_id;
        }

        $trid = $this->element_trid(null, $lookup_id, $type);

        if ($trid) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT element_id FROM {$this->table()} WHERE trid = %d AND language_code = %s",
                $trid, $language
            ));
            if ($found) {
                if ($is_tax) {
                    $t = get_term_by('term_taxonomy_id', (int) $found, substr($type, 4));
                    return $t ? (int) $t->term_id : null;
                }
                return (int) $found;
            }
        }

        return $return_original ? $element_id : null;
    }

    public function set_element_language_details($args) {
        global $wpdb;

        $type = $args['element_type'];
        $id   = (int) $args['element_id'];
        $trid = !empty($args['trid']) ? (int) $args['trid'] : null;
        $lang = $args['language_code'];
        $src  = isset($args['source_language_code']) ? $args['source_language_code'] : null;

        if (!$trid) {
            $trid = (int) $wpdb->get_var("SELECT COALESCE(MAX(trid), 0) + 1 FROM {$this->table()}");
        }

        // Real WPML: an existing translation in that language for this trid
        // is replaced by the new element.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE trid = %d AND language_code = %s AND NOT (element_type = %s AND element_id = %d)",
            $trid, $lang, $type, $id
        ));

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT translation_id FROM {$this->table()} WHERE element_type = %s AND element_id = %d", $type, $id
        ));

        $row = array('element_type' => $type, 'element_id' => $id, 'trid' => $trid, 'language_code' => $lang, 'source_language_code' => $src);

        if ($existing) {
            $wpdb->update($this->table(), $row, array('translation_id' => $existing));
        } else {
            $wpdb->insert($this->table(), $row);
        }

        if ($wpdb->last_error) {
            error_log('[WPML-STUB] ' . $wpdb->last_error);
        }
    }

    public function auto_assign_post($post_id, $post, $update) {
        if ($update || $post->post_type === 'revision' || $post->post_status === 'auto-draft') {
            return;
        }
        if ($this->element_trid(null, $post_id, 'post_' . $post->post_type)) {
            return;
        }
        $this->set_element_language_details(array(
            'element_id' => $post_id, 'element_type' => 'post_' . $post->post_type,
            'trid' => null, 'language_code' => $this->default, 'source_language_code' => null,
        ));
    }

    public function auto_assign_term($term_id, $tt_id, $taxonomy) {
        if ($this->element_trid(null, $tt_id, 'tax_' . $taxonomy)) {
            return;
        }
        // WPML keys taxonomy rows on term_taxonomy_id, not term_id.
        $this->set_element_language_details(array(
            'element_id' => $tt_id, 'element_type' => 'tax_' . $taxonomy,
            'trid' => null, 'language_code' => $this->default, 'source_language_code' => null,
        ));
    }
}

new WIT_WPML_Stub();

function icl_get_languages($args = '') {
    return apply_filters('wpml_active_languages', null, $args);
}
