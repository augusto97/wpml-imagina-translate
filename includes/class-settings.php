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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Enqueue JS/CSS on the settings page
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'settings_page_wpml-imagina-translate-settings') {
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
            'nonce'    => wp_create_nonce('wit_ajax_nonce'),
            'strings'  => array(
                'translating'    => __('Traduciendo...', 'wpml-imagina-translate'),
                'success'        => __('Traducción completada', 'wpml-imagina-translate'),
                'error'          => __('Error en la traducción', 'wpml-imagina-translate'),
                'confirm_batch'  => __('¿Está seguro de que desea traducir los posts seleccionados?', 'wpml-imagina-translate'),
            ),
        ));
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
            array(
                'sanitize_callback' => array($this, 'sanitize_settings'),
                // The option holds API keys. Keeping it out of the autoloaded
                // set means it is not read into memory on every front-end
                // request, including requests that never translate anything.
                'autoload'          => false,
                'show_in_rest'      => false,
            )
        );
    }

    /**
     * Sanitize settings before they are stored.
     *
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        if (!current_user_can('manage_options')) {
            return $this->get_settings();
        }

        $current   = $this->get_settings();
        $sanitized = array();

        $provider = isset($input['ai_provider']) ? sanitize_key($input['ai_provider']) : 'openai';
        $sanitized['ai_provider'] = in_array($provider, array('openai', 'claude', 'gemini'), true) ? $provider : 'openai';

        foreach (array('openai', 'claude', 'gemini') as $name) {
            $sanitized[$name . '_api_key'] = $this->sanitize_api_key(
                isset($input[$name . '_api_key']) ? $input[$name . '_api_key'] : '',
                $current[$name . '_api_key'],
                !empty($input['clear_' . $name . '_api_key'])
            );

            $sanitized[$name . '_model'] = isset($input[$name . '_model'])
                ? sanitize_text_field($input[$name . '_model'])
                : $current[$name . '_model'];
        }

        $sanitized['translation_prompt']        = isset($input['translation_prompt']) ? sanitize_textarea_field($input['translation_prompt']) : '';
        // The glossary is plain text, but must keep its newlines: they separate rules.
        $sanitized['glossary']                  = isset($input['glossary']) ? sanitize_textarea_field($input['glossary']) : '';
        $sanitized['translate_meta_fields']     = !empty($input['translate_meta_fields']);
        $sanitized['enable_translation_memory'] = !empty($input['enable_translation_memory']);
        $sanitized['batch_size']                = isset($input['batch_size']) ? min(50, max(1, absint($input['batch_size']))) : 5;

        // Meta keys only: strip anything that is not a valid meta key so the
        // list cannot be used to read arbitrary data.
        $meta_fields = isset($input['meta_fields_list']) ? (string) $input['meta_fields_list'] : '';
        $meta_fields = array_filter(array_map(
            function ($key) {
                return preg_replace('/[^A-Za-z0-9_\-]/', '', trim($key));
            },
            explode(',', $meta_fields)
        ));
        $sanitized['meta_fields_list'] = implode(',', $meta_fields);

        return $sanitized;
    }

    /**
     * Resolve the value to store for an API key field.
     *
     * The settings form never renders the stored key, so an empty submission
     * means "leave it as it is" rather than "delete it". Clearing a key is an
     * explicit action via its checkbox.
     *
     * @param string $submitted
     * @param string $current
     * @param bool   $clear
     * @return string
     */
    private function sanitize_api_key($submitted, $current, $clear) {
        if ($clear) {
            return '';
        }

        // API keys can contain characters that sanitize_text_field would strip,
        // so only whitespace and control characters are removed.
        $submitted = trim(preg_replace('/[\x00-\x1F\x7F\s]/u', '', (string) $submitted));

        return $submitted !== '' ? $submitted : $current;
    }

    /**
     * Render an API key field that never exposes the stored secret.
     *
     * @param string $name  Provider slug.
     * @param string $value Stored key.
     */
    private function render_api_key_field($name, $value) {
        $has_key = ($value !== '');
        $field   = $name . '_api_key';
        ?>
        <input type="password"
               name="<?php echo esc_attr($this->option_name); ?>[<?php echo esc_attr($field); ?>]"
               id="<?php echo esc_attr($field); ?>"
               value=""
               autocomplete="new-password"
               spellcheck="false"
               placeholder="<?php echo esc_attr(
                   $has_key
                       ? __('Guardada — deja el campo vacío para conservarla', 'wpml-imagina-translate')
                       : __('Introduce tu API key', 'wpml-imagina-translate')
               ); ?>"
               class="regular-text">
        <?php if ($has_key) : ?>
            <p>
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr($this->option_name); ?>[clear_<?php echo esc_attr($field); ?>]"
                           value="1">
                    <?php esc_html_e('Borrar la API key guardada', 'wpml-imagina-translate'); ?>
                </label>
            </p>
        <?php endif; ?>
        <?php
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
                            <select name="<?php echo esc_attr($this->option_name); ?>[ai_provider]" id="ai_provider" class="regular-text">
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
                            <?php $this->render_api_key_field('openai', $settings['openai_api_key']); ?>
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
                            <select name="<?php echo esc_attr($this->option_name); ?>[openai_model]"
                                    id="openai_model"
                                    class="regular-text wit-model-select"
                                    data-provider="openai"
                                    data-key-field="openai_api_key"
                                    data-saved="<?php echo esc_attr($settings['openai_model']); ?>">
                                <option value="<?php echo esc_attr($settings['openai_model']); ?>" selected>
                                    <?php echo esc_html($settings['openai_model'] ?: __('— Carga la lista para seleccionar —', 'wpml-imagina-translate')); ?>
                                </option>
                            </select>
                            <button type="button" class="button wit-refresh-models"
                                    data-target="openai_model"
                                    data-provider="openai"
                                    data-key-field="openai_api_key">
                                ↻ <?php _e('Actualizar lista', 'wpml-imagina-translate'); ?>
                            </button>
                            <span class="wit-models-status"></span>
                            <p class="description"><?php _e('La lista se carga automáticamente al abrir esta página si hay una API key guardada.', 'wpml-imagina-translate'); ?></p>
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
                            <?php $this->render_api_key_field('claude', $settings['claude_api_key']); ?>
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
                            <select name="<?php echo esc_attr($this->option_name); ?>[claude_model]"
                                    id="claude_model"
                                    class="regular-text wit-model-select"
                                    data-provider="claude"
                                    data-key-field="claude_api_key"
                                    data-saved="<?php echo esc_attr($settings['claude_model']); ?>">
                                <option value="<?php echo esc_attr($settings['claude_model']); ?>" selected>
                                    <?php echo esc_html($settings['claude_model'] ?: __('— Carga la lista para seleccionar —', 'wpml-imagina-translate')); ?>
                                </option>
                            </select>
                            <button type="button" class="button wit-refresh-models"
                                    data-target="claude_model"
                                    data-provider="claude"
                                    data-key-field="claude_api_key">
                                ↻ <?php _e('Actualizar lista', 'wpml-imagina-translate'); ?>
                            </button>
                            <span class="wit-models-status"></span>
                            <p class="description"><?php _e('La lista se carga automáticamente al abrir esta página si hay una API key guardada.', 'wpml-imagina-translate'); ?></p>
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
                            <?php $this->render_api_key_field('gemini', $settings['gemini_api_key']); ?>
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
                            <select name="<?php echo esc_attr($this->option_name); ?>[gemini_model]"
                                    id="gemini_model"
                                    class="regular-text wit-model-select"
                                    data-provider="gemini"
                                    data-key-field="gemini_api_key"
                                    data-saved="<?php echo esc_attr($settings['gemini_model']); ?>">
                                <option value="<?php echo esc_attr($settings['gemini_model']); ?>" selected>
                                    <?php echo esc_html($settings['gemini_model'] ?: __('— Carga la lista para seleccionar —', 'wpml-imagina-translate')); ?>
                                </option>
                            </select>
                            <button type="button" class="button wit-refresh-models"
                                    data-target="gemini_model"
                                    data-provider="gemini"
                                    data-key-field="gemini_api_key">
                                ↻ <?php _e('Actualizar lista', 'wpml-imagina-translate'); ?>
                            </button>
                            <span class="wit-models-status"></span>
                            <p class="description"><?php _e('La lista se carga automáticamente al abrir esta página si hay una API key guardada.', 'wpml-imagina-translate'); ?></p>
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
                            <textarea name="<?php echo esc_attr($this->option_name); ?>[translation_prompt]"
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
                                       name="<?php echo esc_attr($this->option_name); ?>[translate_meta_fields]"
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
                            <textarea name="<?php echo esc_attr($this->option_name); ?>[meta_fields_list]"
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
                                   name="<?php echo esc_attr($this->option_name); ?>[batch_size]"
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
                            <label for="glossary"><?php _e('Glosario', 'wpml-imagina-translate'); ?></label>
                        </th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->option_name); ?>[glossary]"
                                      id="glossary"
                                      rows="8"
                                      class="large-text code"
                                      spellcheck="false"
                                      placeholder="Imagina&#10;Servicios = Services&#10;[en] Inicio = Home"><?php echo esc_textarea($settings['glossary']); ?></textarea>
                            <p class="description">
                                <?php _e('Una regla por línea. Las reglas tienen prioridad sobre el criterio de la IA.', 'wpml-imagina-translate'); ?>
                            </p>
                            <ul class="description" style="margin-left:1.5em;list-style:disc;">
                                <li><code>Imagina</code> — <?php _e('nunca se traduce, en ningún idioma', 'wpml-imagina-translate'); ?></li>
                                <li><code>Servicios = Services</code> — <?php _e('traducción fija en todos los idiomas', 'wpml-imagina-translate'); ?></li>
                                <li><code>[en] Inicio = Home</code> — <?php _e('solo para inglés', 'wpml-imagina-translate'); ?></li>
                                <li><code>[fr,de] Contacto = Kontakt</code> — <?php _e('para varios idiomas', 'wpml-imagina-translate'); ?></li>
                                <li><code># comentario</code> — <?php _e('las líneas que empiezan por # se ignoran', 'wpml-imagina-translate'); ?></li>
                            </ul>
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
                                       name="<?php echo esc_attr($this->option_name); ?>[enable_translation_memory]"
                                       id="enable_translation_memory"
                                       value="1"
                                       <?php checked($settings['enable_translation_memory'], true); ?>>
                                <?php _e('Reutilizar traducciones ya realizadas', 'wpml-imagina-translate'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Evita pagar dos veces por la misma frase y garantiza que se traduzca igual en todo el sitio. Recomendado.', 'wpml-imagina-translate'); ?>
                            </p>
                            <?php
                            $memory_stats = WIT_Translation_Memory::instance()->stats();
                            if ($memory_stats['entries'] > 0) :
                                ?>
                                <p>
                                    <strong><?php
                                        printf(
                                            /* translators: 1: stored entries, 2: times reused */
                                            esc_html__('%1$s cadenas guardadas, reutilizadas %2$s veces.', 'wpml-imagina-translate'),
                                            esc_html(number_format_i18n($memory_stats['entries'])),
                                            esc_html(number_format_i18n($memory_stats['reuses']))
                                        );
                                    ?></strong>
                                </p>
                                <p>
                                    <button type="button" class="button" id="wit-clear-memory">
                                        <?php esc_html_e('Vaciar memoria', 'wpml-imagina-translate'); ?>
                                    </button>
                                    <span id="wit-clear-memory-status"></span>
                                </p>
                            <?php endif; ?>
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
            'claude_model' => 'claude-haiku-4-5-20251001',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-2.5-flash',
            'translation_prompt' => 'Translate the following text to {target_language}. Return ONLY the translated text, nothing else. Do not add quotes, explanations, or formatting. Keep proper nouns, brand names, and technical terms unchanged.',
            'glossary' => '',
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
