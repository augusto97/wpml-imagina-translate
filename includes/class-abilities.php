<?php
/**
 * Abilities - the translation app's everyday operations, for Claude.
 *
 * Each operation is a WordPress ability (the Abilities API, core since 6.9):
 * a name, a JSON Schema for its input, a permission check and a callback.
 * WIT_MCP_Server exposes them to the Claude chat as MCP tools.
 *
 * Scope is deliberate. These are the things a person does in this app day to
 * day — see what is missing, translate, review, correct, publish, manage the
 * glossary. Nothing here reads or changes API keys, providers, prompts or any
 * other configuration: a chat connected over the internet has no business
 * with secrets.
 *
 * And nothing here calls the translation API. Translating over MCP means the
 * user's Claude subscription does the work; spending API tokens underneath it
 * would charge twice for one translation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Abilities {

    const CATEGORY = 'wit-translation';

    /** Page size ceiling for listings. */
    const MAX_PER_PAGE = 50;

    /** Source posts inspected per language for overviews and listings. */
    const SCAN_LIMIT = 1000;

    private static $instance = null;

    /**
     * Who is acting: 'mcp' (the Claude chat) or 'panel' (the plugin's own
     * screens, which reuse these callbacks). Only changes what the history
     * says and records.
     *
     * @var string
     */
    public static $channel = 'mcp';

    /**
     * Run a callback as the admin panel rather than the chat.
     *
     * @param callable $callback
     * @return mixed
     */
    public static function as_panel(callable $callback) {
        $previous      = self::$channel;
        self::$channel = 'panel';
        try {
            return $callback();
        } finally {
            self::$channel = $previous;
        }
    }

    /** Where an action came from, for history messages. */
    private static function from() {
        return self::$channel === 'panel'
            ? __('desde el panel', 'wpml-imagina-translate')
            : __('desde Claude', 'wpml-imagina-translate');
    }

    /** The history's "via" column. */
    private static function via() {
        return self::$channel === 'panel' ? 'panel' : 'mcp';
    }

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_abilities_api_categories_init', array($this, 'register_category'));
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    /**
     * Whether this WordPress has the Abilities API.
     *
     * @return bool
     */
    public static function is_supported() {
        return function_exists('wp_register_ability') && function_exists('wp_get_ability');
    }

    public function register_category() {
        wp_register_ability_category(self::CATEGORY, array(
            'label'       => __('Traducciones', 'wpml-imagina-translate'),
            'description' => __('Ver el estado de las traducciones, traducir contenido, revisarlo y publicarlo.', 'wpml-imagina-translate'),
        ));
    }

    /**
     * Definitions of every ability, keyed by name.
     *
     * Also read by WIT_MCP_Server to build its tool list, so the set exposed
     * over MCP is exactly the set registered here.
     *
     * @return array<string,array>
     */
    public static function definitions() {
        $all = self::all_definitions();

        // Only what this site can do: no strings tools without String
        // Translation, no WPML settings tool without the real WPML.
        if (!WIT_Strings::is_available()) {
            unset($all['wit/strings-overview'], $all['wit/list-strings'], $all['wit/prepare-strings'], $all['wit/save-strings']);
        }
        if (!WIT_WPML_Native::is_active()) {
            unset($all['wit/set-wpml-translation-mode']);
        }

        return $all;
    }

    private static function all_definitions() {
        $language = array(
            'type'        => 'string',
            'description' => 'Target language code, as returned by translation_overview (e.g. "en", "fr", "pt-br").',
            'minLength'   => 2,
            'maxLength'   => 10,
        );
        $post_id = array(
            'type'        => 'integer',
            'description' => 'ID of the ORIGINAL post or page (in the site default language), not of a translation.',
            'minimum'     => 1,
        );
        $post_types = array(
            'type'        => 'array',
            'description' => 'Post types to include. Defaults to post and page.',
            'items'       => array('type' => 'string'),
            'maxItems'    => 20,
        );

        return array(
            'wit/translation-overview' => array(
                'label'       => __('Resumen de traducciones', 'wpml-imagina-translate'),
                'description' => 'Overview of the site: default language, active languages, and for each language how many posts are translated and current, outdated (the original changed after translating), missing, or of unknown status. Start here when the user asks what is left to translate.',
                'input'       => array(
                    'type'       => 'object',
                    'properties' => array('post_types' => $post_types),
                ),
                'execute'     => array(__CLASS__, 'overview'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/list-posts' => array(
                'label'       => __('Listar contenidos por estado', 'wpml-imagina-translate'),
                'description' => 'List original posts with their translation status for one language. Filter by status: "pending" (missing or outdated — what needs work), "missing", "outdated", "current", "unknown", "edited" (translations someone changed by hand after they were made) or "all". Paginated.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('language'),
                    'properties' => array(
                        'language'   => $language,
                        'status'     => array(
                            'type'    => 'string',
                            'enum'    => array('pending', 'missing', 'outdated', 'current', 'unknown', 'edited', 'all'),
                            'default' => 'pending',
                        ),
                        'post_types' => $post_types,
                        'search'     => array('type' => 'string', 'description' => 'Text to search in titles and content.', 'maxLength' => 200),
                        'page'       => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
                        'per_page'   => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 20),
                    ),
                ),
                'execute'     => array(__CLASS__, 'list_posts'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/post-status' => array(
                'label'       => __('Estado de un contenido', 'wpml-imagina-translate'),
                'description' => 'Translation status of one original post in every active language, with links to edit each translation.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('post_id'),
                    'properties' => array('post_id' => $post_id),
                ),
                'execute'     => array(__CLASS__, 'post_status'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/prepare-translation' => array(
                'label'       => __('Preparar traducción', 'wpml-imagina-translate'),
                'description' => 'Get the text strings to translate for up to 10 original posts into one language. Returns plain-text strings with ids, the rules to follow and any glossary. YOU translate the strings yourself, then call save_translation. Strings already known from the translation memory or the glossary are left out. '
                    . 'Translating uses the user\'s Claude subscription, not the site\'s API key. Before working through many posts, tell the user how many there are and how large they are (see "totals"), and ask whether to continue batch by batch; never go past 10 posts per call.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('language', 'post_ids'),
                    'properties' => array(
                        'language' => $language,
                        'post_ids' => array(
                            'type'     => 'array',
                            'items'    => array('type' => 'integer', 'minimum' => 1),
                            'minItems' => 1,
                            'maxItems' => WIT_Translation_Plan::MAX_POSTS,
                            'description' => 'IDs of ORIGINAL posts, at most 10.',
                        ),
                        'offset'   => array(
                            'type'        => 'integer',
                            'minimum'     => 0,
                            'default'     => 0,
                            'description' => 'Only for a single post too large for one response: the next_offset returned by the previous call.',
                        ),
                    ),
                ),
                'execute'     => array(__CLASS__, 'prepare'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/save-translation' => array(
                'label'       => __('Guardar traducción', 'wpml-imagina-translate'),
                'description' => 'Save translations you produced for the strings from prepare_translation. "translations" maps each string id to its translation. A post is written only when all of its strings are present; otherwise the ones received are kept and the missing ones are listed, so send only those. Creates the translation as a draft, or updates the existing one, and links it in WPML. A translation that was edited by hand is not replaced unless overwrite_edited is true (status "needs_confirmation" until then).',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('language', 'post_ids', 'translations'),
                    'properties' => array(
                        'language'     => $language,
                        'post_ids'     => array(
                            'type'     => 'array',
                            'items'    => array('type' => 'integer', 'minimum' => 1),
                            'minItems' => 1,
                            'maxItems' => WIT_Translation_Plan::MAX_POSTS,
                        ),
                        'translations' => array(
                            'type'                 => 'object',
                            'description'          => 'Map of string id => translated text (plain text).',
                            'additionalProperties' => array('type' => 'string'),
                        ),
                        'overwrite_edited' => array(
                            'type'        => 'boolean',
                            'default'     => false,
                            'description' => 'Replace an existing translation that someone edited by hand. Only after the user has agreed to lose those edits.',
                        ),
                    ),
                ),
                'execute'     => array(__CLASS__, 'save'),
                'permission'  => 'edit_posts',
                // Updating replaces the existing translation's text.
                'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => true),
            ),

            'wit/get-translation' => array(
                'label'       => __('Ver una traducción', 'wpml-imagina-translate'),
                'description' => 'Read an existing translation next to its original, as plain text strings, to review it or to find the exact wording to correct.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('post_id', 'language'),
                    'properties' => array('post_id' => $post_id, 'language' => $language),
                ),
                'execute'     => array(__CLASS__, 'get_translation'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/correct-translation' => array(
                'label'       => __('Corregir una frase', 'wpml-imagina-translate'),
                'description' => 'Replace one wording in an existing translation — title, excerpt, content or SEO fields — without regenerating the rest, so manual edits elsewhere are kept. "current_text" must match the translation exactly (use get_translation to find it). Also updates the translation memory so future translations use the new wording.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('post_id', 'language', 'current_text', 'new_text'),
                    'properties' => array(
                        'post_id'      => $post_id,
                        'language'     => $language,
                        'current_text' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 5000),
                        'new_text'     => array('type' => 'string', 'minLength' => 1, 'maxLength' => 5000),
                    ),
                ),
                'execute'     => array(__CLASS__, 'correct'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/publish-translation' => array(
                'label'       => __('Publicar una traducción', 'wpml-imagina-translate'),
                'description' => 'Publish a translation that is still a draft, making it visible on the site. Only call this when the user explicitly asks to publish.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('post_id', 'language'),
                    'properties' => array('post_id' => $post_id, 'language' => $language),
                ),
                'execute'     => array(__CLASS__, 'publish'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/remove-translation' => array(
                'label'       => __('Quitar una traducción', 'wpml-imagina-translate'),
                'description' => 'Take one translation off the site. mode "unpublish" turns it back into a draft (content kept, no longer visible); mode "trash" moves it to the WordPress trash, where it can be restored for 30 days — translating the post again later also restores it. Never deletes permanently. Only when the user explicitly asks to remove or unpublish a translation; say which one before doing it.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('post_id', 'language', 'mode'),
                    'properties' => array(
                        'post_id'  => $post_id,
                        'language' => $language,
                        'mode'     => array('type' => 'string', 'enum' => array('unpublish', 'trash')),
                    ),
                ),
                'execute'     => array(__CLASS__, 'remove_translation'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => true),
            ),

            'wit/site-settings' => array(
                'label'       => __('Configuración del sitio y de WPML', 'wpml-imagina-translate'),
                'description' => 'How translation is set up on this site: WPML languages and URL format, which content types WPML translates, this plugin\'s settings (never secrets), and a list of problems worth fixing. Use it when the user asks how things are configured or why something is not being translated.',
                'input'       => array('type' => 'object', 'properties' => array()),
                'execute'     => array(__CLASS__, 'site_settings'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/update-settings' => array(
                'label'       => __('Cambiar ajustes de traducción', 'wpml-imagina-translate'),
                'description' => 'Change this plugin\'s translation settings: turn the translation memory on or off, turn translation of SEO/custom fields on or off, add or remove custom fields to translate, set the background batch size. API keys, the AI provider, the model and the prompt cannot be changed from here — the user changes those in the plugin settings page. WPML\'s own settings are changed in WPML.',
                'input'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'translation_memory'   => array('type' => 'boolean', 'description' => 'Reuse earlier translations of identical text.'),
                        'translate_meta_fields' => array('type' => 'boolean', 'description' => 'Translate the configured custom fields (SEO title, description…).'),
                        'add_meta_fields'      => array('type' => 'array', 'items' => array('type' => 'string', 'pattern' => '^[A-Za-z0-9_\\-]+$', 'maxLength' => 100), 'maxItems' => 20),
                        'remove_meta_fields'   => array('type' => 'array', 'items' => array('type' => 'string', 'maxLength' => 100), 'maxItems' => 20),
                        'batch_size'           => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Posts per background (API) queue pass.'),
                    ),
                ),
                'execute'     => array(__CLASS__, 'update_settings'),
                'permission'  => 'manage_options',
                'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/strings-overview' => array(
                'label'       => __('Resumen de cadenas', 'wpml-imagina-translate'),
                'description' => 'WPML String Translation: for each language, how many interface strings (theme and plugin texts, widgets, admin texts, site title) are translated, pending or need an update, and the text domains with the most pending. Page-builder content is not here: it is translated with its post. Use it when the user asks about buttons, menus, footers or texts that still show in the original language.',
                'input'       => array('type' => 'object', 'properties' => array()),
                'execute'     => array(__CLASS__, 'strings_overview'),
                'permission'  => 'manage_options',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/list-strings' => array(
                'label'       => __('Listar cadenas', 'wpml-imagina-translate'),
                'description' => 'List String Translation strings for one target language. Filter by domain (text domain or context, from strings_overview), status ("pending" — missing or needing an update —, "needs_update", "translated", "all") and text. Paginated.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('language'),
                    'properties' => array(
                        'language' => $language,
                        'domain'   => array('type' => 'string', 'maxLength' => 160),
                        'status'   => array('type' => 'string', 'enum' => array('pending', 'needs_update', 'translated', 'all'), 'default' => 'pending'),
                        'search'   => array('type' => 'string', 'maxLength' => 200),
                        'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
                        'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50),
                    ),
                ),
                'execute'     => array(__CLASS__, 'list_strings'),
                'permission'  => 'manage_options',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/prepare-strings' => array(
                'label'       => __('Preparar cadenas', 'wpml-imagina-translate'),
                'description' => 'Get interface strings to translate into one language: either the given string_ids, or the next pending ones of a domain (up to 100). Returns text units with ids — strings with markup arrive as their text pieces — and the placeholders each string must keep. YOU translate the units, then call save_strings. Uses the user\'s Claude subscription, never the site\'s API; say how many strings there are before working through many.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('language'),
                    'properties' => array(
                        'language'   => $language,
                        'string_ids' => array('type' => 'array', 'items' => array('type' => 'integer', 'minimum' => 1), 'maxItems' => WIT_Strings::MCP_MAX),
                        'domain'     => array('type' => 'string', 'maxLength' => 160),
                        'limit'      => array('type' => 'integer', 'minimum' => 1, 'maximum' => WIT_Strings::MCP_MAX, 'default' => 50),
                    ),
                ),
                'execute'     => array(__CLASS__, 'prepare_strings'),
                'permission'  => 'manage_options',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/save-strings' => array(
                'label'       => __('Guardar cadenas', 'wpml-imagina-translate'),
                'description' => 'Save translations of interface strings to WPML String Translation: "string_ids" from prepare_strings, "translations" mapping each unit id to its translation. A string whose placeholders (%s, {name}...) differ from the original is rejected and reported; the rest are saved and appear on the site.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('language', 'string_ids', 'translations'),
                    'properties' => array(
                        'language'     => $language,
                        'string_ids'   => array('type' => 'array', 'items' => array('type' => 'integer', 'minimum' => 1), 'minItems' => 1, 'maxItems' => WIT_Strings::MCP_MAX),
                        'translations' => array('type' => 'object', 'additionalProperties' => array('type' => 'string')),
                    ),
                ),
                'execute'     => array(__CLASS__, 'save_strings'),
                'permission'  => 'manage_options',
                // Replaces existing translations of those strings.
                'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => true),
            ),

            'wit/set-wpml-translation-mode' => array(
                'label'       => __('Cambiar qué traduce WPML', 'wpml-imagina-translate'),
                'description' => 'Change a WPML setting. For a post type: "translate", "display_as_translated" (show the original where there is no translation) or "do_not_translate". For a custom field: "translate", "copy", "copy_once" or "ignore". These change how the whole site is translated: "do_not_translate" hides existing translations of that type. Explain the effect and get the user\'s explicit go-ahead first; site_settings shows the current modes.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('mode'),
                    'properties' => array(
                        'post_type'    => array('type' => 'string', 'maxLength' => 60),
                        'custom_field' => array('type' => 'string', 'maxLength' => 160),
                        'mode'         => array('type' => 'string', 'enum' => array('translate', 'display_as_translated', 'do_not_translate', 'copy', 'copy_once', 'ignore')),
                    ),
                ),
                'execute'     => array(__CLASS__, 'set_wpml_mode'),
                'permission'  => 'manage_options',
                'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => true),
            ),

            'wit/get-glossary' => array(
                'label'       => __('Ver el glosario', 'wpml-imagina-translate'),
                'description' => 'The translation glossary: brand terms that are never translated and fixed translations, optionally per language.',
                'input'       => array('type' => 'object', 'properties' => array()),
                'execute'     => array(__CLASS__, 'get_glossary'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/update-glossary' => array(
                'label'       => __('Editar el glosario', 'wpml-imagina-translate'),
                'description' => 'Add or remove glossary rules. Rule format, one per entry: "Brand" (never translated), "Original = Translation" (fixed, every language), "[en] Original = Translation" (English only), "[fr,de] Original = Translation" (several languages). Removal matches whole rules exactly as get_glossary returns them.',
                'input'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'add'    => array('type' => 'array', 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 300), 'maxItems' => 50),
                        'remove' => array('type' => 'array', 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 300), 'maxItems' => 50),
                    ),
                ),
                'execute'     => array(__CLASS__, 'update_glossary'),
                'permission'  => 'manage_options',
                'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
            ),

            'wit/translation-history' => array(
                'label'       => __('Historial de traducciones', 'wpml-imagina-translate'),
                'description' => 'Recent translation activity: which posts were translated, into which language, when, whether it succeeded, and whether it was done through Claude (provider "mcp") or through the API.',
                'input'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'limit'    => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20),
                        'language' => $language,
                    ),
                ),
                'execute'     => array(__CLASS__, 'history'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),
        );
    }

    public function register_abilities() {
        foreach (self::definitions() as $name => $definition) {
            $capability = $definition['permission'];

            wp_register_ability($name, array(
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'category'            => self::CATEGORY,
                'input_schema'        => $definition['input'],
                'execute_callback'    => $definition['execute'],
                // A floor. Every callback also checks the specific posts it
                // touches, because edit_posts does not imply edit_post($id).
                'permission_callback' => function () use ($capability) {
                    return current_user_can($capability);
                },
                'meta'                => array(
                    'annotations' => $definition['annotations'],
                ),
            ));
        }
    }

    // -----------------------------------------------------------------------
    // Callbacks
    // -----------------------------------------------------------------------

    /**
     * @param array $types
     * @return string[]
     */
    private static function post_types($types) {
        $types = array_values(array_filter(array_map('sanitize_key', (array) $types), 'post_type_exists'));

        return !empty($types) ? $types : array('post', 'page');
    }

    public static function overview($input = array()) {
        $wpml    = WIT_WPML_Integration::instance();
        $types   = self::post_types(isset($input['post_types']) ? $input['post_types'] : array());
        $default = $wpml->get_default_language();

        $languages = array();
        $truncated = false;
        $prints    = array(); // source fingerprints, shared across languages

        foreach ($wpml->get_active_languages() as $language) {
            if ($language['code'] === $default) {
                continue;
            }

            $posts  = $wpml->get_pending_translations($language['code'], $types, false, self::SCAN_LIMIT);
            $counts = array('current' => 0, 'outdated' => 0, 'missing' => 0, 'unknown' => 0);
            $drafts = 0;
            $edited = 0;

            if (count($posts) >= self::SCAN_LIMIT) {
                $truncated = true;
            }

            foreach ($posts as $post) {
                $status = WIT_Translation_Status::of($post['id'], $language['code'], $prints);
                $counts[$status['status']]++;
                if ($status['translation_status'] === 'draft') {
                    $drafts++;
                }
                if ($status['edited']) {
                    $edited++;
                }
            }

            $languages[] = array(
                'code'                => $language['code'],
                'name'                => $language['name'],
                'english_name'        => $language['english_name'],
                'originals'           => count($posts),
                'current'             => $counts['current'],
                'outdated'            => $counts['outdated'],
                'missing'             => $counts['missing'],
                'unknown'             => $counts['unknown'],
                'drafts_to_review'    => $drafts,
                'edited_by_hand'      => $edited,
                'pending'             => $counts['missing'] + $counts['outdated'],
            );
        }

        $result = array(
            'default_language' => $default,
            'post_types'       => $types,
            'languages'        => $languages,
            'status_meaning'   => array(
                'current'  => 'Translated, and the original has not changed since.',
                'outdated' => 'Translated, but the original changed afterwards.',
                'missing'  => 'Not translated yet.',
                'unknown'  => 'A translation exists but was not made by this plugin, so its freshness cannot be checked.',
                'edited_by_hand' => 'Translations someone changed by hand after they were made: translating them again replaces those edits.',
            ),
        );

        if ($truncated) {
            $result['truncated'] = sprintf('Only the %d most recently modified originals per language were counted.', self::SCAN_LIMIT);
        }

        $total_pending = array_sum(array_column($languages, 'pending'));
        if ($total_pending > WIT_Translation_Plan::MAX_POSTS) {
            $result['note'] = self::volume_note($total_pending);
        }

        return $result;
    }

    public static function list_posts($input) {
        $wpml     = WIT_WPML_Integration::instance();
        $language = (string) $input['language'];

        if (!$wpml->is_active_language($language)) {
            return new WP_Error('wit_bad_language', sprintf('"%s" is not an active WPML language.', $language));
        }

        $filter = isset($input['status']) ? $input['status'] : 'pending';
        $list   = WIT_Translation_Status::listing(
            $language,
            self::post_types(isset($input['post_types']) ? $input['post_types'] : array()),
            $filter,
            isset($input['search']) ? (string) $input['search'] : '',
            isset($input['page']) ? (int) $input['page'] : 1,
            min(self::MAX_PER_PAGE, max(1, isset($input['per_page']) ? (int) $input['per_page'] : 20)),
            self::SCAN_LIMIT
        );

        $items = array();
        foreach ($list['items'] as $post) {
            $items[] = array(
                'post_id'              => $post['id'],
                'title'                => $post['title'],
                'type'                 => $post['type'],
                'status'               => $post['status'],
                'edited_by_hand'       => $post['edited'],
                'translation_id'       => $post['translation_id'],
                'translation_status'   => $post['translation_status'],
                'translated_at'        => $post['translated_at'],
                'edit_url'             => $post['edit_url'],
                'translation_edit_url' => $post['translation_edit_url'],
            );
        }

        $result = array(
            'language' => $language,
            'status'   => $filter,
            'total'    => $list['total'],
            'page'     => $list['page'],
            'pages'    => $list['pages'],
            'items'    => $items,
        );

        if ($list['truncated']) {
            $result['truncated'] = sprintf('Only the %d most recently modified originals were inspected.', self::SCAN_LIMIT);
        }

        if (in_array($filter, array('pending', 'missing', 'outdated'), true) && $list['total'] > WIT_Translation_Plan::MAX_POSTS) {
            $result['note'] = self::volume_note($list['total']);
        }

        return $result;
    }

    public static function post_status($input) {
        $post_id = (int) $input['post_id'];
        $post    = get_post($post_id);

        if (!$post || !current_user_can('edit_post', $post_id)) {
            return new WP_Error('wit_not_found', sprintf('No post with ID %d that you can edit.', $post_id));
        }

        $wpml      = WIT_WPML_Integration::instance();
        $default   = $wpml->get_default_language();
        $languages = array();
        $prints    = array();

        foreach ($wpml->get_active_languages() as $language) {
            if ($language['code'] === $default) {
                continue;
            }

            $status      = WIT_Translation_Status::of($post_id, $language['code'], $prints);
            $languages[] = array(
                'language'             => $language['code'],
                'name'                 => $language['name'],
                'status'               => $status['status'],
                'translation_id'       => $status['translation_id'],
                'translation_status'   => $status['translation_status'],
                'translated_at'        => $status['translated_at'],
                'edited_by_hand'       => $status['edited'],
                'translation_edit_url' => $status['translation_id'] ? get_edit_post_link($status['translation_id'], 'raw') : '',
            );
        }

        return array(
            'post_id'   => $post_id,
            'title'     => get_the_title($post_id),
            'type'      => $post->post_type,
            'language'  => $wpml->get_post_language($post_id),
            'is_original' => $wpml->get_post_language($post_id) === $default,
            'edit_url'  => get_edit_post_link($post_id, 'raw'),
            'languages' => $languages,
        );
    }

    public static function prepare($input) {
        return WIT_Translation_Plan::prepare(
            (array) $input['post_ids'],
            (string) $input['language'],
            isset($input['offset']) ? (int) $input['offset'] : 0
        );
    }

    public static function save($input) {
        return WIT_Translation_Plan::save(
            (array) $input['post_ids'],
            (string) $input['language'],
            (array) $input['translations'],
            !empty($input['overwrite_edited'])
        );
    }

    public static function get_translation($input) {
        $post_id  = (int) $input['post_id'];
        $language = (string) $input['language'];
        $found    = self::translation_of($post_id, $language);

        if (is_wp_error($found)) {
            return $found;
        }

        list($source, $translation) = $found;

        $parser    = new WIT_Content_Parser();
        $elementor = new WIT_Elementor_Handler();
        $manager   = new WIT_Translation_Manager();

        $strings = function (WP_Post $post) use ($parser, $elementor) {
            return $elementor->is_elementor_post($post->ID)
                ? $elementor->collect_strings($post->ID)
                : $parser->collect_strings($post->post_content);
        };

        $source_strings      = $strings($source);
        $translation_strings = $strings($translation);

        // Same response budget as prepare_translation.
        $budget = WIT_Translation_Plan::RESPONSE_BUDGET;
        $trim   = function (array $list) use (&$budget) {
            $out = array();
            foreach ($list as $text) {
                $budget -= mb_strlen($text) + 8;
                if ($budget < 0) {
                    break;
                }
                $out[] = $text;
            }
            return $out;
        };

        $result = array(
            'status'      => WIT_Translation_Status::of($post_id, $language)['status'],
            'original'    => array(
                'post_id' => $source->ID,
                'title'   => $source->post_title,
                'excerpt' => $source->post_excerpt,
                'meta'    => $manager->collect_meta_values($source->ID),
                'strings' => $trim($source_strings),
            ),
            'translation' => array(
                'post_id'     => $translation->ID,
                'post_status' => $translation->post_status,
                'edit_url'    => get_edit_post_link($translation->ID, 'raw'),
                'title'       => $translation->post_title,
                'excerpt'     => $translation->post_excerpt,
                'meta'        => $manager->collect_meta_values($translation->ID),
                'strings'     => $trim($translation_strings),
            ),
        );

        if ($budget < 0) {
            $result['truncated'] = 'The content is too long to show in full; only the first strings are listed.';
        }

        return $result;
    }

    public static function correct($input) {
        $post_id  = (int) $input['post_id'];
        $language = (string) $input['language'];
        $current  = (string) $input['current_text'];
        $new      = (string) $input['new_text'];

        $found = self::translation_of($post_id, $language);

        if (is_wp_error($found)) {
            return $found;
        }

        list(, $translation) = $found;

        $was_edited = WIT_Translation_Status::edited_by_hand($translation->ID);

        // Same rule as translations arriving over MCP: plain text stays plain.
        if (strpos($current, '<') === false) {
            $new = wp_strip_all_tags($new);
        }

        if (trim($new) === '') {
            return new WP_Error('wit_empty', 'The new text is empty.');
        }

        $current_trimmed = trim($current);
        $map             = array($current_trimmed => trim($new));
        $changed         = array();

        $title   = $translation->post_title;
        $excerpt = $translation->post_excerpt;
        $content = $translation->post_content;

        if (trim($title) === $current_trimmed) {
            $title     = trim($new);
            $changed[] = 'title';
        }
        if (trim($excerpt) === $current_trimmed) {
            $excerpt   = trim($new);
            $changed[] = 'excerpt';
        }

        $elementor = new WIT_Elementor_Handler();
        $content_replacements = 0;

        if ($elementor->is_elementor_post($translation->ID)) {
            $content_replacements = $elementor->apply_map_to_post($translation->ID, $map);
            if ($content_replacements > 0) {
                $changed[] = 'elementor';
            }
        } else {
            $content = (new WIT_Content_Parser())->apply_map($content, $map, $content_replacements);
            if ($content_replacements > 0) {
                $changed[] = 'content';
            }
        }

        foreach ((new WIT_Translation_Manager())->collect_meta_values($translation->ID) as $key => $value) {
            if (trim($value) === $current_trimmed) {
                update_post_meta($translation->ID, $key, wp_slash(trim($new)));
                $changed[] = 'meta:' . $key;
            }
        }

        if (empty($changed)) {
            return new WP_Error(
                'wit_text_not_found',
                'That exact text does not appear in the translation. Use get_translation to see its current wording and copy it exactly.'
            );
        }

        if (in_array('title', $changed, true) || in_array('excerpt', $changed, true) || in_array('content', $changed, true)) {
            $saved = WIT_WPML_Integration::instance()->update_translated_post($translation->ID, array(
                'title'   => $title,
                'content' => $content,
                'excerpt' => $excerpt,
            ));

            if (is_wp_error($saved)) {
                return $saved;
            }
        }

        $memory_updated = WIT_Translation_Memory::instance()->replace_translation($language, $current_trimmed, trim($new));

        // A correction made through the plugin is not a hand edit to protect:
        // the memory now holds the new wording, so a re-translation keeps it.
        // Only when the translation was untouched before, though — otherwise
        // this would launder the person's own edits into "plugin text".
        if (!$was_edited) {
            WIT_Translation_Status::accept_edits($translation->ID);
        }

        (new WIT_Translation_Manager())->log_event($post_id, $language, sprintf(
            /* translators: 1: where from, 2: old wording, 3: new wording */
            __('Corrección %1$s: «%2$s» → «%3$s»', 'wpml-imagina-translate'),
            self::from(),
            mb_substr($current_trimmed, 0, 80),
            mb_substr(trim($new), 0, 80)
        ), self::via());

        return array(
            'translation_id'      => $translation->ID,
            'changed'             => $changed,
            'content_replacements' => $content_replacements,
            'memory_updated'      => $memory_updated,
            'edit_url'            => get_edit_post_link($translation->ID, 'raw'),
        );
    }

    public static function publish($input) {
        $found = self::translation_of((int) $input['post_id'], (string) $input['language']);

        if (is_wp_error($found)) {
            return $found;
        }

        list(, $translation) = $found;

        $type = get_post_type_object($translation->post_type);

        if (!$type || !current_user_can($type->cap->publish_posts)) {
            return new WP_Error('wit_forbidden', 'You do not have permission to publish this content.');
        }

        if ($translation->post_status !== 'publish') {
            // wp_publish_post() changes the status only. wp_update_post() would
            // re-run the content through kses for users without
            // unfiltered_html and strip markup the translation inherited.
            wp_publish_post($translation->ID);
            (new WIT_Translation_Manager())->log_event((int) $input['post_id'], (string) $input['language'], sprintf(__('Traducción publicada %s', 'wpml-imagina-translate'), self::from()), self::via());
        }

        return array(
            'translation_id' => $translation->ID,
            'post_status'    => get_post_status($translation->ID),
            'url'            => get_permalink($translation->ID),
        );
    }

    public static function remove_translation($input) {
        $found = self::translation_of((int) $input['post_id'], (string) $input['language']);

        if (is_wp_error($found)) {
            return $found;
        }

        list($source, $translation) = $found;
        $mode = (string) $input['mode'];

        if ($mode === 'trash') {
            if (!current_user_can('delete_post', $translation->ID)) {
                return new WP_Error('wit_forbidden', 'You do not have permission to delete this translation.');
            }
            if (!wp_trash_post($translation->ID)) {
                return new WP_Error('wit_trash_failed', 'WordPress could not move the translation to the trash.');
            }
            $message = sprintf(__('Traducción enviada a la papelera %s', 'wpml-imagina-translate'), self::from());
        } else {
            if ($translation->post_status !== 'draft') {
                // Status only, like publish: wp_update_post() would re-run the
                // content through kses for users without unfiltered_html.
                global $wpdb;
                $wpdb->update($wpdb->posts, array('post_status' => 'draft'), array('ID' => $translation->ID));
                clean_post_cache($translation->ID);
                wp_transition_post_status('draft', $translation->post_status, get_post($translation->ID));
            }
            $message = sprintf(__('Traducción despublicada (vuelve a borrador) %s', 'wpml-imagina-translate'), self::from());
        }

        (new WIT_Translation_Manager())->log_event($source->ID, (string) $input['language'], $message, self::via());

        return array(
            'translation_id' => $translation->ID,
            'post_status'    => get_post_status($translation->ID),
            'restorable'     => $mode === 'trash' ? 'From the WordPress trash for 30 days, or by translating the post again.' : 'Publish it again with publish_translation.',
        );
    }

    public static function site_settings($input = array()) {
        $wpml     = WIT_WPML_Integration::instance();
        $plugin   = WIT_Settings::instance();
        $settings = $plugin->get_settings();
        $problems = array();

        // WPML ---------------------------------------------------------------
        $default   = $wpml->get_default_language();
        $languages = array();
        foreach ($wpml->get_active_languages() as $language) {
            $languages[] = array('code' => $language['code'], 'name' => $language['name'], 'default' => $language['default']);
        }

        $url_formats = array(1 => 'directories (example.com/en/)', 2 => 'separate domains', 3 => 'URL parameter (?lang=en)');
        $negotiation = apply_filters('wpml_setting', null, 'language_negotiation_type');

        $types = array();
        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }
            $translatable = apply_filters('wpml_is_translated_post_type', null, $type->name);
            $types[]      = array(
                'post_type'    => $type->name,
                'label'        => $type->labels->singular_name,
                'translatable' => $translatable === null ? 'unknown' : (bool) $translatable,
                'mode'         => WIT_WPML_Native::post_type_mode($type->name),
            );
            if ($translatable === false && in_array($type->name, array('post', 'page', 'product'), true)) {
                $problems[] = sprintf('WPML is set not to translate "%s". Those items cannot be translated until it is changed in WPML → Settings → Post Types Translation.', $type->labels->name);
            }
        }

        // This plugin --------------------------------------------------------
        $provider = $settings['ai_provider'];
        $memory   = !empty($settings['enable_translation_memory']);
        $glossary = WIT_Glossary::parse((string) $settings['glossary']);

        if (!$memory) {
            $problems[] = 'The translation memory is off: identical sentences are translated (and, through the API, paid for) again each time, and can come out worded differently. Turning it on is recommended (update_settings translation_memory: true).';
        }
        if (!$plugin->has_api_key()) {
            $problems[] = 'No API key is configured, so the dashboard and the background queue cannot translate. Translating through this chat still works.';
        }

        $problems = array_merge($problems, WIT_WPML_Native::problems());

        $fields = array();
        if (WIT_WPML_Native::is_active()) {
            $names = array(0 => 'ignore', 1 => 'copy', 2 => 'translate', 3 => 'copy_once');
            foreach (array_filter(array_map('trim', explode(',', (string) $settings['meta_fields_list']))) as $key) {
                $mode = WIT_WPML_Native::field_mode($key);
                $fields[$key] = $mode === null ? 'not set' : $names[$mode];
            }
        }

        return array(
            'wpml'     => array(
                'real_wpml'        => WIT_WPML_Native::is_active(),
                'translation_management' => WIT_WPML_Native::has_tm(),
                'string_translation' => WIT_Strings::is_available() ? (defined('WPML_ST_VERSION') ? WPML_ST_VERSION : true) : false,
                'plugin_fields_in_wpml' => $fields,
                'version'          => defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : 'unknown',
                'default_language' => $default,
                'languages'        => $languages,
                'url_format'       => isset($url_formats[(int) $negotiation]) ? $url_formats[(int) $negotiation] : 'unknown',
                'post_types'       => $types,
            ),
            'plugin'   => array(
                'version'               => WIT_VERSION,
                'api_provider'          => $provider,
                'api_model'             => isset($settings[$provider . '_model']) ? $settings[$provider . '_model'] : '',
                'api_key_configured'    => $plugin->has_api_key(),
                'translation_memory'    => $memory,
                'memory_entries'        => WIT_Translation_Memory::instance()->stats()['entries'],
                'translate_meta_fields' => !empty($settings['translate_meta_fields']),
                'meta_fields'           => array_values(array_filter(array_map('trim', explode(',', (string) $settings['meta_fields_list'])))),
                'glossary_rules'        => count($glossary),
                'batch_size'            => (int) $settings['batch_size'],
                'new_translations_are'  => apply_filters('wit_new_translation_status', 'draft', null, ''),
            ),
            'problems' => $problems,
            'note'     => 'API keys, provider, model and prompt are changed only in the plugin settings page. WPML\'s translation mode of a post type or custom field can be changed with set_wpml_translation_mode; everything else of WPML only in WPML.',
        );
    }

    public static function update_settings($input) {
        $settings = WIT_Settings::instance()->get_settings();
        $changes  = array();

        if (isset($input['translation_memory'])) {
            $changes['enable_translation_memory'] = (bool) $input['translation_memory'];
        }
        if (isset($input['translate_meta_fields'])) {
            $changes['translate_meta_fields'] = (bool) $input['translate_meta_fields'];
        }
        if (isset($input['batch_size'])) {
            $changes['batch_size'] = (int) $input['batch_size'];
        }
        if (!empty($input['add_meta_fields']) || !empty($input['remove_meta_fields'])) {
            $fields = array_filter(array_map('trim', explode(',', (string) $settings['meta_fields_list'])));
            $fields = array_values(array_diff($fields, array_map('trim', (array) (isset($input['remove_meta_fields']) ? $input['remove_meta_fields'] : array()))));
            foreach ((array) (isset($input['add_meta_fields']) ? $input['add_meta_fields'] : array()) as $key) {
                if (strpos((string) $key, '_elementor') === 0) {
                    return new WP_Error('wit_bad_field', 'Elementor\'s data is translated on its own; it cannot be added as a custom field.');
                }
                $fields[] = (string) $key;
            }
            $changes['meta_fields_list'] = $fields;
        }

        if (empty($changes)) {
            return new WP_Error('wit_nothing', 'Nothing to change.');
        }

        $changed = WIT_Settings::instance()->update_chat_settings($changes);

        if (!empty($changed)) {
            (new WIT_Translation_Manager())->log_event(0, '', sprintf(
                /* translators: 1: where from, 2: setting names */
                __('Ajustes cambiados %1$s: %2$s', 'wpml-imagina-translate'),
                self::from(),
                implode(', ', $changed)
            ), self::via());
        }

        $result            = self::site_settings();
        $result['changed'] = $changed;

        return $result;
    }

    public static function strings_overview($input = array()) {
        if (!WIT_Strings::is_available()) {
            return new WP_Error('wit_no_st', 'WPML String Translation is not active on this site.');
        }

        $overview = WIT_Strings::overview();
        if (!$overview['files_writable']) {
            $overview['problem'] = 'wp-content/languages/wpml does not exist or is not writable: String Translation cannot write its translation files, so translated theme and plugin strings will not show on the site. Tell the user; the hosting needs to fix the folder permissions.';
        }

        $pending = array_sum(array_column($overview['languages'], 'pending'));
        if ($pending > WIT_Strings::MCP_MAX) {
            $overview['note'] = sprintf('%d strings are pending in total. Translating them here uses the user\'s Claude subscription; tell the user and let them choose which languages or domains, %d strings per call at most.', $pending, WIT_Strings::MCP_MAX);
        }

        return $overview;
    }

    public static function list_strings($input) {
        if (!WIT_Strings::is_available()) {
            return new WP_Error('wit_no_st', 'WPML String Translation is not active on this site.');
        }

        $language = (string) $input['language'];
        if (!WIT_WPML_Integration::instance()->is_active_language($language)) {
            return new WP_Error('wit_bad_language', sprintf('"%s" is not an active WPML language.', $language));
        }

        return WIT_Strings::listing($language, array(
            'domain'   => isset($input['domain']) ? (string) $input['domain'] : '',
            'status'   => isset($input['status']) ? (string) $input['status'] : 'pending',
            'search'   => isset($input['search']) ? (string) $input['search'] : '',
            'page'     => isset($input['page']) ? (int) $input['page'] : 1,
            'per_page' => min(100, isset($input['per_page']) ? (int) $input['per_page'] : 50),
        ));
    }

    public static function prepare_strings($input) {
        return WIT_Strings::prepare((string) $input['language'], array(
            'ids'    => isset($input['string_ids']) ? (array) $input['string_ids'] : array(),
            'domain' => isset($input['domain']) ? (string) $input['domain'] : '',
            'limit'  => isset($input['limit']) ? (int) $input['limit'] : 50,
        ));
    }

    public static function save_strings($input) {
        return WIT_Strings::save_from_chat((string) $input['language'], (array) $input['string_ids'], (array) $input['translations']);
    }

    public static function set_wpml_mode($input) {
        if (!WIT_WPML_Native::is_active()) {
            return new WP_Error('wit_no_wpml', 'WPML is not active.');
        }

        $mode = (string) $input['mode'];

        if (!empty($input['post_type'])) {
            $type = sanitize_key((string) $input['post_type']);
            if (!in_array($mode, array('translate', 'display_as_translated', 'do_not_translate'), true)) {
                return new WP_Error('wit_bad_mode', 'For a post type the mode is translate, display_as_translated or do_not_translate.');
            }
            if (!post_type_exists($type)) {
                return new WP_Error('wit_bad_type', sprintf('There is no post type "%s".', $type));
            }
            $before = WIT_WPML_Native::post_type_mode($type);
            if (!WIT_WPML_Native::set_post_type_mode($type, $mode)) {
                return new WP_Error('wit_failed', 'WPML did not accept the change.');
            }
            (new WIT_Translation_Manager())->log_event(0, '', sprintf(
                /* translators: 1: post type, 2: old mode, 3: new mode */
                __('WPML %4$s: tipo «%1$s» de %2$s a %3$s', 'wpml-imagina-translate'),
                $type, $before, $mode, self::from()
            ), self::via());
            return array('post_type' => $type, 'before' => $before, 'now' => WIT_WPML_Native::post_type_mode($type));
        }

        if (!empty($input['custom_field'])) {
            $modes = array('ignore' => 0, 'copy' => 1, 'translate' => 2, 'copy_once' => 3);
            if (!isset($modes[$mode])) {
                return new WP_Error('wit_bad_mode', 'For a custom field the mode is translate, copy, copy_once or ignore.');
            }
            $field  = (string) $input['custom_field'];
            $before = WIT_WPML_Native::field_mode($field);
            if (!WIT_WPML_Native::set_field_mode($field, $modes[$mode])) {
                return new WP_Error('wit_failed', 'WPML did not accept the change.');
            }
            $names = array_flip($modes);
            (new WIT_Translation_Manager())->log_event(0, '', sprintf(
                /* translators: 1: field, 2: old mode, 3: new mode */
                __('WPML %4$s: campo «%1$s» de %2$s a %3$s', 'wpml-imagina-translate'),
                $field, $before === null ? 'sin configurar' : $names[$before], $mode, self::from()
            ), self::via());
            return array('custom_field' => $field, 'before' => $before === null ? 'not set' : $names[$before], 'now' => $mode);
        }

        return new WP_Error('wit_nothing', 'Give a post_type or a custom_field.');
    }

    public static function get_glossary($input = array()) {
        $settings = WIT_Settings::instance()->get_settings();
        $rules    = array();

        foreach (preg_split('/\r\n|\r|\n/', (string) $settings['glossary']) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $rules[] = $line;
            }
        }

        return array(
            'rules'  => $rules,
            'format' => array(
                'Brand'                        => 'never translated, any language',
                'Original = Translation'       => 'fixed translation, every language',
                '[en] Original = Translation'  => 'English only',
                '[fr,de] Original = Translation' => 'several languages',
            ),
        );
    }

    public static function update_glossary($input) {
        $add    = isset($input['add']) ? array_map('trim', (array) $input['add']) : array();
        $remove = isset($input['remove']) ? array_map('trim', (array) $input['remove']) : array();

        if (empty($add) && empty($remove)) {
            return new WP_Error('wit_nothing', 'Nothing to add or remove.');
        }

        foreach ($add as $rule) {
            if (strpos($rule, "\n") !== false || $rule === '' || $rule[0] === '#') {
                return new WP_Error('wit_bad_rule', sprintf('Invalid rule: "%s". One rule per entry, without line breaks.', $rule));
            }
            if (empty(WIT_Glossary::parse($rule))) {
                return new WP_Error('wit_bad_rule', sprintf('Could not understand the rule "%s".', $rule));
            }
        }

        $settings = WIT_Settings::instance()->get_settings();
        $lines    = preg_split('/\r\n|\r|\n/', (string) $settings['glossary']);
        $lines    = array_values(array_filter(array_map('rtrim', $lines), function ($line) {
            return $line !== '';
        }));

        $removed = 0;
        if (!empty($remove)) {
            $before = count($lines);
            $lines  = array_values(array_filter($lines, function ($line) use ($remove) {
                return !in_array(trim($line), $remove, true);
            }));
            $removed = $before - count($lines);
        }

        $added = 0;
        foreach ($add as $rule) {
            if (!in_array($rule, array_map('trim', $lines), true)) {
                $lines[] = $rule;
                $added++;
            }
        }

        WIT_Settings::instance()->update_glossary(implode("\n", $lines));

        $result = self::get_glossary();
        $result['added']   = $added;
        $result['removed'] = $removed;

        if (!empty($remove) && $removed < count($remove)) {
            $result['note'] = 'Some rules to remove were not found; removal needs the exact rule text from get_glossary.';
        }

        return $result;
    }

    public static function history($input = array()) {
        $limit    = min(50, max(1, isset($input['limit']) ? (int) $input['limit'] : 20));
        $language = isset($input['language']) ? (string) $input['language'] : '';

        $logs  = (new WIT_Translation_Manager())->get_translation_logs($language !== '' ? 200 : $limit);
        $items = array();

        foreach ($logs as $log) {
            if ($language !== '' && $log['target_lang'] !== $language) {
                continue;
            }
            // Settings changes have no post; only those who can change
            // settings see them.
            if ((int) $log['post_id'] === 0 ? !current_user_can('manage_options') : !current_user_can('edit_post', (int) $log['post_id'])) {
                continue;
            }

            $items[] = array(
                'post_id'  => (int) $log['post_id'],
                'title'    => $log['post_title'],
                'from'     => $log['source_lang'],
                'to'       => $log['target_lang'],
                'via'      => $log['ai_provider'] === 'mcp' ? 'claude (mcp)' : ($log['ai_provider'] === 'panel' ? 'panel' : 'api (' . $log['ai_provider'] . ')'),
                'status'   => $log['status'],
                'message'  => $log['message'],
                'api_tokens' => isset($log['input_tokens']) ? (int) $log['input_tokens'] + (int) $log['output_tokens'] : 0,
                'date'     => $log['created_at'],
            );

            if (count($items) >= $limit) {
                break;
            }
        }

        return array('items' => $items);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * The original and its translation, when the user may edit both.
     *
     * @param int    $post_id
     * @param string $language
     * @return array{0:WP_Post,1:WP_Post}|WP_Error
     */
    private static function translation_of($post_id, $language) {
        $source = get_post($post_id);
        $wpml   = WIT_WPML_Integration::instance();

        if (!$source || !current_user_can('edit_post', $post_id)) {
            return new WP_Error('wit_not_found', sprintf('No post with ID %d that you can edit.', $post_id));
        }

        // The ID of a translation with the default language would resolve to
        // the ORIGINAL as "its translation", and correct or publish it.
        if (!$wpml->is_original($post_id)) {
            return new WP_Error('wit_not_source', sprintf('Post %d is itself a translation. Use the ID of the original (post_translation_status shows it).', $post_id));
        }

        if ($language === $wpml->get_default_language()) {
            return new WP_Error('wit_same_language', sprintf('"%s" is the language of the original, not a translation.', $language));
        }

        $translation_id = $wpml->get_translation_id($post_id, $language);

        if (!$translation_id) {
            return new WP_Error('wit_no_translation', sprintf('Post %d has no translation into "%s" yet.', $post_id, $language));
        }

        if (!current_user_can('edit_post', $translation_id)) {
            return new WP_Error('wit_forbidden', 'You do not have permission to edit that translation.');
        }

        return array($source, get_post($translation_id));
    }

    /**
     * What to tell the user before a large job.
     *
     * The user decides how to proceed: a big batch through the chat spends
     * subscription usage, and that is theirs to weigh, not the tool's.
     *
     * @param int $count
     * @return string
     */
    private static function volume_note($count) {
        return sprintf(
            'There are %d items pending. Translating them here uses the user\'s Claude subscription (the site\'s API key is not used). '
            . 'Tell the user this before starting, and let them decide: work in batches of up to %d posts per prepare_translation call, '
            . 'confirming between batches unless the user says otherwise.',
            $count,
            WIT_Translation_Plan::MAX_POSTS
        );
    }
}
