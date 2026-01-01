<?php
/**
 * Content Parser - Handles content translation with proper structure preservation
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Content_Parser {

    private $debug_log = array();

    /**
     * Translate content - simple and effective approach
     * Let the AI handle HTML preservation instead of trying to be too smart
     *
     * @param string $content Post content
     * @param string $target_language Target language code
     * @param string $source_language Source language code
     * @return array {content: string, error: string|null, debug: array}
     */
    public function translate_content($content, $target_language, $source_language = '') {
        if (empty($content)) {
            return array(
                'content' => '',
                'error' => null,
                'debug' => array('message' => 'Content is empty')
            );
        }

        $this->debug_log[] = 'Original content length: ' . strlen($content) . ' characters';
        $this->debug_log[] = 'Has Gutenberg blocks: ' . (has_blocks($content) ? 'Yes' : 'No');

        $translator = new WIT_Translator_Engine();

        // Strategy: Translate the ENTIRE content as-is
        // The AI is smart enough to preserve HTML, Gutenberg blocks, and structure
        // We just need to chunk it if it's too long

        $max_chunk_size = 15000; // Characters per chunk (safe for most APIs)

        if (strlen($content) > $max_chunk_size) {
            $this->debug_log[] = 'Content is long, splitting into chunks';
            return $this->translate_in_chunks($content, $target_language, $source_language, $max_chunk_size);
        }

        // Translate entire content in one go
        $this->debug_log[] = 'Translating entire content in single request';

        $result = $translator->translate($content, $target_language, $source_language);

        if ($result['error']) {
            $this->debug_log[] = 'ERROR: ' . $result['error'];
            return array(
                'content' => '',
                'error' => $result['error'],
                'debug' => $this->debug_log
            );
        }

        $this->debug_log[] = 'Translation successful';
        $this->debug_log[] = 'Translated content length: ' . strlen($result['translation']) . ' characters';

        return array(
            'content' => $result['translation'],
            'error' => null,
            'debug' => $this->debug_log
        );
    }

    /**
     * Translate content in chunks for long content
     */
    private function translate_in_chunks($content, $target_language, $source_language, $chunk_size) {
        $translator = new WIT_Translator_Engine();

        // For Gutenberg, split by blocks
        if (has_blocks($content)) {
            return $this->translate_blocks_chunked($content, $target_language, $source_language);
        }

        // For classic editor, split by paragraphs
        $paragraphs = preg_split('/\n\n+/', $content);
        $chunks = array();
        $current_chunk = '';

        foreach ($paragraphs as $paragraph) {
            if (strlen($current_chunk) + strlen($paragraph) > $chunk_size) {
                if (!empty($current_chunk)) {
                    $chunks[] = $current_chunk;
                    $current_chunk = '';
                }
            }
            $current_chunk .= $paragraph . "\n\n";
        }

        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }

        $this->debug_log[] = 'Split into ' . count($chunks) . ' chunks';

        // Translate each chunk
        $translated_chunks = array();
        foreach ($chunks as $index => $chunk) {
            $this->debug_log[] = 'Translating chunk ' . ($index + 1) . '/' . count($chunks);

            $result = $translator->translate(trim($chunk), $target_language, $source_language);

            if ($result['error']) {
                $this->debug_log[] = 'ERROR in chunk ' . ($index + 1) . ': ' . $result['error'];
                // Keep original chunk on error
                $translated_chunks[] = $chunk;
            } else {
                $translated_chunks[] = $result['translation'];
            }
        }

        return array(
            'content' => implode("\n\n", $translated_chunks),
            'error' => null,
            'debug' => $this->debug_log
        );
    }

    /**
     * Translate Gutenberg blocks in chunks
     */
    private function translate_blocks_chunked($content, $target_language, $source_language) {
        $translator = new WIT_Translator_Engine();
        $blocks = parse_blocks($content);

        $this->debug_log[] = 'Found ' . count($blocks) . ' Gutenberg blocks';

        $translated_blocks = array();

        foreach ($blocks as $index => $block) {
            $this->debug_log[] = 'Processing block ' . ($index + 1) . ': ' . ($block['blockName'] ?? 'freeform');

            // Skip empty blocks
            if (empty($block['blockName']) && empty($block['innerHTML'])) {
                $translated_blocks[] = $block;
                continue;
            }

            // Skip blocks that shouldn't be translated
            if (!empty($block['blockName']) && $this->should_skip_block($block['blockName'])) {
                $this->debug_log[] = 'Skipping block: ' . $block['blockName'];
                $translated_blocks[] = $block;
                continue;
            }

            // Serialize the block back to HTML
            $block_html = serialize_block($block);

            // Translate the entire block as HTML
            $result = $translator->translate($block_html, $target_language, $source_language);

            if ($result['error']) {
                $this->debug_log[] = 'ERROR translating block: ' . $result['error'];
                // Keep original on error
                $translated_blocks[] = $block;
            } else {
                // Parse the translated HTML back into a block
                $translated_block_array = parse_blocks($result['translation']);
                if (!empty($translated_block_array[0])) {
                    $translated_blocks[] = $translated_block_array[0];
                } else {
                    // Fallback: keep original block
                    $translated_blocks[] = $block;
                }
            }
        }

        // Rebuild content
        $translated_content = '';
        foreach ($translated_blocks as $block) {
            $translated_content .= serialize_block($block);
        }

        return array(
            'content' => $translated_content,
            'error' => null,
            'debug' => $this->debug_log
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
        );

        return in_array($block_name, $skip_blocks);
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

    /**
     * Get debug log
     */
    public function get_debug_log() {
        return $this->debug_log;
    }
}
