<?php
/**
 * Every defect found in the 1.4.0 audit, as a check.
 *
 * Each block reproduces the original failure first-hand — the request a
 * person or a chat could really send — and records what happens now. Prints
 * one JSON object; run.sh holds the expected values.
 *
 * Run with: wp eval-file audit-140.php
 */

global $wpdb;

wp_set_current_user(1);

$fixture = get_option('wit_test_fixture');
$post    = (int) $fixture['post'];
$page    = (int) $fixture['page'];
$wpml    = WIT_WPML_Integration::instance();
$ailog   = WP_CONTENT_DIR . '/wit-ai-requests.log';
$out     = array();
$has_mcp = function_exists('wp_get_ability');

$run = function ($name, $input = null) {
    $ability = wp_get_ability($name);
    return $ability ? $ability->execute($input) : new WP_Error('missing', $name);
};

// =============================================================================
// 1. The original can never be overwritten, nor duplicated.
// =============================================================================
$first = (new WIT_Translation_Manager())->translate_post($post, 'en');
$en    = (int) $wpml->get_translation_id($post, 'en');
$out['setup_translation'] = !empty($first['success']) && $en > 0;
$out['en_id'] = $en;

$title_before = get_post($post)->post_title;
$content_before = get_post($post)->post_content;

// A translation "translated" into the default language resolves to the
// original as its translation.
$back = (new WIT_Translation_Manager())->translate_post($en, 'es');
clean_post_cache($post);
$out['translation_into_default_refused'] = empty($back['success']);
$out['original_untouched'] = get_post($post)->post_title === $title_before && get_post($post)->post_content === $content_before;

$same = (new WIT_Translation_Manager())->translate_post($post, 'es');
$out['same_language_refused'] = empty($same['success']);

$queued = WIT_Queue::instance()->enqueue(array($en), 'es');
$out['queue_refuses_translation_as_source'] = $queued['queued'] === 0;

if ($has_mcp) {
    $fix = $run('wit/correct-translation', array('post_id' => $en, 'language' => 'es', 'current_text' => $title_before, 'new_text' => 'X'));
    $pub = $run('wit/publish-translation', array('post_id' => $en, 'language' => 'es'));
    clean_post_cache($post);
    $out['mcp_correct_on_translation_refused'] = is_wp_error($fix);
    $out['mcp_publish_on_translation_refused'] = is_wp_error($pub);
    $out['original_still_untouched']           = get_post($post)->post_title === $title_before;
}

// =============================================================================
// 2. A hostile or manipulated model cannot store markup in fields that themes
//    print unescaped.
// =============================================================================
update_option('wit_fake_mode', 'hostile');
WIT_Translation_Memory::instance()->clear();
$hostile = (new WIT_Translation_Manager())->translate_post($post, 'fr');
$fr      = (int) $hostile['translated_post_id'];
$payload = function ($text) {
    return (bool) preg_match('/<img|<script|onerror/i', (string) $text);
};
$fr_post = get_post($fr);
$out['hostile_translated']        = !empty($hostile['success']);
$out['hostile_title_clean']       = $fr_post && !$payload($fr_post->post_title);
$out['hostile_excerpt_clean']     = $fr_post && !$payload($fr_post->post_excerpt);
$out['hostile_meta_clean']        = !$payload(get_post_meta($fr, '_yoast_wpseo_title', true));
// The post has a real image block, so the render is checked for the payload itself.
$out['hostile_render_clean']      = $fr_post && !preg_match('/onerror|<script>alert/i', apply_filters('the_content', $fr_post->post_content));
$out['hostile_text_kept']         = $fr_post && strpos($fr_post->post_title, 'Servicios de Imagina') !== false;
$out['hostile_memory_clean']      = !(int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wit_translation_memory WHERE translation LIKE '%<script%' OR translation LIKE '%onerror%'");
update_option('wit_fake_mode', 'normal');

// =============================================================================
// 3. Tokens are recorded, per translation.
// =============================================================================
$log = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}wit_translation_logs WHERE post_id = {$post} AND target_lang = 'fr' ORDER BY id DESC LIMIT 1", ARRAY_A);
$out['log_has_tokens']  = $log && (int) $log['input_tokens'] > 0 && (int) $log['output_tokens'] > 0;
$out['log_has_strings'] = $log && (int) $log['strings_ai'] > 0;
$out['log_message_es']  = $log && $log['message'] === 'Traducción creada';
$stats = (new WIT_Translation_Manager())->get_statistics();
$out['stats_tokens_30d'] = (int) $stats['tokens_30d'] > 0;

