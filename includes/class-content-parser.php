<?php
/**
 * Content Parser - Handles Gutenberg blocks and content structure
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Content_Parser {

    /**
     * Parse and translate Gutenberg content
     *
     * @param string $content Post content
     * @param string $target_language Target language code
     * @return array {content: string, error: string|null}
     */
    public function translate_content($content, $target_language, $source_language = '') {
        if (empty($content)) {
            return array(
                'content' => '',
                'error' => null
            );
        }

        // Check if content has Gutenberg blocks
        if (has_blocks($content)) {
            return $this->translate_blocks($content, $target_language, $source_language);
        } else {
            // Classic editor content
            return $this->translate_classic_content($content, $target_language, $source_language);
        }
    }

    /**
     * Translate Gutenberg blocks
     */
    private function translate_blocks($content, $target_language, $source_language) {
        $blocks = parse_blocks($content);
        $translated_blocks = array();
        $translator = new WIT_Translator_Engine();

        foreach ($blocks as $block) {
            $translated_block = $this->translate_block($block, $translator, $target_language, $source_language);
            $translated_blocks[] = $translated_block;
        }

        // Rebuild content from blocks
        $translated_content = '';
        foreach ($translated_blocks as $block) {
            $translated_content .= serialize_block($block);
        }

        return array(
            'content' => $translated_content,
            'error' => null
        );
    }

    /**
     * Translate single block recursively
     */
    private function translate_block($block, $translator, $target_language, $source_language) {
        if (empty($block['blockName'])) {
            // HTML or plain text block
            if (!empty($block['innerHTML'])) {
                $result = $translator->translate($block['innerHTML'], $target_language, $source_language);
                if (!$result['error'] && !empty($result['translation'])) {
                    $block['innerHTML'] = $result['translation'];
                }
            }
            return $block;
        }

        // Skip blocks that shouldn't be translated
        if ($this->should_skip_block($block['blockName'])) {
            return $block;
        }

        // Translate block attributes
        if (!empty($block['attrs'])) {
            $block['attrs'] = $this->translate_block_attributes($block['attrs'], $translator, $target_language, $source_language);
        }

        // Translate inner HTML
        if (!empty($block['innerHTML'])) {
            // Extract translatable text from HTML
            $translatable_text = $this->extract_translatable_text($block['innerHTML']);

            if (!empty($translatable_text)) {
                $result = $translator->translate($translatable_text, $target_language, $source_language);

                if (!$result['error'] && !empty($result['translation'])) {
                    $block['innerHTML'] = $this->replace_translatable_text(
                        $block['innerHTML'],
                        $translatable_text,
                        $result['translation']
                    );
                }
            }
        }

        // Translate inner blocks recursively
        if (!empty($block['innerBlocks'])) {
            $translated_inner_blocks = array();
            foreach ($block['innerBlocks'] as $inner_block) {
                $translated_inner_blocks[] = $this->translate_block($inner_block, $translator, $target_language, $source_language);
            }
            $block['innerBlocks'] = $translated_inner_blocks;
        }

        // Rebuild inner content
        if (!empty($block['innerBlocks'])) {
            $block['innerContent'] = array();
            $block['innerContent'][] = $block['innerHTML'];
        }

        return $block;
    }

    /**
     * Check if block should be skipped
     */
    private function should_skip_block($block_name) {
        $skip_blocks = array(
            'core/code',
            'core/preformatted',
            'core/html',
            'core/shortcode',
            'core/embed',
            'core/separator',
            'core/spacer',
        );

        return in_array($block_name, $skip_blocks);
    }

    /**
     * Translate block attributes
     */
    private function translate_block_attributes($attrs, $translator, $target_language, $source_language) {
        $translatable_attrs = array('content', 'text', 'title', 'caption', 'citation', 'value', 'placeholder', 'label');

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
     * Extract translatable text from HTML
     */
    private function extract_translatable_text($html) {
        // Remove HTML tags but preserve structure for later replacement
        $text = strip_tags($html);
        $text = trim($text);

        // Don't translate if only whitespace or very short
        if (strlen($text) < 2) {
            return '';
        }

        return $text;
    }

    /**
     * Replace translatable text in HTML
     */
    private function replace_translatable_text($html, $original_text, $translated_text) {
        // Simple replacement - preserves HTML structure
        $original_stripped = strip_tags($original_text);
        $html_replaced = str_replace($original_stripped, $translated_text, $html);

        return $html_replaced;
    }

    /**
     * Translate classic editor content
     */
    private function translate_classic_content($content, $target_language, $source_language) {
        $translator = new WIT_Translator_Engine();

        // Split content by paragraphs to translate in chunks
        $paragraphs = array_filter(explode("\n\n", $content));
        $translated_paragraphs = array();

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if (empty($paragraph)) {
                $translated_paragraphs[] = $paragraph;
                continue;
            }

            // Skip shortcodes and HTML comments
            if ($this->is_shortcode_or_comment($paragraph)) {
                $translated_paragraphs[] = $paragraph;
                continue;
            }

            $result = $translator->translate($paragraph, $target_language, $source_language);

            if (!$result['error'] && !empty($result['translation'])) {
                $translated_paragraphs[] = $result['translation'];
            } else {
                $translated_paragraphs[] = $paragraph; // Keep original on error
            }
        }

        return array(
            'content' => implode("\n\n", $translated_paragraphs),
            'error' => null
        );
    }

    /**
     * Check if content is shortcode or HTML comment
     */
    private function is_shortcode_or_comment($text) {
        // Check for shortcodes
        if (preg_match('/^\[.*\]$/', $text)) {
            return true;
        }

        // Check for HTML comments
        if (preg_match('/^<!--.*-->$/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Translate post title
     */
    public function translate_title($title, $target_language, $source_language = '') {
        if (empty($title)) {
            return array(
                'title' => '',
                'error' => null
            );
        }

        $translator = new WIT_Translator_Engine();
        $result = $translator->translate($title, $target_language, $source_language);

        return array(
            'title' => $result['translation'],
            'error' => $result['error']
        );
    }

    /**
     * Translate post excerpt
     */
    public function translate_excerpt($excerpt, $target_language, $source_language = '') {
        if (empty($excerpt)) {
            return array(
                'excerpt' => '',
                'error' => null
            );
        }

        $translator = new WIT_Translator_Engine();
        $result = $translator->translate($excerpt, $target_language, $source_language);

        return array(
            'excerpt' => $result['translation'],
            'error' => $result['error']
        );
    }
}
