<?php
/**
 * Translation Dashboard - Admin page for managing translations
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translation_Dashboard {

    /** Rows per page in the dashboard listing. */
    const PER_PAGE = 50;

    /** Originals inspected per listing; status is computed, not queried. */
    const SCAN_LIMIT = 2000;

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
                /* translators: %d: number of translations edited by hand */
                'confirm_edited_batch' => __('%d de los seleccionados tienen una traducción editada a mano. Traducirlos de nuevo reemplazará esas ediciones. ¿Continuar?', 'wpml-imagina-translate'),
                'confirm_edited' => __('Esta traducción se editó a mano después de hacerse. Traducirla de nuevo reemplazará esas ediciones. ¿Continuar?', 'wpml-imagina-translate'),
                'confirm_existing' => __('Ya existe una traducción. Se generará de nuevo desde el original. ¿Continuar?', 'wpml-imagina-translate'),
                /* translators: %d: number of selected posts */
                'selected' => __('%d seleccionados', 'wpml-imagina-translate'),
                'retranslate' => __('Re-Traducir', 'wpml-imagina-translate'),
                'edit_translation' => __('Editar traducción', 'wpml-imagina-translate'),
                'translated' => __('Al día', 'wpml-imagina-translate'),
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

        $filters = array(
            'pending'  => __('Pendientes', 'wpml-imagina-translate'),
            'outdated' => __('Desactualizadas', 'wpml-imagina-translate'),
            'current'  => __('Al día', 'wpml-imagina-translate'),
            'edited'   => __('Editadas a mano', 'wpml-imagina-translate'),
            'all'      => __('Todos', 'wpml-imagina-translate'),
        );

        $status_filter = isset($_GET['wit_status']) ? sanitize_key(wp_unslash($_GET['wit_status'])) : 'pending';
        if (!isset($filters[$status_filter])) {
            $status_filter = 'pending';
        }

        $search = isset($_GET['wit_s']) ? sanitize_text_field(wp_unslash($_GET['wit_s'])) : '';
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        // The listing used to stop at the 100 most recently modified posts,
        // with no paging: on a larger site the rest were simply not there.
        $listing = $target_language !== ''
            ? WIT_Translation_Status::listing($target_language, $selected_post_types, $status_filter, $search, $paged, self::PER_PAGE, self::SCAN_LIMIT)
            : null;

        $pending_posts = $listing ? $listing['items'] : array();

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

        $per_page            = 50;
        $paged               = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $translation_manager = new WIT_Translation_Manager();
        $total_logs          = $translation_manager->count_translation_logs();
        $total_pages         = (int) max(1, ceil($total_logs / $per_page));
        $paged               = min($paged, $total_pages);
        $logs                = $translation_manager->get_translation_logs($per_page, ($paged - 1) * $per_page);

        include WIT_PLUGIN_DIR . 'admin/views/logs.php';
    }
}
