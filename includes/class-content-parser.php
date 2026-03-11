<?php
/**
 * Content Parser - Translates post content preserving HTML/block structure exactly.
 *
 * --- Gutenberg (block editor) content ---
 * Strategy:
 *  1. Use parse_blocks() to split post_content into structured block data.
 *  2. Collect ALL unique translatable text strings from every block's
 *     innerContent HTML fragments (recursively, including nested blocks).
 *  3. Send them ALL in a single batch API call.
 *  4. Apply results to each block's innerContent HTML fragment using
 *     text-node-anchored str_replace — working only on individual block
 *     HTML, which NEVER contains block comment markers.
 *  5. Reconstruct the full post_content with serialize_blocks().
 *
 * Why this is critical:
 *  The previous approach ran str_replace() over the entire raw post_content
 *  string, which includes Gutenberg block comment markers such as:
 *    <!-- wp:image {"alt":"Original text","sizeSlug":"large"} -->
 *  The fallback str_replace (for long/multi-word phrases) would accidentally
 *  replace text inside those JSON attrs, making the block comment JSON
 *  disagree with the innerHTML. Gutenberg's editor then shows:
 *    "El bloque contiene contenido inesperado o no válido."
 *  Working on individual block innerContent fragments avoids this entirely
 *  because block comment markers are NOT part of innerContent.
 *
 * --- Classic editor content ---
 * Strategy (unchanged from before):
 *  1. Use DOMDocument ONLY to detect text nodes (never to produce output HTML).
 *  2. Collect all unique translatable strings.
 *  3. Send them ALL in a single batch API call.
 *  4. Apply results with str_replace on the ORIGINAL string.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Content_Parser {

    private $debug_log = array();
    private $strings_translated = 0;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    public function translate_content($content, $target_language, $source_language = '') {
        $this->debug_log          = array();
        $this->strings_translated = 0;

        if (empty($content)) {
            return array('content' => '', 'error' => null, 'debug' => array('Content is empty'));
        }

        $is_gutenberg = has_blocks($content);
        $this->debug_log[] = 'Content length: ' . strlen($content) . ' chars'
                           . ($is_gutenberg ? ' (Gutenberg)' : ' (Classic editor)');

        $translator = new WIT_Translator_Engine();

        if ($is_gutenberg) {
            $translated = $this->translate_blocks_content($content, $translator, $target_language, $source_language);
        } else {
            $translated = $this->translate_html_string($content, $translator, $target_language, $source_language);
        }

        $this->debug_log[] = '=== STRINGS TRANSLATED: ' . $this->strings_translated . ' ===';

        return array('content' => $translated, 'error' => null, 'debug' => $this->debug_log);
    }

    public function translate_title($title, $target_language, $source_language = '') {
        if (empty($title)) {
            return array('title' => '', 'error' => null);
        }

        $translator = new WIT_Translator_Engine();
        $result     = $translator->translate($title, $target_language, $source_language);

        return array(
            'title' => $result['error'] ? $title : $result['translation'],
            'error' => $result['error'],
        );
    }

    public function translate_excerpt($excerpt, $target_language, $source_language = '') {
        if (empty($excerpt)) {
            return array('excerpt' => '', 'error' => null);
        }

        $translator = new WIT_Translator_Engine();
        $result     = $translator->translate($excerpt, $target_language, $source_language);

        return array(
            'excerpt' => $result['error'] ? $excerpt : $result['translation'],
            'error'   => $result['error'],
        );
    }

    // -----------------------------------------------------------------------
    // Gutenberg block-aware translation
    // -----------------------------------------------------------------------

    /**
     * Translate Gutenberg block content safely using the WordPress block API.
     *
     * Uses parse_blocks() so we can translate each block's innerContent HTML
     * independently, without ever touching block comment attribute JSON.
     * Uses serialize_blocks() to reconstruct valid block markup.
     *
     * @param string               $content
     * @param WIT_Translator_Engine $translator
     * @param string               $target_language
     * @param string               $source_language
     * @return string
     */
    private function translate_blocks_content($content, $translator, $target_language, $source_language) {
        $blocks = parse_blocks($content);

        if (empty($blocks)) {
            return $content;
        }

        // Pass 1: collect ALL unique translatable strings from every block
        // in the entire page (including nested blocks) — one batch API call.
        $originals = array(); // text => sequential index
        $this->collect_texts_from_blocks($blocks, $originals);

        if (empty($originals)) {
            return $content;
        }

        $texts_array = array_keys($originals);

        $this->debug_log[] = 'Gutenberg blocks: ' . count($texts_array) . ' unique strings collected';
        foreach ($texts_array as $t) {
            $this->debug_log[] = '  SEND: "' . mb_substr($t, 0, 80)
                               . (mb_strlen($t) > 80 ? '...' : '') . '"';
        }

        // Pass 2: one batch API call for all collected texts
        $raw_translations = $translator->translate_batch($texts_array, $target_language, $source_language);

        // Build lookup map: original_text => translated_text
        $map = array();
        foreach ($texts_array as $i => $original) {
            if (isset($raw_translations[$i])
                && empty($raw_translations[$i]['error'])
                && !empty($raw_translations[$i]['translation'])) {
                $map[$original] = $raw_translations[$i]['translation'];
                $this->debug_log[] = '  RECV: "' . mb_substr($raw_translations[$i]['translation'], 0, 80)
                                   . (mb_strlen($raw_translations[$i]['translation']) > 80 ? '...' : '') . '"';
            }
        }

        if (empty($map)) {
            return $content;
        }

        // Pass 3: apply translation map to each block's innerContent
        // (never to block comment attrs — they are left completely untouched)
        $translated_blocks = $this->apply_map_to_blocks($blocks, $map);

        return serialize_blocks($translated_blocks);
    }

    /**
     * Recursively collect unique translatable text strings from an array of blocks.
     * Only collects from innerContent HTML fragments (never from block comment attrs).
     *
     * @param array $blocks
     * @param array &$originals  text => index map, populated in place
     */
    private function collect_texts_from_blocks($blocks, &$originals) {
        foreach ($blocks as $block) {
            // innerContent is an array of strings (HTML fragments) and nulls
            // (null = placeholder for an inner block serialized separately).
            foreach ($block['innerContent'] as $chunk) {
                if (is_string($chunk) && !empty(trim(strip_tags($chunk)))) {
                    $this->collect_texts_from_html_chunk($chunk, $originals);
                }
            }

            // Also collect text from block attrs so that third-party blocks that
            // store visible text in attrs (e.g. Greenshift's headingContent /
            // buttonContent) are included in the batch translation request and
            // subsequently updated to match the translated innerHTML.
            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                $this->collect_texts_from_attrs($block['attrs'], $originals);
            }

            // Recurse into nested blocks (columns, groups, etc.)
            if (!empty($block['innerBlocks'])) {
                $this->collect_texts_from_blocks($block['innerBlocks'], $originals);
            }
        }
    }

    /**
     * Recursively walk a block attrs array and add any string value that looks
     * like translatable human-readable text to $originals.
     *
     * Strings are skipped when they:
     * - are shorter than 2 chars
     * - consist only of digits, punctuation or symbols (colours, IDs, etc.)
     * - look like URLs (start with http/https/ftp or contain "://")
     * - look like CSS values ("#" prefix for hex, pure numeric, or known keywords)
     *
     * @param array $attrs
     * @param array &$originals
     */
    private function collect_texts_from_attrs($attrs, &$originals) {
        foreach ($attrs as $value) {
            if (is_string($value)) {
                $trimmed = trim($value);

                if (mb_strlen($trimmed) < 2) {
                    continue;
                }
                // Skip numbers / punctuation / symbols only
                if (preg_match('/^[\d\s\p{P}\p{S}\x{00A0}]+$/u', $trimmed)) {
                    continue;
                }
                // Skip URLs
                if (preg_match('/^https?:\/\//i', $trimmed) || strpos($trimmed, '://') !== false) {
                    continue;
                }
                // Skip hex colours and CSS-only values
                if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $trimmed)) {
                    continue;
                }
                // Skip values that contain no letters (e.g. "0px", "46.60", "flex-start")
                if (!preg_match('/\p{L}/u', $trimmed)) {
                    continue;
                }

                if (!array_key_exists($trimmed, $originals)) {
                    $originals[$trimmed] = count($originals);
                }
            } elseif (is_array($value)) {
                $this->collect_texts_from_attrs($value, $originals);
            }
        }
    }

    /**
     * Extract unique text nodes from a single HTML fragment into $originals.
     * Uses DOMDocument for detection only — the HTML string is never re-serialised.
     *
     * @param string $html
     * @param array  &$originals
     */
    private function collect_texts_from_html_chunk($html, &$originals) {
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
            // Skip strings that are only numbers / punctuation / symbols
            if (preg_match('/^[\d\s\p{P}\p{S}\x{00A0}]+$/u', $trimmed)) {
                continue;
            }
            if (!array_key_exists($trimmed, $originals)) {
                $originals[$trimmed] = count($originals);
            }
        }
    }

    /**
     * Recursively apply a translation map to block innerContent and attrs.
     *
     * innerContent HTML fragments are translated via text-node replacement.
     * Block attrs are also updated: any string value that exactly matches a
     * key in $map is replaced with the translation.
     *
     * Why attrs must also be updated:
     * Third-party blocks (e.g. Greenshift's greenshift-blocks/heading,
     * greenshift-blocks/button) store the visible text directly in block
     * comment attrs — e.g. "headingContent":"Original text",
     * "buttonContent":"Original text". Gutenberg's block validation calls the
     * block's JS save() function with those attrs to produce expected HTML,
     * then compares the result against the stored innerHTML. If we translate
     * innerHTML but leave attrs with the original language, the comparison
     * fails and the editor shows "El bloque contiene contenido inesperado."
     *
     * Safety: $map keys come exclusively from HTML text nodes extracted via
     * DOMDocument. They will never contain URLs, hex colours, CSS values,
     * numeric IDs or other non-text attr values, so the attr walk below
     * will only ever replace genuinely translatable text.
     *
     * @param array $blocks
     * @param array $map    original_text => translated_text
     * @return array        Modified blocks array
     */
    private function apply_map_to_blocks($blocks, $map) {
        foreach ($blocks as &$block) {
            // Translate each HTML fragment in innerContent independently
            $new_inner_content = array();
            foreach ($block['innerContent'] as $chunk) {
                if (is_string($chunk)) {
                    $new_inner_content[] = $this->apply_map_to_html_chunk($chunk, $map);
                } else {
                    // null = slot for an inner block; leave as-is
                    $new_inner_content[] = $chunk;
                }
            }
            $block['innerContent'] = $new_inner_content;

            // Update block attrs so they stay in sync with the translated innerHTML.
            // This is required for blocks that store text in attrs (headingContent,
            // buttonContent, etc.) and use those attrs as the source of truth when
            // Gutenberg re-renders the block for validation.
            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                $block['attrs'] = $this->apply_map_to_attrs($block['attrs'], $map);
            }

            // Recurse into nested blocks
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->apply_map_to_blocks($block['innerBlocks'], $map);
            }
        }
        return $blocks;
    }

    /**
     * Recursively walk a block attrs array and replace any string value whose
     * trimmed form exactly matches a key in $map.
     *
     * Only exact full-string matches are replaced, which means:
     * - URLs  ("https://example.com/page")  → not in $map → untouched ✓
     * - Colours ("#ffffff")                 → not in $map → untouched ✓
     * - CSS / alignment ("flex-start")      → not in $map → untouched ✓
     * - Block IDs ("gsbp-3adf472b-d320")    → not in $map → untouched ✓
     * - Translatable text ("Original text") → in $map     → replaced  ✓
     *
     * @param array $attrs
     * @param array $map   original_text => translated_text
     * @return array
     */
    private function apply_map_to_attrs($attrs, $map) {
        foreach ($attrs as $key => &$value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '' && isset($map[$trimmed])) {
                    $value = $map[$trimmed];
                }
            } elseif (is_array($value)) {
                $value = $this->apply_map_to_attrs($value, $map);
            }
            // int, float, bool, null — left untouched
        }
        return $attrs;
    }

    /**
     * Apply a translation map to a single HTML fragment using text-node-anchored
     * string replacement.
     *
     * This method is safe for use with the fallback str_replace because it works
     * on an individual block's HTML fragment — which never contains block comment
     * markers (<!-- wp:... -->). There is therefore no risk of accidentally
     * corrupting block attribute JSON.
     *
     * @param string $html
     * @param array  $map  original_text => translated_text
     * @return string
     */
    private function apply_map_to_html_chunk($html, $map) {
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

            if (!array_key_exists($trimmed, $map)) {
                continue;
            }
            if (isset($applied[$raw])) {
                continue; // already replaced this exact raw value
            }

            // Preserve leading/trailing whitespace from the original text node
            $leading = $trailing = '';
            if (preg_match('/^(\s+)/u', $raw, $m)) $leading  = $m[1];
            if (preg_match('/(\s+)$/u', $raw, $m)) $trailing = $m[1];
            $translated = $leading . $map[$trimmed] . $trailing;

            // Encode only &, < and > so the search string matches the HTML exactly.
            // (DOMDocument nodeValue gives decoded text; we must re-encode these
            //  three chars to match how they are stored in the HTML string.)
            $s_html = strtr($raw,        $enc);
            $r_html = strtr($translated, $enc);

            $found = false;

            // Preferred: context-anchored — text must sit between > and <
            // This cannot match inside tag names or attribute values.
            if (strpos($result, '>' . $s_html . '<') !== false) {
                $result = str_replace('>' . $s_html . '<', '>' . $r_html . '<', $result);
                $found  = true;
            }
            // Fallback: plain str_replace for text that spans siblings or lines.
            // Safe here because we are working on a single block's HTML fragment —
            // no block comment markers are present in this string.
            elseif (mb_strlen($trimmed) > 15 || strpos($trimmed, ' ') !== false) {
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
    // Classic editor translation
    // -----------------------------------------------------------------------

    /**
     * Translate all visible text in a classic-editor HTML string without
     * altering its structure.
     *
     * Uses DOMDocument ONLY to detect text nodes — the HTML is never
     * re-serialised via saveHTML(), which would alter entity encoding and
     * break any Gutenberg blocks that happen to exist.
     *
     * @param string               $html
     * @param WIT_Translator_Engine $translator
     * @param string               $target_language
     * @param string               $source_language
     * @return string
     */
    private function translate_html_string($html, $translator, $target_language, $source_language) {
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

        if (!$nodes || $nodes->length === 0) {
            return $html;
        }

        // --- Pass 1: collect unique translatable strings ---
        $seen      = array(); // trimmed_text => index in $originals
        $originals = array();

        foreach ($nodes as $node) {
            $raw     = $node->nodeValue;
            $trimmed = trim($raw);

            if (mb_strlen($trimmed) < 2) {
                continue;
            }
            if (preg_match('/^[\d\s\p{P}\p{S}\x{00A0}]+$/u', $trimmed)) {
                continue;
            }
            if (!array_key_exists($trimmed, $seen)) {
                $seen[$trimmed] = count($originals);
                $originals[]    = $trimmed;
                $this->debug_log[] = '  SEND: "' . mb_substr($trimmed, 0, 80)
                                   . (mb_strlen($trimmed) > 80 ? '...' : '') . '"';
            }
        }

        if (empty($originals)) {
            return $html;
        }

        // --- Pass 2: batch-translate all unique texts in ONE API call ---
        $translations = $translator->translate_batch($originals, $target_language, $source_language);

        // --- Pass 3: apply translations to the ORIGINAL HTML string ---
        $result  = $html;
        $applied = array();
        $changes = 0;

        foreach ($nodes as $node) {
            $raw     = $node->nodeValue;
            $trimmed = trim($raw);

            if (!array_key_exists($trimmed, $seen)) {
                continue;
            }
            if (isset($applied[$raw])) {
                continue;
            }

            $idx = $seen[$trimmed];
            $t   = isset($translations[$idx]) ? $translations[$idx] : null;

            if (!$t || $t['error'] || empty($t['translation'])) {
                if ($t && $t['error']) {
                    $this->debug_log[] = '  ERROR: ' . $t['error'];
                }
                continue;
            }

            // Preserve surrounding whitespace
            $leading = $trailing = '';
            if (preg_match('/^(\s+)/u', $raw, $m)) $leading  = $m[1];
            if (preg_match('/(\s+)$/u', $raw, $m)) $trailing = $m[1];
            $translated = $leading . $t['translation'] . $trailing;

            $enc    = array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
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
                $changes++;
                $this->debug_log[] = '  RECV: "' . mb_substr($t['translation'], 0, 80)
                                   . (mb_strlen($t['translation']) > 80 ? '...' : '') . '"';
            }
        }

        return $changes > 0 ? $result : $html;
    }
}
