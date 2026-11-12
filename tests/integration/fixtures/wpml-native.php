<?php
/**
 * Checks that only make sense against the real WPML and String Translation.
 *
 * WPML defers some of its bookkeeping to the end of the request (whether a
 * translation needs an update is decided at shutdown), so the scenario runs
 * in steps, each a separate process: wp eval-file wpml-native.php <step>.
 * Each step prints one JSON object.
 */

global $wpdb;

wp_set_current_user(1);

$step    = isset($args[0]) ? $args[0] : 'translate';
$fixture = get_option('wit_test_fixture');
$post    = (int) $fixture['post'];
$page    = (int) $fixture['page'];
$wpml    = WIT_WPML_Integration::instance();
$ailog   = WP_CONTENT_DIR . '/wit-ai-requests.log';
$out     = array('real_wpml' => WIT_WPML_Native::is_active(), 'st' => WIT_Strings::is_available());

$en_of = function ($id, $lang = 'en') use ($wpml) {
    return (int) $wpml->get_translation_id($id, $lang);
};

switch ($step) {

    // -------------------------------------------------------------------
    case 'translate':
        // WPML's own field preferences: one to translate, one to ignore.
        update_post_meta($post, 'wit_subtitle', 'Diseño y desarrollo web a medida');
        update_post_meta($post, 'wit_internal_note', 'nota interna, no copiar');
        $out['field_translate_set'] = WIT_WPML_Native::set_field_mode('wit_subtitle', WIT_WPML_Native::MODE_TRANSLATE);
        $out['field_ignore_set']    = WIT_WPML_Native::set_field_mode('wit_internal_note', WIT_WPML_Native::MODE_IGNORE);

        // A taxonomy WPML does not translate.
        $out['post_format_not_translated_in_wpml'] = !WIT_WPML_Native::is_translated_taxonomy('post_format');
        set_post_format($post, 'gallery');

        $r  = (new WIT_Translation_Manager())->translate_post($post, 'en');
        $en = $en_of($post);
        $out['translated'] = !empty($r['success']) && $en > 0;

        $status = WIT_WPML_Native::tm_status($en);
        $out['wpml_recorded_complete'] = $status && $status['status'] === 10 && !$status['needs_update'];

        $out['wpml_field_translated'] = get_post_meta($en, 'wit_subtitle', true) === '[EN] Diseño y desarrollo web a medida';
        $out['wpml_ignored_field_not_copied'] = get_post_meta($en, 'wit_internal_note', true) === '';
        $out['shared_taxonomy_same_term'] = get_post_format($en) === 'gallery'
            && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug LIKE 'post-format-gallery%'") === 1;

        // Edit the original. WPML decides "needs update" at shutdown.
        wp_update_post(array('ID' => $post, 'post_title' => get_post($post)->post_title . ' (revisado)'));
        break;

    // -------------------------------------------------------------------
    case 'after-edit':
        $en     = $en_of($post);
        $status = WIT_WPML_Native::tm_status($en);
        $out['wpml_flags_needs_update'] = $status && $status['needs_update'];
        $out['plugin_says_outdated']    = WIT_Translation_Status::of($post, 'en')['status'] === 'outdated';

        $r = (new WIT_Translation_Manager())->translate_post($post, 'en');
        $out['retranslated'] = !empty($r['success']);
        break;

    // -------------------------------------------------------------------
    case 'after-retranslate':
        $status = WIT_WPML_Native::tm_status($en_of($post));
        $out['wpml_current_again'] = $status && $status['status'] === 10 && !$status['needs_update'];

        // Restore the title for later sections and runs.
        wp_update_post(array('ID' => $post, 'post_title' => str_replace(' (revisado)', '', get_post($post)->post_title)));
        set_post_format($post, false);
        delete_post_meta($post, 'wit_subtitle');
        delete_post_meta($post, 'wit_internal_note');

        // WPML settings over MCP.
        if (function_exists('wp_get_ability') && WIT_OAuth::is_enabled()) {
            $set = wp_get_ability('wit/set-wpml-translation-mode');
            $out['mcp_wpml_tool_registered'] = (bool) $set;
            if ($set) {
                $r1 = $set->execute(array('post_type' => 'post', 'mode' => 'display_as_translated'));
                $out['mcp_post_type_mode'] = !is_wp_error($r1) && $r1['now'] === 'display_as_translated';
                $r2 = $set->execute(array('post_type' => 'post', 'mode' => 'translate'));
                $out['mcp_post_type_mode_back'] = !is_wp_error($r2) && $r2['now'] === 'translate';
                $r3 = $set->execute(array('custom_field' => 'wit_tagline', 'mode' => 'translate'));
                $out['mcp_field_mode'] = !is_wp_error($r3) && WIT_WPML_Native::field_mode('wit_tagline') === WIT_WPML_Native::MODE_TRANSLATE;
                $r4 = $set->execute(array('post_type' => 'post', 'mode' => 'copy'));
                $out['mcp_bad_mode_refused'] = is_wp_error($r4);
                $site = wp_get_ability('wit/site-settings')->execute(array());
                $out['site_settings_sees_real_wpml'] = !is_wp_error($site) && $site['wpml']['real_wpml'] === true && $site['wpml']['translation_management'] === true;
            }
        }
        break;

    // -------------------------------------------------------------------
    case 'strings':
        // A theme in English, and an admin text in Spanish.
        $ids = array(
            'plain'  => icl_register_string('wit-theme', md5('Leave a reply'), 'Leave a reply', false, 'en'),
            'ph'     => icl_register_string('wit-theme', md5('Posted on %1$s by %2$s'), 'Posted on %1$s by %2$s', false, 'en'),
            'html'   => icl_register_string('wit-theme', md5('Read our <a href="/privacy">privacy policy</a>'), 'Read our <a href="/privacy">privacy policy</a>', false, 'en'),
            'only'   => icl_register_string('wit-theme', md5('Continue reading <span class="screen-reader-text">%s</span>'), 'Continue reading <span class="screen-reader-text">%s</span>', false, 'en'),
            'broken' => icl_register_string('wit-theme', md5('%d comments'), '%d comments', false, 'en'),
            'admin'  => icl_register_string('wit-widgets', 'footer-copy', 'Todos los derechos reservados', false, 'es'),
        );
        $out['registered'] = count(array_filter($ids)) === 6;

        $list = WIT_Strings::listing('es', array('domain' => 'wit-theme'));
        $out['pending_es'] = $list['total'] === 5;
        $out['own_language_not_listed'] = WIT_Strings::listing('es', array('domain' => 'wit-widgets'))['total'] === 0;

        // API path, with a model that loses a placeholder on one string.
        file_put_contents($ailog, '');
        update_option('wit_fake_drop_placeholder', '%d');
        $res = WIT_Strings::translate(array($ids['plain'], $ids['ph'], $ids['html'], $ids['only'], $ids['broken']), 'es');
        delete_option('wit_fake_drop_placeholder');
        $out['api_saved_4'] = $res['saved'] === 4;
        $out['api_rejects_broken_placeholder'] = count($res['errors']) === 1 && (int) $res['errors'][0]['id'] === (int) $ids['broken'];
        $value = function ($id, $lang) use ($wpdb) {
            return (string) $wpdb->get_var($wpdb->prepare("SELECT value FROM {$wpdb->prefix}icl_string_translations WHERE string_id = %d AND language = %s AND status = 10", $id, $lang));
        };
        $out['api_plain']         = $value($ids['plain'], 'es') === '[ES] Leave a reply';
        $out['api_placeholders']  = $value($ids['ph'], 'es') === '[ES] Posted on %1$s by %2$s';
        $out['api_html_intact']   = $value($ids['html'], 'es') === '[ES] Read our <a href="/privacy">[ES] privacy policy</a>';
        $out['api_placeholder_node_untouched'] = $value($ids['only'], 'es') === '[ES] Continue reading <span class="screen-reader-text">%s</span>';
        $out['api_broken_not_saved'] = $value($ids['broken'], 'es') === '';
        $out['api_requests_made'] = trim((string) file_get_contents($ailog)) !== '';
        $out['strings_logged'] = (bool) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_logs WHERE post_id = 0 AND message LIKE '%String Translation%'");

        // MCP path: the chat translates, the API is never called.
        if (function_exists('wp_get_ability') && WIT_OAuth::is_enabled()) {
            file_put_contents($ailog, '');
            $prep = wp_get_ability('wit/prepare-strings')->execute(array('language' => 'en', 'domain' => 'wit-widgets'));
            $out['mcp_prepare'] = !is_wp_error($prep) && count($prep['units']) === 1 && $prep['units'][0]['text'] === 'Todos los derechos reservados';
            $map = array();
            foreach (is_wp_error($prep) ? array() : $prep['units'] as $unit) {
                $map[$unit['id']] = 'All rights reserved';
            }
            $saved = wp_get_ability('wit/save-strings')->execute(array('language' => 'en', 'string_ids' => array_column($prep['strings'], 'string_id'), 'translations' => $map));
            $out['mcp_saved'] = !is_wp_error($saved) && $saved['saved'] === 1;
            $out['mcp_value'] = $value($ids['admin'], 'en') === 'All rights reserved';

            // A chat answer that drops a placeholder is refused too.
            $prep2 = wp_get_ability('wit/prepare-strings')->execute(array('language' => 'fr', 'string_ids' => array($ids['ph'])));
            $map2  = array();
            foreach (is_wp_error($prep2) ? array() : $prep2['units'] as $unit) {
                $map2[$unit['id']] = 'Publié le %1$s';
            }
            $bad = wp_get_ability('wit/save-strings')->execute(array('language' => 'fr', 'string_ids' => array($ids['ph']), 'translations' => $map2));
            $out['mcp_rejects_lost_placeholder'] = !is_wp_error($bad) && $bad['saved'] === 0 && count($bad['errors']) === 1;
            $out['mcp_no_api_calls'] = trim((string) file_get_contents($ailog)) === '';

            $ov = wp_get_ability('wit/strings-overview')->execute(array());
            $out['mcp_overview'] = !is_wp_error($ov) && $ov['available'] === true && $ov['files_writable'] === true;
        }
        break;

    // -------------------------------------------------------------------
    case 'after-strings':
        // String Translation writes its files at the end of the request
        // that saved the translations.
        $out['files_written'] = (bool) glob(WP_LANG_DIR . '/wpml/wit-theme-es_ES.*');
        break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
