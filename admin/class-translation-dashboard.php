<?php
/**
 * Translation Dashboard - Admin page for managing translations
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translation_Dashboard {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Add admin menu page
     */
    public function add_menu_page() {
        add_menu_page(
            __('WPML IA Translate', 'wpml-imagina-translate'),
            __('IA Translate', 'wpml-imagina-translate'),
            'edit_posts',
            'wpml-ia-translate',
            array($this, 'render_dashboard'),
            'dashicons-translation',
            30
        );

        add_submenu_page(
            'wpml-ia-translate',
            __('Dashboard', 'wpml-imagina-translate'),
            __('Dashboard', 'wpml-imagina-translate'),
            'edit_posts',
            'wpml-ia-translate',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'wpml-ia-translate',
            __('Translation Logs', 'wpml-imagina-translate'),
            __('Logs', 'wpml-imagina-translate'),
            'edit_posts',
            'wpml-ia-translate-logs',
            array($this, 'render_logs')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'wpml-ia-translate') === false) {
            return;
        }

        wp_enqueue_style(
            'wit-admin-css',
            WIT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WIT_VERSION
        );

        wp_enqueue_script(
            'wit-admin-js',
            WIT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WIT_VERSION,
            true
        );

        wp_localize_script('wit-admin-js', 'witAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wit_ajax_nonce'),
            'strings' => array(
                'translating' => __('Traduciendo...', 'wpml-imagina-translate'),
                'success' => __('Traducción completada', 'wpml-imagina-translate'),
                'error' => __('Error en la traducción', 'wpml-imagina-translate'),
                'confirm_batch' => __('¿Está seguro de que desea traducir los posts seleccionados?', 'wpml-imagina-translate'),
            ),
        ));
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'wpml-imagina-translate'));
        }

        $wpml_integration = WIT_WPML_Integration::instance();
        $languages        = $wpml_integration->get_active_languages();
        $default_language = $wpml_integration->get_default_language();

        // This is a read-only listing driven by a GET form, so a nonce is not
        // required; the values are validated instead of trusted.
        $target_language = isset($_GET['target_lang'])
            ? sanitize_text_field(wp_unslash($_GET['target_lang']))
            : '';

        if ($target_language !== '' && !$wpml_integration->is_active_language($target_language)) {
            $target_language = '';
        }

        $selected_post_types = isset($_GET['post_types'])
            ? array_values(array_filter(
                array_map('sanitize_key', (array) wp_unslash($_GET['post_types'])),
                'post_type_exists'
            ))
            : array('post', 'page');

        if (empty($selected_post_types)) {
            $selected_post_types = array('post', 'page');
        }

        $pending_posts = $target_language !== ''
            ? $wpml_integration->get_pending_translations($target_language, $selected_post_types)
            : array();

        $translation_manager = new WIT_Translation_Manager();
        $stats               = $translation_manager->get_statistics();

        include WIT_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render logs page
     */
    public function render_logs() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'wpml-imagina-translate'));
        }

        $translation_manager = new WIT_Translation_Manager();
        $logs                = $translation_manager->get_translation_logs(100);

        include WIT_PLUGIN_DIR . 'admin/views/logs.php';
    }
}
