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
        // Every engine created while this post is translated reports its
        // tokens into one counter, read when the result is logged.
        $previous_usage = WIT_Translator_Engine::begin_job_usage();

        try {
            return $this->run_translation($post_id, $target_language);
        } finally {
            WIT_Translator_Engine::end_job_usage($previous_usage);
        }
    }

    private function run_translation($post_id, $target_language) {
        $post = get_post($post_id);

        if (!$post) {
            return array(
                'success' => false,
                'message' => __('Post no encontrado', 'wpml-imagina-translate'),
                'translated_post_id' => 0,
            );
        }

        // Checked here as well as at every entry point: this is the method that
        // writes, and cron runs it with the queuing user restored.
        $allowed = $this->wpml_integration->can_translate($post_id, $target_language);

        if (is_wp_error($allowed)) {
            return array(
                'success'            => false,
                'message'            => $allowed->get_error_message(),
                'translated_post_id' => 0,
            );
        }

        $source_language = $this->wpml_integration->get_post_language($post_id);
        $elementor       = new WIT_Elementor_Handler();
        $is_elementor    = $elementor->is_elementor_post($post_id);

        try {
            // Title and excerpt in one request. Each used to be its own round
            // trip, and with a real model every round trip is a second or two.
            $fields = array();
            if (trim((string) $post->post_title) !== '') {
                $fields['title'] = $post->post_title;
            }
            if (trim((string) $post->post_excerpt) !== '') {
                $fields['excerpt'] = $post->post_excerpt;
            }

            $batched = array();
            if (!empty($fields)) {
                $engine  = new WIT_Translator_Engine();
                $results = $engine->translate_batch(array_values($fields), $target_language, $source_language);
                $batched = array_combine(array_keys($fields), $results);
            }

            if (isset($batched['title']) && !empty($batched['title']['error'])) {
                throw new Exception($batched['title']['error']);
            }

            $title_result = array(
                'title' => isset($batched['title']) ? $batched['title']['translation'] : $post->post_title,
            );

            $excerpt = '';
            if (isset($batched['excerpt']) && empty($batched['excerpt']['error'])) {
                $excerpt = $batched['excerpt']['translation'];
            }

            // Translate content.
            // For Elementor posts the editable content lives in _elementor_data
            // (post meta), not in post_content.  Elementor's front-end renderer
            // ignores post_content entirely, so there is no point translating it —
            // we just preserve it as-is and handle the Elementor data separately
            // after the translated post has been created/updated.
            if ($is_elementor) {
                $content_result = array(
                    'content' => '',  // Elementor will regenerate this from _elementor_data
                    'error'   => null,
                    'debug'   => array('Elementor post detected — content will be translated from _elementor_data'),
                );
            } else {
                $content_result = $this->content_parser->translate_content(
                    $post->post_content,
                    $target_language,
                    $source_language
                );
            }

            // Debug info is now provided directly by the parser
            $debug_info = isset($content_result['debug']) ? $content_result['debug'] : array();

            if ($content_result['error']) {
                throw new Exception($content_result['error']);
            }

            $translated_data = array(
                'title'   => $title_result['title'],
                'content' => $content_result['content'],
                'excerpt' => $excerpt,
            );

            // Check if translation already exists
            $existing_translation_id = $this->wpml_integration->get_translation_id($post_id, $target_language);

            // A translation in the trash still holds WPML's slot for this
            // language. Bring it back as a draft and update it, rather than
            // writing into the trash or creating a second one beside it.
            if (!$existing_translation_id) {
                $trashed = $this->wpml_integration->get_translation_id($post_id, $target_language, true);
                if ($trashed && get_post_status($trashed) === 'trash' && wp_untrash_post($trashed)) {
                    $existing_translation_id = (int) $trashed;
                    $debug_info[] = 'La traducción estaba en la papelera: restaurada como borrador';
                }
            }

            if ($existing_translation_id) {
                // Update existing translation
                $success = $this->wpml_integration->update_translated_post($existing_translation_id, $translated_data, $post_id);

                if (is_wp_error($success)) {
                    throw new Exception($success->get_error_message());
                }

                if ($success) {
                    // Translate Elementor data if applicable
                    if ($is_elementor) {
                        $el_result  = $elementor->translate($post_id, $existing_translation_id, $target_language, $source_language);
                        $debug_info = array_merge($debug_info, $el_result['debug']);

                        if (!empty($el_result['error'])) {
                            throw new Exception($el_result['error']);
                        }
                    }

                    // Translate meta fields if enabled
                    if ($this->settings['translate_meta_fields']) {
                        $this->translate_meta_fields($post_id, $existing_translation_id, $target_language, $source_language);
                    }

                    WIT_Translation_Status::mark($post_id, $existing_translation_id);
                    $this->log_translation($post_id, $target_language, 'success', __('Traducción actualizada', 'wpml-imagina-translate'));

                    return array(
                        'success'            => true,
                        'message'            => __('Traducción actualizada exitosamente', 'wpml-imagina-translate'),
                        'translated_post_id' => $existing_translation_id,
                        'debug'              => $debug_info,
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

                // Translate Elementor data if applicable
                if ($is_elementor) {
                    $el_result  = $elementor->translate($post_id, $new_post_id, $target_language, $source_language);
                    $debug_info = array_merge($debug_info, $el_result['debug']);

                    if (!empty($el_result['error'])) {
                        throw new Exception($el_result['error']);
                    }
                }

                // Translate meta fields if enabled
                if ($this->settings['translate_meta_fields']) {
                    $this->translate_meta_fields($post_id, $new_post_id, $target_language, $source_language);
                }

                WIT_Translation_Status::mark($post_id, $new_post_id);
                $this->log_translation($post_id, $target_language, 'success', __('Traducción creada', 'wpml-imagina-translate'));

                return array(
                    'success'            => true,
                    'message'            => __('Traducción creada exitosamente', 'wpml-imagina-translate'),
                    'translated_post_id' => $new_post_id,
                    'debug'              => $debug_info,
                );
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage() ?: __('Error interno durante la traducción', 'wpml-imagina-translate');
            $this->log_translation($post_id, $target_language, 'error', $message);

            return array(
                'success'            => false,
                'message'            => $message,
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
    /**
     * The configured meta fields of a post that would be translated.
     *
     * Shared by the translation itself and the MCP planner, so both see the
     * same set of fields.
     *
     * @param int $post_id
     * @return array meta key => value
     */
    public function collect_meta_values($post_id) {
        if (empty($this->settings['translate_meta_fields'])) {
            return array();
        }

        $meta_fields = array_filter(array_map('trim', explode(',', $this->settings['meta_fields_list'])));
        $values      = array();

        foreach ($meta_fields as $meta_key) {
            // Elementor's own meta is handled by WIT_Elementor_Handler; letting
            // it through here would send serialized page data to the API.
            if (strpos($meta_key, '_elementor') === 0) {
                continue;
            }

            $value = get_post_meta($post_id, $meta_key, true);

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $values[$meta_key] = $value;
        }

        return $values;
    }

    private function translate_meta_fields($source_post_id, $target_post_id, $target_language, $source_language) {
        // Collect first, then translate in one batched call. The previous
        // implementation issued a separate API request per meta field.
        $values = $this->collect_meta_values($source_post_id);

        if (empty($values)) {
            return;
        }

        $keys         = array_keys($values);
        $translator   = new WIT_Translator_Engine();
        $translations = $translator->translate_batch(array_values($values), $target_language, $source_language);

        foreach ($keys as $index => $meta_key) {
            if (isset($translations[$index])
                && empty($translations[$index]['error'])
                && $translations[$index]['translation'] !== '') {
                update_post_meta($target_post_id, $meta_key, wp_slash($translations[$index]['translation']));
            }
        }
    }

    /**
     * Record something done to a translation other than translating it —
     * published, unpublished, trashed, corrected — so the history shows what
     * a chat did on the site, not only what it translated.
     *
     * @param int    $post_id         The original.
     * @param string $target_language
     * @param string $message
     * @param string $via             'mcp' for actions taken from the chat.
     */
    public function log_event($post_id, $target_language, $message, $via = 'mcp') {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wit_translation_logs',
            array(
                'post_id'     => (int) $post_id,
                'source_lang' => $this->wpml_integration->get_post_language($post_id),
                'target_lang' => (string) $target_language,
                'ai_provider' => (string) $via,
                'status'      => 'success',
                'message'     => (string) $message,
                'created_at'  => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
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
        // An MCP translation must be told apart from an API one in the history:
        // one was paid per token, the other came out of the user's subscription.
        $ai_provider = WIT_Translator_Engine::is_external_active() ? 'mcp' : $this->settings['ai_provider'];

        // Read, not ended: translate_post() closes the job.
        $usage = WIT_Translator_Engine::job_usage_snapshot();

        $wpdb->insert(
            $wpdb->prefix . 'wit_translation_logs',
            array(
                'post_id'          => $post_id,
                'source_lang'      => $source_language,
                'target_lang'      => $target_language,
                'ai_provider'      => $ai_provider,
                'status'           => $status,
                'message'          => $message,
                'input_tokens'     => $usage['input_tokens'],
                'output_tokens'    => $usage['output_tokens'],
                'strings_ai'       => $usage['api'],
                'strings_memory'   => $usage['memory'],
                'strings_glossary' => $usage['glossary'],
                'created_at'       => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s')
        );
    }

    /**
     * Get translation logs
     *
     * Users who may not edit other people's posts see only the logs of posts
     * they can edit: titles of others' drafts and private pages are not an
     * Author's business.
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_translation_logs($limit = 50, $offset = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'wit_translation_logs';
        $all   = current_user_can('edit_others_posts');
        $out   = array();

        // Filtered in PHP because edit_post is a meta capability; read in
        // pages so a large history does not load at once.
        $skip  = $all ? 0 : (int) $offset;
        $start = $all ? (int) $offset : 0;
        $chunk = max(50, (int) $limit);

        while (count($out) < $limit) {
            $logs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                $chunk,
                $start
            ), ARRAY_A);

            if (empty($logs)) {
                break;
            }

            $start += $chunk;

            foreach ($logs as $log) {
                if (!$all && !current_user_can('edit_post', (int) $log['post_id'])) {
                    continue;
                }
                if ($skip > 0) {
                    $skip--;
                    continue;
                }

                $post              = (int) $log['post_id'] > 0 ? get_post($log['post_id']) : null;
                $log['post_title'] = (int) $log['post_id'] === 0
                    ? __('(ajustes del plugin)', 'wpml-imagina-translate')
                    : ($post ? $post->post_title : __('Post eliminado', 'wpml-imagina-translate'));
                $log['message']    = self::readable_message($log['message']);
                $out[]             = $log;

                if (count($out) >= $limit) {
                    break 2;
                }
            }

            if ($all) {
                break;
            }
        }

        return $out;
    }

    /**
     * Number of log rows the current user can see.
     *
     * @return int
     */
    public function count_translation_logs() {
        global $wpdb;

        $table = $wpdb->prefix . 'wit_translation_logs';

        if (current_user_can('edit_others_posts')) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        // One row per post, not per log line: a long history has far more
        // lines than posts.
        $count = 0;
        foreach ((array) $wpdb->get_results("SELECT post_id, COUNT(*) AS n FROM {$table} GROUP BY post_id", ARRAY_A) as $row) {
            if ((int) $row['post_id'] > 0 && current_user_can('edit_post', (int) $row['post_id'])) {
                $count += (int) $row['n'];
            }
        }

        return $count;
    }

    /**
     * Messages written in English by versions before 1.4.0.
     *
     * @param string $message
     * @return string
     */
    private static function readable_message($message) {
        $legacy = array(
            'Created new translation'      => __('Traducción creada', 'wpml-imagina-translate'),
            'Updated existing translation' => __('Traducción actualizada', 'wpml-imagina-translate'),
        );

        return isset($legacy[$message]) ? $legacy[$message] : (string) $message;
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

        // Recent activity (last 7 days). created_at is the site's local time.
        $stats['recent'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_logs WHERE created_at >= %s",
                gmdate('Y-m-d H:i:s', current_time('timestamp') - 7 * DAY_IN_SECONDS)
            )
        );

        // Tokens billed by the API over the last 30 days: what the provider
        // charges for. Translations made through Claude count zero.
        $stats['tokens_30d'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(input_tokens + output_tokens), 0) FROM {$wpdb->prefix}wit_translation_logs WHERE created_at >= %s",
                gmdate('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS)
            )
        );

        return $stats;
    }
}