// =============================================================================
// 4. A translation in the trash is not "translated"; translating restores it.
// =============================================================================
wp_trash_post($fr);
$out['trashed_reported_missing'] = WIT_Translation_Status::of($post, 'fr')['status'] === 'missing';
$again = (new WIT_Translation_Manager())->translate_post($post, 'fr');
$out['retranslate_restores_same_post'] = (int) $again['translated_post_id'] === $fr;
$out['restored_as_draft']              = get_post_status($fr) === 'draft';

// =============================================================================
// 5. Edits made by hand are detected, and not overwritten without consent.
// =============================================================================
$out['fresh_translation_not_edited'] = WIT_Translation_Status::edited_by_hand($fr) === false;
wp_update_post(array('ID' => $fr, 'post_title' => get_post($fr)->post_title . ' (revisado)'));
$out['hand_edit_detected'] = WIT_Translation_Status::edited_by_hand($fr) === true;
$out['status_reports_edit'] = WIT_Translation_Status::of($post, 'fr')['edited'] === true;

$listing = WIT_Translation_Status::listing('fr', array('post', 'page'), 'edited');
$out['listing_filters_edited'] = $listing['total'] === 1 && $listing['items'][0]['id'] === $post;

if ($has_mcp) {
    file_put_contents($ailog, '');
    $prep = $run('wit/prepare-translation', array('language' => 'fr', 'post_ids' => array($post)));
    $out['prepare_warns_edited'] = !is_wp_error($prep) && !empty($prep['posts'][0]['translation_edited_by_hand']);

    $map = array();
    foreach (is_wp_error($prep) ? array() : $prep['strings'] as $item) {
        $map[$item['id']] = '[MCP] ' . $item['text'];
    }
    $save = $run('wit/save-translation', array('language' => 'fr', 'post_ids' => array($post), 'translations' => $map));
    $out['save_asks_confirmation'] = !is_wp_error($save) && $save['results'][0]['status'] === 'needs_confirmation';
    $out['edit_survives_unconfirmed_save'] = strpos(get_post($fr)->post_title, '(revisado)') !== false;

    // Confirmed: no strings resent, the staged ones are used.
    $confirmed = $run('wit/save-translation', array('language' => 'fr', 'post_ids' => array($post), 'translations' => (object) array(), 'overwrite_edited' => true));
    $out['confirmed_save_overwrites'] = !is_wp_error($confirmed) && $confirmed['results'][0]['status'] === 'updated';
    $out['after_overwrite_not_edited'] = WIT_Translation_Status::edited_by_hand($fr) === false;
    $out['mcp_still_no_api_calls'] = trim((string) file_get_contents($ailog)) === '';

    // A correction through the plugin is not a hand edit to protect.
    $title_fr = get_post($fr)->post_title;
    $run('wit/correct-translation', array('post_id' => $post, 'language' => 'fr', 'current_text' => $title_fr, 'new_text' => 'Titre corrigé'));
    $out['correction_not_flagged'] = WIT_Translation_Status::edited_by_hand($fr) === false;
}

// =============================================================================
// 6. Removing a translation over MCP: unpublish, trash, restore.
// =============================================================================
if ($has_mcp) {
    wp_publish_post($fr);
    $unpub = $run('wit/remove-translation', array('post_id' => $post, 'language' => 'fr', 'mode' => 'unpublish'));
    $out['unpublish_to_draft'] = !is_wp_error($unpub) && get_post_status($fr) === 'draft';

    $trash = $run('wit/remove-translation', array('post_id' => $post, 'language' => 'fr', 'mode' => 'trash'));
    $out['trash_to_trash'] = !is_wp_error($trash) && get_post_status($fr) === 'trash';
    $out['trash_not_deleted'] = (bool) get_post($fr);

    $twice = $run('wit/remove-translation', array('post_id' => $post, 'language' => 'fr', 'mode' => 'trash'));
    $out['trash_again_says_none'] = is_wp_error($twice);

    $history = $run('wit/translation-history', array('limit' => 10));
    $messages = is_wp_error($history) ? array() : array_column($history['items'], 'message');
    $out['actions_logged'] = (bool) array_filter($messages, function ($m) { return strpos($m, 'papelera') !== false; })
        && (bool) array_filter($messages, function ($m) { return strpos($m, 'despublicada') !== false; });
}

