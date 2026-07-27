<?php
/**
 * Field Rules - decides which structured data fields hold translatable text.
 *
 * Why this class exists
 * ---------------------
 * The previous implementation walked Gutenberg block `attrs` and Elementor
 * widget `settings` looking only at VALUES, ignoring the KEY. Any string with a
 * letter in it was sent to the translation API. On real content that meant:
 *
 *   Gutenberg   "align":"center"          -> "centro"        block breaks
 *               "tagName":"main"          -> "principal"     block breaks
 *               "className":"hero-title"  -> translated      CSS breaks
 *               "linkTarget":"_blank"     -> translated      link breaks
 *
 *   Elementor   "header_size":"h2"        -> translated      widget breaks
 *               "size":"medium"           -> "mediano"       widget breaks
 *               "css_classes":"hero btn"  -> translated      CSS breaks
 *               "_animation":"fadeInUp"   -> translated      animation breaks
 *               "is_external":"on"        -> "encendido"     link breaks
 *               "source":"library"        -> "biblioteca"    image breaks
 *               "__globals__":"globals/colors?id=primary"    theme breaks
 *
 * These are latent page-breaking bugs: they only surface when the model decides
 * to actually translate the technical token, which varies run to run.
 *
 * Decision procedure
 * ------------------
 * A field is translated only when ALL of the following hold:
 *
 *   1. The key is not on the technical blocklist (exact names + suffix/prefix
 *      patterns covering colours, sizes, alignment, layout, icons, URLs,
 *      booleans, typography, queries and Elementor's internal `_`-prefixed
 *      style controls).
 *   2. The value passes WIT_HTML_Translator::is_translatable() — it has letters
 *      and is not a URL, colour or data URI.
 *   3. Either the key is a recognised content key (title, text, content,
 *      caption, label, description…), or the value reads like natural language
 *      rather than an identifier / slug / class list / single technical token.
 *
 * Rule 3 is what keeps this working for third-party blocks and widgets whose
 * key names are unknown, without needing a per-plugin whitelist.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Field_Rules {

    /**
     * Key names that never contain translatable text, in any context.
     *
     * @var string[]
     */
    private static $blocked_keys = array(
        // --- Gutenberg core attributes ---
        'classname', 'class', 'tagname', 'anchor', 'lock', 'templatelock',
        'align', 'textalign', 'verticalalignment', 'contentposition',
        'layout', 'style', 'gradient', 'customgradient',
        'backgroundcolor', 'textcolor', 'bordercolor', 'overlaycolor',
        'custombackgroundcolor', 'customtextcolor', 'customoverlaycolor',
        'fontsize', 'customfontsize', 'fontfamily',
        'url', 'href', 'src', 'srcset', 'poster', 'link', 'linktarget',
        'linkclass', 'linkdestination', 'rel', 'target',
        'id', 'ids', 'ref', 'slug', 'theme', 'area', 'kind',
        'sizeslug', 'width', 'height', 'minheight', 'minheightunit',
        'orientation', 'justifycontent', 'flexwrap', 'orderby', 'order',
        'type', 'mode', 'view', 'skin', 'variation',
        'providernameslug', 'responsive', 'allowedblocks',
        'level', 'ordered', 'start', 'reversed',
        'isstackedonmobile', 'dimratio', 'focalpoint', 'opacity',
        'query', 'querycontext', 'taxonomy', 'terms', 'term', 'posttype',
        'categories', 'tags', 'displaylayout', 'columns', 'shadow',
        'position', 'sticky', 'metadata',

        // --- Elementor controls ---
        'css_classes', 'html_tag', 'header_size', 'title_size', 'size',
        'image_size', 'thumbnail_size', 'icon_align', 'button_type',
        'content_align', 'text_align', 'structure', 'content_width',
        'custom_height', 'template_id', 'form_id', 'post_id',
        'animation', 'hover_animation', 'entrance_animation',
        'source', 'effect', 'direction', 'divider_style', 'shape',
        'link_to', 'open_lightbox', 'is_external', 'nofollow',
        'gap', 'space', 'divider', 'overflow', 'mask_shape',
        'selected_icon', 'icon', 'social_icon', 'shortcode',
        '__globals__', '__dynamic__',
    );

    /**
     * Key patterns (matched case-insensitively against the normalised key)
     * that indicate a technical, non-translatable control.
     *
     * @var string[]
     */
    private static $blocked_patterns = array(
        // Elementor internal / advanced style controls are all `_`-prefixed.
        '/^_/',
        '/^__/',
        // Booleans rendered as "yes" / "on" / "true".
        '/^(is|has|show|hide|enable|disable|use|allow|include|exclude)_/',
        // Colours, sizes, spacing, geometry.
        '/(^|_)(color|colour|bg|background)s?$/',
        '/(^|_)(size|sizes|width|height|top|bottom|left|right|gap|space|spacing|offset|padding|margin|radius|border|shadow|blur|opacity|zindex|z_index)$/',
        '/(^|_)(rotate|scale|skew|translate|duration|delay|speed|easing|transition)$/',
        // Layout / positioning.
        '/(^|_)(align|alignment|position|direction|orientation|layout|structure|columns|display|flex|justify|wrap|order)$/',
        // Types, styles, variants.
        '/(^|_)(type|style|skin|view|variant|effect|animation|mode|preset)$/',
        // Identifiers, selectors, markup tags.
        '/(^|_)(id|ids|key|keys|slug|slugs|class|classes|selector|selectors|tag|tags|html_tag|ref|uid|uuid|hash)$/',
        // Links and media sources.
        '/(^|_)(url|urls|link|links|href|src|source|target|rel|path|file|filename|mime)$/',
        // Icons are class strings such as "fas fa-check".
        '/(^|_)(icon|icons|iconset|library)$/',
        // Typography.
        '/(^|_)(font|family|weight|transform|decoration|line_height|letter_spacing|word_spacing|typography)$/',
        // Query / loop controls.
        '/(^|_)(orderby|per_page|posts_per_page|limit|count|number|offset|taxonomy|post_type|author)$/',
        // Unit suffixes used by Elementor responsive controls.
        '/_(tablet|mobile|laptop|widescreen|unit|units)$/',
    );

    /**
     * Key patterns that positively identify user-visible content.
     *
     * @var string[]
     */
    private static $content_patterns = array(
        '/(^|_)(content|text|title|subtitle|heading|subheading)$/',
        '/(^|_)(label|caption|description|excerpt|summary|message|placeholder)$/',
        '/(^|_)(alt|alt_text|tooltip|quote|cite|citation|author|name)$/',
        '/(^|_)(button|button_text|cta|prefix|suffix|before|after|badge)$/',
        // camelCase / PascalCase variants used by third-party Gutenberg blocks,
        // e.g. Greenshift's headingContent, buttonContent, textContent.
        '/content$/i',
        '/text$/i',
        '/title$/i',
        '/label$/i',
        '/caption$/i',
        '/description$/i',
    );

    /**
     * Decide whether a key/value pair should be translated.
     *
     * @param string      $key           Field key. Empty string for list items.
     * @param string      $value         Raw field value.
     * @param string|null $decoded_value Optional pre-decoded value.
     * @return bool
     */
    public static function is_translatable_field($key, $value, $decoded_value = null) {
        if (!is_string($value)) {
            return false;
        }

        $text = $decoded_value !== null
            ? $decoded_value
            : trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (!WIT_HTML_Translator::is_translatable($text)) {
            return false;
        }

        $normalised = strtolower((string) $key);

        if ($normalised !== '') {
            if (in_array($normalised, self::$blocked_keys, true)) {
                return false;
            }
            foreach (self::$blocked_patterns as $pattern) {
                if (preg_match($pattern, $normalised)) {
                    return false;
                }
            }
            foreach (self::$content_patterns as $pattern) {
                if (preg_match($pattern, $normalised)) {
                    return true;
                }
            }
        }

        // Unknown key: fall back to inspecting the value itself.
        return self::looks_like_prose($text);
    }

    /**
     * Heuristic test for natural-language text versus a technical token.
     *
     * Accepts  "Bienvenidos a nuestra web", "Read more about our services"
     * Rejects  "fadeInUp", "library", "success", "h2", "medium", "on",
     *          "hero-title btn-primary", "globals/colors?id=primary"
     *
     * @param string $text Trimmed, entity-decoded text.
     * @return bool
     */
    private static function looks_like_prose($text) {
        // Elementor global/dynamic references.
        if (strpos($text, 'globals/') === 0 || strpos($text, 'dynamic/') === 0) {
            return false;
        }
        // Query-string-ish or path-ish values.
        if (preg_match('/^[\w\-]+\/[\w\-\/?=&]+$/', $text)) {
            return false;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $word_count = count($words);

        // Single token: only prose if it is a reasonably long plain word,
        // which excludes camelCase and kebab/snake identifiers.
        if ($word_count < 2) {
            if (preg_match('/[-_]/', $text)) {
                return false;
            }
            if (preg_match('/^[a-z]+[A-Z]/', $text)) {
                return false; // camelCase such as fadeInUp
            }
            if (preg_match('/^[a-zA-Z]\d+$/', $text)) {
                return false; // h1, h2, col3
            }
            return mb_strlen($text) >= 4 && preg_match('/^\p{L}+$/u', $text) === 1;
        }

        // Multi-token: reject CSS class lists, where every token is an identifier
        // containing a dash or underscore (e.g. "elementor-custom hero-title").
        $identifier_tokens = 0;
        foreach ($words as $word) {
            if (preg_match('/^[a-zA-Z0-9]+[-_][a-zA-Z0-9\-_]*$/', $word)) {
                $identifier_tokens++;
            }
        }
        if ($identifier_tokens === $word_count) {
            return false;
        }

        return true;
    }

    /**
     * Recursively collect translatable strings from a structured array.
     *
     * @param array    $data
     * @param array    $originals Reference map of text => sequential index.
     * @param callable $is_html   Callback returning true when a value is HTML.
     * @param string   $key       Key of the current node (internal, for recursion).
     */
    public static function collect(array $data, array &$originals, $is_html, $key = '') {
        foreach ($data as $child_key => $value) {
            // Numeric keys come from repeater lists; inherit the parent key so
            // that e.g. icon_list[0]['text'] is still judged by its own key.
            $effective_key = is_int($child_key) ? $key : (string) $child_key;

            if (is_array($value)) {
                // Prune the whole subtree when the parent key is technical.
                // Without this, a nested value such as
                // selected_icon => [ 'value' => 'fas fa-check' ] would be judged
                // by its own key ("value") and escape the blocklist.
                if (self::is_blocked_key($effective_key)) {
                    continue;
                }
                self::collect($value, $originals, $is_html, $effective_key);
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            // HTML-bearing fields are handed to the tokenizer, which extracts
            // only the text nodes and leaves markup untouched.
            if (call_user_func($is_html, $value)) {
                if (!self::is_blocked_key($effective_key)) {
                    WIT_HTML_Translator::collect($value, $originals);
                }
                continue;
            }

            if (!self::is_translatable_field($effective_key, $value)) {
                continue;
            }

            $trimmed = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!isset($originals[$trimmed])) {
                $originals[$trimmed] = count($originals);
            }
        }
    }

    /**
     * Recursively apply a translation map to a structured array.
     *
     * @param array    $data
     * @param array    $map     original_text => translated_text
     * @param callable $is_html Callback returning true when a value is HTML.
     * @param int      $count   Reference counter incremented per replacement.
     * @param string   $key     Key of the current node (internal, for recursion).
     * @return array
     */
    public static function apply(array $data, array $map, $is_html, &$count = 0, $key = '') {
        foreach ($data as $child_key => $value) {
            $effective_key = is_int($child_key) ? $key : (string) $child_key;

            if (is_array($value)) {
                // Mirror the pruning done in collect() so that apply() can never
                // write into a subtree the collector deliberately skipped.
                if (self::is_blocked_key($effective_key)) {
                    continue;
                }
                $data[$child_key] = self::apply($value, $map, $is_html, $count, $effective_key);
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            if (call_user_func($is_html, $value)) {
                if (!self::is_blocked_key($effective_key)) {
                    $data[$child_key] = WIT_HTML_Translator::apply($value, $map, $count);
                }
                continue;
            }

            if (!self::is_translatable_field($effective_key, $value)) {
                continue;
            }

            $trimmed = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!isset($map[$trimmed])) {
                continue;
            }

            // Preserve the original surrounding whitespace.
            preg_match('/^(\s*)(?:.*?)(\s*)$/su', $value, $ws);
            $data[$child_key] = $ws[1] . $map[$trimmed] . $ws[2];
            $count++;
        }

        return $data;
    }

    /**
     * Whether a key is on the technical blocklist.
     *
     * @param string $key
     * @return bool
     */
    public static function is_blocked_key($key) {
        $normalised = strtolower((string) $key);

        if ($normalised === '') {
            return false;
        }
        if (in_array($normalised, self::$blocked_keys, true)) {
            return true;
        }
        foreach (self::$blocked_patterns as $pattern) {
            if (preg_match($pattern, $normalised)) {
                return true;
            }
        }

        return false;
    }
}
