<?php
/**
 * Content Parser - translates post_content while preserving its structure exactly.
 *
 * Gutenberg
 * ---------
 * `parse_blocks()` splits the content into a block tree; each block's
 * `innerContent` holds the HTML fragments between its child blocks. Only those
 * fragments and a carefully filtered subset of `attrs` are touched, then
 * `serialize_blocks()` rebuilds the document. Block comment delimiters are
 * never string-edited, which is what broke validation in earlier versions.
 *
 * Block attributes
 * ----------------
 * Some blocks store their visible text in the block comment attributes as well
 * as in the HTML — Greenshift's `headingContent`/`buttonContent` are typical.
 * Gutenberg validates a block by re-running its JS `save()` against the stored
 * attributes and comparing the output with the stored HTML, so translating one
 * without the other produces "this block contains unexpected or invalid
 * content".
 *
 * Attributes are therefore translated too, but only when the key is a
 * recognised content key or the value already appeared as visible text in the
 * block's own HTML. Translating attributes indiscriminately is what would turn
 * `"align":"center"` into `"align":"centro"` and break the block outright.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Content_Parser {

    /** @var string[] */
    private $debug_log = array();

    /** @var int */
    private $strings_translated = 0;

    /** @var int */
    private $strings_failed = 0;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Translate post content.
     *
     * @param string $content
     * @param string $target_language
     * @param string $source_language
     * @return array{content:string,error:string|null,debug:string[]}
     */
    public function translate_content($content, $target_language, $source_language = '') {
        $this->debug_log          = array();
        $this->strings_translated = 0;
        $this->strings_failed     = 0;

        if (trim((string) $content) === '') {
            return array('content' => '', 'error' => null, 'debug' => array('Contenido vacío'));
        }

        $is_blocks = has_blocks($content);

        $this->debug_log[] = sprintf(
            'Contenido: %d caracteres (%s)',
            strlen($content),
            $is_blocks ? 'Gutenberg' : 'editor clásico'
        );

        $translator = new WIT_Translator_Engine();

        $result = $is_blocks
            ? $this->translate_blocks($content, $translator, $target_language, $source_language)
            : $this->translate_html($content, $translator, $target_language, $source_language);

        $this->debug_log[] = '=== CADENAS TRADUCIDAS: ' . $this->strings_translated . ' ===';

        if ($this->strings_failed > 0) {
            $this->debug_log[] = '=== CADENAS FALLIDAS: ' . $this->strings_failed . ' ===';
        }

        return array(
            'content' => $result['content'],
            'error'   => $result['error'],
            'debug'   => $this->debug_log,
        );
    }

    /**
     * @param string $title
     * @param string $target_language
     * @param string $source_language
     * @return array{title:string,error:string|null}
     */
    public function translate_title($title, $target_language, $source_language = '') {
        if (trim((string) $title) === '') {
            return array('title' => '', 'error' => null);
        }

        $translator = new WIT_Translator_Engine();
        $result     = $translator->translate($title, $target_language, $source_language);

        return array(
            'title' => empty($result['error']) ? $result['translation'] : $title,
            'error' => isset($result['error']) ? $result['error'] : null,
        );
    }

    /**
     * @param string $excerpt
     * @param string $target_language
     * @param string $source_language
     * @return array{excerpt:string,error:string|null}
     */
    public function translate_excerpt($excerpt, $target_language, $source_language = '') {
        if (trim((string) $excerpt) === '') {
            return array('excerpt' => '', 'error' => null);
        }

        $translator = new WIT_Translator_Engine();
        $result     = $translator->translate($excerpt, $target_language, $source_language);

        return array(
            'excerpt' => empty($result['error']) ? $result['translation'] : $excerpt,
            'error'   => isset($result['error']) ? $result['error'] : null,
        );
    }

    // -----------------------------------------------------------------------
    // Gutenberg
    // -----------------------------------------------------------------------

    /**
     * @param string                $content
     * @param WIT_Translator_Engine $translator
     * @param string                $target_language
     * @param string                $source_language
     * @return array{content:string,error:string|null}
     */
    private function translate_blocks($content, $translator, $target_language, $source_language) {
        $blocks = parse_blocks($content);

        if (empty($blocks)) {
            return array('content' => $content, 'error' => null);
        }

        // Pass 1a — visible text from the HTML of every block.
        $originals = array();
        $this->collect_html($blocks, $originals);

        // Pass 1b — attributes. Runs second so that an attribute mirroring text
        // already seen in the HTML is recognised as content regardless of its
        // key name.
        $this->collect_attrs($blocks, $originals);

        if (empty($originals)) {
            $this->debug_log[] = 'No se encontró texto traducible en los bloques';
            return array('content' => $content, 'error' => null);
        }

        $map = $this->build_map(array_keys($originals), $translator, $target_language, $source_language);

        if (is_wp_error($map)) {
            return array('content' => $content, 'error' => $map->get_error_message());
        }

        // Pass 2 — write the translations back.
        $translated = $this->apply_blocks($blocks, $map, $originals);
        $translated = $this->restore_empty_objects($translated);

        return array('content' => serialize_blocks($translated), 'error' => null);
    }

    /**
     * Attribute keys whose value is an object in every block schema that uses
     * them, so an empty one must serialize as `{}` rather than `[]`.
     *
     * @var string[]
     */
    private static $object_attribute_keys = array(
        'style', 'layout', 'metadata', 'bindings', 'content', 'overrides',
        'query', 'typography', 'spacing', 'border', 'color', 'elements',
        'dimensions', 'filter', 'shadow', 'blockGap', 'margin', 'padding',
        'radius', 'link', 'background', 'position', 'settings', 'attributes',
    );

    /**
     * Re-tag empty attribute objects so core's serializer emits `{}`.
     *
     * parse_blocks() decodes attribute JSON with json_decode($json, true), so
     * an empty JSON object becomes an empty PHP array and serialize_blocks()
     * writes it back as `[]`. A block that declared `"type": "object"` for that
     * attribute then receives an array, the type check in the editor fails, and
     * the block is reported as invalid.
     *
     * Only keys that are objects in every schema which uses them are converted,
     * so genuinely empty list attributes such as `ids` keep serializing as `[]`.
     *
     * @param array $blocks
     * @return array
     */
    private function restore_empty_objects(array $blocks) {
        foreach ($blocks as $index => $block) {
            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                $blocks[$index]['attrs'] = $this->retag_objects($block['attrs']);
            }

            if (!empty($block['innerBlocks'])) {
                $blocks[$index]['innerBlocks'] = $this->restore_empty_objects($block['innerBlocks']);
            }
        }

        return $blocks;
    }

    /**
     * @param array $data
     * @return array
     */
    private function retag_objects(array $data) {
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if ($value === array() && in_array((string) $key, self::$object_attribute_keys, true)) {
                $data[$key] = new stdClass();
                continue;
            }

            $data[$key] = $this->retag_objects($value);
        }

        return $data;
    }

    /**
     * @param array $blocks
     * @param array $originals
     */
    private function collect_html(array $blocks, array &$originals) {
        foreach ($blocks as $block) {
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $chunk) {
                    if (is_string($chunk) && $chunk !== '') {
                        WIT_HTML_Translator::collect($chunk, $originals);
                    }
                }
            }

            if (!empty($block['innerBlocks'])) {
                $this->collect_html($block['innerBlocks'], $originals);
            }
        }
    }

    /**
     * @param array $blocks
     * @param array $originals
     */
    private function collect_attrs(array $blocks, array &$originals) {
        foreach ($blocks as $block) {
            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                $this->collect_attr_values($block['attrs'], $originals, '');
            }

            if (!empty($block['innerBlocks'])) {
                $this->collect_attrs($block['innerBlocks'], $originals);
            }
        }
    }

    /**
     * @param array  $attrs
     * @param array  $originals
     * @param string $key
     */
    private function collect_attr_values(array $attrs, array &$originals, $key) {
        foreach ($attrs as $child_key => $value) {
            $effective_key = is_int($child_key) ? $key : (string) $child_key;

            if (is_array($value)) {
                if (WIT_Field_Rules::is_blocked_key($effective_key)) {
                    continue;
                }
                $this->collect_attr_values($value, $originals, $effective_key);
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            $trimmed = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            // Already found in this document's visible HTML: it is content by
            // definition, whatever the attribute is called.
            if (isset($originals[$trimmed])) {
                continue;
            }

            if (WIT_Field_Rules::is_translatable_field($effective_key, $value, $trimmed)) {
                $originals[$trimmed] = count($originals);
            }
        }
    }

    /**
     * @param array $blocks
     * @param array $map       original => translation
     * @param array $originals Strings that were collected, used to decide which
     *                         attribute values are safe to touch.
     * @return array
     */
    private function apply_blocks(array $blocks, array $map, array $originals) {
        foreach ($blocks as $index => $block) {
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $chunk_index => $chunk) {
                    if (is_string($chunk) && $chunk !== '') {
                        $blocks[$index]['innerContent'][$chunk_index] =
                            WIT_HTML_Translator::apply($chunk, $map, $this->strings_translated);
                    }
                }
            }

            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                $blocks[$index]['attrs'] = $this->apply_attr_values($block['attrs'], $map, $originals, '');
            }

            if (!empty($block['innerBlocks'])) {
                $blocks[$index]['innerBlocks'] = $this->apply_blocks($block['innerBlocks'], $map, $originals);
            }
        }

        return $blocks;
    }

    /**
     * @param array  $attrs
     * @param array  $map
     * @param array  $originals
     * @param string $key
     * @return array
     */
    private function apply_attr_values(array $attrs, array $map, array $originals, $key) {
        foreach ($attrs as $child_key => $value) {
            $effective_key = is_int($child_key) ? $key : (string) $child_key;

            if (is_array($value)) {
                if (WIT_Field_Rules::is_blocked_key($effective_key)) {
                    continue;
                }
                $attrs[$child_key] = $this->apply_attr_values($value, $map, $originals, $effective_key);
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            // An attribute holding HTML (Greenshift and friends store rich text
            // this way) is handed to the tokenizer so its markup survives.
            if (strpos($value, '<') !== false && preg_match('/<[a-zA-Z!\/][^>]*>/', $value)) {
                if (!WIT_Field_Rules::is_blocked_key($effective_key)) {
                    $attrs[$child_key] = WIT_HTML_Translator::apply($value, $map, $this->strings_translated);
                }
                continue;
            }

            $trimmed = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (!isset($map[$trimmed])) {
                continue;
            }

            // Only write to attributes that the collector deemed translatable —
            // either a recognised content key, or a value that appears as
            // visible text elsewhere in the document.
            $is_content_key = WIT_Field_Rules::is_translatable_field($effective_key, $value, $trimmed);
            $seen_in_html   = isset($originals[$trimmed]);

            if (!$is_content_key && !$seen_in_html) {
                continue;
            }

            if (WIT_Field_Rules::is_blocked_key($effective_key)) {
                continue;
            }

            $attrs[$child_key] = $map[$trimmed];
            $this->strings_translated++;
        }

        return $attrs;
    }

    // -----------------------------------------------------------------------
    // Classic editor
    // -----------------------------------------------------------------------

    /**
     * @param string                $html
     * @param WIT_Translator_Engine $translator
     * @param string                $target_language
     * @param string                $source_language
     * @return array{content:string,error:string|null}
     */
    private function translate_html($html, $translator, $target_language, $source_language) {
        $originals = array();
        WIT_HTML_Translator::collect($html, $originals);

        if (empty($originals)) {
            $this->debug_log[] = 'No se encontró texto traducible';
            return array('content' => $html, 'error' => null);
        }

        $map = $this->build_map(array_keys($originals), $translator, $target_language, $source_language);

        if (is_wp_error($map)) {
            return array('content' => $html, 'error' => $map->get_error_message());
        }

        return array(
            'content' => WIT_HTML_Translator::apply($html, $map, $this->strings_translated),
            'error'   => null,
        );
    }

    // -----------------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------------

    /**
     * Translate a list of strings and build the lookup map.
     *
     * @param string[]              $texts
     * @param WIT_Translator_Engine $translator
     * @param string                $target_language
     * @param string                $source_language
     * @return array|WP_Error Map of original => translation, or an error when
     *                        the provider returned nothing usable at all.
     */
    private function build_map(array $texts, $translator, $target_language, $source_language) {
        $this->debug_log[] = count($texts) . ' cadenas únicas recopiladas';

        if ($this->is_verbose()) {
            foreach ($texts as $text) {
                $this->debug_log[] = '  ENVÍA: "' . $this->preview($text) . '"';
            }
        }

        $translations = $translator->translate_batch($texts, $target_language, $source_language);

        $map        = array();
        $first_error = '';

        foreach ($texts as $i => $original) {
            $translation = isset($translations[$i]) ? $translations[$i] : null;

            if ($translation && empty($translation['error']) && $translation['translation'] !== '') {
                $map[$original] = $translation['translation'];

                if ($this->is_verbose()) {
                    $this->debug_log[] = '  RECIBE: "' . $this->preview($translation['translation']) . '"';
                }
            } else {
                $this->strings_failed++;
                if ($first_error === '' && !empty($translation['error'])) {
                    $first_error = $translation['error'];
                }
            }
        }

        // Nothing came back at all — a configuration or connectivity problem
        // that must surface as an error instead of silently saving the source
        // language into the translation.
        if (empty($map)) {
            return new WP_Error(
                'wit_translation_failed',
                $first_error !== ''
                    ? $first_error
                    : __('El proveedor de IA no devolvió ninguna traducción', 'wpml-imagina-translate')
            );
        }

        if ($this->strings_failed > 0 && $first_error !== '') {
            $this->debug_log[] = 'Primer error del proveedor: ' . $first_error;
        }

        return $map;
    }

    /**
     * Whether to log every source and translated string.
     *
     * Off by default: the log is returned to the browser and stored in a
     * transient, so it should not carry the full text of the post unless
     * someone is actively debugging.
     *
     * @return bool
     */
    private function is_verbose() {
        return (defined('WP_DEBUG') && WP_DEBUG) || apply_filters('wit_verbose_debug', false);
    }

    /**
     * @param string $text
     * @return string
     */
    private function preview($text) {
        return mb_substr($text, 0, 80) . (mb_strlen($text) > 80 ? '…' : '');
    }
}
