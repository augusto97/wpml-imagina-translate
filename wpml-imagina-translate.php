<?php
/**
 * Plugin Name: WPML Imagina Translate
 * Plugin URI: https://github.com/augusto97/wpml-imagina-translate
 * Description: Traduce automáticamente contenido de WordPress usando tu propia API key de IA (OpenAI, Claude, Gemini). Integración perfecta con WPML.
 * Version: 1.0.0
 * Author: Imagina
 * Author URI: https://github.com/augusto97
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpml-imagina-translate
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: sitepress-multilingual-cms
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WIT_VERSION', '1.0.0');
define('WIT_PLUGIN_FILE', __FILE__);
define('WIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class WPML_Imagina_Translate {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_dependencies();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Check required dependencies
     */
    private function check_dependencies() {
        // Check if WPML is active
        if (!defined('ICL_SITEPRESS_VERSION')) {
            add_action('admin_notices', array($this, 'wpml_missing_notice'));
            return false;
        }

        return true;
    }

    /**
     * WPML missing notice
     */
    public function wpml_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('WPML Imagina Translate', 'wpml-imagina-translate'); ?>:</strong>
                <?php _e('Este plugin requiere WPML (Multilingual CMS) para funcionar. Por favor instala y activa WPML.', 'wpml-imagina-translate'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once WIT_PLUGIN_DIR . 'includes/class-settings.php';
        require_once WIT_PLUGIN_DIR . 'includes/class-translator-engine.php';
        require_once WIT_PLUGIN_DIR . 'includes/class-content-parser.php';
        require_once WIT_PLUGIN_DIR . 'includes/class-wpml-integration.php';
        require_once WIT_PLUGIN_DIR . 'includes/class-translation-manager.php';
        require_once WIT_PLUGIN_DIR . 'includes/class-batch-processor.php';

        // Admin classes
        if (is_admin()) {
            require_once WIT_PLUGIN_DIR . 'admin/class-translation-dashboard.php';
            require_once WIT_PLUGIN_DIR . 'admin/class-admin-ajax.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/deactivation hooks
        register_activation_hook(WIT_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WIT_PLUGIN_FILE, array($this, 'deactivate'));

        // Init hook
        add_action('plugins_loaded', array($this, 'init'));

        // Load text domain
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Initialize plugin
     */
    public function init() {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        // Initialize components
        WIT_Settings::instance();
        WIT_WPML_Integration::instance();

        if (is_admin()) {
            WIT_Translation_Dashboard::instance();
            WIT_Admin_Ajax::instance();
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wpml-imagina-translate',
            false,
            dirname(WIT_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create options with defaults
        $default_settings = array(
            'ai_provider' => 'openai',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'claude_api_key' => '',
            'claude_model' => 'claude-haiku-4-5-20251001',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-2.5-flash',
            'translation_prompt' => 'Translate the following text to {target_language}. Return ONLY the translated text without any HTML escaping, encoding, or modifications. Do not escape special characters. Do not add \u, \\u, u003c or any Unicode escapes. Return plain text translation exactly as it should appear.',
            'translate_meta_fields' => true,
            'meta_fields_list' => '_yoast_wpseo_title,_yoast_wpseo_metadesc,_excerpt',
            'batch_size' => 5,
            'enable_translation_memory' => false,
        );

        add_option('wit_settings', $default_settings);

        // Create translation logs table
        global $wpdb;
        $table_name = $wpdb->prefix . 'wit_translation_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            source_lang varchar(10) NOT NULL,
            target_lang varchar(10) NOT NULL,
            ai_provider varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear any scheduled cron jobs if we add them later
    }
}

/**
 * Initialize the plugin
 */
function WIT() {
    return WPML_Imagina_Translate::instance();
}

// Start the plugin
WIT();
