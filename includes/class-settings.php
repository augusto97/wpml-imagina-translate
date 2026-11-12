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


    /**
     * Set while the plugin itself writes an already-validated settings array,
     * so the form sanitizer does not rebuild it from absent form fields.
     *
     * @var bool
     */
    private $internal_write = false;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));

        // Not admin_init: wp-admin/includes/menu.php runs the access check and
        // wp_die()s with a 403 before admin_init ever fires, so a redirect
        // hooked there never ran. This action is fired immediately before that
        // wp_die() and is the only point early enough to intercept it.
        add_action('admin_page_access_denied', array($this, 'redirect_legacy_settings_url'));
        add_action('admin_notices', array($this, 'missing_api_key_notice'));

        // "Ajustes" straight from the plugins list, where people look first.
        add_filter('plugin_action_links_' . WIT_PLUGIN_BASENAME, array($this, 'add_action_links'));
    }

    /**
     * URL of the settings page.
     *
     * @return string
     */
    public function settings_url() {
        return admin_url('admin.php?page=wpml-imagina-translate-settings');
    }

    /**
     * Whether the selected provider has a key stored.
     *
     * @return bool
     */
    public function has_api_key() {
        $settings = $this->get_settings();
        $provider = $settings['ai_provider'];

        return !empty($settings[$provider . '_api_key']);
    }

    /**
     * Replace the glossary text, and nothing else.
     *
     * Written straight to the option rather than through update_option()'s
     * sanitize callback: that callback rebuilds the whole settings array from
     * form input, and a glossary edit coming from the chat must never be able
     * to touch API keys or any other setting by accident.
     *
     * @param string $glossary
     */
    public function update_glossary($glossary) {
        $settings             = $this->get_settings();
        $settings['glossary'] = sanitize_textarea_field($glossary);

        $this->internal_write = true;
        try {
            update_option($this->option_name, $settings, false);
        } finally {
            $this->internal_write = false;
        }
    }

    /**
     * Settings that may be changed from the Claude chat, with their sanitizers.
     *
     * Deliberately short. Not here, ever: API keys and the provider (secrets
     * and billing), the model, the translation prompt — text a manipulated
     * chat could turn into "add this link to every page" for every future
     * API translation — and the MCP switch itself.
     *
     * @return array<string,callable>
     */
    public static function chat_editable() {
        return array(
            'enable_translation_memory' => function ($value) { return (bool) $value; },
            'translate_meta_fields'     => function ($value) { return (bool) $value; },
            'batch_size'                => function ($value) { return min(50, max(1, absint($value))); },
            'meta_fields_list'          => function ($value) {
                $keys = array_filter(array_map(function ($key) {
                    return preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) $key));
                }, is_array($value) ? $value : explode(',', (string) $value)));
                return implode(',', array_values(array_unique($keys)));
            },
        );
    }

    /**
     * Change some of the chat-editable settings, and nothing else.
     *
     * @param array $changes key => value; unknown keys are ignored.
     * @return array The keys actually changed.
     */
    public function update_chat_settings(array $changes) {
        $settings = $this->get_settings();
        $editable = self::chat_editable();
        $changed  = array();

        foreach ($changes as $key => $value) {
            if (!isset($editable[$key])) {
                continue;
            }
            $clean = call_user_func($editable[$key], $value);
            if ($settings[$key] !== $clean) {
                $settings[$key] = $clean;
                $changed[]      = $key;
            }
        }

        if (!empty($changed)) {
            $this->internal_write = true;
            try {
                update_option($this->option_name, $settings, false);
            } finally {
                $this->internal_write = false;
            }
        }

        return $changed;
    }

    /**
     * Save settings coming from the admin app.
     *
     * Partial: only the keys sent change. The form sanitizer expects a full
     * form submission — an absent checkbox means "off", an empty key means
     * "keep" — so the current values are laid under the changes first. And
     * it runs explicitly: WordPress attaches it in admin_init, which REST
     * requests never fire, so update_option() alone would store the input
     * unsanitized.
     *
     * @param array $changes
     * @return array The stored settings.
     */
    public function save_from_panel(array $changes) {
        $current = $this->get_settings();
        $input   = $current;

        // Keys are write-only: never echoed back, so never resubmitted.
        foreach (array('openai', 'claude', 'gemini') as $name) {
            $input[$name . '_api_key'] = '';
        }

        foreach ($changes as $key => $value) {
            $input[$key] = $value;
        }

        $input['use_wpml_fields_shown'] = 1;

        foreach (array('translate_meta_fields', 'enable_translation_memory', 'mcp_enabled', 'use_wpml_fields') as $flag) {
            if (empty($input[$flag])) {
                unset($input[$flag]);
            }
        }

        $clean = $this->sanitize_settings($input);

        $this->internal_write = true;
        try {
            update_option($this->option_name, $clean, false);
        } finally {
            $this->internal_write = false;
        }

        return $this->get_settings();
    }

    /**
     * Add a direct "Ajustes" link to the plugin's row on the plugins screen.
     *
     * @param string[] $links
     * @return string[]
     */
    public function add_action_links($links) {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url($this->settings_url()),
            esc_html__('Ajustes', 'wpml-imagina-translate')
        ));

        return $links;
    }

    /**
     * Point out that nothing can be translated until a key is stored.
     *
     * Without this the first failure is a generic "API key no configurada"
     * error on an individual post, which does not say where to fix it.
     */
    public function missing_api_key_notice() {
        if (!current_user_can('manage_options') || $this->has_api_key()) {
            return;
        }

        // Translating through Claude needs no API key at all.
        $settings = $this->get_settings();
        if (!empty($settings['mcp_enabled'])) {
            return;
        }

        // Only on the plugins list: the plugin's own screens guide the
        // setup themselves.
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'plugins') {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('WPML Imagina Translate', 'wpml-imagina-translate'); ?>:</strong>
                <?php esc_html_e('todavía no has configurado una API key, así que no se puede traducir nada.', 'wpml-imagina-translate'); ?>
                <a href="<?php echo esc_url($this->settings_url()); ?>">
                    <?php esc_html_e('Configurar ahora', 'wpml-imagina-translate'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Send the pre-1.2.1 settings URL to the current one.
     *
     * The page used to live under Settings; bookmarks and older documentation
     * still point at options-general.php, where WordPress now answers 403
     * because no page is registered there any more.
     */
    public function redirect_legacy_settings_url() {
        global $pagenow;

        if ($pagenow !== 'options-general.php') {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page !== 'wpml-imagina-translate-settings') {
            return;
        }

        wp_safe_redirect($this->settings_url());
        exit;
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
        if ($this->internal_write) {
            return $input;
        }

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
        $sanitized['mcp_enabled']               = !empty($input['mcp_enabled']);
        // The checkbox only exists when WPML is active; without it the value
        // is kept, not switched off by a form that never showed it.
        $sanitized['use_wpml_fields']           = array_key_exists('use_wpml_fields_shown', (array) $input)
            ? !empty($input['use_wpml_fields'])
            : !empty($current['use_wpml_fields']);
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
     * Problems that would stop Claude from connecting, in plain words.
     *
     * @return string[]
     */
    public function mcp_requirement_problems() {
        $problems = array();

        if (!class_exists('WIT_Abilities') || !WIT_Abilities::is_supported()) {
            $problems[] = sprintf(
                /* translators: %s: installed WordPress version */
                __('Requiere WordPress 6.9 o superior (este sitio tiene %s).', 'wpml-imagina-translate'),
                get_bloginfo('version')
            );
        }

        if (!get_option('permalink_structure')) {
            $problems[] = __('Los enlaces permanentes están en «Simple». Cámbialos en Ajustes → Enlaces permanentes a cualquier otra opción: la conexión necesita URLs limpias.', 'wpml-imagina-translate');
        }

        if (wp_parse_url(home_url(), PHP_URL_SCHEME) !== 'https') {
            $problems[] = __('El sitio no usa HTTPS. Claude solo se conecta a servidores HTTPS.', 'wpml-imagina-translate');
        }

        return $problems;
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
            'mcp_enabled' => false,
            'use_wpml_fields' => true,
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
