<?php
/**
 * The MCP translation path, driven through the abilities as Claude would.
 *
 * The "chat" is simulated: it translates by prefixing [MCP-XX]. What matters
 * is everything around it — which strings are asked for, that nothing is
 * written until a post is complete, that the API is never called, and that
 * the result is as faithful as an API translation.
 *
 * Run with: wp eval-file mcp-plan.php  (prints one JSON object)
 */

wp_set_current_user(1);

$fixture = get_option('wit_test_fixture');
$post    = (int) $fixture['post'];
$page    = (int) $fixture['page'];
$ailog   = WP_CONTENT_DIR . '/wit-ai-requests.log';
$out     = array();

$run = function ($name, $input = null) {
    $ability = wp_get_ability($name);
    if (!$ability) {
        return new WP_Error('missing_ability', $name . ' is not registered');
    }
    return $ability->execute($input);
};

$chat = function (array $strings, $lang) {
    $translations = array();
    foreach ($strings as $item) {
        $translations[$item['id']] = '[MCP-' . strtoupper($lang) . '] ' . $item['text'];
    }
    return $translations;
};

// The API must never be reached from this path. Any line here is a failure.
file_put_contents($ailog, '');

// --- Abilities are registered ------------------------------------------------
$registered = array();
foreach (array_keys(WIT_Abilities::definitions()) as $name) {
    $registered[$name] = (bool) wp_get_ability($name);
}
$out['abilities_registered'] = count(array_filter($registered)) === count($registered);
$out['abilities_count']      = count($registered);

// --- Overview and listing ----------------------------------------------------
$overview = $run('wit/translation-overview', array());
$en = null;
foreach ((array) (is_wp_error($overview) ? array() : $overview['languages']) as $language) {
    if ($language['code'] === 'en') {
        $en = $language;
    }
}
$out['overview_ok']      = !is_wp_error($overview) && $en !== null;
$out['overview_missing'] = $en ? $en['missing'] : -1;

$list = $run('wit/list-posts', array('language' => 'en', 'status' => 'pending'));
$ids  = is_wp_error($list) ? array() : array_column($list['items'], 'post_id');
$out['list_has_both'] = in_array($post, $ids, true) && in_array($page, $ids, true);

// --- Batch limit ---------------------------------------------------------------
$too_many = $run('wit/prepare-translation', array('language' => 'en', 'post_ids' => range(1, 11)));
$out['rejects_11_posts'] = is_wp_error($too_many);

// --- prepare -------------------------------------------------------------------
$prepared = $run('wit/prepare-translation', array('language' => 'en', 'post_ids' => array($post, $page)));
$out['prepare_ok'] = !is_wp_error($prepared);

if (is_wp_error($prepared)) {
    $out['prepare_error'] = $prepared->get_error_message();
    echo wp_json_encode($out);
    return;
}

$texts    = array_column($prepared['strings'], 'text');
$contexts = array_unique(array_column($prepared['strings'], 'context'));
sort($contexts);

$out['contexts']            = array_values($contexts);
$out['has_title']           = in_array('Servicios de Imagina', $texts, true);
$out['has_meta']            = in_array('Servicios | Imagina', $texts, true);
$out['has_terms']           = in_array('Diseño web', $texts, true) && in_array('Marketing', $texts, true);
// Resolved by the glossary as a whole string: must not be sent.
$out['glossary_excluded']   = !in_array('Contacta con nosotros', $texts, true);
// Shortcodes are not text.
$out['shortcode_excluded']  = !in_array('[gallery ids="1,2,3"]', $texts, true);
$out['no_html_sent']        = count(array_filter($texts, function ($t) { return (bool) preg_match('/<[a-z\/!][^>]*>/i', $t); })) === 0;
// A string on both posts is sent once.
$out['dedup_across_posts']  = count(array_keys($texts, 'Bienvenidos a Imagina', true)) === 1;
$out['instructions']        = strpos($prepared['instructions'], 'English') !== false;
$out['glossary_guidance']   = isset($prepared['glossary']) && strpos($prepared['glossary'], 'Imagina') !== false;
$out['totals']              = $prepared['totals'];

$all = $chat($prepared['strings'], 'en');

