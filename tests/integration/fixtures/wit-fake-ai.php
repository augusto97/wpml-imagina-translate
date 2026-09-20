<?php
/**
 * Plugin Name: Fake AI provider (test double)
 * Description: Answers the OpenAI / Claude / Gemini endpoints locally with a deterministic "translation", and records every string that reaches the API. Test-only.
 */

class WIT_Fake_AI {

    /** Beside the install, so the runner finds it without being told where. */
    private function log_path() {
        return WP_CONTENT_DIR . '/wit-ai-requests.log';
    }

    public function __construct() {
        add_filter('pre_http_request', array($this, 'intercept'), 10, 3);
    }

    /**
     * Behaviour switch, so robustness can be tested without a real model:
     *   normal      well-formed answer
     *   preamble    chatty answer with markdown around the markers
     *   drop_item   omits item 2 of every batch (forces the per-item retry)
     *   rate_limit  first call answers 429, the retry succeeds
     */
    private function mode() {
        return get_option('wit_fake_mode', 'normal');
    }

    public function intercept($preempt, $args, $url) {
        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!in_array($host, array('api.openai.com', 'api.anthropic.com', 'generativelanguage.googleapis.com'), true)) {
            return $preempt; // Anything else goes out for real.
        }

        if ($this->mode() === 'rate_limit' && !get_transient('wit_fake_429_sent')) {
            set_transient('wit_fake_429_sent', 1, 60);
            return $this->response(429, array('error' => array('message' => 'Rate limit exceeded')));
        }

        // Model listings.
        if (strpos($url, '/v1/models') !== false && $host === 'api.openai.com') {
            return $this->response(200, array('data' => array(array('id' => 'gpt-4o-mini'), array('id' => 'gpt-4o'), array('id' => 'text-embedding-3-small'))));
        }
        if (strpos($url, '/v1/models') !== false && $host === 'api.anthropic.com') {
            return $this->response(200, array('data' => array(array('id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5'))));
        }
        if ($host === 'generativelanguage.googleapis.com' && strpos($url, ':generateContent') === false) {
            return $this->response(200, array('models' => array(array('name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => array('generateContent')))));
        }

        $body = json_decode($args['body'], true);
        list($system, $user) = $this->extract($host, $body);

        $lang   = $this->target_code($system);
        $items  = $this->split_items($user);
        // The single-string prompt now asks for the [[[1]]] marker too; a
        // real model complies with an explicit format instruction even when
        // it is otherwise chatty.
        $wants_marker = strpos($system, '[[[1]]]') !== false;
        $wants_end    = strpos($system, '[[[end]]]') !== false;
        $output = $this->render($items, $lang, $wants_marker, $wants_end);

        $this->log(array(
            'provider' => $host,
            'lang'     => $lang,
            'mode'     => $this->mode(),
            'batch'    => count($items) > 1 || isset($items[1]),
            'strings'  => array_values($items),
            'system'   => $system,
        ));

        $usage_in  = (int) (strlen($system . $user) / 4);
        $usage_out = (int) (strlen($output) / 4);

        switch ($host) {
            case 'api.openai.com':
                return $this->response(200, array(
                    'choices' => array(array('message' => array('role' => 'assistant', 'content' => $output))),
                    'usage'   => array('prompt_tokens' => $usage_in, 'completion_tokens' => $usage_out),
                ));
            case 'api.anthropic.com':
                return $this->response(200, array(
                    'content' => array(array('type' => 'text', 'text' => $output)),
                    'usage'   => array('input_tokens' => $usage_in, 'output_tokens' => $usage_out),
                ));
            default:
                return $this->response(200, array(
                    'candidates'    => array(array('content' => array('parts' => array(array('text' => $output))))),
                    'usageMetadata' => array('promptTokenCount' => $usage_in, 'candidatesTokenCount' => $usage_out),
                ));
        }
    }

    private function extract($host, $body) {
        $system = '';
        $user   = '';

        if ($host === 'api.openai.com') {
            foreach ((array) $body['messages'] as $m) {
                if ($m['role'] === 'system') { $system .= $m['content']; }
                if ($m['role'] === 'user')   { $user   .= $m['content']; }
            }
        } elseif ($host === 'api.anthropic.com') {
            $system = isset($body['system']) ? (is_array($body['system']) ? implode("\n", array_column($body['system'], 'text')) : $body['system']) : '';
            foreach ((array) $body['messages'] as $m) {
                if ($m['role'] === 'user') {
                    $user .= is_array($m['content']) ? implode("\n", array_column($m['content'], 'text')) : $m['content'];
                }
            }
        } else {
            $system = isset($body['systemInstruction']['parts'][0]['text']) ? $body['systemInstruction']['parts'][0]['text'] : '';
            foreach ((array) $body['contents'] as $c) {
                foreach ((array) $c['parts'] as $p) { $user .= isset($p['text']) ? $p['text'] : ''; }
            }
        }

        return array($system, $user);
    }

    private function target_code($system) {
        if (preg_match('/\b(?:in)?to ([A-Z][A-Za-z ]+?)[\.\n,;]/', $system, $m)) {
            $map = array('English' => 'EN', 'French' => 'FR', 'Spanish' => 'ES', 'German' => 'DE');
            $name = trim($m[1]);
            return isset($map[$name]) ? $map[$name] : strtoupper(substr($name, 0, 2));
        }
        return 'XX';
    }

    /** @return array index => text  (index 0 means "no markers, single text") */
    private function split_items($user) {
        if (strpos($user, '[[[') === false) {
            return array(0 => $user);
        }
        $parts = preg_split('/^\[\[\[(\d+)\]\]\]\s*$/m', $user, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $items = array();
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $items[(int) $parts[$i]] = trim($parts[$i + 1]);
        }
        return $items;
    }

    private function translate($text, $lang) {
        return '[' . $lang . '] ' . $text;
    }

    private function render($items, $lang, $wants_marker = false, $wants_end = false) {
        $end = $wants_end ? "\n[[[end]]]" : '';
        $mode = $this->mode();

        if (isset($items[0]) && count($items) === 1) {
            $t = $this->translate($items[0], $lang);

            if (!$wants_marker) {
                return $mode === 'preamble' ? "Sure! Here is the translation:\n\n\"" . $t . "\"" : $t;
            }

            return $mode === 'preamble'
                ? "Sure! Here is the translation:\n\n[[[1]]]\n" . $t . $end . "\n\nHope that helps!"
                : "[[[1]]]\n" . $t . $end;
        }

        $out = array();
        foreach ($items as $i => $text) {
            if ($mode === 'drop_item' && $i === 2) {
                continue;
            }
            $marker = $mode === 'preamble' ? '**[[[' . $i . ']]]**' : '[[[' . $i . ']]]';
            $out[]  = $marker . "\n" . $this->translate($text, $lang);
        }
        $joined = implode("\n", $out) . $end;
        return $mode === 'preamble' ? "Claro, aquí tienes:\n\n" . $joined . "\n\nEspero que te sirva." : $joined;
    }

    private function response($code, $payload) {
        return array(
            'headers'  => array(),
            'body'     => wp_json_encode($payload),
            'response' => array('code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    private function log($entry) {
        file_put_contents($this->log_path(), wp_json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}

new WIT_Fake_AI();
