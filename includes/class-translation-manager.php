<?php
/**
 * Translation Manager - Orchestrates the translation process
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translation_Manager {

    private $wpml_integration;
    private $content_parser;
    private $settings;

    public function __construct() {
        $this->wpml_integration = WIT_WPML_Integration::instance();
        $this->content_parser = new WIT_Content_Parser();
        $this->settings = WIT_Settings::instance()->get_settings();
    }

    /**
     * Translate a single post
     *
     * @param int $post_id
     * @param string $target_language
     * @return array {success: bool, message: string, translated_post_id: int}
     */
    public function translate_post($post_id, $target_language) {
        $post = get_post($post_id);

        if (!$post) {
            return array(
                'success' => false,
                'message' => __('Post no encontrado', 'wpml-imagina-translate'),
                'translated_post_id' => 0,
            );
        }

        $source_language = $this->wpml_integration->get_post_language($post_id);

        try {
            // Translate title
            $title_result = $this->content_parser->translate_title(
                $post->post_title,
                $target_language,
                $source_language
            );

            if ($title_result['error']) {
                throw new Exception($title_result['error']);
            }

            // Translate content
            $content_result = $this->content_parser->translate_content(
                $post->post_content,
                $target_language,
                $source_language
            );

            if ($content_result['error']) {
                throw new Exception($content_result['error']);
            }

            // Translate excerpt
            $excerpt = '';
            if (!empty($post->post_excerpt)) {
                $excerpt_result = $this->content_parser->translate_excerpt(
                    $post->post_excerpt,
                    $target_language,
                    $source_language
                );

                if (!$excerpt_result['error']) {
                    $excerpt = $excerpt_result['excerpt'];
                }
            }

            $translated_data = array(
                'title' => $title_result['title'],
                'content' => $content_result['content'],
                'excerpt' => $excerpt,
            );

            // Check if translation already exists
            $existing_translation_id = $this->wpml_integration->get_translation_id($post_id, $target_language);

            if ($existing_translation_id) {
                // Update existing translation
                $success = $this->wpml_integration->update_translated_post($existing_translation_id, $translated_data);

                if ($success) {
                    // Translate meta fields if enabled
                    if ($this->settings['translate_meta_fields']) {
                        $this->translate_meta_fields($post_id, $existing_translation_id, $target_language, $source_language);
                    }

                    $this->log_translation($post_id, $target_language, 'success', 'Updated existing translation');

                    return array(
                        'success' => true,
                        'message' => __('Traducción actualizada exitosamente', 'wpml-imagina-translate'),
                        'translated_post_id' => $existing_translation_id,
                    );
                } else {
                    throw new Exception(__('Error al actualizar la traducción', 'wpml-imagina-translate'));
                }
            } else {
                // Create new translation
                $new_post_id = $this->wpml_integration->create_translated_post($post_id, $target_language, $translated_data);

                if (is_wp_error($new_post_id)) {
                    throw new Exception($new_post_id->get_error_message());
                }

                // Translate meta fields if enabled
                if ($this->settings['translate_meta_fields']) {
                    $this->translate_meta_fields($post_id, $new_post_id, $target_language, $source_language);
                }

                $this->log_translation($post_id, $target_language, 'success', 'Created new translation');

                return array(
                    'success' => true,
                    'message' => __('Traducción creada exitosamente', 'wpml-imagina-translate'),
                    'translated_post_id' => $new_post_id,
                );
            }
        } catch (Exception $e) {
            $this->log_translation($post_id, $target_language, 'error', $e->getMessage());

            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'translated_post_id' => 0,
            );
        }
    }

    /**
     * Translate meta fields
     *
     * @param int $source_post_id
     * @param int $target_post_id
     * @param string $target_language
     * @param string $source_language
     */
    private function translate_meta_fields($source_post_id, $target_post_id, $target_language, $source_language) {
        $meta_fields = array_map('trim', explode(',', $this->settings['meta_fields_list']));
        $translator = new WIT_Translator_Engine();

        foreach ($meta_fields as $meta_key) {
            if (empty($meta_key)) {
                continue;
            }

            $meta_value = get_post_meta($source_post_id, $meta_key, true);

            if (empty($meta_value) || !is_string($meta_value)) {
                continue;
            }

            // Translate meta value
            $result = $translator->translate($meta_value, $target_language, $source_language);

            if (!$result['error'] && !empty($result['translation'])) {
                update_post_meta($target_post_id, $meta_key, $result['translation']);
            }
        }
    }

    /**
     * Log translation
     *
     * @param int $post_id
     * @param string $target_language
     * @param string $status
     * @param string $message
     */
    private function log_translation($post_id, $target_language, $status, $message = '') {
        global $wpdb;

        $source_language = $this->wpml_integration->get_post_language($post_id);
        $ai_provider = $this->settings['ai_provider'];

        $wpdb->insert(
            $wpdb->prefix . 'wit_translation_logs',
            array(
                'post_id' => $post_id,
                'source_lang' => $source_language,
                'target_lang' => $target_language,
                'ai_provider' => $ai_provider,
                'status' => $status,
                'message' => $message,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get translation logs
     *
     * @param int $limit
     * @return array
     */
    public function get_translation_logs($limit = 50) {
        global $wpdb;

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wit_translation_logs ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        // Add post title to each log
        foreach ($logs as &$log) {
            $post = get_post($log['post_id']);
            $log['post_title'] = $post ? $post->post_title : __('Post eliminado', 'wpml-imagina-translate');
        }

        return $logs;
    }

    /**
     * Get translation statistics
     *
     * @return array
     */
    public function get_statistics() {
        global $wpdb;

        $stats = array();

        // Total translations
        $stats['total'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_logs"
        );

        // Successful translations
        $stats['successful'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_logs WHERE status = 'success'"
        );

        // Failed translations
        $stats['failed'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_logs WHERE status = 'error'"
        );

        // Translations by provider
        $stats['by_provider'] = $wpdb->get_results(
            "SELECT ai_provider, COUNT(*) as count FROM {$wpdb->prefix}wit_translation_logs GROUP BY ai_provider",
            ARRAY_A
        );

        // Recent activity (last 7 days)
        $stats['recent'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_logs WHERE created_at >= %s",
                date('Y-m-d H:i:s', strtotime('-7 days'))
            )
        );

        return $stats;
    }
}
