<?php
/**
 * Admin AJAX Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Admin_Ajax {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_wit_translate_post',           array($this, 'ajax_translate_post'));
        add_action('wp_ajax_wit_check_translation_status', array($this, 'ajax_check_translation_status'));
        add_action('wp_ajax_wit_test_connection',          array($this, 'ajax_test_connection'));
        add_action('wp_ajax_wit_fetch_models',             array($this, 'ajax_fetch_models'));
    }

    /**
     * Verify AJAX nonce
     */
    private function verify_nonce() {
        if (!check_ajax_referer('wit_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => __('Error de seguridad', 'wpml-imagina-translate')
            ));
        }
    }

    /**
     * Transient key for a translation job.
     */
    private function job_key($post_id, $target_language) {
        return 'wit_job_' . intval($post_id) . '_' . sanitize_key($target_language);
    }

    /**
     * AJAX handler for translating a post.
     *
     * Uses ignore_user_abort(true) so the PHP process keeps running even when
     * the browser HTTP connection is cut by a proxy/server timeout. The final
     * result is written to a transient that the client can poll via
     * wit_check_translation_status.
     */
    public function ajax_translate_post() {
        // Keep PHP alive even if the browser connection drops (gateway timeout).
        // The client-side poller will read the transient once PHP finishes.
        @ignore_user_abort(true);
        @set_time_limit(600);

        $this->verify_nonce();

        $request = $this->read_request();

        if (is_wp_error($request)) {
            wp_send_json_error(array('message' => $request->get_error_message()));
        }

        list($post_id, $target_language) = $request;

        $key = $this->job_key($post_id, $target_language);

        // Guard against a second run being started for the same post and
        // language while the first is still working — a double click, or the
        // poller racing the original request.
        $existing = get_transient($key);
        if (is_array($existing)
            && isset($existing['status'])
            && $existing['status'] === 'processing'
            && isset($existing['started_at'])
            && (time() - (int) $existing['started_at']) < 15 * MINUTE_IN_SECONDS) {
            wp_send_json_success($existing);
        }

        // Signal that the job has started — the poller sees this immediately.
        set_transient($key, array(
            'status'     => 'processing',
            'started_at' => time(),
        ), 30 * MINUTE_IN_SECONDS);

        // Run translation — catch any uncaught PHP Error/Exception.
        try {
            $batch_processor = new WIT_Batch_Processor();
            $result = $batch_processor->process_single($post_id, $target_language);
        } catch (\Throwable $e) {
            $payload = array(
                'status'  => 'error',
                'message' => $e->getMessage() ?: __('Error interno del servidor', 'wpml-imagina-translate'),
                'debug'   => array(get_class($e) . ': ' . $e->getMessage()),
            );
            set_transient($key, $payload, 30 * MINUTE_IN_SECONDS);
            wp_send_json_error($payload);
            return;
        }

        $debug = isset($result['debug']) ? $result['debug'] : array();

        if ($result['success']) {
            $payload = array(
                'status'             => 'complete',
                'message'            => $result['message'],
                'translated_post_id' => $result['translated_post_id'],
                'edit_url'           => get_edit_post_link($result['translated_post_id'], 'raw'),
                'debug'              => $debug,
            );
            set_transient($key, $payload, 30 * MINUTE_IN_SECONDS);
            wp_send_json_success($payload);
        } else {
            $payload = array(
                'status'  => 'error',
                'message' => $result['message'] ?: __('Error desconocido en la traducción', 'wpml-imagina-translate'),
                'debug'   => $debug,
            );
            set_transient($key, $payload, 30 * MINUTE_IN_SECONDS);
            wp_send_json_error($payload);
        }
    }

    /**
     * AJAX handler for polling translation job status.
     *
     * Returns the current status of a running or completed translation job.
     * Possible status values in the response:
     *   'processing' — still running
     *   'complete'   — finished successfully
     *   'error'      — finished with an error
     *   'not_found'  — no job record (not started yet, or transient expired)
     */
    public function ajax_check_translation_status() {
        $this->verify_nonce();

        $request = $this->read_request();

        if (is_wp_error($request)) {
            wp_send_json_error(array('message' => $request->get_error_message()));
        }

        list($post_id, $target_language) = $request;

        $job = get_transient($this->job_key($post_id, $target_language));

        wp_send_json_success($job !== false ? $job : array('status' => 'not_found'));
    }

    /**
     * Read, validate and authorise the post/language pair from the request.
     *
     * @return array{0:int,1:string}|WP_Error
     */
    private function read_request() {
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;

        // wp_unslash() first: WordPress adds slashes to every superglobal, so
        // sanitising without unslashing corrupts values containing quotes.
        $target_language = isset($_POST['target_language'])
            ? sanitize_text_field(wp_unslash($_POST['target_language']))
            : '';

        if (!$post_id || $target_language === '') {
            return new WP_Error('wit_invalid', __('Datos inválidos', 'wpml-imagina-translate'));
        }

        if (!get_post($post_id)) {
            return new WP_Error('wit_not_found', __('Post no encontrado', 'wpml-imagina-translate'));
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('wit_forbidden', __('No tienes permisos para editar este post', 'wpml-imagina-translate'));
        }

        // Reject language codes that WPML does not know about, so the plugin
        // cannot be driven into writing junk into WPML's translation tables.
        if (!WIT_WPML_Integration::instance()->is_active_language($target_language)) {
            return new WP_Error('wit_language', __('El idioma destino no está activo en WPML', 'wpml-imagina-translate'));
        }

        return array($post_id, $target_language);
    }

    /**
     * AJAX handler for fetching available models from a provider
     */
    public function ajax_fetch_models() {
        $this->verify_nonce();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos', 'wpml-imagina-translate')));
        }

        $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';

        if (!in_array($provider, array('openai', 'claude', 'gemini'), true)) {
            wp_send_json_error(array('message' => __('Proveedor no reconocido', 'wpml-imagina-translate')));
        }

        // Only whitespace and control characters are stripped: sanitize_text_field()
        // would mangle otherwise valid keys.
        $api_key = isset($_POST['api_key'])
            ? trim(preg_replace('/[\x00-\x1F\x7F\s]/u', '', wp_unslash($_POST['api_key'])))
            : '';

        // The settings form deliberately never renders the stored key, so an
        // empty field means "use the one already saved".
        if ($api_key === '') {
            $settings = WIT_Settings::instance()->get_settings();
            $api_key  = $settings[$provider . '_api_key'];
        }

        if ($api_key === '') {
            wp_send_json_error(array('message' => __('API key requerida', 'wpml-imagina-translate')));
        }

        $result = WIT_Translator_Engine::fetch_models($provider, $api_key);

        if ($result['success']) {
            wp_send_json_success(array('models' => $result['models']));
        } else {
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    /**
     * AJAX handler for testing API connection
     */
    public function ajax_test_connection() {
        $this->verify_nonce();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos', 'wpml-imagina-translate')
            ));
        }

        $translator = new WIT_Translator_Engine();
        $result = $translator->test_connection();

        if ($result['success']) {
            wp_send_json_success(array(
                'message'     => $result['message'],
                'translation' => $result['translation'],
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
}
