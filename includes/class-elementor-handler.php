<?php
/**
 * Elementor Handler - Translates Elementor page-builder content.
 *
 * Elementor stores its editable data in the post meta field `_elementor_data`
 * as a JSON array.  WordPress's `post_content` is effectively ignored by
 * Elementor's front-end renderer (it renders directly from the JSON), so
 * this handler focuses exclusively on the JSON structure.
 *
 * Strategy
 * --------
 * 1. Decode `_elementor_data` from the source post.
 * 2. Walk every element's `settings` object recursively (including nested
 *    repeater fields such as icon_list, tabs, slides, accordion items…).
 * 3. Collect ALL unique translatable strings:
 *    - Plain text values  → added directly to the translation batch.
 *    - HTML values        → text nodes extracted via DOMDocument and added
 *                           to the same batch.
 * 4. Send ONE batch API call for all collected strings.
 * 5. Apply translations back to the JSON:
 *    - Plain text → direct lookup in the translation map.
 *    - HTML       → text-node-anchored str_replace (same technique as
 *                   WIT_Content_Parser for Gutenberg block HTML fragments).
 * 6. Save the translated JSON to `_elementor_data` on the target post.
 * 7. Copy required Elementor meta (_elementor_edit_mode, _template_type…)
 *    and purge any cached CSS so Elementor regenerates it.
 *
 * This approach works for every widget type, including third-party Elementor
 * add-ons (Crocoblock JetElements, Essential Addons, etc.), because it does
 * not maintain a widget-specific whitelist of translatable keys.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Elementor_Handler {

    /** @var array Debug messages accumulated during translation */
    private $debug_log = array();

    /** @var int Counter of translated strings */
    private $strings_translated = 0;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Check whether a post was built with Elementor.
     *
     * @param int $post_id
     * @return bool
     */
    public function is_elementor_post($post_id) {
        return get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
    }

    /**
     * Translate the Elementor data of a post and write it to the target post.
     *
     * @param int    $source_post_id
     * @param int    $target_post_id
     * @param string $target_language
     * @param string $source_language
     * @return array { debug: string[] }
     */
    public function translate($source_post_id, $target_post_id, $target_language, $source_language) {
        $this->debug_log          = array();
        $this->strings_translated = 0;

        $raw_json = get_post_meta($source_post_id, '_elementor_data', true);

        if (empty($raw_json)) {
            $this->debug_log[] = 'Elementor: no _elementor_data found on post #' . $source_post_id;
            $this->copy_elementor_meta($source_post_id, $target_post_id, '[]');
            return array('debug' => $this->debug_log);
        }

        $elements = json_decode($raw_json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($elements)) {
            $this->debug_log[] = 'Elementor: failed to decode _elementor_data — ' . json_last_error_msg();
            $this->copy_elementor_meta($source_post_id, $target_post_id, $raw_json);
            return array('debug' => $this->debug_log);
        }

        // Pass 1 — collect all unique translatable strings
        $originals = array(); // text => sequential index
        $this->collect_from_elements($elements, $originals);

        if (empty($originals)) {
            $this->debug_log[] = 'Elementor: no translatable strings found';
            $this->copy_elementor_meta($source_post_id, $target_post_id, $raw_json);
            return array('debug' => $this->debug_log);
        }

        $texts_array = array_keys($originals);
        $this->debug_log[] = 'Elementor: ' . count($texts_array) . ' unique strings collected';

        // Pass 2 — one batch API call
        $translator       = new WIT_Translator_Engine();
        $raw_translations = $translator->translate_batch($texts_array, $target_language, $source_language);

        // Build lookup map: original_text => translated_text
        $map = array();
        foreach ($texts_array as $i => $original) {
            if (isset($raw_translations[$i])
                && empty($raw_translations[$i]['error'])
                && !empty($raw_translations[$i]['translation'])) {
                $map[$original] = $raw_translations[$i]['translation'];
            }
        }

        if (empty($map)) {
            $this->debug_log[] = 'Elementor: no translations received from the API';
            $this->copy_elementor_meta($source_post_id, $target_post_id, $raw_json);
            return array('debug' => $this->debug_log);
        }

        // Pass 3 — apply the map back into the element tree
        $translated_elements = $this->apply_to_elements($elements, $map);

        $translated_json = wp_json_encode($translated_elements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($translated_json === false) {
            $this->debug_log[] = 'Elementor: JSON re-encoding failed — saving original';
            $translated_json = $raw_json;
        }

        $this->copy_elementor_meta($source_post_id, $target_post_id, $translated_json);

        $this->debug_log[] = 'Elementor: === STRINGS TRANSLATED: ' . $this->strings_translated . ' ===';

        return array('debug' => $this->debug_log);
    }

    // -----------------------------------------------------------------------
    // Meta management
    // -----------------------------------------------------------------------

    /**
     * Save the (translated) Elementor JSON to the target post and copy all
     * required structural meta from the source post.
     *
     * @param int    $source_id
     * @param int    $target_id
     * @param string $translated_json
     */
    private function copy_elementor_meta($source_id, $target_id, $translated_json) {
        // Save translated content
        update_post_meta($target_id, '_elementor_data', $translated_json);

        // Copy non-translatable structural meta
        $keys_to_copy = array(
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_pro_version',
            '_elementor_page_settings',  // page-level settings (header/footer template etc.)
        );

        foreach ($keys_to_copy as $key) {
            $value = get_post_meta($source_id, $key, true);
            if ($value !== '') {
                update_post_meta($target_id, $key, $value);
            }
        }

        // Remove cached CSS so Elementor regenerates it for the new language
        delete_post_meta($target_id, '_elementor_css');
        delete_post_meta($target_id, '_elementor_element_cache');
    }

    // -----------------------------------------------------------------------
    // Pass 1 — collect translatable strings
    // -----------------------------------------------------------------------

    /**
     * Recursively walk an array of Elementor elements and collect unique
     * translatable strings into $originals.
     *
     * @param array $elements
     * @param array &$originals  text => index
     */
    private function collect_from_elements($elements, &$originals) {
        foreach ($elements as $element) {
            if (!empty($element['settings']) && is_array($element['settings'])) {
                $this->collect_from_settings($element['settings'], $originals);
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->collect_from_elements($element['elements'], $originals);
            }
        }
    }

    /**
     * Walk a settings array and add translatable strings to $originals.
     * Handles plain strings, HTML strings and nested arrays (repeater fields).
     *
     * @param array $settings
     * @param array &$originals
     */
    private function collect_from_settings($settings, &$originals) {
        foreach ($settings as $value) {
            if (is_string($value)) {
                if ($this->is_html($value)) {
                    // Extract visible text nodes from HTML fields (e.g. `editor`)
                    $this->collect_from_html($value, $originals);
                } else {
                    $this->maybe_add_string($value, $originals);
                }
            } elseif (is_array($value)) {
                // Repeater fields and nested objects
                $this->collect_from_settings($value, $originals);
            }
        }
    }

    /**
     * Add a plain-text string to $originals after applying heuristic filters.
     *
     * Filters out: short strings, number/punctuation-only, URLs, hex colours,
     * CSS keywords, and values with no Unicode letters.
     *
     * @param string $str
     * @param array  &$originals
     */
    private function maybe_add_string($str, &$originals) {
        $trimmed = trim($str);

        if (mb_strlen($trimmed) < 2) {
            return;
        }
        // Numbers / punctuation / symbols only
        if (preg_match('/^[\d\s\p{P}\p{S}\x{00A0}]+$/u', $trimmed)) {
            return;
        }
        // URLs
        if (preg_match('/^https?:\/\//i', $trimmed) || strpos($trimmed, '://') !== false) {
            return;
        }
        // Hex colours
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $trimmed)) {
            return;
        }
        // Must contain at least one Unicode letter
        if (!preg_match('/\p{L}/u', $trimmed)) {
            return;
        }
        // Single CSS/layout keyword  (keeps translatable single words like "Submit" but drops "flex", "auto", …)
        if (preg_match('/^(px|em|rem|vh|vw|vmin|vmax|%|flex|grid|block|inline|none|auto|normal|bold|italic|center|left|right|top|bottom|hidden|visible|solid|dashed|dotted|inherit|unset|initial|absolute|relative|fixed|sticky)$/i', $trimmed)) {
            return;
        }

        if (!array_key_exists($trimmed, $originals)) {
            $originals[$trimmed] = count($originals);
        }
    }

    /**
     * Extract visible text nodes from an HTML string (e.g. the `editor` field
     * of a Text Editor widget) and add them to $originals.
     *
     * @param string $html
     * @param array  &$originals
     */
    private function collect_from_html($html, &$originals) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="wit-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xpath   = new DOMXPath($dom);
        $wrapper = $xpath->query('//*[@id="wit-root"]')->item(0);
        if (!$wrapper) {
            return;
        }

        $nodes = $xpath->query(
            './/text()'
            . '[not(ancestor::script)][not(ancestor::style)]'
            . '[not(ancestor::code)][not(ancestor::pre)][not(ancestor::textarea)]',
            $wrapper
        );

        foreach ($nodes as $node) {
            $trimmed = trim($node->nodeValue);
            if (mb_strlen($trimmed) < 2) {
                continue;
            }
            if (preg_match('/^[\d\s\p{P}\p{S}\x{00A0}]+$/u', $trimmed)) {
                continue;
            }
            if (!array_key_exists($trimmed, $originals)) {
                $originals[$trimmed] = count($originals);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Pass 3 — apply translation map
    // -----------------------------------------------------------------------

    /**
     * Recursively apply the translation map to all elements.
     *
     * @param array $elements
     * @param array $map  original_text => translated_text
     * @return array
     */
    private function apply_to_elements($elements, $map) {
        foreach ($elements as &$element) {
            if (!empty($element['settings']) && is_array($element['settings'])) {
                $element['settings'] = $this->apply_to_settings($element['settings'], $map);
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $element['elements'] = $this->apply_to_elements($element['elements'], $map);
            }
        }
        return $elements;
    }

    /**
     * Apply the translation map to a settings array.
     *
     * @param array $settings
     * @param array $map
     * @return array
     */
    private function apply_to_settings($settings, $map) {
        foreach ($settings as &$value) {
            if (is_string($value)) {
                if ($this->is_html($value)) {
                    $value = $this->apply_map_to_html($value, $map);
                } else {
                    $trimmed = trim($value);
                    if (isset($map[$trimmed])) {
                        // Preserve surrounding whitespace from the original value
                        $leading = $trailing = '';
                        if (preg_match('/^(\s+)/u', $value, $m)) $leading  = $m[1];
                        if (preg_match('/(\s+)$/u', $value, $m)) $trailing = $m[1];
                        $value = $leading . $map[$trimmed] . $trailing;
                        $this->strings_translated++;
                    }
                }
            } elseif (is_array($value)) {
                $value = $this->apply_to_settings($value, $map);
            }
        }
        return $settings;
    }

    /**
     * Apply the translation map to an HTML string using text-node-anchored
     * string replacement — identical technique to WIT_Content_Parser.
     *
     * @param string $html
     * @param array  $map
     * @return string
     */
    private function apply_map_to_html($html, $map) {
        if (empty(trim(strip_tags($html)))) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="wit-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xpath   = new DOMXPath($dom);
        $wrapper = $xpath->query('//*[@id="wit-root"]')->item(0);
        if (!$wrapper) {
            return $html;
        }

        $nodes = $xpath->query(
            './/text()'
            . '[not(ancestor::script)][not(ancestor::style)]'
            . '[not(ancestor::code)][not(ancestor::pre)][not(ancestor::textarea)]',
            $wrapper
        );

        $result  = $html;
        $applied = array();
        $enc     = array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;');

        foreach ($nodes as $node) {
            $raw     = $node->nodeValue;
            $trimmed = trim($raw);

            if (!isset($map[$trimmed]))  continue;
            if (isset($applied[$raw]))   continue;

            $leading = $trailing = '';
            if (preg_match('/^(\s+)/u', $raw, $m)) $leading  = $m[1];
            if (preg_match('/(\s+)$/u', $raw, $m)) $trailing = $m[1];
            $translated = $leading . $map[$trimmed] . $trailing;

            $s_html = strtr($raw,        $enc);
            $r_html = strtr($translated, $enc);

            $found = false;

            if (strpos($result, '>' . $s_html . '<') !== false) {
                $result = str_replace('>' . $s_html . '<', '>' . $r_html . '<', $result);
                $found  = true;
            } elseif (mb_strlen($trimmed) > 15 || strpos($trimmed, ' ') !== false) {
                if (strpos($result, $s_html) !== false) {
                    $result = str_replace($s_html, $r_html, $result);
                    $found  = true;
                }
            }

            if ($found) {
                $applied[$raw] = true;
                $this->strings_translated++;
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Return true when the string contains HTML tags.
     *
     * @param string $str
     * @return bool
     */
    private function is_html($str) {
        return strip_tags($str) !== $str && (bool) preg_match('/<[a-z][\s\S]*>/i', $str);
    }
}