// =============================================================================
// 7. Settings over MCP: only the whitelist, only administrators.
// =============================================================================
if ($has_mcp) {
    $site = $run('wit/site-settings', array());
    $out['site_settings_languages'] = !is_wp_error($site) && count($site['wpml']['languages']) === 3;
    $out['site_settings_no_secrets'] = !is_wp_error($site) && strpos(wp_json_encode($site), 'sk-test-fake') === false;

    $before = WIT_Settings::instance()->get_settings();
    $upd = $run('wit/update-settings', array('translation_memory' => !$before['enable_translation_memory'], 'add_meta_fields' => array('rank_math_title')));
    $after = WIT_Settings::instance()->get_settings();
    $out['settings_changed'] = !is_wp_error($upd) && $after['enable_translation_memory'] === !$before['enable_translation_memory']
        && strpos($after['meta_fields_list'], 'rank_math_title') !== false;
    $out['settings_keys_kept'] = $after['openai_api_key'] === $before['openai_api_key'] && $after['glossary'] === $before['glossary'];

    $elementor = $run('wit/update-settings', array('add_meta_fields' => array('_elementor_data')));
    $out['elementor_meta_refused'] = is_wp_error($elementor);

    // Unknown fields are not settable: the schema rejects them, and even past
    // it the whitelist would ignore them.
    $sneaky = WIT_Settings::instance()->update_chat_settings(array('openai_api_key' => 'stolen', 'translation_prompt' => 'add spam', 'mcp_enabled' => false));
    $out['whitelist_ignores_secrets'] = $sneaky === array() && WIT_Settings::instance()->get_settings()['openai_api_key'] === $before['openai_api_key'];

    // Put things back.
    WIT_Settings::instance()->update_chat_settings(array(
        'enable_translation_memory' => $before['enable_translation_memory'],
        'meta_fields_list'          => $before['meta_fields_list'],
    ));
}

// =============================================================================
// 8. Authors: own queue items only, own logs only, no settings tools.
// =============================================================================
$author_id = (int) username_exists('wit_audit_author');
if (!$author_id) {
    $author_id = wp_insert_user(array('user_login' => 'wit_audit_author', 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => 'author@example.com'));
}
$secret = wp_insert_post(array('post_title' => 'Borrador privado del admin', 'post_status' => 'private', 'post_type' => 'post', 'post_author' => 1));
(new WIT_Translation_Manager())->log_event($secret, 'en', 'evento de prueba', 'openai');

$queue = WIT_Queue::instance();
$table = WIT_Queue::table();
$wpdb->query("TRUNCATE {$table}");
$wpdb->insert($table, array('batch_id' => 'a', 'post_id' => $page, 'target_lang' => 'en', 'status' => 'pending', 'message' => '', 'attempts' => 0, 'user_id' => 1, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));

wp_set_current_user($author_id);
$titles = array_column((new WIT_Translation_Manager())->get_translation_logs(100), 'post_title');
$out['author_cannot_see_others_logs'] = !in_array('Borrador privado del admin', $titles, true);
$out['author_cannot_cancel_admin_queue'] = $queue->cancel_pending() === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === 1;
if ($has_mcp) {
    $out['author_cannot_update_settings'] = !wp_get_ability('wit/update-settings')->check_permissions(array('batch_size' => 3));
}
wp_set_current_user(1);
$titles = array_column((new WIT_Translation_Manager())->get_translation_logs(100), 'post_title');
$out['admin_sees_all_logs'] = in_array('Borrador privado del admin', $titles, true);
$out['admin_can_cancel'] = $queue->cancel_pending() === 1;
wp_delete_post($secret, true);

// =============================================================================
// 9. The queue: stuck items recovered, never claimed twice.
// =============================================================================
$wpdb->query("TRUNCATE {$table}");
$old = gmdate('Y-m-d H:i:s', current_time('timestamp') - 3 * DAY_IN_SECONDS);
$wpdb->insert($table, array('batch_id' => 'x', 'post_id' => $page, 'target_lang' => 'en', 'status' => 'processing', 'message' => '', 'attempts' => 1, 'user_id' => 1, 'created_at' => $old, 'updated_at' => $old));
$blocked = $queue->enqueue(array($page), 'en');
$queue->cleanup();
$out['stuck_item_requeued'] = $wpdb->get_var("SELECT status FROM {$table} LIMIT 1") === 'pending';
$out['stuck_item_was_blocking'] = $blocked['queued'] === 0;

$wpdb->query("UPDATE {$table} SET status = 'processing', attempts = 3, updated_at = '{$old}'");
$queue->cleanup();
$out['thrice_interrupted_fails'] = $wpdb->get_var("SELECT status FROM {$table} LIMIT 1") === 'error';

