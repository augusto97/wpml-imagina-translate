<?php
/**
 * Test bootstrap.
 *
 * Provides the small slice of WordPress the pure-logic classes touch, so the
 * suite runs with plain `php tests/run.php` and needs no WordPress install,
 * no database and no Composer.
 *
 * Only classes that do not require WordPress itself are covered here: the HTML
 * tokenizer, the field rules, the glossary and the batch protocol. Those are
 * exactly the pieces where a silent regression corrupts content.
 */

define('ABSPATH', __DIR__ . '/');

// --- WordPress function stubs -------------------------------------------

function __($text, $domain = '')            { return $text; }
function esc_html__($text, $domain = '')    { return $text; }
function apply_filters($tag, $value)        { return $value; }
function wp_json_encode($data, $flags = 0)  { return json_encode($data, $flags); }
function wp_strip_all_tags($text)           { return trim(strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text))); }
// Deliberately NOT defined: wp_remote_post / wp_remote_request. Any attempt by
// the engine to reach an API in these tests is a fatal error, not a pass.

/**
 * Minimal settings stub. Translation memory is switched off so no database is
 * touched; the glossary is injected per test.
 */
class WIT_Settings {

    private static $instance = null;

    public static $overrides = array();

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_settings() {
        return array_merge(array(
            'ai_provider'               => 'openai',
            'openai_api_key'            => 'test-key',
            'openai_model'              => 'test-model',
            'claude_api_key'            => '',
            'claude_model'              => '',
            'gemini_api_key'            => '',
            'gemini_model'              => '',
            'translation_prompt'        => 'Translate to {target_language}.',
            'glossary'                  => '',
            'enable_translation_memory' => false,
        ), self::$overrides);
    }
}

/**
 * Translation memory stub: always disabled, so the engine never reaches $wpdb.
 */
class WIT_Translation_Memory {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_enabled()                                    { return false; }
    public function get_many(array $t, $s, $g)                      { return array(); }
    public function peek_many(array $t, $s, $g)                     { return array(); }
    public function store_many(array $p, $s, $g, $pr = '', $m = '') { return 0; }
}

// --- Classes under test --------------------------------------------------

require_once dirname(__DIR__) . '/includes/class-html-translator.php';
require_once dirname(__DIR__) . '/includes/class-field-rules.php';
require_once dirname(__DIR__) . '/includes/class-glossary.php';
require_once dirname(__DIR__) . '/includes/class-translator-engine.php';

// --- Tiny assertion framework -------------------------------------------

class WIT_Tests {

    public static $passed = 0;
    public static $failed = 0;
    public static $group  = '';

    public static function group($name) {
        self::$group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public static function ok($condition, $description) {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m {$description}\n";
            return true;
        }

        self::$failed++;
        echo "  \033[31m✗ {$description}\033[0m\n";
        return false;
    }

    public static function same($expected, $actual, $description) {
        if (self::ok($expected === $actual, $description)) {
            return;
        }

        echo "      expected: " . self::render($expected) . "\n";
        echo "      actual:   " . self::render($actual) . "\n";
    }

    private static function render($value) {
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Call a private or protected method.
     */
    public static function call($object, $method, array $args = array()) {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    public static function summary() {
        $total = self::$passed + self::$failed;
        echo "\n" . str_repeat('─', 56) . "\n";

        if (self::$failed === 0) {
            echo "\033[32mTodos los tests pasaron\033[0m ({$total})\n";
            return 0;
        }

        echo "\033[31m" . self::$failed . " fallo(s)\033[0m de {$total}\n";
        return 1;
    }
}
