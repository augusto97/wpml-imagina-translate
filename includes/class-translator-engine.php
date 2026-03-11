<?php
/**
 * Translator Engine - Handles AI API calls
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translator_Engine {

    private $settings;
    private $provider;
    private $api_key;
    private $model;

    public function __construct() {
        $settings_instance = WIT_Settings::instance();
        $this->settings = $settings_instance->get_settings();
        $this->provider = $this->settings['ai_provider'];

        // Set API credentials based on provider
        switch ($this->provider) {
            case 'openai':
                $this->api_key = $this->settings['openai_api_key'];
                $this->model = $this->settings['openai_model'];
                break;
            case 'claude':
                $this->api_key = $this->settings['claude_api_key'];
                $this->model = $this->settings['claude_model'];
                break;
            case 'gemini':
                $this->api_key = $this->settings['gemini_api_key'];
                $this->model = $this->settings['gemini_model'];
                break;
        }
    }

    /**
     * Translate text using configured AI provider
     *
     * @param string $text Text to translate
     * @param string $target_language Target language code
     * @param string $source_language Source language code (optional)
     * @return array {translation: string, error: string|null}
     */
    public function translate($text, $target_language, $source_language = '') {
        if (empty($this->api_key)) {
            return array(
                'translation' => '',
                'error' => __('API key no configurada', 'wpml-imagina-translate')
            );
        }

        if (empty($text)) {
            return array(
                'translation' => '',
                'error' => __('Texto vacío', 'wpml-imagina-translate')
            );
        }

        // Get language name from code
        $target_lang_name = $this->get_language_name($target_language);

        // Build prompt
        $prompt = str_replace('{target_language}', $target_lang_name, $this->settings['translation_prompt']);

        try {
            switch ($this->provider) {
                case 'openai':
                    return $this->translate_openai($text, $prompt);
                case 'claude':
                    return $this->translate_claude($text, $prompt);
                case 'gemini':
                    return $this->translate_gemini($text, $prompt);
                default:
                    return array(
                        'translation' => '',
                        'error' => __('Proveedor de IA no válido', 'wpml-imagina-translate')
                    );
            }
        } catch (Exception $e) {
            return array(
                'translation' => '',
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Translate using OpenAI API
     */
    private function translate_openai($text, $prompt) {
        return $this->call_openai_chat($text, $prompt, true);
    }

    private function call_openai_chat($text, $prompt, $with_temperature) {
        $body = array(
            'model'    => $this->model,
            'messages' => array(
                array('role' => 'system', 'content' => $prompt),
                array('role' => 'user',   'content' => $text),
            ),
        );

        if ($with_temperature) {
            $body['temperature'] = 0.3;
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => json_encode($body),
        ));

        if (is_wp_error($response)) {
            return array('translation' => '', 'error' => $response->get_error_message());
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('translation' => '', 'error' => 'OpenAI JSON error: ' . json_last_error_msg());
        }

        // If the model rejects temperature, retry once without it
        if (isset($decoded['error'])) {
            $msg = isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : '';
            if ($with_temperature && strpos($msg, 'temperature') !== false) {
                return $this->call_openai_chat($text, $prompt, false);
            }
            return array(
                'translation' => '',
                'error'       => $msg ?: __('Error de la API de OpenAI', 'wpml-imagina-translate'),
            );
        }

        if (!isset($decoded['choices'][0]['message']['content'])) {
            return array('translation' => '', 'error' => __('Respuesta inválida de OpenAI', 'wpml-imagina-translate'));
        }

        return array('translation' => trim($decoded['choices'][0]['message']['content']), 'error' => null);
    }

    /**
     * Translate using Claude API
     */
    private function translate_claude($text, $prompt) {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => json_encode(array(
                'model' => $this->model,
                'max_tokens' => 8000,
                'system' => $prompt,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $text
                    )
                ),
            )),
        ));

        if (is_wp_error($response)) {
            return array(
                'translation' => '',
                'error' => $response->get_error_message()
            );
        }

        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'translation' => '',
                'error' => 'Claude JSON error: ' . json_last_error_msg()
            );
        }

        if (isset($body['error'])) {
            return array(
                'translation' => '',
                'error' => $body['error']['message']
            );
        }

        if (!isset($body['content'][0]['text'])) {
            return array(
                'translation' => '',
                'error' => __('Respuesta inválida de Claude', 'wpml-imagina-translate')
            );
        }

        return array(
            'translation' => trim($body['content'][0]['text']),
            'error' => null
        );
    }

    /**
     * Translate using Gemini API
     */
    private function translate_gemini($text, $prompt) {
        $full_prompt = $prompt . "\n\n" . $text;

        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->api_key,
            array(
                'timeout' => 60,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'contents' => array(
                        array(
                            'parts' => array(
                                array('text' => $full_prompt)
                            )
                        )
                    ),
                    'generationConfig' => array(
                        'temperature' => 0.3,
                    )
                )),
            )
        );

        if (is_wp_error($response)) {
            return array(
                'translation' => '',
                'error' => $response->get_error_message()
            );
        }

        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'translation' => '',
                'error' => 'Gemini JSON error: ' . json_last_error_msg()
            );
        }

        if (isset($body['error'])) {
            return array(
                'translation' => '',
                'error' => $body['error']['message']
            );
        }

        if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            return array(
                'translation' => '',
                'error' => __('Respuesta inválida de Gemini', 'wpml-imagina-translate')
            );
        }

        return array(
            'translation' => trim($body['candidates'][0]['content']['parts'][0]['text']),
            'error' => null
        );
    }

    /**
     * Get language name from code
     */
    private function get_language_name($code) {
        $languages = array(
            'es' => 'Spanish',
            'en' => 'English',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'pt-br' => 'Brazilian Portuguese',
            'nl' => 'Dutch',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
            'ar' => 'Arabic',
            'pl' => 'Polish',
            'tr' => 'Turkish',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'el' => 'Greek',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'id' => 'Indonesian',
            'cs' => 'Czech',
            'ro' => 'Romanian',
            'hu' => 'Hungarian',
            'uk' => 'Ukrainian',
        );

        return isset($languages[$code]) ? $languages[$code] : ucfirst($code);
    }

    /**
     * Fetch available models from provider API
     *
     * Calls each provider's real /models endpoint so the list is always up to date.
     * Returns array of {id, name} objects sorted alphabetically.
     *
     * @param string $provider  'openai' | 'claude' | 'gemini'
     * @param string $api_key   API key for that provider
     * @return array {success: bool, models: array, error: string}
     */
    public static function fetch_models($provider, $api_key) {
        if (empty($api_key)) {
            return array('success' => false, 'models' => array(), 'error' => __('API key requerida', 'wpml-imagina-translate'));
        }

        switch ($provider) {
            case 'openai':
                return self::fetch_models_openai($api_key);
            case 'claude':
                return self::fetch_models_claude($api_key);
            case 'gemini':
                return self::fetch_models_gemini($api_key);
            default:
                return array('success' => false, 'models' => array(), 'error' => __('Proveedor no reconocido', 'wpml-imagina-translate'));
        }
    }

    private static function fetch_models_openai($api_key) {
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
            ),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'models' => array(), 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($body['data'])) {
            $msg = isset($body['error']['message']) ? $body['error']['message'] : __('Respuesta inválida de OpenAI', 'wpml-imagina-translate');
            return array('success' => false, 'models' => array(), 'error' => $msg);
        }

        // Exclude models that clearly cannot do text chat/completion.
        // Everything else is shown — this avoids missing new models OpenAI releases.
        $exclude_patterns = array(
            'embedding', 'embed',
            'whisper',
            'dall-e', 'dalle',
            'tts',
            'transcribe',
            'image',
            'moderation',
            'text-davinci-edit',
            'text-similarity',
            'text-search',
            'code-search',
        );

        $models = array();
        foreach ($body['data'] as $model) {
            $id    = $model['id'];
            $lower = strtolower($id);

            $excluded = false;
            foreach ($exclude_patterns as $pattern) {
                if (strpos($lower, $pattern) !== false) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) continue;

            $models[] = array('id' => $id, 'name' => $id);
        }

        usort($models, function($a, $b) { return strcmp($a['id'], $b['id']); });

        return array('success' => true, 'models' => $models, 'error' => null);
    }

    private static function fetch_models_claude($api_key) {
        $response = wp_remote_get('https://api.anthropic.com/v1/models', array(
            'timeout' => 15,
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'models' => array(), 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($body['data'])) {
            $msg = isset($body['error']['message']) ? $body['error']['message'] : __('Respuesta inválida de Anthropic', 'wpml-imagina-translate');
            return array('success' => false, 'models' => array(), 'error' => $msg);
        }

        $models = array();
        foreach ($body['data'] as $model) {
            $models[] = array(
                'id'   => $model['id'],
                'name' => isset($model['display_name']) ? $model['display_name'] : $model['id'],
            );
        }

        usort($models, function($a, $b) { return strcmp($a['id'], $b['id']); });

        return array('success' => true, 'models' => $models, 'error' => null);
    }

    private static function fetch_models_gemini($api_key) {
        $response = wp_remote_get(
            'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key,
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            return array('success' => false, 'models' => array(), 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($body['models'])) {
            $msg = isset($body['error']['message']) ? $body['error']['message'] : __('Respuesta inválida de Gemini', 'wpml-imagina-translate');
            return array('success' => false, 'models' => array(), 'error' => $msg);
        }

        $models = array();
        foreach ($body['models'] as $model) {
            // Only include models that support generateContent (text generation)
            $methods = isset($model['supportedGenerationMethods']) ? $model['supportedGenerationMethods'] : array();
            if (!in_array('generateContent', $methods)) continue;

            // model name is like "models/gemini-2.5-flash" — extract just the ID
            $id = isset($model['name']) ? str_replace('models/', '', $model['name']) : '';
            if (empty($id)) continue;

            $models[] = array(
                'id'   => $id,
                'name' => isset($model['displayName']) ? $model['displayName'] : $id,
            );
        }

        usort($models, function($a, $b) { return strcmp($a['id'], $b['id']); });

        return array('success' => true, 'models' => $models, 'error' => null);
    }

    /**
     * Translate a batch of texts in a SINGLE API call.
     *
     * Sends all texts as a numbered list and parses the numbered response.
     * Falls back to individual translate() calls if parsing fails.
     *
     * @param string[] $texts           Indexed array of strings to translate.
     * @param string   $target_language Target language code.
     * @param string   $source_language Source language code (optional).
     * @return array   Indexed array (same length as $texts), each element:
     *                 {translation: string, error: string|null}
     */
    public function translate_batch($texts, $target_language, $source_language = '') {
        if (empty($texts)) {
            return array();
        }

        if (empty($this->api_key)) {
            $err = __('API key no configurada', 'wpml-imagina-translate');
            return array_fill(0, count($texts), array('translation' => '', 'error' => $err));
        }

        $target_lang_name = $this->get_language_name($target_language);

        // Split into chunks of 40 to stay within token limits
        $chunk_size  = 40;
        $chunks      = array_chunk($texts, $chunk_size, true); // preserve keys
        $results     = array();

        foreach ($chunks as $chunk) {
            $chunk_results = $this->translate_batch_chunk($chunk, $target_lang_name, $source_language);
            $results = $results + $chunk_results; // merge preserving numeric keys
        }

        // Ensure every index exists in results
        $out = array();
        foreach ($texts as $i => $text) {
            $out[$i] = isset($results[$i]) ? $results[$i] : array('translation' => '', 'error' => 'Missing response');
        }

        return $out;
    }

    /**
     * Translate one chunk (up to 40 texts) in a single API call.
     *
     * @param  array  $chunk           Slice of texts with their original indices.
     * @param  string $target_lang_name Human-readable target language name.
     * @param  string $source_language  Source language code.
     * @return array  Same keys as $chunk, each value: {translation, error}
     */
    private function translate_batch_chunk($chunk, $target_lang_name, $source_language) {
        // Build numbered list: "1. text\n2. text\n..."
        $keys      = array_keys($chunk);
        $numbered  = array();
        foreach ($chunk as $i => $text) {
            $pos        = array_search($i, $keys) + 1; // 1-based position in this chunk
            $numbered[] = $pos . '. ' . $text;
        }
        $input_text = implode("\n", $numbered);

        $prompt = 'You are a professional translator. '
                . 'Translate each numbered item below into ' . $target_lang_name . '. '
                . 'Return ONLY the numbered translations in the SAME order and numbering. '
                . 'Do NOT add explanations, notes, or extra text. '
                . 'Keep any HTML tags, special characters, or markup exactly as they appear. '
                . 'Format: "1. [translation]\n2. [translation]\n..."';

        try {
            switch ($this->provider) {
                case 'openai':
                    $raw = $this->call_openai_chat($input_text, $prompt, true);
                    break;
                case 'claude':
                    $raw = $this->translate_claude($input_text, $prompt);
                    break;
                case 'gemini':
                    $raw = $this->translate_gemini($input_text, $prompt);
                    break;
                default:
                    $raw = array('translation' => '', 'error' => __('Proveedor de IA no válido', 'wpml-imagina-translate'));
            }
        } catch (Exception $e) {
            $raw = array('translation' => '', 'error' => $e->getMessage());
        }

        // If the API call itself failed, propagate error to all items in this chunk
        if (!empty($raw['error']) || empty($raw['translation'])) {
            $err = isset($raw['error']) ? $raw['error'] : 'Empty response';
            $out = array();
            foreach ($keys as $i) {
                $out[$i] = array('translation' => '', 'error' => $err);
            }
            return $out;
        }

        // Parse numbered response back to per-item translations
        $parsed = $this->parse_numbered_response($raw['translation'], count($chunk));

        $out = array();
        foreach ($keys as $pos_index => $original_index) {
            $pos = $pos_index + 1; // 1-based
            if (isset($parsed[$pos]) && !empty($parsed[$pos])) {
                $out[$original_index] = array('translation' => $parsed[$pos], 'error' => null);
            } else {
                $out[$original_index] = array('translation' => '', 'error' => 'Parse error for item ' . $pos);
            }
        }

        return $out;
    }

    /**
     * Parse a numbered-list API response into an associative array.
     *
     * Handles various formats:
     *   "1. text"  "1) text"  "1: text"  or just "text" on its own line if count === 1
     *
     * @param  string $response      Raw text from the AI.
     * @param  int    $expected_count Expected number of items.
     * @return array  1-based index => translation string
     */
    private function parse_numbered_response($response, $expected_count) {
        $result = array();
        $lines  = explode("\n", trim($response));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Match "1. text", "1) text", "1: text"
            if (preg_match('/^(\d+)[.):\s]\s*(.+)$/su', $line, $m)) {
                $num = (int) $m[1];
                $translation = trim($m[2]);
                if ($num >= 1 && !empty($translation)) {
                    $result[$num] = $translation;
                }
            }
        }

        // If the AI returned items without numbers but count matches, assign sequentially
        if (empty($result) && $expected_count === 1 && !empty($response)) {
            $result[1] = trim($response);
        }

        return $result;
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        $result = $this->translate('Hello', 'es');

        if ($result['error']) {
            return array(
                'success' => false,
                'message' => $result['error']
            );
        }

        return array(
            'success' => true,
            'message' => __('Conexión exitosa', 'wpml-imagina-translate'),
            'translation' => $result['translation']
        );
    }
}
