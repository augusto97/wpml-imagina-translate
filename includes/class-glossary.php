<?php
/**
 * Glossary - brand terms, fixed translations and do-not-translate rules.
 *
 * A single global prompt cannot express "never translate our product names, and
 * always render Servicios as Services, but only for English". That is what
 * separates output a client will accept from output that reads as machine
 * translation, and it is the main thing paid translation services sell.
 *
 * Format, one rule per line:
 *
 *     # Comments and blank lines are ignored
 *     Imagina                      -> never translated, in any language
 *     Servicios = Services         -> fixed translation, every language
 *     [en] Inicio = Home           -> fixed translation, English only
 *     [fr,de] Contacto = Kontakt   -> several languages at once
 *
 * Rules are applied in two ways, because a term behaves differently depending
 * on whether it is the whole string or part of a sentence:
 *
 *  1. A rule matching an ENTIRE string resolves it without calling the API at
 *     all. That is both free and perfectly consistent.
 *  2. Rules whose term appears INSIDE a longer string are injected into the
 *     prompt as explicit instructions, scoped to the terms actually present in
 *     that batch so the prompt stays small.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Glossary {

    /** @var array[] Parsed rules. */
    private $rules;

    /**
     * @param string|null $raw Raw glossary text. Read from settings when null.
     */
    public function __construct($raw = null) {
        if ($raw === null) {
            $settings = WIT_Settings::instance()->get_settings();
            $raw      = isset($settings['glossary']) ? $settings['glossary'] : '';
        }

        $this->rules = self::parse($raw);
    }

    /**
     * Parse glossary text into rules.
     *
     * @param string $raw
     * @return array[] Each: {term, translation|null, languages: string[]}
     */
    public static function parse($raw) {
        $rules = array();

        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $languages = array();

            // Optional [lang] or [lang,lang] prefix.
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/u', $line, $match)) {
                $languages = array_values(array_filter(array_map(
                    function ($code) {
                        return strtolower(trim($code));
                    },
                    explode(',', $match[1])
                )));
                $line = trim($match[2]);
            }

            if ($line === '') {
                continue;
            }

            $term        = $line;
            $translation = null;

            // "original = translation". Only the first "=" separates.
            $separator = strpos($line, '=');
            if ($separator !== false) {
                $term        = trim(substr($line, 0, $separator));
                $translation = trim(substr($line, $separator + 1));

                if ($term === '' || $translation === '') {
                    continue;
                }
            }

            $rules[] = array(
                'term'        => $term,
                'translation' => $translation,
                'languages'   => $languages,
            );
        }

        return $rules;
    }

    /**
     * Whether any rule is defined.
     *
     * @return bool
     */
    public function is_empty() {
        return empty($this->rules);
    }

    /**
     * Rules applicable to one target language.
     *
     * @param string $target_lang
     * @return array[]
     */
    private function for_language($target_lang) {
        $target_lang = strtolower($target_lang);
        $applicable  = array();

        foreach ($this->rules as $rule) {
            if (empty($rule['languages']) || in_array($target_lang, $rule['languages'], true)) {
                $applicable[] = $rule;
            }
        }

        return $applicable;
    }

    /**
     * Resolve a string that a glossary rule covers in full.
     *
     * @param string $text
     * @param string $target_lang
     * @return string|null The translation, or null when no rule matches the
     *                     whole string.
     */
    public function resolve($text, $target_lang) {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return null;
        }

        $folded = mb_strtolower($trimmed);

        foreach ($this->for_language($target_lang) as $rule) {
            if (mb_strtolower($rule['term']) !== $folded) {
                continue;
            }

            // A rule with no translation means "leave this exactly as it is".
            return $rule['translation'] !== null ? $rule['translation'] : $trimmed;
        }

        return null;
    }

    /**
     * Build the prompt instructions for the terms present in a batch.
     *
     * @param string[] $texts       Strings about to be sent.
     * @param string   $target_lang
     * @return string Empty when no rule is relevant.
     */
    public function prompt_section(array $texts, $target_lang) {
        $rules = $this->for_language($target_lang);

        if (empty($rules) || empty($texts)) {
            return '';
        }

        // Match against the whole batch at once; a term is relevant if it
        // appears anywhere in it.
        $haystack = mb_strtolower(implode("\n", $texts));

        $keep  = array();
        $fixed = array();

        foreach ($rules as $rule) {
            if (mb_strpos($haystack, mb_strtolower($rule['term'])) === false) {
                continue;
            }

            if ($rule['translation'] === null) {
                $keep[] = $rule['term'];
            } else {
                $fixed[] = $rule['term'] . ' -> ' . $rule['translation'];
            }
        }

        $sections = array();

        if (!empty($keep)) {
            $sections[] = 'Leave these terms completely unchanged, in any context: '
                        . implode(', ', array_unique($keep)) . '.';
        }

        if (!empty($fixed)) {
            $sections[] = "Use exactly these translations for the following terms:\n"
                        . implode("\n", array_unique($fixed));
        }

        if (empty($sections)) {
            return '';
        }

        return "\n\nGlossary (these rules override your own judgement):\n" . implode("\n", $sections);
    }
}
