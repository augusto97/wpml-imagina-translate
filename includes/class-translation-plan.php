<?php
/**
 * Translation Plan - translating through the Claude chat instead of the API.
 *
 * The API path is: collect every string → send it to a provider → apply the
 * translations. Over MCP the middle step moves into the user's Claude chat,
 * so the subscription they already pay for does the translating and the API
 * key is never charged:
 *
 *   prepare()  collects the strings a post needs and hands them to the chat
 *   save()     receives the chat's translations and runs the normal pipeline
 *
 * Two properties matter more than anything else here.
 *
 * The chat never touches markup. It receives plain text strings and returns
 * plain text; everything structural — block JSON, Elementor trees, entities,
 * attributes — is rebuilt by the same code the API path uses. Letting a chat
 * rewrite HTML would undo every fidelity guarantee the pipeline has.
 *
 * The API is never called. save() runs the pipeline inside
 * WIT_Translator_Engine::with_external_translations(), where a string the
 * chat did not supply fails instead of falling back to a provider. And a post
 * is only applied once every string it needs has arrived, so a translation is
 * never published half in the source language.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Translation_Plan {

    /** Posts per prepare/save call. The user decides how many batches to run. */
    const MAX_POSTS = 10;

    /**
     * Budget for one prepare response, in JSON characters.
     *
     * Claude caps a tool result at roughly 150,000 characters, and a result
     * travels twice — as text and as structured content — so each copy has to
     * stay well under half of that.
     */
    const RESPONSE_BUDGET = 50000;

    /** Partial translations are kept this long while the chat sends the rest. */
    const STAGE_TTL = DAY_IN_SECONDS;

    // -----------------------------------------------------------------------
    // Collecting
    // -----------------------------------------------------------------------

    /**
     * Every string translating $post_id into $language would need, in the
     * order the pipeline encounters them, with where each one comes from.
     *
     * @param int    $post_id
     * @param string $language
     * @return array<string,string> text => context
     */
    private static function collect($post_id, $language) {
        $post    = get_post($post_id);
        $strings = array();

        $add = function (array $texts, $context) use (&$strings) {
            foreach ($texts as $text) {
                $text = (string) $text;
                if (trim($text) !== '' && !isset($strings[$text])) {
                    $strings[$text] = $context;
                }
            }
        };

        $add(array($post->post_title), 'title');
        $add(array($post->post_excerpt), 'excerpt');

        $elementor = new WIT_Elementor_Handler();

        if ($elementor->is_elementor_post($post_id)) {
            // Same decision translate_post() makes: Elementor ignores
            // post_content, so only its own data is translated.
            $add($elementor->collect_strings($post_id), 'elementor');
        } else {
            $add((new WIT_Content_Parser())->collect_strings($post->post_content), 'content');
        }

        foreach ((new WIT_Translation_Manager())->collect_meta_values($post_id) as $key => $value) {
            $add(array($value), 'meta:' . $key);
        }

        $add(WIT_WPML_Integration::instance()->collect_missing_term_strings($post_id, $language), 'term');

        return $strings;
    }

    /**
     * Short, stable identifier for a string.
     *
     * Derived from the text itself, so the same string has the same id in
     * every post and every call. That is what lets one batch share a single
     * translation map, and a chat that retries send the same ids again.
     *
     * @param string $text
     * @return string
     */
    public static function string_id($text) {
        return substr(hash('sha256', (string) $text), 0, 12);
    }

    /**
     * What a post needs from the chat.
     *
     * @param int    $post_id
     * @param string $language
     * @return array|WP_Error {
     *     @type int      $post_id
     *     @type string   $title
     *     @type string   $source_language
     *     @type string   $action   'create' or 'update'
     *     @type int      $total    Strings the post has.
     *     @type array    $pending  text => context, for strings neither the
     *                              glossary nor the memory already answers.
     * }
     */
    public static function for_post($post_id, $language) {
        $check = self::check_post($post_id, $language);

        if (is_wp_error($check)) {
            return $check;
        }

        $wpml            = WIT_WPML_Integration::instance();
        $source_language = $wpml->get_post_language($post_id);
        $all             = self::collect($post_id, $language);

        $engine     = new WIT_Translator_Engine();
        $unresolved = array_flip($engine->unresolved(array_keys($all), $language, $source_language));

        return array(
            'post_id'         => (int) $post_id,
            'title'           => get_the_title($post_id),
            'source_language' => $source_language,
            'action'          => $wpml->get_translation_id($post_id, $language) ? 'update' : 'create',
            'total'           => count($all),
            'pending'         => array_intersect_key($all, $unresolved),
        );
    }

    /**
     * Whether $post_id may be translated into $language by the current user.
     *
     * @param int    $post_id
     * @param string $language
     * @return true|WP_Error
     */
    private static function check_post($post_id, $language) {
        $wpml = WIT_WPML_Integration::instance();
        $post = get_post($post_id);

        if (!$post || in_array($post->post_type, array('revision', 'attachment', 'nav_menu_item'), true)) {
            return new WP_Error('wit_not_found', sprintf(
                /* translators: %d: post id */
                __('No existe ningún contenido con ID %d.', 'wpml-imagina-translate'),
                $post_id
            ));
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('wit_forbidden', __('No tienes permiso para editar este contenido.', 'wpml-imagina-translate'));
        }

        if (!$wpml->is_active_language($language)) {
            return new WP_Error('wit_bad_language', sprintf(
                /* translators: %s: language code */
                __('"%s" no es un idioma activo en WPML.', 'wpml-imagina-translate'),
                $language
            ));
        }

        $source_language = $wpml->get_post_language($post_id);

        if ($source_language !== $wpml->get_default_language()) {
            return new WP_Error('wit_not_source', __('Este contenido ya es una traducción. Indica el original.', 'wpml-imagina-translate'));
        }

        if ($source_language === $language) {
            return new WP_Error('wit_same_language', __('El idioma destino es el mismo que el del original.', 'wpml-imagina-translate'));
        }

        $translation_id = $wpml->get_translation_id($post_id, $language);

        if ($translation_id && !current_user_can('edit_post', $translation_id)) {
            return new WP_Error('wit_forbidden', __('No tienes permiso para editar la traducción existente.', 'wpml-imagina-translate'));
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // prepare
    // -----------------------------------------------------------------------

    /**
     * Strings the chat has to translate for a batch of posts.
     *
     * Strings are de-duplicated across the whole batch: a button label shared
     * by ten pages is sent once, translated once, and comes out identical on
     * every page.
     *
     * @param int[]  $post_ids At most MAX_POSTS.
     * @param string $language
     * @param int    $offset   For a single post too large for one response,
     *                         where to resume.
     * @return array|WP_Error
     */
    public static function prepare(array $post_ids, $language, $offset = 0) {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));

        if (empty($post_ids)) {
            return new WP_Error('wit_no_posts', __('Indica al menos un contenido.', 'wpml-imagina-translate'));
        }

        if (count($post_ids) > self::MAX_POSTS) {
            return new WP_Error('wit_too_many', sprintf(
                /* translators: %d: maximum posts per batch */
                __('Como máximo %d contenidos por tanda. Divide el trabajo en varias tandas.', 'wpml-imagina-translate'),
                self::MAX_POSTS
            ));
        }

        if ($offset > 0 && count($post_ids) > 1) {
            return new WP_Error('wit_bad_offset', __('"offset" solo se usa con un único contenido.', 'wpml-imagina-translate'));
        }

        $strings  = array();   // id => {id, text, context}
        $posts    = array();
        $deferred = array();
        $errors   = array();
        $used     = 0;
        $next     = null;

        foreach ($post_ids as $post_id) {
            $plan = self::for_post($post_id, $language);

            if (is_wp_error($plan)) {
                $errors[] = array('post_id' => $post_id, 'error' => $plan->get_error_message());
                continue;
            }

            $pending = $plan['pending'];

            if ($offset > 0) {
                $pending = array_slice($pending, $offset, null, true);
            }

            // Budget: what this post would add to the response.
            $cost = 0;
            $new  = array();
            foreach ($pending as $text => $context) {
                $id = self::string_id($text);
                if (!isset($strings[$id]) && !isset($new[$id])) {
                    $new[$id] = array('id' => $id, 'text' => $text, 'context' => $context);
                    $cost    += mb_strlen($text) + 60;
                }
            }

            $is_first = empty($posts);

            if (!$is_first && $used + $cost > self::RESPONSE_BUDGET) {
                // Does not fit alongside what is already in this response;
                // the chat prepares it in its next call.
                $deferred[] = $post_id;
                continue;
            }

            if ($is_first && $cost > self::RESPONSE_BUDGET) {
                // One post larger than a whole response: send what fits and
                // say where to resume. save() keeps the partial translations.
                $taken = array();
                $spent = 0;
                $count = 0;
                foreach ($new as $id => $item) {
                    $item_cost = mb_strlen($item['text']) + 60;
                    if ($spent + $item_cost > self::RESPONSE_BUDGET && $count > 0) {
                        break;
                    }
                    $taken[$id] = $item;
                    $spent     += $item_cost;
                    $count++;
                }
                $new  = $taken;
                $cost = $spent;
                $next = $offset + $count;
            }

            $strings += $new;
            $used    += $cost;

            // Only ids present in this response — a post cut short by the
            // budget lists what was actually sent, not what is still to come.
            $sent_ids = array();
            foreach (array_keys($pending) as $text) {
                $id = self::string_id($text);
                if (isset($strings[$id])) {
                    $sent_ids[] = $id;
                }
            }

            $posts[] = array(
                'post_id'       => $plan['post_id'],
                'title'         => $plan['title'],
                'action'        => $plan['action'],
                'total_strings' => $plan['total'],
                'to_translate'  => count($plan['pending']),
                // Everything already known: saving needs no strings at all.
                'ready'         => empty($plan['pending']),
                'string_ids'    => $sent_ids,
            );

            if ($next !== null) {
                break;
            }
        }

        if (empty($posts) && !empty($errors)) {
            return new WP_Error('wit_nothing_to_prepare', $errors[0]['error']);
        }

        $texts   = array_column(array_values($strings), 'text');
        $engine  = new WIT_Translator_Engine();
        $glossary = $engine->glossary_guidance($texts, $language);

        $characters = 0;
        foreach ($texts as $text) {
            $characters += mb_strlen($text);
        }

        $response = array(
            'target_language' => $language,
            'target_language_name' => self::language_name($language),
            'instructions'    => self::instructions($language, $glossary),
            'strings'         => array_values($strings),
            'posts'           => $posts,
            'totals'          => array(
                'strings'    => count($strings),
                'characters' => $characters,
            ),
        );

        if ($glossary !== '') {
            $response['glossary'] = $glossary;
        }
        if (!empty($deferred)) {
            $response['deferred_post_ids'] = $deferred;
            $response['deferred_note']     = __('Estos contenidos no cupieron en esta respuesta. Tradúcelos en otra llamada a prepare_translation.', 'wpml-imagina-translate');
        }
        if ($next !== null) {
            $response['next_offset'] = $next;
            $response['next_note']   = __('Este contenido es demasiado grande para una sola respuesta. Guarda estas cadenas y vuelve a llamar con offset = next_offset para el resto; las traducciones parciales se conservan.', 'wpml-imagina-translate');
        }
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return $response;
    }

    /**
     * How the chat should translate. Travels with every prepare response,
     * because over MCP there is no prompt of ours to put it in.
     *
     * @param string $language
     * @param string $glossary
     * @return string
     */
    private static function instructions($language, $glossary) {
        $text = sprintf(
            'Translate the "text" of every item into %s. Then call save_translation with '
            . '"translations" as an object mapping each item "id" to its translation. '
            . 'Rules: translate only the text; never add quotes, notes or explanations; '
            . 'return plain text, never HTML; keep numbers, URLs, e-mail addresses, '
            . 'placeholders such as %%s or {name}, and [shortcodes] exactly as they are; '
            . 'preserve leading and trailing punctuation and line breaks; keep brand and '
            . 'product names unchanged. "context" says where the string appears '
            . '(title, excerpt, content, elementor, meta:<field>, term) so you can pick the '
            . 'right register: a button label should stay short.',
            self::language_name($language)
        );

        if ($glossary !== '') {
            $text .= ' Follow the glossary exactly; it overrides your own judgement.';
        }

        return $text;
    }

    // -----------------------------------------------------------------------
    // save
    // -----------------------------------------------------------------------

    /**
     * Apply translations the chat produced.
     *
     * Translations are merged into a per-post staging area first, so a large
     * post can arrive over several calls and a retried call is harmless. A
     * post is written only once every string it needs is present.
     *
     * @param int[]                $post_ids
     * @param string               $language
     * @param array<string,string> $translations id => translation
     * @return array|WP_Error
     */
    public static function save(array $post_ids, $language, array $translations) {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));

        if (empty($post_ids)) {
            return new WP_Error('wit_no_posts', __('Indica al menos un contenido.', 'wpml-imagina-translate'));
        }

        if (count($post_ids) > self::MAX_POSTS) {
            return new WP_Error('wit_too_many', sprintf(
                /* translators: %d: maximum posts per batch */
                __('Como máximo %d contenidos por tanda.', 'wpml-imagina-translate'),
                self::MAX_POSTS
            ));
        }

        // Keys are ids; values must be non-empty strings.
        $provided = array();
        foreach ($translations as $id => $value) {
            if (is_string($value) && trim($value) !== '') {
                $provided[(string) $id] = $value;
            }
        }

        $results   = array();
        $used_ids  = array();

        foreach ($post_ids as $post_id) {
            $plan = self::for_post($post_id, $language);

            if (is_wp_error($plan)) {
                $results[] = array('post_id' => $post_id, 'status' => 'error', 'message' => $plan->get_error_message());
                continue;
            }

            $required = array();   // id => text
            foreach (array_keys($plan['pending']) as $text) {
                $required[self::string_id($text)] = $text;
            }

            $stage_key = self::stage_key($post_id, $language);
            $stage     = get_transient($stage_key);
            $stage     = is_array($stage) ? $stage : array();

            foreach ($required as $id => $unused) {
                if (isset($provided[$id])) {
                    $stage[$id]     = $provided[$id];
                    $used_ids[$id]  = true;
                }
            }

            $missing = array_diff_key($required, $stage);

            if (!empty($missing)) {
                set_transient($stage_key, $stage, self::STAGE_TTL);

                $list = array();
                foreach (array_slice($missing, 0, 50, true) as $id => $text) {
                    $list[] = array('id' => $id, 'text' => $text);
                }

                $results[] = array(
                    'post_id'       => $post_id,
                    'status'        => 'incomplete',
                    'message'       => sprintf(
                        /* translators: 1: strings missing, 2: strings required */
                        __('Faltan %1$d de %2$d cadenas; no se ha guardado nada todavía. Las recibidas se conservan: envía solo las que faltan.', 'wpml-imagina-translate'),
                        count($missing),
                        count($required)
                    ),
                    'missing_count' => count($missing),
                    'missing'       => $list,
                );
                continue;
            }

            $map = array();
            foreach ($required as $id => $text) {
                $map[$text] = $stage[$id];
            }

            $outcome = WIT_Translator_Engine::with_external_translations($map, function () use ($post_id, $language) {
                return (new WIT_Translation_Manager())->translate_post($post_id, $language);
            });

            $gaps = WIT_Translator_Engine::external_missing();

            if (empty($outcome['success'])) {
                // Staging is kept so a retry does not need the strings again.
                set_transient($stage_key, $stage, self::STAGE_TTL);
                $results[] = array(
                    'post_id' => $post_id,
                    'status'  => 'error',
                    'message' => isset($outcome['message']) ? $outcome['message'] : __('Error al guardar la traducción', 'wpml-imagina-translate'),
                );
                continue;
            }

            delete_transient($stage_key);

            $translation_id = (int) $outcome['translated_post_id'];
            $entry = array(
                'post_id'        => $post_id,
                'status'         => $plan['action'] === 'update' ? 'updated' : 'created',
                'translation_id' => $translation_id,
                'post_status'    => get_post_status($translation_id),
                'edit_url'       => get_edit_post_link($translation_id, 'raw'),
                'strings_saved'  => count($required),
            );

            if (!empty($gaps)) {
                // The plan and the pipeline disagreed — should not happen, and
                // is reported rather than hidden.
                $entry['warning'] = sprintf(
                    /* translators: %d: number of strings */
                    __('%d cadenas no estaban en el plan y quedaron en el idioma original.', 'wpml-imagina-translate'),
                    count($gaps)
                );
            }

            $results[] = $entry;
        }

        $ignored = count(array_diff_key($provided, $used_ids));

        $response = array('results' => $results);

        if ($ignored > 0) {
            $response['ignored_ids'] = $ignored;
            $response['ignored_note'] = __('Algunos ids no corresponden a ninguna cadena pendiente de estos contenidos y se ignoraron.', 'wpml-imagina-translate');
        }

        return $response;
    }

    /**
     * Staging key: per user, so two people translating the same post through
     * two chats never mix their strings.
     *
     * @param int    $post_id
     * @param string $language
     * @return string
     */
    private static function stage_key($post_id, $language) {
        return 'wit_mcp_stage_' . md5(get_current_user_id() . '|' . (int) $post_id . '|' . $language);
    }

    /**
     * English name of a language, for instructions addressed to the model.
     *
     * @param string $code
     * @return string
     */
    public static function language_name($code) {
        foreach (WIT_WPML_Integration::instance()->get_active_languages() as $language) {
            if ($language['code'] === $code) {
                return $language['english_name'];
            }
        }

        return $code;
    }
}
