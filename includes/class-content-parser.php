<?php
/**
 * Content Parser V3 - DOM-based text node replacement
 * Uses DOMDocument to replace text nodes directly without str_replace
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Content_Parser {

    private $debug_log = array();

    /**
     * Translate content using DOM manipulation
     * This avoids str_replace issues and HTML escaping
     */
    public function translate_content($content, $target_language, $source_language = '') {
        $this->debug_log = array();

        if (empty($content)) {
            return array(
                'content' => '',
                'error' => null,
                'debug' => array('Content is empty')
            );
        }

        $this->debug_log[] = 'Content length: ' . strlen($content) . ' characters';

        // Check if Gutenberg
        if (has_blocks($content)) {
            $this->debug_log[] = 'Detected Gutenberg blocks';
            return $this->translate_gutenberg_blocks($content, $target_language, $source_language);
        } else {
            $this->debug_log[] = 'Classic editor content';
            return $this->translate_html_content($content, $target_language, $source_language);
        }
    }

    /**
     * Translate Gutenberg blocks
     */
    private function translate_gutenberg_blocks($content, $target_language, $source_language) {
        $blocks = parse_blocks($content);
        $this->debug_log[] = 'Found ' . count($blocks) . ' blocks';

        $translator = new WIT_Translator_Engine();
        $translated_content = '';
        $total_strings = 0;

        foreach ($blocks as $index => $block) {
            $block_name = $block['blockName'] ?? 'freeform';

            // Skip non-translatable blocks
            if ($this->should_skip_block($block_name)) {
                $this->debug_log[] = 'Skipping block: ' . $block_name;
                $translated_content .= serialize_block($block);
                continue;
            }

            // Translate block innerHTML using DOM
            if (!empty($block['innerHTML'])) {
                $result = $this->translate_html_dom($block['innerHTML'], $translator, $target_language, $source_language);

                if (!$result['error']) {
                    $block['innerHTML'] = $result['html'];
                    $total_strings += $result['strings_count'];

                    $this->debug_log[] = sprintf(
                        'Block %d (%s): Translated %d strings',
                        $index + 1,
                        $block_name,
                        $result['strings_count']
                    );
                }
            }

            // Translate block attributes
            if (!empty($block['attrs'])) {
                $block['attrs'] = $this->translate_block_attributes($block['attrs'], $translator, $target_language, $source_language);
            }

            // Rebuild block
            $translated_content .= serialize_block($block);
        }

        $this->debug_log[] = 'Total strings translated: ' . $total_strings;

        return array(
            'content' => $translated_content,
            'error' => null,
            'debug' => $this->debug_log
        );
    }

    /**
     * Translate HTML content using DOM manipulation
     * This is the KEY function that prevents HTML from being damaged
     */
    private function translate_html_dom($html, $translator, $target_language, $source_language) {
        if (empty(trim(strip_tags($html)))) {
            return array(
                'html' => $html,
                'error' => null,
                'strings_count' => 0
            );
        }

        // Parse HTML with DOMDocument
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Preserve encoding and avoid adding extra tags
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Find all text nodes (excluding script, style, code)
        $text_nodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::code)]');

        $strings_count = 0;

        // Translate each text node DIRECTLY in the DOM
        foreach ($text_nodes as $text_node) {
            $text = trim($text_node->nodeValue);

            // Skip empty, very short, or non-text content
            if (strlen($text) < 2) {
                continue;
            }

            // Skip if it's just numbers, symbols, or &nbsp;
            if (preg_match('/^[\d\s\p{P}&;]+$/u', $text)) {
                continue;
            }

            // Translate this text node
            $result = $translator->translate($text, $target_language, $source_language);

            if (!$result['error'] && !empty($result['translation'])) {
                // DIRECTLY replace the text node value
                // This preserves ALL HTML structure
                $text_node->nodeValue = $result['translation'];
                $strings_count++;

                $this->debug_log[] = sprintf(
                    '  [%d] "%s" → "%s"',
                    $strings_count,
                    mb_substr($text, 0, 50) . (mb_strlen($text) > 50 ? '...' : ''),
                    mb_substr($result['translation'], 0, 50) . (mb_strlen($result['translation']) > 50 ? '...' : '')
                );
            } else if ($result['error']) {
                $this->debug_log[] = '  ERROR: ' . $result['error'];
            }
        }

        // Serialize back to HTML
        $translated_html = $dom->saveHTML();

        // Remove XML encoding declaration that DOMDocument adds
        $translated_html = preg_replace('/^<\?xml[^>]+>\s*/i', '', $translated_html);

        return array(
            'html' => $translated_html,
            'error' => null,
            'strings_count' => $strings_count
        );
    }

    /**
     * Translate classic HTML content
     */
    private function translate_html_content($content, $target_language, $source_language) {
        $translator = new WIT_Translator_Engine();
        $result = $this->translate_html_dom($content, $translator, $target_language, $source_language);

        return array(
            'content' => $result['html'],
            'error' => $result['error'],
            'debug' => $this->debug_log
        );
    }

    /**
     * Translate block attributes
     */
    private function translate_block_attributes($attrs, $translator, $target_language, $source_language) {
        $translatable_attrs = array('alt', 'caption', 'citation', 'title', 'placeholder', 'label', 'value');

        foreach ($attrs as $key => $value) {
            if (in_array($key, $translatable_attrs) && is_string($value) && !empty($value)) {
                $result = $translator->translate($value, $target_language, $source_language);

                if (!$result['error'] && !empty($result['translation'])) {
                    $attrs[$key] = $result['translation'];
                }
            }
        }

        return $attrs;
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
