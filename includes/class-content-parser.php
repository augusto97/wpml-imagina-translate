<?php
/**
 * Content Parser V2 - Safe text extraction and replacement
 * Uses DOMDocument for proper HTML parsing
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Content_Parser {

    private $debug_log = array();
    private $extracted_strings = array();

    /**
     * Extract all translatable strings from content
     * Returns array of strings WITHOUT translating
     *
     * @param string $content
     * @return array {strings: array, blocks_info: array}
     */
    public function extract_translatable_strings($content) {
        $this->extracted_strings = array();
        $this->debug_log = array();

        if (empty($content)) {
            return array(
                'strings' => array(),
                'blocks_info' => array(),
                'debug' => array('Content is empty')
            );
        }

        $this->debug_log[] = 'Content length: ' . strlen($content) . ' characters';

        // Check if Gutenberg
        if (has_blocks($content)) {
            $this->debug_log[] = 'Detected Gutenberg blocks';
            return $this->extract_from_gutenberg($content);
        } else {
            $this->debug_log[] = 'Classic editor content';
            return $this->extract_from_html($content);
        }
    }

    /**
     * Extract strings from Gutenberg blocks
     */
    private function extract_from_gutenberg($content) {
        $blocks = parse_blocks($content);
        $strings = array();
        $blocks_info = array();

        $this->debug_log[] = 'Found ' . count($blocks) . ' blocks';

        foreach ($blocks as $index => $block) {
            $block_name = $block['blockName'] ?? 'freeform';

            // Skip non-translatable blocks
            if ($this->should_skip_block($block_name)) {
                $this->debug_log[] = 'Skipping block: ' . $block_name;
                continue;
            }

            $block_strings = $this->extract_from_block($block, $index);

            if (!empty($block_strings)) {
                $blocks_info[] = array(
                    'index' => $index,
                    'name' => $block_name,
                    'string_count' => count($block_strings),
                );

                $strings = array_merge($strings, $block_strings);
            }
        }

        $this->debug_log[] = 'Extracted ' . count($strings) . ' translatable strings';

        return array(
            'strings' => $strings,
            'blocks_info' => $blocks_info,
            'debug' => $this->debug_log,
            'original_content' => $content,
            'blocks' => $blocks
        );
    }

    /**
     * Extract strings from a single block
     */
    private function extract_from_block($block, $block_index) {
        $strings = array();

        // Extract from block attributes (like alt text, captions, etc.)
        if (!empty($block['attrs'])) {
            $translatable_attrs = array('alt', 'caption', 'citation', 'title', 'placeholder', 'label', 'value');

            foreach ($block['attrs'] as $attr_name => $attr_value) {
                if (in_array($attr_name, $translatable_attrs) && is_string($attr_value) && !empty(trim($attr_value))) {
                    $strings[] = array(
                        'text' => $attr_value,
                        'context' => 'block_' . $block_index . '_attr_' . $attr_name,
                        'type' => 'attribute',
                        'block_name' => $block['blockName'] ?? 'freeform'
                    );
                }
            }
        }

        // Extract from innerHTML using DOMDocument
        if (!empty($block['innerHTML'])) {
            $html_strings = $this->extract_text_nodes_from_html($block['innerHTML'], 'block_' . $block_index);
            $strings = array_merge($strings, $html_strings);
        }

        // Recursive: extract from inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner_index => $inner_block) {
                $inner_strings = $this->extract_from_block($inner_block, $block_index . '_inner_' . $inner_index);
                $strings = array_merge($strings, $inner_strings);
            }
        }

        return $strings;
    }

    /**
     * Extract text nodes from HTML using DOMDocument
     */
    private function extract_text_nodes_from_html($html, $context_prefix) {
        $strings = array();

        if (empty(trim(strip_tags($html)))) {
            return $strings;
        }

        // Debug: log original HTML
        $this->debug_log[] = 'Extracting from HTML: ' . mb_substr($html, 0, 200);

        // Use DOMDocument for proper HTML parsing
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Extract all text nodes (excluding script, style, code)
        $text_nodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::code)]');

        $this->debug_log[] = 'Found ' . $text_nodes->length . ' text nodes';

        foreach ($text_nodes as $node_index => $text_node) {
            $text = trim($text_node->nodeValue);

            // Skip empty or very short strings
            if (strlen($text) < 2) {
                $this->debug_log[] = '  Skipping short text: "' . $text . '"';
                continue;
            }

            // Skip if it's just numbers or symbols
            if (preg_match('/^[\d\s\p{P}]+$/u', $text)) {
                $this->debug_log[] = '  Skipping symbols/numbers: "' . $text . '"';
                continue;
            }

            $this->debug_log[] = '  Extracted text node #' . $node_index . ': "' . mb_substr($text, 0, 100) . '"';

            $strings[] = array(
                'text' => $text,
                'context' => $context_prefix . '_text_' . $node_index,
                'type' => 'text_node',
                'block_name' => $context_prefix
            );
        }

        return $strings;
    }

    /**
     * Extract from classic HTML content
     */
    private function extract_from_html($content) {
        $strings = $this->extract_text_nodes_from_html($content, 'classic');

        return array(
            'strings' => $strings,
            'blocks_info' => array(),
            'debug' => $this->debug_log,
            'original_content' => $content,
            'blocks' => null
        );
    }

    /**
     * Translate content using extracted strings and their translations
     *
     * @param array $extraction_result Result from extract_translatable_strings()
     * @param array $translations Array of translated strings (same order as extracted)
     * @return array {content: string, error: string|null}
     */
    public function apply_translations($extraction_result, $translations) {
        $original_content = $extraction_result['original_content'];
        $strings = $extraction_result['strings'];

        if (count($strings) !== count($translations)) {
            return array(
                'content' => $original_content,
                'error' => 'Mismatch between extracted strings and translations count'
            );
        }

        $translated_content = $original_content;

        // Simple replacement strategy: replace each original string with translation
        // Going in reverse order to avoid position shifts
        for ($i = count($strings) - 1; $i >= 0; $i--) {
            $original_text = $strings[$i]['text'];
            $translated_text = $translations[$i];

            if (!empty($translated_text) && $translated_text !== $original_text) {
                $translated_content = str_replace($original_text, $translated_text, $translated_content);
            }
        }

        return array(
            'content' => $translated_content,
            'error' => null
        );
    }

    /**
     * Complete translation workflow
     */
    public function translate_content($content, $target_language, $source_language = '') {
        // Step 1: Extract strings
        $extraction = $this->extract_translatable_strings($content);

        if (empty($extraction['strings'])) {
            return array(
                'content' => $content,
                'error' => null,
                'debug' => array_merge($extraction['debug'], array('No translatable strings found'))
            );
        }

        // Step 2: Translate each string
        $translator = new WIT_Translator_Engine();
        $translations = array();
        $errors = array();

        $this->debug_log[] = '=== TRANSLATING ' . count($extraction['strings']) . ' STRINGS ===';

        foreach ($extraction['strings'] as $index => $string_data) {
            $original_text = $string_data['text'];
            $this->debug_log[] = sprintf('[%d] SENDING TO AI: "%s"', $index + 1, mb_substr($original_text, 0, 100));

            $result = $translator->translate($original_text, $target_language, $source_language);

            if ($result['error']) {
                $errors[] = 'Error translating string ' . ($index + 1) . ': ' . $result['error'];
                $translations[] = $original_text; // Keep original on error
                $this->debug_log[] = sprintf('[%d] ERROR: %s', $index + 1, $result['error']);
            } else {
                $translations[] = $result['translation'];
                $this->debug_log[] = sprintf('[%d] AI RESPONSE: "%s"', $index + 1, mb_substr($result['translation'], 0, 100));
            }
        }

        // Step 3: Apply translations
        $final = $this->apply_translations($extraction, $translations);

        return array(
            'content' => $final['content'],
            'error' => !empty($errors) ? implode('; ', $errors) : $final['error'],
            'debug' => array_merge(
                $extraction['debug'],
                array(
                    'Strings translated: ' . count($translations),
                    'Errors: ' . count($errors)
                )
            ),
            'extracted_strings' => $extraction['strings'],
            'translations' => $translations
        );
    }

    /**
     * Translate title (simple string)
     */
    public function translate_title($title, $target_language, $source_language = '') {
        if (empty($title)) {
            return array('title' => '', 'error' => null);
        }

        $translator = new WIT_Translator_Engine();
        $result = $translator->translate($title, $target_language, $source_language);

        return array(
            'title' => $result['translation'],
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
            'excerpt' => $result['translation'],
            'error' => $result['error']
        );
    }

    /**
     * Check if block should be skipped
     */
    private function should_skip_block($block_name) {
        $skip_blocks = array(
            'core/code',
            'core/html',
            'core/shortcode',
            'core/separator',
            'core/spacer',
            'core/embed',
        );

        return in_array($block_name, $skip_blocks);
    }
}
