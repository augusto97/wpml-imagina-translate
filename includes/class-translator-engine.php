<?php
/**
 * Translator Engine - Handles AI API calls.
 *
 * Batch protocol
 * --------------
 * The previous implementation sent batches as a numbered list ("1. text") and
 * parsed the reply line by line. That protocol silently corrupted content:
 *
 *   - A source string containing a newline produced more lines than items, so
 *     every subsequent translation was assigned to the WRONG source string.
 *   - A translation spanning several lines was truncated to its first line.
 *   - A translation beginning with digits ("2024 was...") was parsed as item
 *     number 2024 and discarded.
 *
 * Items are now delimited by explicit markers on their own line:
 *
 *   [[[1]]]
 *   first source string
 *   possibly spanning lines
 *   [[[2]]]
 *   second source string
 *
 * Parsing splits on the marker, so multi-line values round-trip intact, order
 * does not matter, and a missing item is detected instead of silently shifting
 * every following translation.
 *
 * Reliability
 * -----------
 * Requests are retried with exponential backoff on 429 and 5xx responses, and
 * any item the model fails to return is retried individually before being
 * reported as an error.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translator_Engine {

    /** Maximum characters of source text per batch request. */
    const MAX_CHUNK_CHARS = 12000;

    /** Maximum number of items per batch request. */
    const MAX_CHUNK_ITEMS = 40;

    /** Number of attempts for a transient API failure. */
    const MAX_RETRIES = 3;

    private $settings;
    private $provider;
    private $api_key;
    private $model;

    /** @var WIT_Glossary|null Lazily built. */
    private $glossary = null;

    /**
     * Where the translations of the last call came from.
     *
     * @var array{glossary:int,memory:int,api:int,input_tokens:int,output_tokens:int}
     */
    private $usage = array(
        'glossary'      => 0,
        'memory'        => 0,
        'api'           => 0,
        'input_tokens'  => 0,
        'output_tokens' => 0,
    );

    public function __construct() {
        $this->settings = WIT_Settings::instance()->get_settings();
        $this->provider = $this->settings['ai_provider'];

        switch ($this->provider) {
            case 'openai':
                $this->api_key = $this->settings['openai_api_key'];
                $this->model   = $this->settings['openai_model'];
                break;
            case 'claude':
                $this->api_key = $this->settings['claude_api_key'];
                $this->model   = $this->settings['claude_model'];
                break;
            case 'gemini':
                $this->api_key = $this->settings['gemini_api_key'];
                $this->model   = $this->settings['gemini_model'];
                break;
            default:
                $this->api_key = '';
                $this->model   = '';
        }
    }

    // -----------------------------------------------------------------------
    // Single-string translation
    // -----------------------------------------------------------------------

    /**
     * Translate a single string.
     *
     * @param string $text
     * @param string $target_language Language code.
     * @param string $source_language Language code (optional).
     * @return array{translation:string,error:string|null}
     */
    public function translate($text, $target_language, $source_language = '') {
        if (trim((string) $text) === '') {
            return $this->error(__('Texto vacío', 'wpml-imagina-translate'));
        }

        // A glossary rule covering the whole string settles it without a
        // request, which is both free and perfectly consistent.
        $forced = $this->glossary()->resolve($text, $target_language);
        if ($forced !== null) {
            $this->usage['glossary']++;
            return array('translation' => $forced, 'error' => null);
        }

        $memory = WIT_Translation_Memory::instance();
        $hits   = $memory->get_many(array($text), $source_language, $target_language);

        if (isset($hits[$text])) {
            $this->usage['memory']++;
            return array('translation' => $hits[$text], 'error' => null);
        }

        if (empty($this->api_key)) {
            return $this->error(__('API key no configurada', 'wpml-imagina-translate'));
        }

        $target_name = $this->get_language_name($target_language);
        $prompt      = $this->single_prompt($target_name, $this->get_language_name($source_language))
                     . $this->glossary()->prompt_section(array($text), $target_language);

        $result = $this->request($text, $prompt);

        if (empty($result['error']) && $result['translation'] !== '') {
            $this->usage['api']++;
            $memory->store_many(
                array($text => $result['translation']),
                $source_language,
                $target_language,
                $this->provider,
                $this->model
            );
        }

        return $result;
    }

    /**
     * Lazily built glossary.
     *
     * @return WIT_Glossary
     */
    private function glossary() {
        if ($this->glossary === null) {
            $this->glossary = new WIT_Glossary();
        }

        return $this->glossary;
    }

    /**
     * Where the translations produced so far came from.
     *
     * Token counts come straight from each provider's own `usage` field. No
     * price is applied: published rates change often enough that a hardcoded
     * table would report confidently wrong numbers.
     *
     * @return array{glossary:int,memory:int,api:int,input_tokens:int,output_tokens:int}
     */
    public function get_usage() {
        return $this->usage;
    }

    // -----------------------------------------------------------------------
    // Batch translation
    // -----------------------------------------------------------------------

    /**
     * Translate many strings, minimising the number of API calls.
     *
     * @param string[] $texts           Indexed array of source strings.
     * @param string   $target_language Language code.
     * @param string   $source_language Language code (optional).
     * @return array Same keys as $texts, each {translation:string,error:string|null}
     */
    public function translate_batch($texts, $target_language, $source_language = '') {
        if (empty($texts)) {
            return array();
        }

        $out      = array();
        $pending  = array();
        $glossary = $this->glossary();

        // Stage 1 — glossary rules that cover an entire string.
        foreach ($texts as $i => $text) {
            $forced = $glossary->resolve($text, $target_language);

            if ($forced !== null) {
                $out[$i] = array('translation' => $forced, 'error' => null);
                $this->usage['glossary']++;
                continue;
            }

            $pending[$i] = $text;
        }

        // Stage 2 — strings already translated in this language pair before.
        $memory = WIT_Translation_Memory::instance();

        if (!empty($pending)) {
            $hits = $memory->get_many(array_values($pending), $source_language, $target_language);

            foreach ($pending as $i => $text) {
                if (isset($hits[$text])) {
                    $out[$i] = array('translation' => $hits[$text], 'error' => null);
                    $this->usage['memory']++;
                    unset($pending[$i]);
                }
            }
        }

        // Stage 3 — whatever is genuinely new goes to the provider.
        if (!empty($pending)) {
            if (empty($this->api_key)) {
                $error = __('API key no configurada', 'wpml-imagina-translate');
                foreach ($pending as $i => $unused) {
                    $out[$i] = $this->error($error);
                }
            } else {
                $target_name = $this->get_language_name($target_language);
                $source_name = $this->get_language_name($source_language);

                $results = array();
                foreach ($this->chunk($pending) as $chunk) {
                    $results += $this->translate_chunk($chunk, $target_name, $source_name, $target_language);
                }

                $store = array();

                foreach ($pending as $i => $text) {
                    $out[$i] = isset($results[$i])
                        ? $results[$i]
                        : $this->error(__('Sin respuesta del proveedor', 'wpml-imagina-translate'));

                    if (empty($out[$i]['error']) && $out[$i]['translation'] !== '') {
                        $this->usage['api']++;
                        $store[$text] = $out[$i]['translation'];
                    }
                }

                $memory->store_many($store, $source_language, $target_language, $this->provider, $this->model);
            }
        }

        // Return in the caller's original key order.
        $ordered = array();
        foreach ($texts as $i => $unused) {
            $ordered[$i] = isset($out[$i])
                ? $out[$i]
                : $this->error(__('Sin respuesta del proveedor', 'wpml-imagina-translate'));
        }

        return $ordered;
    }

    /**
     * Split texts into chunks bounded by both item count and character count.
     *
     * Character-bounded chunking matters because 40 long paragraphs can exceed
     * the model's context window, whereas 40 short labels are trivially small.
     *
     * @param array $texts
     * @return array[] List of chunks preserving original keys.
     */
    private function chunk($texts) {
        $chunks  = array();
        $current = array();
        $chars   = 0;

        foreach ($texts as $index => $text) {
            $length = mb_strlen((string) $text);

            if (!empty($current)
                && ($chars + $length > self::MAX_CHUNK_CHARS
                    || count($current) >= self::MAX_CHUNK_ITEMS)) {
                $chunks[] = $current;
                $current  = array();
                $chars    = 0;
            }

            $current[$index] = $text;
            $chars += $length;
        }

        if (!empty($current)) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Translate one chunk in a single API call, retrying missing items individually.
     *
     * @param array  $chunk       Source strings keyed by their original index.
     * @param string $target_name Human-readable target language.
     * @param string $source_name Human-readable source language.
     * @return array Same keys as $chunk.
     */
    private function translate_chunk($chunk, $target_name, $source_name, $target_language = '') {
        $keys = array_keys($chunk);

        // Glossary terms occurring inside these strings are appended to the
        // prompt, scoped to this chunk so the instructions stay short.
        $glossary_section = $this->glossary()->prompt_section(array_values($chunk), $target_language);

        // Single item: no batching protocol needed, avoids marker overhead.
        if (count($keys) === 1) {
            $result = $this->request(
                reset($chunk),
                $this->single_prompt($target_name, $source_name) . $glossary_section
            );
            return array($keys[0] => $result);
        }

        $payload  = array();
        $position = 0;
        foreach ($chunk as $text) {
            $position++;
            $payload[] = '[[[' . $position . ']]]' . "\n" . $text;
        }

        $response = $this->request(
            implode("\n", $payload),
            $this->batch_prompt($target_name, $source_name) . $glossary_section
        );

        if (!empty($response['error']) || trim($response['translation']) === '') {
            $error = !empty($response['error'])
                ? $response['error']
                : __('Respuesta vacía del proveedor', 'wpml-imagina-translate');

            $out = array();
            foreach ($keys as $index) {
                $out[$index] = $this->error($error);
            }
            return $out;
        }

        $parsed = $this->parse_batch_response($response['translation'], count($keys));

        $out     = array();
        $missing = array();
        foreach ($keys as $offset => $index) {
            $position = $offset + 1;
            if (isset($parsed[$position]) && trim($parsed[$position]) !== '') {
                $out[$index] = array('translation' => $parsed[$position], 'error' => null);
            } else {
                $missing[$index] = $chunk[$index];
            }
        }

        // Retry anything the model dropped, one string at a time. This turns a
        // partial batch failure into a slower success rather than lost content.
        foreach ($missing as $index => $text) {
            $single = $this->request($text, $this->single_prompt($target_name, $source_name) . $glossary_section);
            $out[$index] = (empty($single['error']) && trim($single['translation']) !== '')
                ? array('translation' => $single['translation'], 'error' => null)
                : $this->error(
                    !empty($single['error'])
                        ? $single['error']
                        : __('El proveedor no devolvió este fragmento', 'wpml-imagina-translate')
                );
        }

        return $out;
    }

    /**
     * Parse a marker-delimited batch response.
     *
     * @param string $response Raw model output.
     * @param int    $expected Number of items requested.
     * @return array 1-based position => translation.
     */
    private function parse_batch_response($response, $expected) {
        // Models occasionally wrap the marker in markdown emphasis or a list
        // bullet ("**[[[1]]]**", "- [[[1]]]"), so decoration is tolerated on
        // both sides of the marker rather than being left in the translation.
        $parts = preg_split(
            '/^[ \t>*_#\-]*\[\[\[\s*(\d+)\s*\]\]\][ \t*_:.\-]*\r?\n?/m',
            $response,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if (!is_array($parts) || count($parts) < 3) {
            return array();
        }

        $result = array();
        for ($i = 1; $i < count($parts); $i += 2) {
            $position = (int) $parts[$i];
            $value    = isset($parts[$i + 1]) ? $parts[$i + 1] : '';

            // Trim only the separator newlines, preserving internal line breaks.
            $value = preg_replace('/\r?\n+$/', '', $value);

            if ($position >= 1 && $position <= $expected && trim($value) !== '') {
                $result[$position] = $value;
            }
        }

        return $result;
    }

    private function batch_prompt($target_name, $source_name) {
        $source = $source_name ? ' from ' . $source_name : '';

        return 'You are a professional translator. The input contains several '
             . 'independent items. Each item begins with a marker of the form '
             . '[[[N]]] alone on its own line, followed by the text of that item, '
             . 'which may span multiple lines.' . "\n\n"
             . 'Translate the text of every item' . $source . ' into ' . $target_name . '.' . "\n\n"
             . 'Rules:' . "\n"
             . '- Reproduce every [[[N]]] marker exactly, on its own line, before its translation.' . "\n"
             . '- Return every item you were given, using the same numbers.' . "\n"
             . '- Preserve the internal line breaks of each item.' . "\n"
             . '- Preserve any HTML tags, shortcodes, placeholders and entities exactly as they appear.' . "\n"
             . '- Keep proper nouns, brand names and technical terms unchanged.' . "\n"
             . '- Output nothing except the markers and their translations. No preamble, no notes.';
    }

    private function single_prompt($target_name, $source_name) {
        $prompt = str_replace(
            array('{target_language}', '{source_language}'),
            array($target_name, $source_name),
            $this->settings['translation_prompt']
        );

        return $prompt !== '' ? $prompt : 'Translate the following text to ' . $target_name
             . '. Return ONLY the translated text, nothing else.';
    }

    // -----------------------------------------------------------------------
    // Provider dispatch
    // -----------------------------------------------------------------------

    /**
     * Send one request to the configured provider.
     *
     * @param string $text
     * @param string $system_prompt
     * @return array{translation:string,error:string|null}
     */
    private function request($text, $system_prompt) {
        switch ($this->provider) {
            case 'openai':
                return $this->call_openai($text, $system_prompt);
            case 'claude':
                return $this->call_claude($text, $system_prompt);
            case 'gemini':
                return $this->call_gemini($text, $system_prompt);
            default:
                return $this->error(__('Proveedor de IA no válido', 'wpml-imagina-translate'));
        }
    }

    /**
     * Perform an HTTP request with retry/backoff on transient failures.
     *
     * @param string $url
     * @param array  $args wp_remote_post() arguments.
     * @return array{code:int,body:string,error:string|null}
     */
    private function http($url, $args) {
        $args['timeout'] = isset($args['timeout']) ? $args['timeout'] : 180;

        $last_error = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                // Success, or a client error that retrying cannot fix.
                if ($code < 500 && $code !== 429) {
                    return array('code' => $code, 'body' => $body, 'error' => null);
                }

                $last_error = sprintf(
                    /* translators: %d: HTTP status code */
                    __('El proveedor respondió HTTP %d', 'wpml-imagina-translate'),
                    $code
                );

                // Honour Retry-After when the provider supplies it.
                $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                if ($retry_after > 0 && $retry_after <= 30 && $attempt < self::MAX_RETRIES) {
                    sleep($retry_after);
                    continue;
                }
            }

            if ($attempt < self::MAX_RETRIES) {
                sleep((int) pow(2, $attempt)); // 2s, then 4s
            }
        }

        return array('code' => 0, 'body' => '', 'error' => $last_error);
    }

    private function call_openai($text, $system_prompt, $with_temperature = true) {
        $body = array(
            'model'    => $this->model,
            'messages' => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user',   'content' => $text),
            ),
        );

        if ($with_temperature) {
            $body['temperature'] = 0.3;
        }

        $response = $this->http('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => wp_json_encode($body),
        ));

        if ($response['error'] !== null) {
            return $this->error($response['error']);
        }

        $decoded = json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('OpenAI: ' . json_last_error_msg());
        }

        if (isset($decoded['error'])) {
            $message = isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : '';

            // Newer reasoning models reject a custom temperature; retry without it.
            if ($with_temperature && stripos($message, 'temperature') !== false) {
                return $this->call_openai($text, $system_prompt, false);
            }

            return $this->error($message ?: __('Error de la API de OpenAI', 'wpml-imagina-translate'));
        }

        if (!isset($decoded['choices'][0]['message']['content'])) {
            return $this->error(__('Respuesta inválida de OpenAI', 'wpml-imagina-translate'));
        }

        $this->record_tokens($decoded);

        return array('translation' => trim($decoded['choices'][0]['message']['content']), 'error' => null);
    }

    private function call_claude($text, $system_prompt) {
        $response = $this->http('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode(array(
                'model'      => $this->model,
                'max_tokens' => $this->claude_max_tokens($text),
                'system'     => $system_prompt,
                'messages'   => array(
                    array('role' => 'user', 'content' => $text),
                ),
            )),
        ));

        if ($response['error'] !== null) {
            return $this->error($response['error']);
        }

        $decoded = json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('Claude: ' . json_last_error_msg());
        }

        if (isset($decoded['error'])) {
            return $this->error(
                isset($decoded['error']['message'])
                    ? $decoded['error']['message']
                    : __('Error de la API de Claude', 'wpml-imagina-translate')
            );
        }

        if (!isset($decoded['content'][0]['text'])) {
            return $this->error(__('Respuesta inválida de Claude', 'wpml-imagina-translate'));
        }

        $this->record_tokens($decoded);

        // A truncated reply would silently drop trailing batch items.
        if (isset($decoded['stop_reason']) && $decoded['stop_reason'] === 'max_tokens') {
            return $this->error(__('La respuesta de Claude se truncó (max_tokens)', 'wpml-imagina-translate'));
        }

        return array('translation' => trim($decoded['content'][0]['text']), 'error' => null);
    }

    /**
     * Choose a max_tokens value large enough for the translation but within
     * the limits of older models, which reject oversized requests outright.
     *
     * @param string $text
     * @return int
     */
    private function claude_max_tokens($text) {
        // Roughly 4 characters per token, doubled to allow for expansion into
        // more verbose target languages plus the batch markers.
        $estimate = (int) ceil(mb_strlen($text) / 4) * 2 + 1024;

        return max(1024, min(8192, $estimate));
    }

    private function call_gemini($text, $system_prompt) {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
                  . rawurlencode($this->model) . ':generateContent';

        $response = $this->http($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                // Passing the key as a header keeps it out of access logs,
                // proxy logs and error reports, unlike a ?key= query parameter.
                'x-goog-api-key' => $this->api_key,
            ),
            'body' => wp_json_encode(array(
                'systemInstruction' => array(
                    'parts' => array(array('text' => $system_prompt)),
                ),
                'contents' => array(
                    array('parts' => array(array('text' => $text))),
                ),
                'generationConfig' => array(
                    'temperature' => 0.3,
                ),
            )),
        ));

        if ($response['error'] !== null) {
            return $this->error($response['error']);
        }

        $decoded = json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('Gemini: ' . json_last_error_msg());
        }

        if (isset($decoded['error'])) {
            return $this->error(
                isset($decoded['error']['message'])
                    ? $decoded['error']['message']
                    : __('Error de la API de Gemini', 'wpml-imagina-translate')
            );
        }

        $candidate = isset($decoded['candidates'][0]) ? $decoded['candidates'][0] : null;

        if ($candidate === null) {
            // A prompt blocked by safety filters returns no candidates at all.
            $reason = isset($decoded['promptFeedback']['blockReason'])
                ? $decoded['promptFeedback']['blockReason']
                : '';
            return $this->error(
                $reason
                    ? sprintf(__('Gemini bloqueó la petición (%s)', 'wpml-imagina-translate'), $reason)
                    : __('Respuesta inválida de Gemini', 'wpml-imagina-translate')
            );
        }

        // Gemini can split a reply across several parts.
        $chunks = array();
        if (!empty($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
            foreach ($candidate['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $chunks[] = $part['text'];
                }
            }
        }

        if (empty($chunks)) {
            return $this->error(__('Respuesta inválida de Gemini', 'wpml-imagina-translate'));
        }

        $this->record_tokens($decoded);

        if (isset($candidate['finishReason']) && $candidate['finishReason'] === 'MAX_TOKENS') {
            return $this->error(__('La respuesta de Gemini se truncó (MAX_TOKENS)', 'wpml-imagina-translate'));
        }

        return array('translation' => trim(implode('', $chunks)), 'error' => null);
    }

    // -----------------------------------------------------------------------
    // Model discovery
    // -----------------------------------------------------------------------

    /**
     * List the text-generation models available for a provider.
     *
     * @param string $provider 'openai' | 'claude' | 'gemini'
     * @param string $api_key
     * @return array{success:bool,models:array,error:string|null}
     */
    public static function fetch_models($provider, $api_key) {
        if (empty($api_key)) {
            return array(
                'success' => false,
                'models'  => array(),
                'error'   => __('API key requerida', 'wpml-imagina-translate'),
            );
        }

        switch ($provider) {
            case 'openai':
                return self::fetch_models_openai($api_key);
            case 'claude':
                return self::fetch_models_claude($api_key);
            case 'gemini':
                return self::fetch_models_gemini($api_key);
            default:
                return array(
                    'success' => false,
                    'models'  => array(),
                    'error'   => __('Proveedor no reconocido', 'wpml-imagina-translate'),
                );
        }
    }

    private static function fetch_models_openai($api_key) {
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'Bearer ' . $api_key),
        ));

        $body = self::decode_models_response($response, 'data', __('Respuesta inválida de OpenAI', 'wpml-imagina-translate'));
        if (isset($body['error'])) {
            return $body;
        }

        $exclude = array(
            'embedding', 'embed', 'whisper', 'dall-e', 'dalle', 'tts',
            'transcribe', 'image', 'moderation', 'realtime', 'audio',
            'text-davinci-edit', 'text-similarity', 'text-search', 'code-search',
        );

        $models = array();
        foreach ($body['data'] as $model) {
            if (!isset($model['id'])) {
                continue;
            }
            $lower = strtolower($model['id']);

            foreach ($exclude as $pattern) {
                if (strpos($lower, $pattern) !== false) {
                    continue 2;
                }
            }

            $models[] = array('id' => $model['id'], 'name' => $model['id']);
        }

        return self::sorted_models($models);
    }

    private static function fetch_models_claude($api_key) {
        $response = wp_remote_get('https://api.anthropic.com/v1/models?limit=100', array(
            'timeout' => 20,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
        ));

        $body = self::decode_models_response($response, 'data', __('Respuesta inválida de Anthropic', 'wpml-imagina-translate'));
        if (isset($body['error'])) {
            return $body;
        }

        $models = array();
        foreach ($body['data'] as $model) {
            if (!isset($model['id'])) {
                continue;
            }
            $models[] = array(
                'id'   => $model['id'],
                'name' => isset($model['display_name']) ? $model['display_name'] : $model['id'],
            );
        }

        return self::sorted_models($models);
    }

    private static function fetch_models_gemini($api_key) {
        $response = wp_remote_get(
            'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200',
            array(
                'timeout' => 20,
                'headers' => array('x-goog-api-key' => $api_key),
            )
        );

        $body = self::decode_models_response($response, 'models', __('Respuesta inválida de Gemini', 'wpml-imagina-translate'));
        if (isset($body['error'])) {
            return $body;
        }

        $models = array();
        foreach ($body['models'] as $model) {
            $methods = isset($model['supportedGenerationMethods']) ? $model['supportedGenerationMethods'] : array();
            if (!in_array('generateContent', $methods, true)) {
                continue;
            }

            $id = isset($model['name']) ? preg_replace('#^models/#', '', $model['name']) : '';
            if ($id === '') {
                continue;
            }

            $models[] = array(
                'id'   => $id,
                'name' => isset($model['displayName']) ? $model['displayName'] : $id,
            );
        }

        return self::sorted_models($models);
    }

    /**
     * Shared decoding and error handling for the three /models endpoints.
     *
     * @param array|WP_Error $response
     * @param string         $list_key Key holding the model list.
     * @param string         $fallback Fallback error message.
     * @return array Decoded body, or a failure payload carrying an 'error' key.
     */
    private static function decode_models_response($response, $list_key, $fallback) {
        if (is_wp_error($response)) {
            return array('success' => false, 'models' => array(), 'error' => $response->get_error_message());
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded[$list_key]) || !is_array($decoded[$list_key])) {
            $message = isset($decoded['error']['message']) ? $decoded['error']['message'] : $fallback;
            return array('success' => false, 'models' => array(), 'error' => $message);
        }

        return $decoded;
    }

    private static function sorted_models($models) {
        usort($models, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        return array('success' => true, 'models' => $models, 'error' => null);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Map a WPML language code to an English language name for the prompt.
     *
     * Falls back to WPML's own language table so newly added languages work
     * without a plugin update.
     *
     * @param string $code
     * @return string
     */
    private function get_language_name($code) {
        if (empty($code)) {
            return '';
        }

        static $languages = array(
            'es' => 'Spanish',    'en' => 'English',    'fr' => 'French',
            'de' => 'German',     'it' => 'Italian',    'pt' => 'Portuguese',
            'pt-br' => 'Brazilian Portuguese',          'pt-pt' => 'European Portuguese',
            'nl' => 'Dutch',      'ru' => 'Russian',    'ja' => 'Japanese',
            'zh' => 'Chinese',    'zh-hans' => 'Simplified Chinese',
            'zh-hant' => 'Traditional Chinese',         'ko' => 'Korean',
            'ar' => 'Arabic',     'pl' => 'Polish',     'tr' => 'Turkish',
            'sv' => 'Swedish',    'da' => 'Danish',     'no' => 'Norwegian',
            'nb' => 'Norwegian Bokmal',                 'fi' => 'Finnish',
            'el' => 'Greek',      'he' => 'Hebrew',     'hi' => 'Hindi',
            'th' => 'Thai',       'vi' => 'Vietnamese', 'id' => 'Indonesian',
            'cs' => 'Czech',      'ro' => 'Romanian',   'hu' => 'Hungarian',
            'uk' => 'Ukrainian',  'ca' => 'Catalan',    'eu' => 'Basque',
            'gl' => 'Galician',   'bg' => 'Bulgarian',  'hr' => 'Croatian',
            'sr' => 'Serbian',    'sk' => 'Slovak',     'sl' => 'Slovenian',
            'et' => 'Estonian',   'lv' => 'Latvian',    'lt' => 'Lithuanian',
            'ms' => 'Malay',      'fa' => 'Persian',    'bn' => 'Bengali',
            'ta' => 'Tamil',      'ur' => 'Urdu',       'af' => 'Afrikaans',
            'sq' => 'Albanian',   'is' => 'Icelandic',  'ga' => 'Irish',
            'cy' => 'Welsh',      'hy' => 'Armenian',   'ka' => 'Georgian',
        );

        $normalised = strtolower($code);

        if (isset($languages[$normalised])) {
            return $languages[$normalised];
        }

        // Ask WPML for the English name of any language it knows about.
        if (function_exists('icl_get_languages')) {
            $wpml_languages = icl_get_languages('skip_missing=0');
            if (is_array($wpml_languages) && isset($wpml_languages[$normalised]['english_name'])) {
                return $wpml_languages[$normalised]['english_name'];
            }
        }

        // Fall back to the base language of a regional code (e.g. "de-ch" -> German).
        if (strpos($normalised, '-') !== false) {
            $base = strtok($normalised, '-');
            if (isset($languages[$base])) {
                return $languages[$base];
            }
        }

        return ucfirst($code);
    }

    /**
     * Accumulate token counts from a provider response.
     *
     * Each provider names the fields differently; all three report them.
     *
     * @param array $decoded Decoded response body.
     */
    private function record_tokens(array $decoded) {
        // OpenAI: usage.prompt_tokens / completion_tokens
        if (isset($decoded['usage']['prompt_tokens'])) {
            $this->usage['input_tokens']  += (int) $decoded['usage']['prompt_tokens'];
            $this->usage['output_tokens'] += isset($decoded['usage']['completion_tokens'])
                ? (int) $decoded['usage']['completion_tokens'] : 0;
            return;
        }

        // Claude: usage.input_tokens / output_tokens
        if (isset($decoded['usage']['input_tokens'])) {
            $this->usage['input_tokens']  += (int) $decoded['usage']['input_tokens'];
            $this->usage['output_tokens'] += isset($decoded['usage']['output_tokens'])
                ? (int) $decoded['usage']['output_tokens'] : 0;
            return;
        }

        // Gemini: usageMetadata.promptTokenCount / candidatesTokenCount
        if (isset($decoded['usageMetadata']['promptTokenCount'])) {
            $this->usage['input_tokens']  += (int) $decoded['usageMetadata']['promptTokenCount'];
            $this->usage['output_tokens'] += isset($decoded['usageMetadata']['candidatesTokenCount'])
                ? (int) $decoded['usageMetadata']['candidatesTokenCount'] : 0;
        }
    }

    /**
     * Build a failure payload.
     *
     * @param string $message
     * @return array{translation:string,error:string}
     */
    private function error($message) {
        return array('translation' => '', 'error' => $message);
    }

    /**
     * Verify that the configured provider and key work.
     *
     * @return array{success:bool,message:string,translation?:string}
     */
    public function test_connection() {
        $result = $this->translate('Hello world', 'es', 'en');

        if (!empty($result['error'])) {
            return array('success' => false, 'message' => $result['error']);
        }

        return array(
            'success'     => true,
            'message'     => __('Conexión exitosa', 'wpml-imagina-translate'),
            'translation' => $result['translation'],
        );
    }
}
