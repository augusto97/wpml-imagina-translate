<?php
/**
 * Elementor Handler - translates Elementor page-builder content.
 *
 * Storage
 * -------
 * Elementor keeps its editable data in the `_elementor_data` post meta as a
 * JSON array of elements. That has not changed from 1.x through 4.x. What DID
 * change in Elementor 4.0 is the shape of the JSON, because the "atomic"
 * (V4) editor became the default for new sites.
 *
 * Legacy (V3) widget:
 *     { "elType":"widget", "widgetType":"heading",
 *       "settings": { "title":"Hello", "header_size":"h2" } }
 *
 * Atomic (V4) widget — values are wrapped in typed prop objects:
 *     { "elType":"widget", "widgetType":"e-heading",
 *       "settings": {
 *         "title":   { "$$type":"string",  "value":"Hello" },
 *         "tag":     { "$$type":"string",  "value":"h3" },
 *         "classes": { "$$type":"classes", "value":["e-abc","g-123"] } },
 *       "styles": { ... }, "interactions": { ... } }
 *
 * Atomic rich text nests further still (`html-v3`):
 *     "title": { "$$type":"html-v3", "value": {
 *         "content":  { "$$type":"string", "value":"Hello" },
 *         "children": [ { "id":"abc1", "type":"span", "content":"world" } ] } }
 *
 * A naive walker that translates every string it finds will corrupt an atomic
 * page: it would translate the literal type discriminators ("string"), the
 * style breakpoints ("desktop"), and the base64 blob in `custom_css.raw`.
 * This handler therefore dispatches on `$$type` and only descends into prop
 * types that actually carry text.
 *
 * Saving
 * ------
 * Writing `_elementor_data` directly skips `elementor/document/after_save`,
 * whose listeners rebuild atomic style caches and global-class relations.
 * Skipping them on a V4 page produces a page that renders without styles.
 * The handler therefore prefers `$document->save()` and only falls back to a
 * raw meta write when Elementor refuses (no editable user context), repairing
 * the caches by hand in that case.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Elementor_Handler {

    /**
     * Prop types that carry translatable text.
     *
     * Every other `$$type` (classes, color, size, dimensions, link, image,
     * background, shadow…) is skipped wholesale.
     *
     * @var string[]
     */
    private static $text_prop_types = array('string', 'html', 'html-v2', 'html-v3');

    /**
     * Top-level element keys that must never be walked for text.
     *
     * @var string[]
     */
    private static $skip_element_keys = array(
        'styles', 'editor_settings', 'interactions', 'version',
        'id', 'elType', 'widgetType', 'isInner', 'isLocked',
        'defaultEditSettings', 'editSettings', 'htmlCache',
    );

    /**
     * Meta keys that must not be copied to the translation because they are
     * derived state that Elementor regenerates.
     *
     * Mirrors Elementor's own exclusion list in includes/compatibility.php,
     * extended with the V4 global-class relation keys.
     *
     * @var string[]
     */
    private static $derived_meta_keys = array(
        '_elementor_css',
        '_elementor_element_cache',
        '_elementor_page_assets',
        '_elementor_controls_usage',
        '_elementor_screenshot',
        '_elementor_source_image_hash',
        '_elementor_used_global_class',
        '_elementor_used_global_class_preview',
        '_elementor_global_class_usage_indexed',
        '_elementor_global_class_usage_indexed_preview',
        '_elementor_global_class_using_documents',
        '_elementor_global_class_using_documents_preview',
    );

    /** @var string[] Debug messages accumulated during translation. */
    private $debug_log = array();

    /** @var int Number of strings replaced. */
    private $strings_translated = 0;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Whether Elementor is active and its API is usable.
     *
     * @return bool
     */
    public function is_available() {
        return did_action('elementor/loaded') > 0 && class_exists('\Elementor\Plugin');
    }

    /**
     * Whether a post was built with Elementor.
     *
     * @param int $post_id
     * @return bool
     */
    public function is_elementor_post($post_id) {
        // Matches Document::BUILT_WITH_ELEMENTOR_META_KEY.
        return get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
    }

    /**
     * Translate a post's Elementor data into the target post.
     *
     * @param int    $source_post_id
     * @param int    $target_post_id
     * @param string $target_language
     * @param string $source_language
     * @return array{debug:string[],error:string|null}
     */
    public function translate($source_post_id, $target_post_id, $target_language, $source_language) {
        $this->debug_log          = array();
        $this->strings_translated = 0;

        $elements = $this->read_elements($source_post_id);

        if ($elements === null) {
            $this->debug_log[] = 'Elementor: no se pudo leer _elementor_data del post #' . $source_post_id;
            return $this->result(__('No se pudo leer los datos de Elementor', 'wpml-imagina-translate'));
        }

        $this->copy_meta($source_post_id, $target_post_id);

        // Pass 1 — collect every unique translatable string.
        $originals = array();
        $this->collect_elements($elements, $originals);

        if (empty($originals)) {
            $this->debug_log[] = 'Elementor: no se encontró texto traducible';
            $this->write($target_post_id, $elements);
            return $this->result(null);
        }

        $texts = array_keys($originals);
        $this->debug_log[] = 'Elementor: ' . count($texts) . ' cadenas únicas recopiladas';

        // Pass 2 — one batched API call.
        $translator   = new WIT_Translator_Engine();
        $translations = $translator->translate_batch($texts, $target_language, $source_language);

        $map    = array();
        $failed = 0;
        foreach ($texts as $i => $original) {
            if (isset($translations[$i]) && empty($translations[$i]['error']) && $translations[$i]['translation'] !== '') {
                $map[$original] = $translations[$i]['translation'];
            } else {
                $failed++;
            }
        }

        if (empty($map)) {
            $first_error = isset($translations[0]['error']) ? $translations[0]['error'] : '';
            $this->debug_log[] = 'Elementor: la API no devolvió traducciones. ' . $first_error;
            $this->write($target_post_id, $elements);
            return $this->result(
                $first_error ?: __('El proveedor de IA no devolvió traducciones', 'wpml-imagina-translate')
            );
        }

        if ($failed > 0) {
            $this->debug_log[] = 'Elementor: ' . $failed . ' de ' . count($texts) . ' cadenas no se pudieron traducir';
        }

        // Pass 3 — write translations back into the element tree.
        $translated = $this->apply_elements($elements, $map);

        $this->write($target_post_id, $translated);

        $this->debug_log[] = 'Elementor: === CADENAS TRADUCIDAS: ' . $this->strings_translated . ' ===';

        return $this->result(null);
    }

    /**
     * Every string translate() would send for translation.
     *
     * Same collector as translate(), so an MCP plan asks the chat for exactly
     * what the pipeline will look up.
     *
     * @param int $post_id
     * @return string[]
     */
    public function collect_strings($post_id) {
        $elements = $this->read_elements($post_id);

        if ($elements === null) {
            return array();
        }

        $originals = array();
        $this->collect_elements($elements, $originals);

        return array_keys($originals);
    }

    /**
     * Rewrite a post's Elementor data through a map, touching nothing else.
     *
     * Used to correct one string in an existing translation.
     *
     * @param int   $post_id
     * @param array $map text => replacement
     * @return int Replacements made; 0 means nothing was written.
     */
    public function apply_map_to_post($post_id, array $map) {
        $elements = $this->read_elements($post_id);

        if ($elements === null || empty($map)) {
            return 0;
        }

        $this->strings_translated = 0;
        $applied = $this->apply_elements($elements, $map);

        if ($this->strings_translated > 0) {
            $this->write($post_id, $applied);
        }

        return $this->strings_translated;
    }

    // -----------------------------------------------------------------------
    // Reading and writing
    // -----------------------------------------------------------------------

    /**
     * Read the element tree of a post.
     *
     * Uses Elementor's document API when available (it resolves the correct
     * meta for revisions and previews) and falls back to the raw meta.
     *
     * @param int $post_id
     * @return array|null Null when the data cannot be read or decoded.
     */
    private function read_elements($post_id) {
        if ($this->is_available()) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id, false);
            if ($document) {
                // get_elements_data() is a pure read. get_elements_raw_data()
                // would run prop-type migrations and persist them to the SOURCE
                // post, which a translation job must never do.
                $elements = $document->get_elements_data();
                if (is_array($elements)) {
                    return $elements;
                }
            }
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);

        if (empty($raw)) {
            return null;
        }

        $elements = is_array($raw) ? $raw : json_decode($raw, true);

        return (json_last_error() === JSON_ERROR_NONE && is_array($elements)) ? $elements : null;
    }

    /**
     * Persist the translated element tree to the target post.
     *
     * @param int   $post_id
     * @param array $elements
     */
    private function write($post_id, array $elements) {
        $saved = false;

        if ($this->is_available()) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id, false);

            if ($document) {
                try {
                    // The preferred path: runs the full save pipeline, which
                    // rebuilds atomic styles, global-class relations and the
                    // plain-text mirror of the content.
                    $saved = (bool) $document->save(array('elements' => $elements));
                } catch (\Throwable $e) {
                    // Atomic settings validation throws rather than returning
                    // false. Fall through to the raw write and record why.
                    $this->debug_log[] = 'Elementor: document->save() falló (' . $e->getMessage() . '), usando escritura directa';
                }
            }
        }

        if ($saved) {
            $this->debug_log[] = 'Elementor: guardado con la API de documentos';
            return;
        }

        // Fallback: write the meta directly and repair everything save() would
        // have done.
        //
        // wp_slash() is mandatory. update_post_meta() runs wp_unslash() on the
        // value, whose stripslashes() removes the backslash before ANY
        // character, so an un-slashed JSON string has \n turned into a literal
        // "n", \" into ", and \\ into \. Elementor slashes for the same reason.
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)));

        $this->purge_caches($post_id);

        // Elementor mirrors the page as plain text into post_content on every
        // save. WordPress search, RSS, excerpts and SEO plugins read that field,
        // so leaving the source language there would index the wrong language.
        if ($this->is_available() && isset(\Elementor\Plugin::$instance->db)) {
            \Elementor\Plugin::$instance->db->save_plain_text($post_id);
        }

        $this->debug_log[] = 'Elementor: guardado con escritura directa de meta';
    }

    /**
     * Copy the structural Elementor meta that the translation needs.
     *
     * @param int $source_id
     * @param int $target_id
     */
    private function copy_meta($source_id, $target_id) {
        // Elementor's own copier handles every `_elementor*` key with the right
        // slashing and unserialisation, and is what its Polylang integration
        // uses. Prefer it when available.
        if ($this->is_available() && isset(\Elementor\Plugin::$instance->db)) {
            \Elementor\Plugin::$instance->db->copy_elementor_meta($source_id, $target_id);
        } else {
            $keys = array(
                '_elementor_edit_mode',
                '_elementor_template_type',
                '_elementor_version',
                '_elementor_pro_version',
                '_elementor_page_settings',
                '_elementor_template_widget_type',
            );

            foreach ($keys as $key) {
                $value = get_post_meta($source_id, $key, true);
                if ($value !== '' && $value !== false) {
                    update_post_meta($target_id, $key, wp_slash($value));
                }
            }
        }

        // Remove the derived meta the copier may have brought across, so
        // Elementor regenerates it for the translated page.
        $this->purge_caches($target_id);
    }

    /**
     * Delete every cached artefact Elementor derives from the element tree.
     *
     * @param int $post_id
     */
    private function purge_caches($post_id) {
        // Post_CSS::delete() removes the generated post-N.css FILE as well as
        // the _elementor_css meta, which deleting the meta alone would leave
        // stale on disk.
        if ($this->is_available() && class_exists('\Elementor\Core\Files\CSS\Post')) {
            try {
                \Elementor\Core\Files\CSS\Post::create($post_id)->delete();
            } catch (\Throwable $e) {
                // Non-fatal: fall back to deleting the meta below.
            }
        }

        foreach (self::$derived_meta_keys as $key) {
            delete_post_meta($post_id, $key);
        }
    }

    // -----------------------------------------------------------------------
    // Pass 1 — collection
    // -----------------------------------------------------------------------

    /**
     * @param array $elements
     * @param array $originals text => index
     */
    private function collect_elements(array $elements, array &$originals) {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (!empty($element['settings']) && is_array($element['settings'])) {
                $this->walk_settings($element['settings'], $originals, null, '', $this->controls_for($element));
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->collect_elements($element['elements'], $originals);
            }
        }
    }

    /**
     * @param array $elements
     * @param array $map
     * @return array
     */
    private function apply_elements(array $elements, array $map) {
        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                continue;
            }

            if (!empty($element['settings']) && is_array($element['settings'])) {
                $unused = array();
                $elements[$index]['settings'] = $this->walk_settings($element['settings'], $unused, $map, '', $this->controls_for($element));
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $elements[$index]['elements'] = $this->apply_elements($element['elements'], $map);
            }
        }

        return $elements;
    }

    /**
     * Single recursive walk used for both collection and application.
     *
     * Passing $map === null collects into $originals; passing an array applies
     * it. Sharing one traversal guarantees the two passes can never disagree
     * about which fields are translatable.
     *
     * @param array      $settings
     * @param array      $originals Collected strings (collection mode).
     * @param array|null $map       Translation map (application mode).
     * @param string     $key       Key of the current node.
     * @return array The (possibly modified) settings.
     */
    private function walk_settings(array $settings, array &$originals, $map, $key, $controls = null) {
        // ---- atomic prop wrapper: { "$$type": T, "value": V } ----
        if (isset($settings['$$type']) && array_key_exists('value', $settings)) {
            $type = $settings['$$type'];

            if (!in_array($type, self::$text_prop_types, true)) {
                // classes, color, size, link, image, dimensions, background…
                return $settings;
            }

            $value = $settings['value'];

            if ($type === 'html-v3' && is_array($value)) {
                $settings['value'] = $this->walk_html_v3($value, $originals, $map, $key);
                return $settings;
            }

            if (is_string($value)) {
                $settings['value'] = $this->handle_string($value, $originals, $map, $key, $type !== 'string');
                return $settings;
            }

            if (is_array($value)) {
                $settings['value'] = $this->walk_settings($value, $originals, $map, $key);
            }

            return $settings;
        }

        foreach ($settings as $child_key => $value) {
            // Numeric keys come from repeaters; the parent key still describes
            // what the items are, so inherit it.
            $effective_key = is_int($child_key) ? $key : (string) $child_key;

            if (in_array($effective_key, self::$skip_element_keys, true)) {
                continue;
            }

            // Elementor knows what every control is. When it says a setting is
            // a select, a switcher, a date or a URL, that settles it — whatever
            // the value looks like. Heuristics alone read "euro", "circle" or
            // "email" as words, and translated a form's submit_actions into
            // values Elementor does not recognise: the form kept showing and
            // silently stopped sending.
            $control = (is_array($controls) && is_string($child_key) && isset($controls[$child_key]))
                ? $controls[$child_key]
                : null;

            if ($control !== null) {
                $type = isset($control['type']) ? (string) $control['type'] : '';

                if (self::is_repeater_type($type) && is_array($value)) {
                    $fields = self::field_controls($control);
                    foreach ($value as $i => $item) {
                        if (is_array($item)) {
                            $settings[$child_key][$i] = $this->walk_settings($item, $originals, $map, $effective_key, $fields);
                        }
                    }
                    continue;
                }

                if (!in_array($type, self::$text_control_types, true)) {
                    continue;
                }
            }

            if (is_array($value)) {
                if (WIT_Field_Rules::is_blocked_key($effective_key)) {
                    continue;
                }
                $settings[$child_key] = $this->walk_settings($value, $originals, $map, $effective_key);
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            $settings[$child_key] = $this->handle_string($value, $originals, $map, $effective_key, $this->is_html($value));
        }

        return $settings;
    }

    /**
     * Walk the nested structure of an `html-v3` rich-text prop.
     *
     * Shape: { content: <prop>, children: [ { content: "…", children: [...] } ] }
     *
     * @param array      $value
     * @param array      $originals
     * @param array|null $map
     * @param string     $key
     * @return array
     */
    private function walk_html_v3(array $value, array &$originals, $map, $key) {
        if (isset($value['content'])) {
            if (is_array($value['content'])) {
                $value['content'] = $this->walk_settings($value['content'], $originals, $map, $key);
            } elseif (is_string($value['content'])) {
                $value['content'] = $this->handle_string($value['content'], $originals, $map, $key, true);
            }
        }

        if (!empty($value['children']) && is_array($value['children'])) {
            foreach ($value['children'] as $i => $child) {
                if (is_array($child)) {
                    $value['children'][$i] = $this->walk_html_v3($child, $originals, $map, $key);
                }
            }
        }

        return $value;
    }

    /**
     * Collect or translate a single string value.
     *
     * @param string     $value
     * @param array      $originals
     * @param array|null $map
     * @param string     $key
     * @param bool       $is_html Whether to treat the value as HTML.
     * @return string
     */
    private function handle_string($value, array &$originals, $map, $key, $is_html) {
        if ($key === 'field_options') {
            return $this->handle_options($value, $originals, $map);
        }

        if ($key === 'rotating_text') {
            return $this->handle_lines($value, $originals, $map);
        }

        if ($is_html) {
            if (WIT_Field_Rules::is_blocked_key($key)) {
                return $value;
            }
            if ($map === null) {
                WIT_HTML_Translator::collect($value, $originals);
                return $value;
            }
            return WIT_HTML_Translator::apply($value, $map, $this->strings_translated);
        }

        if (!WIT_Field_Rules::is_translatable_field($key, $value)) {
            return $value;
        }

        $trimmed = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($map === null) {
            if (!isset($originals[$trimmed])) {
                $originals[$trimmed] = count($originals);
            }
            return $value;
        }

        if (!isset($map[$trimmed])) {
            return $value;
        }

        preg_match('/^(\s*)(?:.*?)(\s*)$/su', $value, $ws);
        $this->strings_translated++;

        return $ws[1] . $map[$trimmed] . $ws[2];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param string $value
     * @return bool
     */
    /**
     * Control types whose value is text a visitor reads.
     *
     * Everything else Elementor registers — select, select2, switcher,
     * choose, number, slider, color, url, date_time, icons, hidden, animation
     * and the rest — holds a machine value, and translating it breaks the
     * widget. media is kept so its alt text is still reached (the field rules
     * leave its url and id alone), and code so free HTML keeps being handled
     * by the tokenizer as before.
     *
     * @var string[]
     */
    private static $text_control_types = array('text', 'textarea', 'wysiwyg', 'code', 'media');

    /** @var array<string,array|null> Controls per element type, per request. */
    private $controls_cache = array();

    /**
     * Elementor's control definitions for an element, keyed by setting name.
     *
     * Null when Elementor is not loaded or does not know the element — then
     * the field rules decide alone, as they always have.
     *
     * @param array $element
     * @return array|null
     */
    private function controls_for(array $element) {
        if (!$this->is_available()) {
            return null;
        }

        $el_type = isset($element['elType']) ? (string) $element['elType'] : '';
        $name    = $el_type === 'widget' && isset($element['widgetType']) ? (string) $element['widgetType'] : $el_type;

        if ($name === '') {
            return null;
        }

        if (array_key_exists($name, $this->controls_cache)) {
            return $this->controls_cache[$name];
        }

        $controls = null;

        try {
            $plugin   = \Elementor\Plugin::$instance;
            $instance = $el_type === 'widget'
                ? $plugin->widgets_manager->get_widget_types($name)
                : $plugin->elements_manager->get_element_types($name);

            if ($instance && method_exists($instance, 'get_controls')) {
                $list = $instance->get_controls();
                $controls = is_array($list) && !empty($list) ? $list : null;
            }
        } catch (\Throwable $e) {
            $controls = null;
        }

        return $this->controls_cache[$name] = $controls;
    }

    /**
     * repeater, form-fields-repeater, nested-elements-repeater…
     *
     * @param string $type
     * @return bool
     */
    private static function is_repeater_type($type) {
        return $type !== '' && substr($type, -8) === 'repeater';
    }

    /**
     * A repeater's field definitions keyed by setting name.
     *
     * Elementor stores them as a list with a "name" key in some versions and
     * keyed by name in others.
     *
     * @param array $control
     * @return array
     */
    private static function field_controls(array $control) {
        $fields = array();

        foreach (isset($control['fields']) ? (array) $control['fields'] : array() as $index => $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = isset($field['name']) ? (string) $field['name'] : (string) $index;
            $fields[$name] = $field;
        }

        return $fields;
    }

    /**
     * A form select/radio/checkbox field's options: one per line, "Label" or
     * "Label|value".
     *
     * Only the label is translated. The value is what the form submits, and
     * integrations, conditions and anything that reads submissions key on it:
     * a translated value quietly stops matching. When an option has no value,
     * Elementor submits the label, so the source label is written in as the
     * value — the visitor reads the translation, the site keeps receiving the
     * same value in every language.
     *
     * @return string
     */
    private function handle_options($value, array &$originals, $map) {
        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $value));
        $out   = array();

        foreach ($lines as $line) {
            $parts = explode('|', $line, 2);
            $label = trim($parts[0]);

            if ($label === '' || !WIT_HTML_Translator::is_translatable($label)) {
                $out[] = $line;
                continue;
            }

            if ($map === null) {
                if (!isset($originals[$label])) {
                    $originals[$label] = count($originals);
                }
                $out[] = $line;
                continue;
            }

            if (!isset($map[$label])) {
                $out[] = $line;
                continue;
            }

            $submitted = isset($parts[1]) ? $parts[1] : $label;
            $out[]     = trim(str_replace(array('|', "\n"), array('', ' '), $map[$label])) . '|' . $submitted;
            $this->strings_translated++;
        }

        return implode("\n", $out);
    }

    /**
     * A setting that is a list, one entry per line — the animated headline's
     * rotating words. Each line is its own string, so the number of entries,
     * which is the animation, cannot change whatever the translator does
     * with line breaks.
     *
     * @return string
     */
    private function handle_lines($value, array &$originals, $map) {
        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $value));

        foreach ($lines as $i => $line) {
            $text = trim($line);

            if ($text === '' || !WIT_HTML_Translator::is_translatable($text)) {
                continue;
            }

            if ($map === null) {
                if (!isset($originals[$text])) {
                    $originals[$text] = count($originals);
                }
                continue;
            }

            if (isset($map[$text])) {
                $lines[$i] = trim(str_replace("\n", ' ', $map[$text]));
                $this->strings_translated++;
            }
        }

        return implode("\n", $lines);
    }

    private function is_html($value) {
        return strpos($value, '<') !== false && (bool) preg_match('/<[a-zA-Z!\/][^>]*>/', $value);
    }

    /**
     * @param string|null $error
     * @return array{debug:string[],error:string|null}
     */
    private function result($error) {
        return array('debug' => $this->debug_log, 'error' => $error);
    }
}