// --- save, incomplete: nothing may be written ----------------------------------
$half = array_slice($all, 0, (int) floor(count($all) / 2), true);
$partial = $run('wit/save-translation', array('language' => 'en', 'post_ids' => array($post), 'translations' => $half));
$first   = is_wp_error($partial) ? array() : $partial['results'][0];

$out['partial_is_incomplete'] = isset($first['status']) && $first['status'] === 'incomplete';
$out['partial_wrote_nothing'] = !WIT_WPML_Integration::instance()->get_translation_id($post, 'en');
$out['partial_lists_missing'] = isset($first['missing_count']) && $first['missing_count'] > 0;

// --- save, the rest: staging keeps the first half --------------------------------
$rest  = array_diff_key($all, $half);
$saved = $run('wit/save-translation', array('language' => 'en', 'post_ids' => array($post, $page), 'translations' => $rest));

$results = is_wp_error($saved) ? array() : $saved['results'];
$by_post = array();
foreach ($results as $result) {
    $by_post[$result['post_id']] = $result;
}

$out['saved_post']    = isset($by_post[$post]['status']) ? $by_post[$post]['status'] : (is_wp_error($saved) ? $saved->get_error_message() : 'none');
$out['saved_page']    = isset($by_post[$page]['status']) ? $by_post[$page]['status'] : 'none';
$out['no_divergence'] = empty($by_post[$post]['warning']) && empty($by_post[$page]['warning']);

// --- The guarantee this path exists for ----------------------------------------
$out['api_requests'] = count(array_filter(explode("\n", (string) file_get_contents($ailog))));

$translation = (int) WIT_WPML_Integration::instance()->get_translation_id($post, 'en');
$t           = get_post($translation);
$c           = $t ? $t->post_content : '';

$out['title']        = $t ? $t->post_title : '';
$out['is_draft']     = $t && $t->post_status === 'draft';
$out['content_mcp']  = substr_count($c, '[MCP-EN]');
$out['glossary_fix'] = strpos($c, 'Get in touch') !== false;
$out['shortcode']    = strpos($c, "\n[gallery ids=\"1,2,3\"]\n") !== false;
$out['entities']     = strpos($c, '&amp;') !== false && strpos($c, '&lt;') !== false;
$out['attributes']   = strpos($c, 'class="btn center"') !== false;
$out['alt_both']     = substr_count($c, '[MCP-EN] Foto del equipo') === 2;
$out['blocks_valid'] = count(array_filter(parse_blocks($c), function ($b) { return $b['blockName'] !== null; })) === 10;
$out['meta']         = get_post_meta($translation, '_yoast_wpseo_title', true);
$out['custom_meta']  = get_post_meta($translation, '_custom_technical', true) === 'do-not-touch-me';

$cats = array();
foreach (wp_get_object_terms($translation, 'category') as $term) {
    $cats[] = $term->name;
}
sort($cats);
$out['categories'] = $cats;

// Taxonomy round trip, the defect the fixture drift exists for.
$en_cat = apply_filters('wpml_object_id', (int) $fixture['cat_parent'], 'category', false, 'en');
$out['tax_roundtrip'] = $en_cat && (int) apply_filters('wpml_object_id', $en_cat, 'category', false, 'es') === (int) $fixture['cat_parent'];

