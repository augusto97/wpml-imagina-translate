<?php
/**
 * Translation Status - whether a translation is missing, current or outdated.
 *
 * "Is this translated?" has three honest answers, not two. A translation made
 * before the source was edited exists, but no longer says what the source
 * says — and nothing in WordPress or the WPML hook surface records that.
 *
 * So when a translation is written, a fingerprint of everything that was
 * translated is stored on it. Comparing that fingerprint with the source's
 * current one tells the two apart.
 *
 * A translation carrying no fingerprint was made by hand, by WPML's own
 * editor, or by a version of this plugin that predates the fingerprint. It is
 * reported as "unknown" rather than guessed at.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translation_Status {

    const MISSING = 'missing';
    const CURRENT = 'current';
    const OUTDATED = 'outdated';
    const UNKNOWN = 'unknown';

    const META_HASH     = '_wit_source_hash';
    const META_MODIFIED = '_wit_source_modified';
    const META_AT       = '_wit_translated_at';

    /**
     * Fingerprint of the translation itself as it was written. A different
     * one later means someone edited the translation by hand, and translating
     * again would throw that work away.
     */
    const META_OWN_HASH = '_wit_translation_hash';

    /**
     * Record that $translation_id now reflects $source_id as it stands.
     *
     * @param int $source_id
     * @param int $translation_id
     */
    public static function mark($source_id, $translation_id) {
        $source = get_post($source_id);

        if (!$source || !$translation_id) {
            return;
        }

        update_post_meta($translation_id, self::META_HASH, self::fingerprint($source_id));
        update_post_meta($translation_id, self::META_MODIFIED, $source->post_modified_gmt);
        update_post_meta($translation_id, self::META_AT, current_time('mysql', true));
        self::accept_edits($translation_id);
    }

    /**
     * Record the translation's current text as the plugin's own.
     *
     * Called after writing it, and after a correction requested through the
     * plugin — that wording is also stored in the translation memory, so a
     * later re-translation reproduces it and loses nothing.
     *
     * @param int $translation_id
     */
    public static function accept_edits($translation_id) {
        clean_post_cache($translation_id);
        update_post_meta($translation_id, self::META_OWN_HASH, self::fingerprint($translation_id, true));
    }

    /**
     * Whether a translation was edited by hand after the plugin wrote it.
     *
     * Content-based, so opening the translation and pressing Update without
     * changing anything does not count. False for translations the plugin
     * did not write, which have nothing to compare with.
     *
     * @param int $translation_id
     * @return bool
     */
    public static function edited_by_hand($translation_id) {
        $own = get_post_meta($translation_id, self::META_OWN_HASH, true);

        return $own !== '' && !hash_equals($own, self::fingerprint($translation_id, true));
    }

    /**
     * Status of one source post in one language.
     *
     * @param int        $source_id
     * @param string     $language
     * @param array|null $cache     Optional, by reference: source fingerprints
     *                              already computed, reused across the
     *                              languages of one listing. Callers pass a
     *                              fresh array per listing, never one that
     *                              outlives a write.
     * @return array{status:string,translation_id:int,translation_status:string,translated_at:string}
     */
    public static function of($source_id, $language, &$cache = null) {
        $translation_id = WIT_WPML_Integration::instance()->get_translation_id($source_id, $language);

        if (!$translation_id) {
            return array(
                'status'             => self::MISSING,
                'translation_id'     => 0,
                'translation_status' => '',
                'translated_at'      => '',
                'edited'             => false,
            );
        }

        $stored = get_post_meta($translation_id, self::META_HASH, true);

        if ($stored === '') {
            $status = self::UNKNOWN;
        } else {
            // Always the hash. An earlier shortcut treated an unchanged
            // modified date as an unchanged source, but post_modified has
            // one-second resolution: an edit in the same second as the
            // translation — an import, a script, a quick fix — kept the same
            // date and the translation was reported current while stale.
            if (is_array($cache)) {
                if (!isset($cache[$source_id])) {
                    $cache[$source_id] = self::fingerprint($source_id);
                }
                $current = $cache[$source_id];
            } else {
                $current = self::fingerprint($source_id);
            }

            $status = hash_equals($stored, $current) ? self::CURRENT : self::OUTDATED;
        }

        return array(
            'status'             => $status,
            'translation_id'     => (int) $translation_id,
            'translation_status' => (string) get_post_status($translation_id),
            'translated_at'      => (string) get_post_meta($translation_id, self::META_AT, true),
            'edited'             => self::edited_by_hand($translation_id),
        );
    }

    /**
     * Original posts with their status in one language, filtered and paged.
     *
     * Shared by the dashboard and the MCP tools, so both count the same way.
     * Status cannot be expressed in SQL — it depends on fingerprints — so up
     * to $scan originals are inspected and the page is cut afterwards.
     *
     * @param string   $language
     * @param string[] $post_types
     * @param string   $filter   pending (missing or outdated), missing,
     *                           outdated, current, unknown, edited or all.
     * @param string   $search
     * @param int      $page     1-based.
     * @param int      $per_page
     * @param int      $scan
     * @return array{items:array[],total:int,page:int,pages:int,counts:array<string,int>,truncated:bool}
     */
    public static function listing($language, array $post_types, $filter = 'pending', $search = '', $page = 1, $per_page = 20, $scan = 1000) {
        $wpml   = WIT_WPML_Integration::instance();
        $posts  = $wpml->get_pending_translations($language, $post_types, false, $scan, $search);
        $prints = array();
        $counts = array('all' => 0, 'pending' => 0, 'missing' => 0, 'outdated' => 0, 'current' => 0, 'unknown' => 0, 'edited' => 0);
        $items  = array();

        foreach ($posts as $post) {
            $status = self::of($post['id'], $language, $prints);
            $state  = $status['status'];

            $counts['all']++;
            $counts[$state]++;
            if ($state === self::MISSING || $state === self::OUTDATED) {
                $counts['pending']++;
            }
            if ($status['edited']) {
                $counts['edited']++;
            }

            $wanted = $filter === 'all'
                || ($filter === 'pending' && ($state === self::MISSING || $state === self::OUTDATED))
                || ($filter === 'edited' && $status['edited'])
                || $filter === $state;

            if ($wanted) {
                $items[] = array_merge($post, array(
                    'status'               => $state,
                    'edited'               => $status['edited'],
                    'translation_id'       => $status['translation_id'],
                    'translation_status'   => $status['translation_status'],
                    'translated_at'        => $status['translated_at'],
                    'translation_edit_url' => $status['translation_id'] ? get_edit_post_link($status['translation_id'], 'raw') : '',
                ));
            }
        }

        $per_page = max(1, (int) $per_page);
        $total    = count($items);
        $pages    = (int) max(1, ceil($total / $per_page));
        $page     = min(max(1, (int) $page), $pages);

        return array(
            'items'     => array_slice($items, ($page - 1) * $per_page, $per_page),
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'counts'    => $counts,
            'truncated' => count($posts) >= $scan,
        );
    }

    /**
     * Hash of every part of a post that gets translated.
     *
     * Deliberately excludes the modified date and anything the plugin does not
     * translate: changing a featured image or an author must not flag a
     * translation as outdated.
     *
     * @param int  $post_id
     * @param bool $canonical Normalise Elementor JSON first. Used for the
     *                        translation's own fingerprint; the source's keeps
     *                        the raw form so fingerprints stored by earlier
     *                        versions still match.
     * @return string
     */
    public static function fingerprint($post_id, $canonical = false) {
        $post = get_post($post_id);

        if (!$post) {
            return '';
        }

        $manager = new WIT_Translation_Manager();

        $parts = array(
            'title'     => $post->post_title,
            'content'   => $post->post_content,
            'excerpt'   => $post->post_excerpt,
            'elementor' => $canonical
                ? self::canonical_json((string) get_post_meta($post_id, '_elementor_data', true))
                : (string) get_post_meta($post_id, '_elementor_data', true),
            'meta'      => $manager->collect_meta_values($post_id),
        );

        return hash('sha256', wp_json_encode($parts));
    }

    /**
     * Elementor JSON in one canonical form, so re-encoding the same data with
     * different escaping — as Elementor does when it re-saves a document —
     * does not read as an edit.
     *
     * @param string $json
     * @return string
     */
    private static function canonical_json($json) {
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? (string) wp_json_encode($decoded) : $json;
    }
}