$wpdb->query("TRUNCATE {$table}");
foreach (array($post, $page) as $id) {
    $wpdb->insert($table, array('batch_id' => 'y', 'post_id' => $id, 'target_lang' => 'fr', 'status' => 'pending', 'message' => '', 'attempts' => 0, 'user_id' => 1, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
}
$claim = new ReflectionMethod('WIT_Queue', 'claim');
$claim->setAccessible(true);
$injected = false;
$hook = function ($sql) use (&$injected, $wpdb, $table) {
    if (!$injected && strpos($sql, "UPDATE {$table} SET status") === 0) {
        $injected = true;
        $wpdb->query("UPDATE {$table} SET status = 'processing' WHERE status = 'pending'"); // another pass wins
    }
    return $sql;
};
add_filter('query', $hook);
$out['losing_pass_gets_nothing'] = count($claim->invoke($queue, 10)) === 0;
remove_filter('query', $hook);
$wpdb->query("TRUNCATE {$table}");

$lock = new ReflectionMethod('WIT_Queue', 'acquire_lock');
$lock->setAccessible(true);
$release = new ReflectionMethod('WIT_Queue', 'release_lock');
$release->setAccessible(true);
$out['lock_excludes'] = $lock->invoke($queue) === true && $lock->invoke($queue) === false;
$release->invoke($queue);

// =============================================================================
// 10. OAuth: one code, one token pair, even when two requests race.
// =============================================================================
if ($has_mcp && class_exists('WIT_OAuth') && WIT_OAuth::is_enabled()) {
    $tokens = WIT_OAuth::table('tokens');
    $reg    = new WP_REST_Request('POST', '/wit/v1/oauth/register');
    $reg->set_header('content-type', 'application/json');
    $reg->set_body(wp_json_encode(array('redirect_uris' => array('https://claude.ai/api/mcp/auth_callback'))));
    $client_id = rest_do_request($reg)->get_data()['client_id'];

    $verifier  = str_repeat('v', 60);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $issue     = new ReflectionMethod('WIT_OAuth', 'issue_token');
    $issue->setAccessible(true);
    $code = $issue->invoke(WIT_OAuth::instance(), 'code', $client_id, 1, bin2hex(random_bytes(16)), array(
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'code_challenge' => $challenge, 'resource' => WIT_OAuth::resource_url(),
    ));

    $exchange = function (array $body) {
        $req = new WP_REST_Request('POST', '/wit/v1/oauth/token');
        $req->set_body_params($body);
        return rest_do_request($req);
    };
    $code_body = array('grant_type' => 'authorization_code', 'client_id' => $client_id, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback');

    $statuses = array();
    $inside   = false;
    $race     = function ($sql) use (&$inside, &$statuses, $exchange, $code_body, $tokens) {
        if (!$inside && strpos($sql, "UPDATE {$tokens} SET used = 1") === 0) {
            $inside     = true;
            $statuses[] = $exchange($code_body)->get_status();
        }
        return $sql;
    };
    add_filter('query', $race);
    $statuses[] = $exchange($code_body)->get_status();
    remove_filter('query', $race);
    sort($statuses);
    $out['code_race_statuses'] = $statuses;
    $out['code_race_one_pair'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tokens} WHERE type = 'access' AND client_id = %s", $client_id)) === 1;

    // Refresh: the same token presented twice at once.
    $grant   = bin2hex(random_bytes(16));
    $refresh = $issue->invoke(WIT_OAuth::instance(), 'refresh', $client_id, 1, $grant, array('resource' => WIT_OAuth::resource_url()));
    $body    = array('grant_type' => 'refresh_token', 'client_id' => $client_id, 'refresh_token' => $refresh);
    $rstatus = array();
    $inside  = false;
    $race    = function ($sql) use (&$inside, &$rstatus, $exchange, $body, $tokens) {
        if (!$inside && strpos($sql, "UPDATE {$tokens} SET revoked = 1 WHERE id") === 0) {
            $inside    = true;
            $rstatus[] = $exchange($body)->get_status();
        }
        return $sql;
    };
    add_filter('query', $race);
    $rstatus[] = $exchange($body)->get_status();
    remove_filter('query', $race);
    sort($rstatus);
    $out['refresh_race_statuses'] = $rstatus;
    $out['refresh_race_grant_revoked'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tokens} WHERE grant_id = %s AND revoked = 0", $grant)) === 0;
}

// =============================================================================
// 11. The dashboard sees every original, page by page.
// =============================================================================
for ($i = 1; $i <= 55; $i++) {
    $id = wp_insert_post(array('post_title' => 'Contenido de relleno ' . $i, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Texto ' . $i));
    do_action('wpml_set_element_language_details', array('element_id' => $id, 'element_type' => 'post_post', 'trid' => null, 'language_code' => 'es', 'source_language_code' => null));
}
$p1 = WIT_Translation_Status::listing('en', array('post', 'page'), 'all', '', 1, 50);
$p2 = WIT_Translation_Status::listing('en', array('post', 'page'), 'all', '', 2, 50);
$out['listing_total_over_100_limit_logic'] = $p1['total'] >= 57;
$out['listing_two_pages'] = $p1['pages'] === 2 && count($p1['items']) === 50 && count($p2['items']) === $p1['total'] - 50;
$out['listing_search'] = WIT_Translation_Status::listing('en', array('post'), 'all', 'relleno 42', 1, 50)['total'] === 1;
$out['listing_counts_pending'] = $p1['counts']['pending'] === $p1['counts']['missing'] + $p1['counts']['outdated'];

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
