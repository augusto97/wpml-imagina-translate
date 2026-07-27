<?php
/**
 * Translation Memory - reuses previously translated strings.
 *
 * Solves two separate problems at once:
 *
 *  Cost.  Re-translating a page after fixing a typo used to re-send and re-pay
 *         for every string on it. Only genuinely new strings now reach the API.
 *
 *  Consistency.  The same string appearing on twenty pages used to be
 *         translated twenty separate times, and a model is free to return a
 *         different wording each time. "Leer más" would come out as "Read
 *         more", "Read More" and "See more" across one site. A memory hit
 *         returns the wording already used.
 *
 * Entries are keyed on the source text plus the language pair. Provider and
 * model are recorded but deliberately excluded from the key, so switching
 * models does not invalidate the whole memory or reintroduce inconsistency.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translation_Memory {

    /** Rows fetched per query when looking up a large batch. */
    const LOOKUP_CHUNK = 200;

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Fully-qualified table name.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wit_translation_memory';
    }

    /**
     * Whether the memory is switched on in the settings.
     *
     * @return bool
     */
    public function is_enabled() {
        $settings = WIT_Settings::instance()->get_settings();

        return !empty($settings['enable_translation_memory']);
    }

    /**
     * Key for one source string in one language pair.
     *
     * @param string $text
     * @param string $source_lang
     * @param string $target_lang
     * @return string 64-character hex digest.
     */
    private function hash($text, $source_lang, $target_lang) {
        return hash(
            'sha256',
            strtolower($source_lang) . "\x1f" . strtolower($target_lang) . "\x1f" . $text
        );
    }

    /**
     * Look up many strings at once.
     *
     * @param string[] $texts
     * @param string   $source_lang
     * @param string   $target_lang
     * @return array Map of source text => stored translation, for hits only.
     */
    public function get_many(array $texts, $source_lang, $target_lang) {
        if (empty($texts) || !$this->is_enabled()) {
            return array();
        }

        global $wpdb;

        // Map hash back to the original string so the caller gets its own keys.
        $by_hash = array();
        foreach ($texts as $text) {
            $by_hash[$this->hash($text, $source_lang, $target_lang)] = $text;
        }

        $table = self::table();
        $found = array();
        $hits  = array();

        foreach (array_chunk(array_keys($by_hash), self::LOOKUP_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));

            // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders are generated, values are bound.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT text_hash, translation FROM {$table} WHERE text_hash IN ({$placeholders})",
                    $chunk
                ),
                ARRAY_A
            );

            if (!$rows) {
                continue;
            }

            foreach ($rows as $row) {
                if (!isset($by_hash[$row['text_hash']])) {
                    continue;
                }
                $found[$by_hash[$row['text_hash']]] = $row['translation'];
                $hits[] = $row['text_hash'];
            }
        }

        $this->bump_hits($hits);

        return $found;
    }

    /**
     * Store translations, overwriting any existing entry for the same key.
     *
     * @param array  $pairs       Map of source text => translation.
     * @param string $source_lang
     * @param string $target_lang
     * @param string $provider
     * @param string $model
     * @return int Number of rows written.
     */
    public function store_many(array $pairs, $source_lang, $target_lang, $provider = '', $model = '') {
        if (empty($pairs) || !$this->is_enabled()) {
            return 0;
        }

        global $wpdb;

        $table   = self::table();
        $now     = current_time('mysql');
        $written = 0;

        foreach ($pairs as $text => $translation) {
            $text        = (string) $text;
            $translation = (string) $translation;

            if ($text === '' || $translation === '') {
                continue;
            }

            // REPLACE rather than INSERT ... ON DUPLICATE KEY so a re-translation
            // with a better model supersedes the older wording.
            $result = $wpdb->replace(
                $table,
                array(
                    'text_hash'   => $this->hash($text, $source_lang, $target_lang),
                    'source_lang' => $source_lang,
                    'target_lang' => $target_lang,
                    'source_text' => $text,
                    'translation' => $translation,
                    'provider'    => $provider,
                    'model'       => $model,
                    'hits'        => 0,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            if ($result) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Record that entries were reused, for the dashboard counter.
     *
     * @param string[] $hashes
     */
    private function bump_hits(array $hashes) {
        if (empty($hashes)) {
            return;
        }

        global $wpdb;

        $table        = self::table();
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders are generated, values are bound.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET hits = hits + 1 WHERE text_hash IN ({$placeholders})",
                $hashes
            )
        );
    }

    /**
     * Delete stored entries.
     *
     * @param string $target_lang Limit to one target language, or '' for all.
     * @return int Rows deleted.
     */
    public function clear($target_lang = '') {
        global $wpdb;

        $table = self::table();

        if ($target_lang !== '') {
            return (int) $wpdb->query(
                $wpdb->prepare("DELETE FROM {$table} WHERE target_lang = %s", $target_lang)
            );
        }

        return (int) $wpdb->query("DELETE FROM {$table}");
    }

    /**
     * Summary figures for the dashboard.
     *
     * @return array{entries:int,reuses:int,languages:array}
     */
    public function stats() {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array('entries' => 0, 'reuses' => 0, 'languages' => array());
        }

        return array(
            'entries'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'reuses'    => (int) $wpdb->get_var("SELECT COALESCE(SUM(hits), 0) FROM {$table}"),
            'languages' => (array) $wpdb->get_results(
                "SELECT target_lang, COUNT(*) AS total FROM {$table} GROUP BY target_lang ORDER BY total DESC",
                ARRAY_A
            ),
        );
    }

    /**
     * CREATE TABLE statement, used on activation and upgrade.
     *
     * @return string
     */
    public static function schema() {
        global $wpdb;

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        // text_hash is the unique key; source_text is stored only so the memory
        // can be inspected and exported.
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            text_hash char(64) NOT NULL,
            source_lang varchar(20) NOT NULL DEFAULT '',
            target_lang varchar(20) NOT NULL DEFAULT '',
            source_text longtext NOT NULL,
            translation longtext NOT NULL,
            provider varchar(32) NOT NULL DEFAULT '',
            model varchar(100) NOT NULL DEFAULT '',
            hits bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY text_hash (text_hash),
            KEY lang_pair (source_lang, target_lang)
        ) {$collate};";
    }
}
