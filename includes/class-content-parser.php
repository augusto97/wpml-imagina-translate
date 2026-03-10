<?php
/**
 * Content Parser - DOM-based direct text node translation
 *
 * Translates content by manipulating text nodes directly in the DOM.
 * Never uses str_replace. Updates innerContent (used by serialize_block).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Content_Parser {

    private $debug_log = array();
    private $strings_translated = 0;

    /**
     * Translate post content
     */
    public function translate_content($content, $target_language, $source_language = '') {
        $this->debug_log = array();
        $this->strings_translated = 0;

        if (empty($content)) {
            return array(
                'content' => '',
                'error' => null,
                'debug' => array('Content is empty')
            );
        }

        $this->debug_log[] = 'Content length: ' . strlen($content) . ' characters';

        $translator = new WIT_Translator_Engine();

        if (has_blocks($content)) {
            $this->debug_log[] = 'Detected Gutenberg blocks';
            $translated = $this->translate_gutenberg($content, $translator, $target_language, $source_language);
        } else {
            $this->debug_log[] = 'Classic editor content';
            $translated = $this->translate_html_via_dom($content, $translator, $target_language, $source_language, 'classic');
        }

        $this->debug_log[] = '=== TOTAL STRINGS TRANSLATED: ' . $this->strings_translated . ' ===';

        return array(
            'content' => $translated,
            'error' => null,
            'debug' => $this->debug_log
        );
    }

    /**
     * Translate Gutenberg blocks
     *
     * Key insight: serialize_block() uses innerContent (NOT innerHTML).
     * So we must update innerContent entries for translations to persist.
     */
    private function translate_gutenberg($content, $translator, $target_language, $source_language) {
        $blocks = parse_blocks($content);
        $this->debug_log[] = 'Found ' . count($blocks) . ' top-level blocks';

        foreach ($blocks as &$block) {
            $this->translate_block_recursive($block, $translator, $target_language, $source_language);
        }
        unset($block);

        // Serialize all blocks back to content
        $result = '';
        foreach ($blocks as $block) {
            $result .= serialize_block($block);
        }

        return $result;
    }

    /**
     * Recursively translate a single block and its inner blocks
     */
    private function translate_block_recursive(&$block, $translator, $target_language, $source_language) {
        $block_name = $block['blockName'] ?? '';

        // Skip null blocks (whitespace between blocks) and non-translatable blocks
        if (empty($block_name)) {
            return;
        }

        if ($this->should_skip_block($block_name)) {
            $this->debug_log[] = 'Skipping block: ' . $block_name;
            return;
        }

        $this->debug_log[] = 'Processing block: ' . $block_name;

        // Translate each string chunk in innerContent
        // innerContent is an array: strings are HTML, nulls are inner block positions
        if (!empty($block['innerContent'])) {
            foreach ($block['innerContent'] as $i => $chunk) {
                if (!is_string($chunk)) {
                    continue; // null = inner block placeholder, skip
                }

                // Only process chunks that have actual text
                if (empty(trim(strip_tags($chunk)))) {
                    continue;
                }

                $translated_chunk = $this->translate_html_via_dom(
                    $chunk, $translator, $target_language, $source_language, $block_name
                );

                $block['innerContent'][$i] = $translated_chunk;
            }
        }

        // Also update innerHTML for consistency (some plugins may use it)
        if (!empty($block['innerHTML']) && !empty(trim(strip_tags($block['innerHTML'])))) {
            // For blocks without inner blocks, innerHTML = innerContent[0]
            if (empty($block['innerBlocks']) && isset($block['innerContent'][0])) {
                $block['innerHTML'] = $block['innerContent'][0];
            }
        }

        // Translate translatable attributes (alt text, captions, etc.)
        if (!empty($block['attrs'])) {
            $block['attrs'] = $this->translate_block_attributes(
                $block['attrs'], $translator, $target_language, $source_language
            );
        }

        // Recurse into inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as &$inner_block) {
                $this->translate_block_recursive($inner_block, $translator, $target_language, $source_language);
            }
            unset($inner_block);
        }
    }

    /**
     * Translate HTML by directly manipulating DOM text nodes
     *
     * This is the core function. It:
     * 1. Parses HTML with DOMDocument
     * 2. Finds all text nodes (skipping script, style, code, pre)
     * 3. Translates each text node directly
     * 4. Serializes DOM back to HTML
     *
     * HTML structure is NEVER sent to the AI. Only plain text.
     */
    private function translate_html_via_dom($html, $translator, $target_language, $source_language, $context = '') {
        if (empty(trim(strip_tags($html)))) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Wrap content to prevent DOMDocument from adding html/body tags
        $wrapper_id = 'wit-root-' . uniqid();
        $wrapped = '<div id="' . $wrapper_id . '">' . $html . '</div>';
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Find text nodes ONLY inside our wrapper, excluding code-like elements
        $query = '//div[@id="' . $wrapper_id . '"]//text()' .
                 '[not(ancestor::script)]' .
                 '[not(ancestor::style)]' .
                 '[not(ancestor::code)]' .
                 '[not(ancestor::pre)]' .
                 '[not(ancestor::textarea)]';

        $text_nodes = $xpath->query($query);

        if ($text_nodes === false || $text_nodes->length === 0) {
            return $html;
        }

        $changes_made = 0;

        foreach ($text_nodes as $text_node) {
            $raw_value = $text_node->nodeValue;
            $trimmed = trim($raw_value);

            // Skip empty or very short text
            if (mb_strlen($trimmed) < 2) {
                continue;
            }

            // Skip if it's only numbers, punctuation, symbols, or whitespace (including &nbsp;)
            if (preg_match('/^[\d\s\p{P}\p{S}\x{00A0}]+$/u', $trimmed)) {
                continue;
            }

            $this->debug_log[] = sprintf(
                '  [%s] SEND: "%s"',
                $context,
                mb_substr($trimmed, 0, 80) . (mb_strlen($trimmed) > 80 ? '...' : '')
            );

            $result = $translator->translate($trimmed, $target_language, $source_language);

            if (!$result['error'] && !empty($result['translation'])) {
                // Preserve leading and trailing whitespace from the original node value
                $leading = '';
                $trailing = '';

                if (preg_match('/^(\s+)/u', $raw_value, $m)) {
                    $leading = $m[1];
                }
                if (preg_match('/(\s+)$/u', $raw_value, $m)) {
                    $trailing = $m[1];
                }

                // Set the translated text directly on the DOM text node
                $text_node->nodeValue = $leading . $result['translation'] . $trailing;
                $changes_made++;
                $this->strings_translated++;

                $this->debug_log[] = sprintf(
                    '  [%s] RECV: "%s"',
                    $context,
                    mb_substr($result['translation'], 0, 80) . (mb_strlen($result['translation']) > 80 ? '...' : '')
                );
            } elseif ($result['error']) {
                $this->debug_log[] = sprintf('  [%s] ERROR: %s', $context, $result['error']);
            }
        }

        // If no changes were made, return original HTML to avoid DOMDocument artifacts
        if ($changes_made === 0) {
            return $html;
        }

        // Extract translated HTML from wrapper
        $root = $dom->getElementById($wrapper_id);
        if (!$root) {
            $this->debug_log[] = '  WARNING: Could not find wrapper, returning original';
            return $html;
        }

        $translated_html = '';
        foreach ($root->childNodes as $child) {
            $translated_html .= $dom->saveHTML($child);
        }

        return $translated_html;
    }

    /**
     * Translate block attributes
     */
    private function translate_block_attributes($attrs, $translator, $target_language, $source_language) {
        $translatable_attrs = array('alt', 'caption', 'citation', 'title', 'placeholder', 'label');

        foreach ($attrs as $key => $value) {
            if (in_array($key, $translatable_attrs) && is_string($value) && !empty(trim($value))) {
                $result = $translator->translate($value, $target_language, $source_language);

                if (!$result['error'] && !empty($result['translation'])) {
                    $attrs[$key] = $result['translation'];
                    $this->strings_translated++;
                }
            }
        }

        return $attrs;
    }

    /**
     * Translate title (simple string, no HTML)
     */
    public function translate_title($title, $target_language, $source_language = '') {
        if (empty($title)) {
            return array('title' => '', 'error' => null);
        }

        $translator = new WIT_Translator_Engine();
        $result = $translator->translate($title, $target_language, $source_language);

        return array(
            'title' => $result['error'] ? $title : $result['translation'],
            'error' => $result['error']
        );
    }

    /**
     * Translate excerpt (simple string)
     */
    public function translate_excerpt($excerpt, $target_language, $source_language = '') {
        if (empty($excerpt)) {
            return array('excerpt' => '', 'error' => null);
        }

        $translator = new WIT_Translator_Engine();
        $result = $translator->translate($excerpt, $target_language, $source_language);

        return array(
            'excerpt' => $result['error'] ? $excerpt : $result['translation'],
            'error' => $result['error']
        );
    }

    /**
     * Check if block should be skipped (no translatable content)
     */
    private function should_skip_block($block_name) {
        $skip_blocks = array(
            'core/code',
            'core/html',
            'core/shortcode',
            'core/separator',
            'core/spacer',
            'core/embed',
            'core/audio',
            'core/video',
            'core/file',
        );

        return in_array($block_name, $skip_blocks);
    }
}
