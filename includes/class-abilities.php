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
                'description' => 'List original posts with their translation status for one language. Filter by status: "pending" (missing or outdated — what needs work), "missing", "outdated", "current", "unknown" or "all". Paginated.',
                'input'       => array(
                    'type'       => 'object',
                    'required'   => array('language'),
                    'properties' => array(
                        'language'   => $language,
                        'status'     => array(
                            'type'    => 'string',
                            'enum'    => array('pending', 'missing', 'outdated', 'current', 'unknown', 'all'),
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
                'description' => 'Save translations you produced for the strings from prepare_translation. "translations" maps each string id to its translation. A post is written only when all of its strings are present; otherwise the ones received are kept and the missing ones are listed, so send only those. Creates the translation as a draft, or updates the existing one, and links it in WPML.',
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
                    ),
                ),
                'execute'     => array(__CLASS__, 'save'),
                'permission'  => 'edit_posts',
                'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
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

        foreach ($wpml->get_active_languages() as $language) {
            if ($language['code'] === $default) {
                continue;
            }

            $posts  = $wpml->get_pending_translations($language['code'], $types, false, self::SCAN_LIMIT);
            $counts = array('current' => 0, 'outdated' => 0, 'missing' => 0, 'unknown' => 0);
            $drafts = 0;

            if (count($posts) >= self::SCAN_LIMIT) {
                $truncated = true;
            }

            foreach ($posts as $post) {
                $status = WIT_Translation_Status::of($post['id'], $language['code']);
                $counts[$status['status']]++;
                if ($status['translation_status'] === 'draft') {
                    $drafts++;
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

        $filter   = isset($input['status']) ? $input['status'] : 'pending';
        $types    = self::post_types(isset($input['post_types']) ? $input['post_types'] : array());
        $search   = isset($input['search']) ? (string) $input['search'] : '';
        $page     = max(1, isset($input['page']) ? (int) $input['page'] : 1);
        $per_page = min(self::MAX_PER_PAGE, max(1, isset($input['per_page']) ? (int) $input['per_page'] : 20));

        $matched = array();

        foreach ($wpml->get_pending_translations($language, $types, false, self::SCAN_LIMIT, $search) as $post) {
            $status = WIT_Translation_Status::of($post['id'], $language);

            $wanted = ($filter === 'all')
                || ($filter === 'pending' && in_array($status['status'], array('missing', 'outdated'), true))
                || ($filter === $status['status']);

            if (!$wanted) {
                continue;
            }

            $matched[] = array(
                'post_id'              => $post['id'],
                'title'                => $post['title'],
                'type'                 => $post['type'],
                'status'               => $status['status'],
                'translation_id'       => $status['translation_id'],
                'translation_status'   => $status['translation_status'],
                'translated_at'        => $status['translated_at'],
                'edit_url'             => $post['edit_url'],
                'translation_edit_url' => $status['translation_id'] ? get_edit_post_link($status['translation_id'], 'raw') : '',
            );
        }

        $total  = count($matched);
        $result = array(
            'language' => $language,
            'status'   => $filter,
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int) max(1, ceil($total / $per_page)),
            'items'    => array_slice($matched, ($page - 1) * $per_page, $per_page),
        );

        if (in_array($filter, array('pending', 'missing', 'outdated'), true) && $total > WIT_Translation_Plan::MAX_POSTS) {
            $result['note'] = self::volume_note($total);
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

        foreach ($wpml->get_active_languages() as $language) {
            if ($language['code'] === $default) {
                continue;
            }

            $status      = WIT_Translation_Status::of($post_id, $language['code']);
            $languages[] = array(
                'language'             => $language['code'],
                'name'                 => $language['name'],
                'status'               => $status['status'],
                'translation_id'       => $status['translation_id'],
                'translation_status'   => $status['translation_status'],
                'translated_at'        => $status['translated_at'],
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
            (array) $input['translations']
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
        }

        return array(
            'translation_id' => $translation->ID,
            'post_status'    => get_post_status($translation->ID),
            'url'            => get_permalink($translation->ID),
        );
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
            if (!current_user_can('edit_post', (int) $log['post_id'])) {
                continue;
            }

            $items[] = array(
                'post_id'  => (int) $log['post_id'],
                'title'    => $log['post_title'],
                'from'     => $log['source_lang'],
                'to'       => $log['target_lang'],
                'via'      => $log['ai_provider'] === 'mcp' ? 'claude (mcp)' : 'api (' . $log['ai_provider'] . ')',
                'status'   => $log['status'],
                'message'  => $log['message'],
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

        if (!$source || !current_user_can('edit_post', $post_id)) {
            return new WP_Error('wit_not_found', sprintf('No post with ID %d that you can edit.', $post_id));
        }

        $translation_id = WIT_WPML_Integration::instance()->get_translation_id($post_id, $language);

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
