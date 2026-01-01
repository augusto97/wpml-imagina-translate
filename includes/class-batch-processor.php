<?php
/**
 * Batch Processor - Handles batch translation of multiple posts
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Batch_Processor {

    private $translation_manager;

    public function __construct() {
        $this->translation_manager = new WIT_Translation_Manager();
    }

    /**
     * Process batch translation
     *
     * @param array $post_ids Array of post IDs
     * @param string $target_language Target language code
     * @return array {processed: int, successful: int, failed: int, results: array}
     */
    public function process_batch($post_ids, $target_language) {
        $results = array(
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'results' => array(),
        );

        foreach ($post_ids as $post_id) {
            $result = $this->translation_manager->translate_post($post_id, $target_language);

            $results['processed']++;

            if ($result['success']) {
                $results['successful']++;
            } else {
                $results['failed']++;
            }

            $results['results'][] = array(
                'post_id' => $post_id,
                'success' => $result['success'],
                'message' => $result['message'],
                'translated_post_id' => $result['translated_post_id'],
            );
        }

        return $results;
    }

    /**
     * Process single item in batch (for AJAX)
     *
     * @param int $post_id
     * @param string $target_language
     * @return array
     */
    public function process_single($post_id, $target_language) {
        return $this->translation_manager->translate_post($post_id, $target_language);
    }
}
