<?php
/**
 * Settings management class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Settings {

    private static $instance = null;
    private $option_name = 'wit_settings';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page() {
        add_options_page(
            __('WPML Imagina Translate Settings', 'wpml-imagina-translate'),
            __('WPML IA Translate', 'wpml-imagina-translate'),
            'manage_options',
            'wpml-imagina-translate-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'wit_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        // AI Provider
        $sanitized['ai_provider'] = isset($input['ai_provider']) ? sanitize_text_field($input['ai_provider']) : 'openai';

        // OpenAI settings
        $sanitized['openai_api_key'] = isset($input['openai_api_key']) ? sanitize_text_field($input['openai_api_key']) : '';
        $sanitized['openai_model'] = isset($input['openai_model']) ? sanitize_text_field($input['openai_model']) : 'gpt-4o-mini';

        // Claude settings
        $sanitized['claude_api_key'] = isset($input['claude_api_key']) ? sanitize_text_field($input['claude_api_key']) : '';
        $sanitized['claude_model'] = isset($input['claude_model']) ? sanitize_text_field($input['claude_model']) : 'claude-3-5-sonnet-20241022';

        // Gemini settings
        $sanitized['gemini_api_key'] = isset($input['gemini_api_key']) ? sanitize_text_field($input['gemini_api_key']) : '';
        $sanitized['gemini_model'] = isset($input['gemini_model']) ? sanitize_text_field($input['gemini_model']) : 'gemini-1.5-flash';

        // Translation settings
        $sanitized['translation_prompt'] = isset($input['translation_prompt']) ? wp_kses_post($input['translation_prompt']) : '';
        $sanitized['translate_meta_fields'] = isset($input['translate_meta_fields']) ? (bool)$input['translate_meta_fields'] : false;
        $sanitized['meta_fields_list'] = isset($input['meta_fields_list']) ? sanitize_textarea_field($input['meta_fields_list']) : '';
        $sanitized['batch_size'] = isset($input['batch_size']) ? absint($input['batch_size']) : 5;
        $sanitized['enable_translation_memory'] = isset($input['enable_translation_memory']) ? (bool)$input['enable_translation_memory'] : false;

        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('wit_settings_group');
                ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ai_provider"><?php _e('Proveedor de IA', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>[ai_provider]" id="ai_provider" class="regular-text">
                                <option value="openai" <?php selected($settings['ai_provider'], 'openai'); ?>>OpenAI (GPT)</option>
                                <option value="claude" <?php selected($settings['ai_provider'], 'claude'); ?>>Anthropic Claude</option>
                                <option value="gemini" <?php selected($settings['ai_provider'], 'gemini'); ?>>Google Gemini</option>
                            </select>
                            <p class="description"><?php _e('Selecciona el proveedor de IA que quieres usar', 'wpml-imagina-translate'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php _e('OpenAI Configuration', 'wpml-imagina-translate'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="openai_api_key"><?php _e('OpenAI API Key', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   name="<?php echo $this->option_name; ?>[openai_api_key]"
                                   id="openai_api_key"
                                   value="<?php echo esc_attr($settings['openai_api_key']); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Obtén tu API key en', 'wpml-imagina-translate'); ?>
                                <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai_model"><?php _e('Modelo OpenAI', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>[openai_model]" id="openai_model" class="regular-text">
                                <option value="gpt-4o" <?php selected($settings['openai_model'], 'gpt-4o'); ?>>GPT-4o (Recomendado)</option>
                                <option value="gpt-4o-mini" <?php selected($settings['openai_model'], 'gpt-4o-mini'); ?>>GPT-4o Mini (Más barato)</option>
                                <option value="gpt-4-turbo" <?php selected($settings['openai_model'], 'gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php _e('Claude Configuration', 'wpml-imagina-translate'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="claude_api_key"><?php _e('Claude API Key', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   name="<?php echo $this->option_name; ?>[claude_api_key]"
                                   id="claude_api_key"
                                   value="<?php echo esc_attr($settings['claude_api_key']); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Obtén tu API key en', 'wpml-imagina-translate'); ?>
                                <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="claude_model"><?php _e('Modelo Claude', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>[claude_model]" id="claude_model" class="regular-text">
                                <option value="claude-3-5-sonnet-20241022" <?php selected($settings['claude_model'], 'claude-3-5-sonnet-20241022'); ?>>Claude 3.5 Sonnet (Recomendado)</option>
                                <option value="claude-3-5-haiku-20241022" <?php selected($settings['claude_model'], 'claude-3-5-haiku-20241022'); ?>>Claude 3.5 Haiku (Más barato)</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php _e('Gemini Configuration', 'wpml-imagina-translate'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="gemini_api_key"><?php _e('Gemini API Key', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   name="<?php echo $this->option_name; ?>[gemini_api_key]"
                                   id="gemini_api_key"
                                   value="<?php echo esc_attr($settings['gemini_api_key']); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Obtén tu API key en', 'wpml-imagina-translate'); ?>
                                <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com/app/apikey</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gemini_model"><?php _e('Modelo Gemini', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>[gemini_model]" id="gemini_model" class="regular-text">
                                <option value="gemini-2.5-flash" <?php selected($settings['gemini_model'], 'gemini-2.5-flash'); ?>>Gemini 2.5 Flash (Recomendado - Rápido)</option>
                                <option value="gemini-2.5-pro" <?php selected($settings['gemini_model'], 'gemini-2.5-pro'); ?>>Gemini 2.5 Pro (Mejor calidad)</option>
                                <option value="gemini-3-flash-preview" <?php selected($settings['gemini_model'], 'gemini-3-flash-preview'); ?>>Gemini 3 Flash Preview (Más nuevo)</option>
                                <option value="gemini-3-pro-preview" <?php selected($settings['gemini_model'], 'gemini-3-pro-preview'); ?>>Gemini 3 Pro Preview (Más potente)</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php _e('Translation Settings', 'wpml-imagina-translate'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="translation_prompt"><?php _e('Prompt de Traducción', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <textarea name="<?php echo $this->option_name; ?>[translation_prompt]"
                                      id="translation_prompt"
                                      rows="5"
                                      class="large-text"><?php echo esc_textarea($settings['translation_prompt']); ?></textarea>
                            <p class="description">
                                <?php _e('Usa {target_language} como placeholder para el idioma destino.', 'wpml-imagina-translate'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="translate_meta_fields">
                                <?php _e('Traducir Meta Fields', 'wpml-imagina-translate'); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo $this->option_name; ?>[translate_meta_fields]"
                                       id="translate_meta_fields"
                                       value="1"
                                       <?php checked($settings['translate_meta_fields'], true); ?>>
                                <?php _e('Traducir automáticamente meta fields (SEO, excerpt, etc.)', 'wpml-imagina-translate'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="meta_fields_list"><?php _e('Lista de Meta Fields', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <textarea name="<?php echo $this->option_name; ?>[meta_fields_list]"
                                      id="meta_fields_list"
                                      rows="4"
                                      class="large-text"><?php echo esc_textarea($settings['meta_fields_list']); ?></textarea>
                            <p class="description">
                                <?php _e('Lista separada por comas de meta fields a traducir. Ejemplo: _yoast_wpseo_title,_yoast_wpseo_metadesc', 'wpml-imagina-translate'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="batch_size"><?php _e('Tamaño de Lote', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   name="<?php echo $this->option_name; ?>[batch_size]"
                                   id="batch_size"
                                   value="<?php echo esc_attr($settings['batch_size']); ?>"
                                   min="1"
                                   max="50"
                                   class="small-text">
                            <p class="description">
                                <?php _e('Número de posts a procesar en cada lote de traducción.', 'wpml-imagina-translate'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="enable_translation_memory">
                                <?php _e('Memoria de Traducción', 'wpml-imagina-translate'); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo $this->option_name; ?>[enable_translation_memory]"
                                       id="enable_translation_memory"
                                       value="1"
                                       <?php checked($settings['enable_translation_memory'], true); ?>>
                                <?php _e('Activar caché de traducciones (próximamente)', 'wpml-imagina-translate'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Get all settings
     */
    public function get_settings() {
        $defaults = array(
            'ai_provider' => 'openai',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'claude_api_key' => '',
            'claude_model' => 'claude-3-5-sonnet-20241022',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-2.5-flash',
            'translation_prompt' => 'Translate the following text to {target_language}. Maintain all HTML tags, formatting, and structure. Only translate the visible text content, not HTML attributes or code.',
            'translate_meta_fields' => true,
            'meta_fields_list' => '_yoast_wpseo_title,_yoast_wpseo_metadesc,_excerpt',
            'batch_size' => 5,
            'enable_translation_memory' => false,
        );

        $settings = get_option($this->option_name, $defaults);
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Get single setting value
     */
    public function get($key, $default = '') {
        $settings = $this->get_settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
}
