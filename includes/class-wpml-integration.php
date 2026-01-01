<?php
/**
 * WPML Integration - Handles WPML API integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_WPML_Integration {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize WPML hooks if needed
    }

    /**
     * Get all posts pending translation
     *
     * @param string $target_language Target language code
     * @param array $post_types Post types to include
     * @return array
     */
    public function get_pending_translations($target_language = '', $post_types = array('post', 'page')) {
        global $wpdb;

        if (empty($target_language)) {
            return array();
        }

        $default_language = $this->get_default_language();

        // Query to get posts that need translation
        $query = "
            SELECT p.ID, p.post_title, p.post_type, p.post_status
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
            WHERE t.element_type LIKE 'post_%'
            AND t.language_code = %s
            AND p.post_status IN ('publish', 'draft')
            AND p.post_type IN (" . implode(',', array_fill(0, count($post_types), '%s')) . ")
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}icl_translations t2
                WHERE t2.trid = t.trid
                AND t2.language_code = %s
            )
            ORDER BY p.post_modified DESC
            LIMIT 100
        ";

        $params = array_merge(
            array($default_language),
            $post_types,
            array($target_language)
        );

        $results = $wpdb->get_results(
            $wpdb->prepare($query, $params)
        );

        $pending_posts = array();
        foreach ($results as $post) {
            $pending_posts[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'edit_url' => get_edit_post_link($post->ID),
                'view_url' => get_permalink($post->ID),
            );
        }

        return $pending_posts;
    }

    /**
     * Get all active languages
     *
     * @return array
     */
    public function get_active_languages() {
        if (!function_exists('icl_get_languages')) {
            return array();
        }

        $languages = icl_get_languages('skip_missing=0');
        $active_languages = array();

        foreach ($languages as $lang) {
            $active_languages[] = array(
                'code' => $lang['code'],
                'name' => $lang['native_name'],
                'default' => $lang['default_locale'],
            );
        }

        return $active_languages;
    }

    /**
     * Get default language
     *
     * @return string
     */
    public function get_default_language() {
        if (function_exists('wpml_get_default_language')) {
            return wpml_get_default_language();
        }

        global $sitepress;
        if ($sitepress) {
            return $sitepress->get_default_language();
        }

        return 'en';
    }

    /**
     * Get post language
     *
     * @param int $post_id
     * @return string
     */
    public function get_post_language($post_id) {
        if (function_exists('wpml_get_language_information')) {
            $lang_info = wpml_get_language_information(null, $post_id);
            return isset($lang_info['language_code']) ? $lang_info['language_code'] : '';
        }

        return '';
    }

    /**
     * Create translated post
     *
     * @param int $original_post_id
     * @param string $target_language
     * @param array $translated_data {title, content, excerpt}
     * @return int|WP_Error New post ID or error
     */
    public function create_translated_post($original_post_id, $target_language, $translated_data) {
        $original_post = get_post($original_post_id);

        if (!$original_post) {
            return new WP_Error('invalid_post', __('Post original no encontrado', 'wpml-imagina-translate'));
        }

        // Prepare new post data
        $new_post_data = array(
            'post_title' => $translated_data['title'],
            'post_content' => $translated_data['content'],
            'post_excerpt' => isset($translated_data['excerpt']) ? $translated_data['excerpt'] : '',
            'post_status' => 'draft', // Create as draft for review
            'post_type' => $original_post->post_type,
            'post_author' => $original_post->post_author,
            'post_parent' => 0, // Will be linked via WPML
            'menu_order' => $original_post->menu_order,
            'comment_status' => $original_post->comment_status,
            'ping_status' => $original_post->ping_status,
        );

        // Insert the new post
        $new_post_id = wp_insert_post($new_post_data);

        if (is_wp_error($new_post_id)) {
            return $new_post_id;
        }

        // Link posts in WPML
        $this->link_translation($original_post_id, $new_post_id, $target_language);

        // Copy taxonomies
        $this->copy_taxonomies($original_post_id, $new_post_id, $target_language);

        // Copy featured image
        $this->copy_featured_image($original_post_id, $new_post_id);

        return $new_post_id;
    }

    /**
     * Link translation in WPML
     *
     * @param int $original_post_id
     * @param int $translated_post_id
     * @param string $target_language
     */
    private function link_translation($original_post_id, $translated_post_id, $target_language) {
        global $sitepress;

        if (!$sitepress) {
            return;
        }

        $source_language = $this->get_post_language($original_post_id);
        $post_type = get_post_type($original_post_id);

        // Get trid (translation group ID) of original post
        $trid = $sitepress->get_element_trid($original_post_id, 'post_' . $post_type);

        if (!$trid) {
            // Create new translation group
            $trid = $sitepress->get_element_trid($original_post_id, 'post_' . $post_type);
        }

        // Set language for translated post
        $sitepress->set_element_language_details(
            $translated_post_id,
            'post_' . $post_type,
            $trid,
            $target_language,
            $source_language
        );
    }

    /**
     * Copy taxonomies to translated post
     *
     * @param int $original_post_id
     * @param int $translated_post_id
     * @param string $target_language
     */
    private function copy_taxonomies($original_post_id, $translated_post_id, $target_language) {
        $taxonomies = get_object_taxonomies(get_post_type($original_post_id));

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($original_post_id, $taxonomy, array('fields' => 'ids'));

            if (!empty($terms) && !is_wp_error($terms)) {
                // Try to get translated terms if they exist in WPML
                $translated_terms = array();

                foreach ($terms as $term_id) {
                    $translated_term_id = $this->get_translated_term($term_id, $taxonomy, $target_language);
                    if ($translated_term_id) {
                        $translated_terms[] = $translated_term_id;
                    }
                }

                if (!empty($translated_terms)) {
                    wp_set_object_terms($translated_post_id, $translated_terms, $taxonomy);
                }
            }
        }
    }

    /**
     * Get translated term ID
     *
     * @param int $term_id
     * @param string $taxonomy
     * @param string $target_language
     * @return int|null
     */
    private function get_translated_term($term_id, $taxonomy, $target_language) {
        if (function_exists('icl_object_id')) {
            return icl_object_id($term_id, $taxonomy, false, $target_language);
        }

        return null;
    }

    /**
     * Copy featured image
     *
     * @param int $original_post_id
     * @param int $translated_post_id
     */
    private function copy_featured_image($original_post_id, $translated_post_id) {
        $thumbnail_id = get_post_thumbnail_id($original_post_id);

        if ($thumbnail_id) {
            set_post_thumbnail($translated_post_id, $thumbnail_id);
        }
    }

    /**
     * Update existing translation
     *
     * @param int $post_id
     * @param array $translated_data
     * @return bool
     */
    public function update_translated_post($post_id, $translated_data) {
        $post_data = array(
            'ID' => $post_id,
            'post_title' => $translated_data['title'],
            'post_content' => $translated_data['content'],
        );

        if (isset($translated_data['excerpt'])) {
            $post_data['post_excerpt'] = $translated_data['excerpt'];
        }

        $result = wp_update_post($post_data);

        return !is_wp_error($result);
    }

    /**
     * Check if translation exists
     *
     * @param int $original_post_id
     * @param string $target_language
     * @return int|false Translated post ID or false
     */
    public function get_translation_id($original_post_id, $target_language) {
        if (function_exists('icl_object_id')) {
            $post_type = get_post_type($original_post_id);
            $translated_id = icl_object_id($original_post_id, $post_type, false, $target_language);

            return $translated_id ? $translated_id : false;
        }

        return false;
    }
}
