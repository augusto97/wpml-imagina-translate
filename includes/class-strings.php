<?php
/**
 * Strings - translating WPML String Translation's strings.
 *
 * Theme and plugin texts, widget titles, admin texts declared in
 * wpml-config.xml, the site title and tagline: everything WPML String
 * Translation holds that is not a post. On a typical site this is what a
 * visitor still sees in the source language after every page is translated —
 * buttons, footers, form labels, "Read more".
 *
 * Read from ST's tables, written through ST's own API, so ST does what it
 * does after a translation entered in its own screen: updates the string's
 * status and regenerates the .mo files it serves gettext from.
 *
 * Which strings
 * -------------
 * - Each string is translated from ITS language. Themes and plugins are in
 *   English, so on a Spanish site their strings need a Spanish translation
 *   too: the default language is a target like any other.
 * - Strings of page-builder packages (Elementor, Gutenberg) are left out: they
 *   are translated with their post.
 * - Strings already translated by a .mo file the theme or plugin ships are not
 *   pending: paying to translate them again would be waste.
 *
 * Safety
 * ------
 * Strings with markup are translated text node by text node, like content, so
 * attributes and tags cannot change. And a translation must keep exactly the
 * placeholders of its original — %s, %1$d, {name}, [shortcode] — or it is
 * discarded: gettext strings go through sprintf(), and on PHP 8 a missing %s
 * is a fatal error on the page that prints it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Strings {

    /** ICL_TM_COMPLETE / ICL_TM_NEEDS_UPDATE, without depending on WPML's constants. */
    const COMPLETE     = 10;
    const NEEDS_UPDATE = 3;

    /** Strings per API translation call from the dashboard. */
    const BATCH = 50;

    /** Strings per prepare_strings call over MCP. */
    const MCP_MAX = 100;

    // -----------------------------------------------------------------------
    // Availability
    // -----------------------------------------------------------------------

    /**
     * @return bool
     */
    public static function is_available() {
        return defined('WPML_ST_VERSION') && function_exists('icl_add_string_translation');
    }

    /**
     * Whether ST can write the translation files it serves strings from.
     *
     * ST writes to wp-content/languages/wpml and, when it cannot, gives up
     * without a word: translations are saved and never appear.
     *
     * @return bool
     */
    public static function files_writable() {
        $dir = WP_LANG_DIR . '/wpml';

        if (is_dir($dir)) {
            return wp_is_writable($dir);
        }

        return is_dir(WP_LANG_DIR) ? wp_is_writable(WP_LANG_DIR) : wp_is_writable(dirname(WP_LANG_DIR));
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    private static function tables() {
        global $wpdb;
        return array($wpdb->prefix . 'icl_strings', $wpdb->prefix . 'icl_string_translations');
    }

    /**
     * SQL fragments for one target language and one status.
     *
     * @param string $language
     * @param string $status pending, needs_update, translated or all
     * @return string WHERE clause (values escaped through prepare by callers)
     */
    private static function status_where($status) {
        $translated = "(t.status = " . self::COMPLETE . " AND t.value IS NOT NULL AND t.value <> '')";
        $by_file    = "(t.mo_string IS NOT NULL AND t.mo_string <> '')";

        switch ($status) {
            case 'translated':
                return " AND ({$translated} OR {$by_file})";
            case 'needs_update':
                return " AND t.status = " . self::NEEDS_UPDATE;
            case 'all':
                return '';
            case 'pending':
            default:
                return " AND NOT ({$translated} OR {$by_file})";
        }
    }

    private static function base_where() {
        return "(s.string_package_id IS NULL OR s.string_package_id = 0) AND s.value <> ''";
    }

    /**
     * Per language: strings that can be translated into it, and how many are
     * still pending. Plus the domains with the most pending strings.
     *
     * @return array
     */
    public static function overview() {
        global $wpdb;

        if (!self::is_available()) {
            return array('available' => false);
        }

        list($strings, $translations) = self::tables();
        $out = array();

        foreach (WIT_WPML_Integration::instance()->get_active_languages() as $language) {
            $code = $language['code'];

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN (t.status = %d AND t.value IS NOT NULL AND t.value <> '') OR (t.mo_string IS NOT NULL AND t.mo_string <> '') THEN 1 ELSE 0 END) AS translated,
                        SUM(CASE WHEN t.status = %d THEN 1 ELSE 0 END) AS needs_update
                 FROM {$strings} s
                 LEFT JOIN {$translations} t ON t.string_id = s.id AND t.language = %s
                 WHERE " . self::base_where() . " AND s.language <> %s",
                self::COMPLETE,
                self::NEEDS_UPDATE,
                $code,
                $code
            ), ARRAY_A);

            $total = (int) $row['total'];

            $domains = $wpdb->get_results($wpdb->prepare(
                "SELECT s.context AS domain, COUNT(*) AS pending
                 FROM {$strings} s
                 LEFT JOIN {$translations} t ON t.string_id = s.id AND t.language = %s
                 WHERE " . self::base_where() . " AND s.language <> %s" . self::status_where('pending') . "
                 GROUP BY s.context ORDER BY pending DESC LIMIT 15",
                $code,
                $code
            ), ARRAY_A);

            $out[] = array(
                'code'         => $code,
                'name'         => $language['name'],
                'total'        => $total,
                'translated'   => (int) $row['translated'],
                'pending'      => $total - (int) $row['translated'],
                'needs_update' => (int) $row['needs_update'],
                'top_domains'  => array_map(function ($d) {
                    return array('domain' => $d['domain'], 'pending' => (int) $d['pending']);
                }, $domains),
            );
        }

        return array('available' => true, 'languages' => $out, 'files_writable' => self::files_writable());
    }

    /**
     * Domains (ST "contexts") with string counts for one language.
     *
     * @param string $language
     * @return array[] {domain, total, pending}
     */
    public static function domains($language) {
        global $wpdb;

        if (!self::is_available()) {
            return array();
        }

        list($strings, $translations) = self::tables();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.context AS domain, COUNT(*) AS total,
                    SUM(CASE WHEN NOT ((t.status = %d AND t.value IS NOT NULL AND t.value <> '') OR (t.mo_string IS NOT NULL AND t.mo_string <> '')) OR t.id IS NULL THEN 1 ELSE 0 END) AS pending
             FROM {$strings} s
             LEFT JOIN {$translations} t ON t.string_id = s.id AND t.language = %s
             WHERE " . self::base_where() . " AND s.language <> %s
             GROUP BY s.context ORDER BY s.context",
            self::COMPLETE,
            $language,
            $language
        ), ARRAY_A);

        return array_map(function ($r) {
            return array('domain' => $r['domain'], 'total' => (int) $r['total'], 'pending' => (int) $r['pending']);
        }, (array) $rows);
    }

    /**
     * Strings for one language, filtered and paged.
     *
     * @param string $language
     * @param array  $args {domain, status, search, page, per_page, ids}
     * @return array{items:array[],total:int,page:int,pages:int}
     */
    public static function listing($language, array $args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'domain'   => '',
            'status'   => 'pending',
            'search'   => '',
            'page'     => 1,
            'per_page' => 50,
            'ids'      => array(),
        ));

        if (!self::is_available()) {
            return array('items' => array(), 'total' => 0, 'page' => 1, 'pages' => 1);
        }

        list($strings, $translations) = self::tables();

        $where  = self::base_where() . ' AND s.language <> %s' . self::status_where($args['status']);
        $params = array($language, $language);

        if ($args['domain'] !== '') {
            $where   .= ' AND s.context = %s';
            $params[] = (string) $args['domain'];
        }
        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where   .= ' AND (s.value LIKE %s OR t.value LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($args['ids'])) {
            $ids    = array_values(array_filter(array_map('absint', (array) $args['ids'])));
            $where .= ' AND s.id IN (' . ($ids ? implode(',', $ids) : '0') . ')';
        }

        $from = "FROM {$strings} s LEFT JOIN {$translations} t ON t.string_id = s.id AND t.language = %s WHERE {$where}";

        // phpcs:ignore WordPress.DB.PreparedSQL -- fragments are literals, values bound.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$from}", $params));

        $per_page = max(1, min(500, (int) $args['per_page']));
        $pages    = (int) max(1, ceil($total / $per_page));
        $page     = min(max(1, (int) $args['page']), $pages);

        // phpcs:ignore WordPress.DB.PreparedSQL -- fragments are literals, values bound.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.language, s.context, s.name, s.value, s.gettext_context,
                    t.status AS t_status, t.value AS t_value, t.mo_string
             {$from} ORDER BY s.context, s.id LIMIT %d OFFSET %d",
            array_merge($params, array($per_page, ($page - 1) * $per_page))
        ), ARRAY_A);

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = self::item($row);
        }

        return array('items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    private static function item(array $row) {
        $translated = ((int) $row['t_status'] === self::COMPLETE && (string) $row['t_value'] !== '');
        $by_file    = (string) $row['mo_string'] !== '';

        return array(
            'id'              => (int) $row['id'],
            'domain'          => (string) $row['context'],
            'name'            => (string) $row['name'],
            'source_language' => (string) $row['language'],
            'text'            => (string) $row['value'],
            'translation'     => $translated ? (string) $row['t_value'] : ($by_file ? (string) $row['mo_string'] : ''),
            'status'          => $translated || $by_file
                ? 'translated'
                : ((int) $row['t_status'] === self::NEEDS_UPDATE ? 'needs_update' : 'pending'),
            'from_file'       => !$translated && $by_file,
        );
    }

    // -----------------------------------------------------------------------
    // Translating
    // -----------------------------------------------------------------------

    /**
     * The pieces of a string that get translated: the whole string when it
     * is plain text, its text nodes when it has markup.
     *
     * @param string $value
     * @return string[]
     */
    public static function units($value) {
        $value = (string) $value;

        if (strpos($value, '<') === false) {
            return self::has_words($value) ? array($value) : array();
        }

        $originals = array();
        WIT_HTML_Translator::collect($value, $originals);

        return array_values(array_filter(array_keys($originals), array(__CLASS__, 'has_words')));
    }

    /**
     * Whether a piece has anything to translate once its placeholders are
     * set aside: "%s" alone inside a <span> is not text.
     *
     * @param string $text
     * @return bool
     */
    public static function has_words($text) {
        $bare = preg_replace('/%%|%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]|\{\{?\s*[A-Za-z0-9_.\-]+\s*\}?\}/', '', (string) $text);

        // A word is two letters in a row (or one ideograph/kana/hangul).
        // Loose letters are not: "F j, Y" and "g:i a" are PHP date formats
        // that WPML lists as admin texts, and a model "translating" them
        // into "j F, A" breaks every date on the site.
        $words = preg_match('/\p{L}{2,}|[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', (string) $bare);

        return (bool) $words && WIT_HTML_Translator::is_translatable(trim((string) $text));
    }

    /**
     * Rebuild a string from its units' translations.
     *
     * @param string               $value
     * @param array<string,string> $map unit => translation
     * @return string|null Null when a unit is missing.
     */
    public static function rebuild($value, array $map) {
        $value = (string) $value;

        if (strpos($value, '<') === false) {
            // Nothing to translate in it ("%s", "F j, Y"): its translation is
            // itself. Without this it stayed pending forever and every
            // "translate all" reported it as failed.
            if (!self::has_words($value)) {
                return $value;
            }
            return isset($map[$value]) && trim($map[$value]) !== '' ? (string) $map[$value] : null;
        }

        foreach (self::units($value) as $unit) {
            if (!isset($map[$unit])) {
                return null;
            }
        }

        $count = 0;

        return WIT_HTML_Translator::apply($value, $map, $count);
    }

    /**
     * Placeholders a translation must keep: printf conversions, {named}
     * tokens and [shortcodes], as a sorted list.
     *
     * @param string $text
     * @return string[]
     */
    public static function placeholders($text) {
        preg_match_all(
            '/%%|%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]|\{\{?\s*[A-Za-z0-9_.\-]+\s*\}?\}/',
            (string) $text,
            $matches
        );

        $found = $matches[0];

        // Shortcodes only when they are real ones: "[Required]" or "[beta]"
        // are words in brackets, and a translator rightly translates them.
        preg_match_all('/\[\/?([A-Za-z0-9_\-]+)[^\]]*\]/', (string) $text, $codes, PREG_SET_ORDER);
        foreach ($codes as $code) {
            if (function_exists('shortcode_exists') && shortcode_exists($code[1])) {
                $found[] = $code[0];
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Translate strings through the configured API (or, inside
     * WIT_Translator_Engine::with_external_translations(), through the map a
     * chat supplied) and save the results to String Translation.
     *
     * @param int[]  $ids
     * @param string $language
     * @return array{saved:int,errors:array[],results:array[]}
     */
    public static function translate($ids, $language) {
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));

        $result = array('saved' => 0, 'errors' => array(), 'results' => array());

        if (!self::is_available() || empty($ids)) {
            return $result;
        }

        if (!WIT_WPML_Integration::instance()->is_active_language($language)) {
            $result['errors'][] = array('id' => 0, 'error' => sprintf('"%s" no es un idioma activo.', $language));
            return $result;
        }

        $list = self::listing($language, array('status' => 'all', 'ids' => $ids, 'per_page' => count($ids)));

        // Group by source language: one batch per language pair.
        $by_source = array();
        foreach ($list['items'] as $item) {
            $by_source[$item['source_language']][] = $item;
        }

        $previous = WIT_Translator_Engine::begin_job_usage();

        try {
            foreach ($by_source as $source => $items) {
                $units = array();
                foreach ($items as $item) {
                    foreach (self::units($item['text']) as $unit) {
                        $units[$unit] = true;
                    }
                }

                $texts   = array_keys($units);
                $engine  = new WIT_Translator_Engine();
                $answers = $texts ? $engine->translate_batch($texts, $language, $source) : array();

                $map = array();
                foreach ($texts as $i => $text) {
                    if (isset($answers[$i]) && empty($answers[$i]['error']) && trim((string) $answers[$i]['translation']) !== '') {
                        $map[$text] = $answers[$i]['translation'];
                    }
                }

                foreach ($items as $item) {
                    $outcome = self::save_one($item, $language, $map);
                    $result['results'][] = $outcome;
                    if ($outcome['status'] === 'saved') {
                        $result['saved']++;
                    } else {
                        $result['errors'][] = array('id' => $item['id'], 'error' => $outcome['message']);
                    }
                }
            }
        } finally {
            $usage = WIT_Translator_Engine::end_job_usage($previous);
        }

        if ($result['saved'] > 0 || !empty($result['errors'])) {
            (new WIT_Translation_Manager())->log_strings($language, $result['saved'], count($result['errors']), $usage);
        }

        $result['usage'] = $usage;

        return $result;
    }

    /**
     * Rebuild, check and store one string's translation.
     *
     * @param array                $item
     * @param string               $language
     * @param array<string,string> $map
     * @return array{id:int,status:string,message:string}
     */
    private static function save_one(array $item, $language, array $map) {
        $translation = self::rebuild($item['text'], $map);

        if ($translation === null) {
            return array('id' => $item['id'], 'status' => 'error', 'message' => __('No llegó la traducción de esta cadena.', 'wpml-imagina-translate'));
        }

        if (self::placeholders($translation) !== self::placeholders($item['text'])) {
            return array(
                'id'      => $item['id'],
                'status'  => 'error',
                'message' => sprintf(
                    /* translators: 1: placeholders of the original, 2: of the translation */
                    __('Los marcadores no coinciden (original: %1$s; traducción: %2$s). Se descarta: una cadena así puede romper la página que la imprime.', 'wpml-imagina-translate'),
                    implode(' ', self::placeholders($item['text'])) ?: '—',
                    implode(' ', self::placeholders($translation)) ?: '—'
                ),
            );
        }

        if (!self::store($item['id'], $language, $translation)) {
            return array('id' => $item['id'], 'status' => 'error', 'message' => __('String Translation no aceptó la traducción.', 'wpml-imagina-translate'));
        }

        return array('id' => $item['id'], 'status' => 'saved', 'message' => '', 'translation' => $translation);
    }

    /**
     * A translation typed by hand in the panel: same placeholder check as an
     * AI one, since a broken %s breaks the page just the same.
     *
     * @param int    $string_id
     * @param string $language
     * @param string $value
     * @return array{id:int,translation:string}|WP_Error
     */
    public static function save_manual($string_id, $language, $value) {
        if (!self::is_available()) {
            return new WP_Error('wit_no_st', __('WPML String Translation no está activo.', 'wpml-imagina-translate'));
        }

        $value = trim((string) $value);
        if ($value === '') {
            return new WP_Error('wit_empty', __('Escribe la traducción.', 'wpml-imagina-translate'));
        }

        $list = self::listing($language, array('status' => 'all', 'ids' => array((int) $string_id), 'per_page' => 1));
        if (empty($list['items'])) {
            return new WP_Error('wit_not_found', __('La cadena no existe o no se traduce a ese idioma.', 'wpml-imagina-translate'));
        }

        $item = $list['items'][0];
        if (self::placeholders($value) !== self::placeholders($item['text'])) {
            return new WP_Error('wit_placeholders', sprintf(
                /* translators: %s: placeholders of the original */
                __('La traducción debe conservar los marcadores del original, tal cual: %s', 'wpml-imagina-translate'),
                implode(' ', self::placeholders($item['text'])) ?: '—'
            ));
        }

        if (!self::store($item['id'], $language, $value)) {
            return new WP_Error('wit_store', __('String Translation no aceptó la traducción.', 'wpml-imagina-translate'));
        }

        return array('id' => $item['id'], 'translation' => $value);
    }

    /**
     * Write one translation through String Translation.
     *
     * @param int    $string_id
     * @param string $language
     * @param string $value
     * @return bool
     */
    public static function store($string_id, $language, $value) {
        if (function_exists('wpml_st_set_trusted_string_translation')) {
            // ST 5.2: also checks the language is active.
            $id = wpml_st_set_trusted_string_translation((int) $string_id, (string) $language, (string) $value, self::COMPLETE);
        } else {
            $id = icl_add_string_translation((int) $string_id, (string) $language, (string) $value, self::COMPLETE);
        }

        return (bool) $id;
    }

    // -----------------------------------------------------------------------
    // MCP: the chat translates, the plugin applies
    // -----------------------------------------------------------------------

    /**
     * What the chat has to translate for some strings.
     *
     * @param string $language
     * @param array  $args {ids, domain, status, limit}
     * @return array|WP_Error
     */
    public static function prepare($language, array $args) {
        if (!self::is_available()) {
            return new WP_Error('wit_no_st', __('WPML String Translation no está activo.', 'wpml-imagina-translate'));
        }
        if (!WIT_WPML_Integration::instance()->is_active_language($language)) {
            return new WP_Error('wit_bad_language', sprintf('"%s" is not an active WPML language.', $language));
        }

        $limit = min(self::MCP_MAX, max(1, isset($args['limit']) ? (int) $args['limit'] : 50));
        $list  = self::listing($language, array(
            'ids'      => isset($args['ids']) ? (array) $args['ids'] : array(),
            'domain'   => isset($args['domain']) ? (string) $args['domain'] : '',
            'status'   => !empty($args['ids']) ? 'all' : (isset($args['status']) ? (string) $args['status'] : 'pending'),
            'per_page' => $limit,
        ));

        $engine = new WIT_Translator_Engine();
        $units  = array();   // unit id => {id, text}
        $out    = array();

        foreach ($list['items'] as $item) {
            $pending = $engine->unresolved(self::units($item['text']), $language, $item['source_language']);
            $ids     = array();
            foreach ($pending as $unit) {
                $uid         = WIT_Translation_Plan::string_id($unit);
                $units[$uid] = array('id' => $uid, 'text' => $unit);
                $ids[]       = $uid;
            }
            $out[] = array(
                'string_id'       => $item['id'],
                'domain'          => $item['domain'],
                'source_language' => $item['source_language'],
                'placeholders'    => self::placeholders($item['text']),
                'unit_ids'        => $ids,
            );
        }

        return array(
            'target_language'      => $language,
            'target_language_name' => WIT_Translation_Plan::language_name($language),
            'instructions'         => sprintf(
                'Translate the "text" of every unit into %s and call save_strings with "translations" mapping each unit "id" to its translation. '
                . 'These are interface texts of the site (buttons, labels, footers): keep them short and natural. '
                . 'Keep every placeholder exactly as it is — %%s, %%1$s, %%d, {name}, [shortcode] — the same ones, the same number of times: '
                . 'a translation that changes them is rejected, because it can break the page. Plain text only.',
                WIT_Translation_Plan::language_name($language)
            ),
            'units'                => array_values($units),
            'strings'              => $out,
            'total_matching'       => $list['total'],
        );
    }

    /**
     * Apply translations the chat produced for some strings.
     *
     * @param string               $language
     * @param int[]                $string_ids
     * @param array<string,string> $translations unit id => translation
     * @return array|WP_Error
     */
    public static function save_from_chat($language, array $string_ids, array $translations) {
        if (!self::is_available()) {
            return new WP_Error('wit_no_st', __('WPML String Translation no está activo.', 'wpml-imagina-translate'));
        }

        $string_ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $string_ids)))), 0, self::MCP_MAX);
        $list       = self::listing($language, array('status' => 'all', 'ids' => $string_ids, 'per_page' => max(1, count($string_ids))));

        // unit text => translation, through the unit ids the chat answered.
        $map = array();
        foreach ($list['items'] as $item) {
            foreach (self::units($item['text']) as $unit) {
                $uid = WIT_Translation_Plan::string_id($unit);
                if (isset($translations[$uid]) && is_string($translations[$uid]) && trim($translations[$uid]) !== '') {
                    $map[$unit] = $translations[$uid];
                }
            }
        }

        $result = WIT_Translator_Engine::with_external_translations($map, function () use ($string_ids, $language) {
            return self::translate($string_ids, $language);
        });

        return array(
            'saved'  => $result['saved'],
            'errors' => $result['errors'],
        );
    }
}