global $wpdb;
$out['memory_mcp'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_memory WHERE provider = 'mcp'");

$history = $run('wit/translation-history', array('limit' => 5));
$out['history_via_mcp'] = !is_wp_error($history) && !empty($history['items']) && $history['items'][0]['via'] === 'claude (mcp)';

// --- Status: current, then outdated after the original changes ---------------
$out['status_current'] = WIT_Translation_Status::of($post, 'en')['status'];
wp_update_post(array('ID' => $post, 'post_excerpt' => 'Resumen actualizado.'));
clean_post_cache($post);
$out['status_outdated'] = WIT_Translation_Status::of($post, 'en')['status'];
$outdated = $run('wit/list-posts', array('language' => 'en', 'status' => 'outdated'));
$out['list_outdated'] = !is_wp_error($outdated) && in_array($post, array_column($outdated['items'], 'post_id'), true);

// --- Re-translating: only the changed string is new ------------------------------
$again = $run('wit/prepare-translation', array('language' => 'en', 'post_ids' => array($post)));
$out['retranslate_only_new'] = !is_wp_error($again)
    && count($again['strings']) === 1
    && $again['strings'][0]['text'] === 'Resumen actualizado.';
$saved_again = $run('wit/save-translation', array('language' => 'en', 'post_ids' => array($post), 'translations' => $chat($again['strings'], 'en')));
$out['retranslate_updates_same'] = !is_wp_error($saved_again)
    && $saved_again['results'][0]['status'] === 'updated'
    && (int) $saved_again['results'][0]['translation_id'] === $translation;
$out['status_current_again'] = WIT_Translation_Status::of($post, 'en')['status'];

// --- Correcting one wording -------------------------------------------------------
$fix = $run('wit/correct-translation', array(
    'post_id'      => $post,
    'language'     => 'en',
    'current_text' => '[MCP-EN] Nuestro equipo',
    'new_text'     => 'Our team',
));
clean_post_cache($translation);
$after = get_post($translation)->post_content;
$out['correct_ok']       = !is_wp_error($fix) && in_array('content', $fix['changed'], true);
$out['correct_applied']  = strpos($after, '>Our team<') !== false && strpos($after, 'Nuestro equipo') === false;
$out['correct_rest_kept'] = substr_count($after, '[MCP-EN]') === $out['content_mcp'] - 1;
$out['correct_memory']   = !is_wp_error($fix) && $fix['memory_updated'] >= 1;
$missing_fix = $run('wit/correct-translation', array('post_id' => $post, 'language' => 'en', 'current_text' => 'texto que no existe', 'new_text' => 'x'));
$out['correct_rejects_unknown'] = is_wp_error($missing_fix);
// Markup cannot be smuggled in through a correction.
$run('wit/correct-translation', array('post_id' => $post, 'language' => 'en', 'current_text' => 'Our team', 'new_text' => 'Team<script>alert(1)</script>'));
clean_post_cache($translation);
$out['correct_no_script'] = strpos(get_post($translation)->post_content, '<script>alert(1)') === false;

// --- Publishing -----------------------------------------------------------------
$published = $run('wit/publish-translation', array('post_id' => $post, 'language' => 'en'));
$out['published'] = !is_wp_error($published) && $published['post_status'] === 'publish';
// Publishing must not strip the translation's markup.
$out['publish_kept_blocks'] = count(array_filter(parse_blocks(get_post($translation)->post_content), function ($b) { return $b['blockName'] !== null; })) === 10;

// --- Glossary: edits touch the glossary and nothing else --------------------------
$key_before = WIT_Settings::instance()->get_settings()['openai_api_key'];
$g1 = $run('wit/update-glossary', array('add' => array('[en] Presupuesto = Quote')));
$g2 = $run('wit/update-glossary', array('remove' => array('[en] Presupuesto = Quote')));
$out['glossary_add']     = !is_wp_error($g1) && in_array('[en] Presupuesto = Quote', $g1['rules'], true);
$out['glossary_remove']  = !is_wp_error($g2) && !in_array('[en] Presupuesto = Quote', $g2['rules'], true);
$out['glossary_key_kept'] = WIT_Settings::instance()->get_settings()['openai_api_key'] === $key_before && $key_before !== '';
$bad = $run('wit/update-glossary', array('add' => array("dos\nlineas")));
$out['glossary_rejects_bad'] = is_wp_error($bad);

// --- Permissions ----------------------------------------------------------------
$subscriber = wp_insert_user(array('user_login' => 'suscriptor', 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
wp_set_current_user($subscriber);
$denied = $run('wit/translation-overview', array());
$out['subscriber_denied'] = is_wp_error($denied);

$editor = wp_insert_user(array('user_login' => 'editora', 'user_pass' => wp_generate_password(), 'role' => 'editor'));
wp_set_current_user($editor);
$editor_glossary = $run('wit/update-glossary', array('add' => array('Nada')));
$out['editor_no_glossary_edit'] = is_wp_error($editor_glossary);
$editor_read = $run('wit/translation-overview', array());
$out['editor_can_read'] = !is_wp_error($editor_read);
wp_delete_user($subscriber);
wp_delete_user($editor);
wp_set_current_user(1);

// --- And still: not one request to the API -------------------------------------
$out['api_requests_final'] = count(array_filter(explode("\n", (string) file_get_contents($ailog))));

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
