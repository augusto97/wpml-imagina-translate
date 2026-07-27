<?php
/**
 * HTML Translator - byte-exact text extraction and replacement inside HTML.
 *
 * Why this class exists
 * ---------------------
 * The previous implementation used DOMDocument to *find* text nodes and then
 * ran str_replace() over the original HTML string to write translations back.
 * That approach had two fatal flaws:
 *
 *  1. ENTITY MISMATCH (silent data loss)
 *     DOMDocument returns *decoded* text: "Caf&eacute;" becomes "Café" and
 *     "&nbsp;" becomes U+00A0. The str_replace() search string therefore never
 *     matched the original HTML, so any text containing an HTML entity was
 *     silently left untranslated. `&nbsp;` alone appears in a large share of
 *     real WordPress content.
 *
 *  2. UNANCHORED FALLBACK (silent corruption)
 *     The fallback `str_replace($text, $translation, $html)` could match inside
 *     tag attributes (class names, data-* values, alt text, URLs).
 *
 * This class instead tokenizes the HTML into an alternating list of markup and
 * text segments. Translations are written back by segment index, so:
 *
 *  - Every byte outside a text segment is preserved exactly (verified by a
 *    round-trip assertion in the unit tests). Block comment markers, attribute
 *    JSON, self-closing slashes and quoting style all survive untouched.
 *  - Entities are decoded for matching and re-encoded on write.
 *  - Replacement cannot leak into attributes, because attributes live inside
 *    markup segments which are never modified.
 *
 * The HTML is never re-serialized, which is what makes it safe for Gutenberg:
 * a block's stored innerHTML must match what its JS save() function produces,
 * byte for byte, or the editor reports "invalid content".
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_HTML_Translator {

    /**
     * Elements whose text content must never be translated.
     *
     * @var string[]
     */
    private static $skip_elements = array(
        'script', 'style', 'pre', 'code', 'textarea', 'svg', 'math', 'template',
    );

    /**
     * Void elements never open a "skip" region even without a self-closing slash.
     *
     * @var string[]
     */
    private static $void_elements = array(
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    );

    /**
     * HTML attributes that hold user-visible text and must be translated.
     *
     * Translating these is not optional. Several core blocks — core/cover most
     * visibly — keep `alt` in the block comment JSON only, and their save()
     * function prints it into the markup. Translating the JSON copy while
     * leaving the printed copy in the source language makes the regenerated
     * HTML differ from the stored HTML, which is exactly the condition that
     * produces "this block contains unexpected or invalid content".
     *
     * @var string[]
     */
    private static $text_attributes = array(
        'alt', 'title', 'placeholder', 'aria-label', 'aria-description',
        'aria-placeholder', 'aria-roledescription', 'label', 'summary',
    );

    /**
     * Split HTML into alternating markup and text segments.
     *
     * Markup segments always start with "<". Concatenating the result always
     * reproduces the input exactly.
     *
     * @param string $html
     * @return string[]
     */
    public static function tokenize($html) {
        $parts = preg_split(
            '/(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<![^>]*>|<\?.*?\?>|<\/?[a-zA-Z][^>]*>)/s',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        // preg_split returns false on catastrophic backtracking or PCRE limits.
        // Falling back to the whole string means "no text segments found",
        // which makes the caller leave the HTML untouched rather than corrupt it.
        return is_array($parts) ? $parts : array($html);
    }

    /**
     * Locate translatable text segments in a tokenized HTML array.
     *
     * @param string[] $parts Output of tokenize().
     * @return array List of array{0:int,1:string} — segment index and decoded text.
     */
    public static function text_segments(array $parts) {
        $segments   = array();
        $skip_depth = 0;

        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }

            // ---- markup segment: only used to track skip regions ----
            if ($part[0] === '<') {
                if (preg_match('/^<\/([a-zA-Z][a-zA-Z0-9]*)/', $part, $m)) {
                    if ($skip_depth > 0 && in_array(strtolower($m[1]), self::$skip_elements, true)) {
                        $skip_depth--;
                    }
                } elseif (preg_match('/^<([a-zA-Z][a-zA-Z0-9]*)/', $part, $m)) {
                    $tag = strtolower($m[1]);
                    $self_closing = (substr(rtrim($part), -2) === '/>');
                    if (in_array($tag, self::$skip_elements, true)
                        && !$self_closing
                        && !in_array($tag, self::$void_elements, true)) {
                        $skip_depth++;
                    }
                }
                continue;
            }

            // ---- text segment ----
            if ($skip_depth > 0) {
                continue;
            }

            $decoded = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (trim($decoded) === '') {
                continue;
            }

            $segments[] = array($i, $decoded);
        }

        return $segments;
    }

    /**
     * Collect unique translatable strings from an HTML fragment.
     *
     * @param string $html
     * @param array  $originals Reference map of text => sequential index.
     */
    public static function collect($html, array &$originals) {
        if ($html === '' || strpos($html, '<') === false) {
            // Plain text with no markup — still translatable.
            $trimmed = trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (self::is_translatable($trimmed) && !isset($originals[$trimmed])) {
                $originals[$trimmed] = count($originals);
            }
            return;
        }

        $parts = self::tokenize($html);

        foreach (self::text_segments($parts) as $segment) {
            $trimmed = trim($segment[1]);
            if (self::is_translatable($trimmed) && !isset($originals[$trimmed])) {
                $originals[$trimmed] = count($originals);
            }
        }

        foreach (self::attribute_values($parts) as $value) {
            if (self::is_translatable($value) && !isset($originals[$value])) {
                $originals[$value] = count($originals);
            }
        }
    }

    /**
     * Extract the values of user-visible attributes from markup segments.
     *
     * @param string[] $parts Output of tokenize().
     * @return string[] Decoded, trimmed attribute values.
     */
    private static function attribute_values(array $parts) {
        $values  = array();
        $pattern = self::attribute_pattern();

        foreach ($parts as $part) {
            if ($part === '' || $part[0] !== '<' || $part[1] === '!') {
                continue;
            }

            if (!preg_match_all($pattern, $part, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $raw = $match[3] !== '' ? $match[3] : (isset($match[4]) ? $match[4] : '');
                if ($raw === '') {
                    continue;
                }
                $values[] = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return $values;
    }

    /**
     * Regex matching a translatable attribute and capturing its quoted value.
     *
     * @return string
     */
    private static function attribute_pattern() {
        static $pattern = null;

        if ($pattern === null) {
            $names   = implode('|', array_map('preg_quote', self::$text_attributes));
            $pattern = '/(\s)(' . $names . ')\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        }

        return $pattern;
    }

    /**
     * Apply a translation map to an HTML fragment.
     *
     * @param string $html
     * @param array  $map   original_text => translated_text
     * @param int    $count Reference counter incremented per replacement.
     * @return string
     */
    public static function apply($html, array $map, &$count = 0) {
        if ($html === '' || empty($map)) {
            return $html;
        }

        if (strpos($html, '<') === false) {
            $trimmed = trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (isset($map[$trimmed])) {
                $count++;
                preg_match('/^(\s*)(?:.*?)(\s*)$/su', $html, $ws);
                return $ws[1] . self::encode($map[$trimmed]) . $ws[2];
            }
            return $html;
        }

        $parts = self::tokenize($html);

        foreach (self::text_segments($parts) as $segment) {
            list($index, $decoded) = $segment;

            $trimmed = trim($decoded);
            if (!isset($map[$trimmed])) {
                continue;
            }

            // Preserve the exact leading/trailing whitespace of the raw segment
            // so inline formatting and indentation are unchanged.
            preg_match('/^(\s*)(?:.*?)(\s*)$/su', $parts[$index], $ws);

            $parts[$index] = $ws[1] . self::encode($map[$trimmed]) . $ws[2];
            $count++;
        }

        $parts = self::apply_to_attributes($parts, $map, $count);

        return implode('', $parts);
    }

    /**
     * Translate user-visible attribute values inside markup segments.
     *
     * @param string[] $parts
     * @param array    $map
     * @param int      $count
     * @return string[]
     */
    private static function apply_to_attributes(array $parts, array $map, &$count) {
        $pattern = self::attribute_pattern();

        foreach ($parts as $index => $part) {
            if ($part === '' || $part[0] !== '<' || $part[1] === '!') {
                continue;
            }
            if (strpos($part, '=') === false) {
                continue;
            }

            $replaced = preg_replace_callback(
                $pattern,
                function ($match) use ($map, &$count) {
                    $double = ($match[3] !== '');
                    $raw    = $double ? $match[3] : (isset($match[4]) ? $match[4] : '');

                    if ($raw === '') {
                        return $match[0];
                    }

                    $decoded = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    if (!isset($map[$decoded])) {
                        return $match[0];
                    }

                    $count++;

                    // Encode quotes as well: unlike a text node, an unescaped
                    // quote here would terminate the attribute early.
                    $value = htmlspecialchars($map[$decoded], ENT_QUOTES, 'UTF-8');

                    return $match[1] . $match[2] . '="' . $value . '"';
                },
                $part
            );

            if ($replaced !== null) {
                $parts[$index] = $replaced;
            }
        }

        return $parts;
    }

    /**
     * Encode a translated string for insertion into an HTML text node.
     *
     * Only &, < and > are encoded. Quotes are intentionally left alone: inside
     * a text node they are legal literal characters, and encoding them would
     * change the byte output of blocks whose save() emits raw quotes, which
     * would in turn trigger Gutenberg's block validation error.
     *
     * @param string $text
     * @return string
     */
    private static function encode($text) {
        return strtr($text, array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;'));
    }

    /**
     * Heuristic: is this string worth sending to a translation API?
     *
     * Rejects strings with no letters (numbers, punctuation, measurements),
     * bare URLs, hex colours and single-character fragments.
     *
     * @param string $text Trimmed, entity-decoded text.
     * @return bool
     */
    public static function is_translatable($text) {
        if ($text === '' || mb_strlen($text) < 2) {
            return false;
        }
        // Digits, punctuation, symbols and non-breaking spaces only.
        if (preg_match('/^[\d\s\p{P}\p{S}\x{00A0}]+$/u', $text)) {
            return false;
        }
        // Must contain at least one Unicode letter.
        if (!preg_match('/\p{L}/u', $text)) {
            return false;
        }
        // Bare URLs and protocol-relative links.
        if (preg_match('#^(https?:)?//#i', $text) || strpos($text, '://') !== false) {
            return false;
        }
        // Hex colours.
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $text)) {
            return false;
        }
        // Data URIs and base64 blobs.
        if (preg_match('/^data:/i', $text)) {
            return false;
        }

        return true;
    }
}
