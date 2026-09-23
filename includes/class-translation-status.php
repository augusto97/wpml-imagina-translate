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
    }

    /**
     * Status of one source post in one language.
     *
     * @param int    $source_id
     * @param string $language
     * @return array{status:string,translation_id:int,translation_status:string,translated_at:string}
     */
    public static function of($source_id, $language) {
        $translation_id = WIT_WPML_Integration::instance()->get_translation_id($source_id, $language);

        if (!$translation_id) {
            return array(
                'status'             => self::MISSING,
                'translation_id'     => 0,
                'translation_status' => '',
                'translated_at'      => '',
            );
        }

        $stored = get_post_meta($translation_id, self::META_HASH, true);

        if ($stored === '') {
            $status = self::UNKNOWN;
        } else {
            $source = get_post($source_id);

            // Fast path: an untouched source has an unchanged modified date,
            // and hashing the full content of every post in a listing is not
            // free. A changed date still falls through to the hash, because
            // WordPress bumps it on saves that change nothing translatable.
            $status = ($source && $source->post_modified_gmt === get_post_meta($translation_id, self::META_MODIFIED, true))
                ? self::CURRENT
                : (hash_equals($stored, self::fingerprint($source_id)) ? self::CURRENT : self::OUTDATED);
        }

        return array(
            'status'             => $status,
            'translation_id'     => (int) $translation_id,
            'translation_status' => (string) get_post_status($translation_id),
            'translated_at'      => (string) get_post_meta($translation_id, self::META_AT, true),
        );
    }

    /**
     * Hash of every part of a post that gets translated.
     *
     * Deliberately excludes the modified date and anything the plugin does not
     * translate: changing a featured image or an author must not flag a
     * translation as outdated.
     *
     * @param int $post_id
     * @return string
     */
    public static function fingerprint($post_id) {
        $post = get_post($post_id);

        if (!$post) {
            return '';
        }

        $manager = new WIT_Translation_Manager();

        $parts = array(
            'title'     => $post->post_title,
            'content'   => $post->post_content,
            'excerpt'   => $post->post_excerpt,
            'elementor' => (string) get_post_meta($post_id, '_elementor_data', true),
            'meta'      => $manager->collect_meta_values($post_id),
        );

        return hash('sha256', wp_json_encode($parts));
    }
}
