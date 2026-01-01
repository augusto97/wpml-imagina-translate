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
        add_action('wp_ajax_wit_translate_post', array($this, 'ajax_translate_post'));
        add_action('wp_ajax_wit_test_connection', array($this, 'ajax_test_connection'));
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
     * AJAX handler for translating a post
     */
    public function ajax_translate_post() {
        $this->verify_nonce();

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $target_language = isset($_POST['target_language']) ? sanitize_text_field($_POST['target_language']) : '';

        if (!$post_id || !$target_language) {
            wp_send_json_error(array(
                'message' => __('Datos inválidos', 'wpml-imagina-translate')
            ));
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos para editar este post', 'wpml-imagina-translate')
            ));
        }

        // Process translation
        $batch_processor = new WIT_Batch_Processor();
        $result = $batch_processor->process_single($post_id, $target_language);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'translated_post_id' => $result['translated_post_id'],
                'edit_url' => get_edit_post_link($result['translated_post_id'], 'raw'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
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
                'message' => $result['message'],
                'translation' => $result['translation'],
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
}
