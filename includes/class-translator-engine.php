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
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => json_encode(array(
                'model' => $this->model,
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => $prompt
                    ),
                    array(
                        'role' => 'user',
                        'content' => $text
                    )
                ),
                'temperature' => 0.3,
            )),
        ));

        if (is_wp_error($response)) {
            return array(
                'translation' => '',
                'error' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return array(
                'translation' => '',
                'error' => $body['error']['message']
            );
        }

        if (!isset($body['choices'][0]['message']['content'])) {
            return array(
                'translation' => '',
                'error' => __('Respuesta inválida de OpenAI', 'wpml-imagina-translate')
            );
        }

        return array(
            'translation' => trim($body['choices'][0]['message']['content']),
            'error' => null
        );
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

        $body = json_decode(wp_remote_retrieve_body($response), true);

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

        $body = json_decode(wp_remote_retrieve_body($response), true);

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
