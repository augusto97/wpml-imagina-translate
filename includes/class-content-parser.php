<?php
/**
 * Content Parser - Translates post content preserving HTML/block structure exactly.
 *
 * Strategy:
 *  1. Use DOMDocument ONLY to detect text nodes (never to produce output HTML).
 *  2. Collect all unique translatable strings.
 *  3. Send them ALL in a single batch API call.
 *  4. Apply results with str_replace on the ORIGINAL string.
 *
 * Gutenberg block comments (<!-- wp:xxx -->) are HTML comments; DOMDocument
 * treats them as comment nodes, so their content is never touched.
 * The HTML is never re-serialized, which is what caused Gutenberg block
 * validation errors with the previous approach.
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
        $this->debug_log        = array();
        $this->strings_translated = 0;

        if (empty($content)) {
            return array('content' => '', 'error' => null, 'debug' => array('Content is empty'));
        }

        $this->debug_log[] = 'Content length: ' . strlen($content) . ' chars'
                           . (has_blocks($content) ? ' (Gutenberg)' : ' (Classic editor)');

        $translator = new WIT_Translator_Engine();
        $translated  = $this->translate_html_string($content, $translator, $target_language, $source_language);

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
    // Core translation logic
    // -----------------------------------------------------------------------

    /**
     * Translate all visible text in an HTML string without altering its structure.
     *
     * Works identically for classic-editor HTML and Gutenberg block content.
     * Gutenberg block comment markers (<!-- wp:xxx -->) are HTML comment nodes
     * and are invisible to the text-node XPath query, so they are never modified.
     *
     * @param string $html
     * @param WIT_Translator_Engine $translator
     * @param string $target_language
     * @param string $source_language
     * @return string  Original $html with translated text nodes.
     */
    private function translate_html_string($html, $translator, $target_language, $source_language) {
        if (empty(trim(strip_tags($html)))) {
            return $html;
        }

        // --- Load DOM (for detection only, never for output) ---
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapper_id = 'wit-root-' . uniqid();
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="' . $wrapper_id . '">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query(
            '//div[@id="' . $wrapper_id . '"]//text()'
            . '[not(ancestor::script)][not(ancestor::style)]'
            . '[not(ancestor::code)][not(ancestor::pre)][not(ancestor::textarea)]'
        );

        if (!$nodes || $nodes->length === 0) {
            return $html;
        }

        // --- Pass 1: collect unique translatable strings ---
        $seen      = array(); // trimmed_text => index
        $originals = array(); // ordered list of unique texts to translate

        foreach ($nodes as $node) {
            $raw     = $node->nodeValue;
            $trimmed = trim($raw);

            if (mb_strlen($trimmed) < 2) {
                continue;
            }

            // Skip pure numbers / punctuation / whitespace / non-breaking space
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

        // --- Pass 3: build raw_value → translated_value map ---
        $replacements = array();

        foreach ($nodes as $node) {
            $raw     = $node->nodeValue;
            $trimmed = trim($raw);

            if (!array_key_exists($trimmed, $seen)) {
                continue;
            }
            if (array_key_exists($raw, $replacements)) {
                continue; // already handled (same raw value)
            }

            $idx = $seen[$trimmed];
            $t   = isset($translations[$idx]) ? $translations[$idx] : null;

            if (!$t || $t['error'] || empty($t['translation'])) {
                if ($t && $t['error']) {
                    $this->debug_log[] = '  ERROR: ' . $t['error'];
                }
                continue;
            }

            // Preserve leading/trailing whitespace from the original node value
            $leading = $trailing = '';
            if (preg_match('/^(\s+)/u', $raw, $m)) $leading  = $m[1];
            if (preg_match('/(\s+)$/u', $raw, $m)) $trailing = $m[1];

            $replacements[$raw] = $leading . $t['translation'] . $trailing;
            $this->strings_translated++;
            $this->debug_log[] = '  RECV: "' . mb_substr($t['translation'], 0, 80)
                               . (mb_strlen($t['translation']) > 80 ? '...' : '') . '"';
        }

        if (empty($replacements)) {
            return $html;
        }

        // --- Pass 4: apply str_replace on the ORIGINAL HTML string ---
        $result  = $html;
        $changes = 0;

        foreach ($replacements as $original => $translated) {
            // Primary: search for the HTML-entity-encoded form
            $search  = htmlspecialchars($original,  ENT_HTML5, 'UTF-8');
            $replace = htmlspecialchars($translated, ENT_HTML5, 'UTF-8');

            if (strpos($result, $search) !== false) {
                $result = str_replace($search, $replace, $result);
                $changes++;
            } elseif ($search !== $original && strpos($result, $original) !== false) {
                // Fallback: text stored as raw UTF-8 without entity encoding
                $result = str_replace($original, $translated, $result);
                $changes++;
            }
        }

        return $changes > 0 ? $result : $html;
    }
}
