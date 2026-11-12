<?php
/**
 * Admin REST API — what the admin app (admin-ui/) talks to.
 *
 * Namespace wit/admin/v1, apart from the MCP and OAuth routes (wit/v1). Every
 * route authenticates with the logged-in user's cookie and the wp_rest nonce,
 * checks a capability, and delegates to the classes the rest of the plugin
 * and its tests already use: nothing here translates, writes WPML data or
 * decides permissions on its own.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Admin_REST {

    const NAMESPACE_ = 'wit/admin/v1';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    // -----------------------------------------------------------------------
    // Routes
    // -----------------------------------------------------------------------

    public function register_routes() {
        $edit   = function () { return current_user_can('edit_posts'); };
        $manage = function () { return current_user_can('manage_options'); };

        $routes = array(
            array('/overview', 'GET', 'overview', $edit),
            array('/posts', 'GET', 'posts', $edit),
            array('/translate', 'POST', 'translate', $edit),
            array('/translate-status', 'GET', 'translate_status', $edit),
            array('/review', 'GET', 'review', $edit),
            array('/correct', 'POST', 'correct', $edit),
            array('/publish', 'POST', 'publish', $edit),
            array('/remove', 'POST', 'remove', $edit),
            array('/queue', 'GET', 'queue_status', $edit),
            array('/queue', 'POST', 'queue_add', $edit),
            array('/queue', 'DELETE', 'queue_cancel', $edit),
            array('/logs', 'GET', 'logs', $edit),
            array('/strings', 'GET', 'strings', $manage),
            array('/strings/domains', 'GET', 'string_domains', $manage),
            array('/strings/translate', 'POST', 'strings_translate', $manage),
            array('/strings/save', 'POST', 'strings_save', $manage),
            array('/strings/next', 'GET', 'strings_next', $manage),
            array('/settings', 'GET', 'settings_get', $manage),
            array('/settings', 'PUT', 'settings_put', $manage),
            array('/models', 'POST', 'models', $manage),
            array('/test-connection', 'POST', 'test_connection', $manage),
            array('/memory', 'DELETE', 'memory_clear', $manage),
            array('/connections/(?P<grant>[a-f0-9]{32})', 'DELETE', 'connection_revoke', $manage),
        );

        foreach ($routes as $route) {
            list($path, $method, $callback, $permission) = $route;
            register_rest_route(self::NAMESPACE_, $path, array(
                'methods'             => $method,
                'callback'            => array($this, $callback),
                'permission_callback' => $permission,
            ));
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, array('status' => $status));
    }

    private function language(WP_REST_Request $request, $key = 'language') {
        $language = sanitize_text_field((string) $request->get_param($key));
        return WIT_WPML_Integration::instance()->is_active_language($language) ? $language : '';
    }

    /** Local MySQL datetime → GMT, which the app formats in the viewer's zone. */
    private function gmt($local) {
        return $local ? get_gmt_from_date((string) $local) : '';
    }

    /** Ability callbacks return WP_Error without a status: make it a 400. */
    private function result($value) {
        if (is_wp_error($value)) {
            $data = $value->get_error_data();
            if (!is_array($data) || !isset($data['status'])) {
                $value->add_data(array('status' => 400));
            }
        }
        return $value;
    }

    // -----------------------------------------------------------------------
    // Overview
    // -----------------------------------------------------------------------

    public function overview(WP_REST_Request $request) {
        $wpml     = WIT_WPML_Integration::instance();
        $settings = WIT_Settings::instance();
        $config   = $settings->get_settings();
        $default  = $wpml->get_default_language();
        $types    = array('post', 'page');

        $languages = array();
        foreach ($wpml->get_active_languages() as $language) {
            if ($language['code'] === $default) {
                continue;
            }
            $list = WIT_Translation_Status::listing($language['code'], $types, 'all', '', 1, 1, 2000);
            $languages[] = array(
                'code'     => $language['code'],
                'name'     => $language['name'],
                'counts'   => $list['counts'],
                'truncated' => $list['truncated'],
            );
        }

        $strings = null;
        if (WIT_Strings::is_available() && current_user_can('manage_options')) {
            $ov      = WIT_Strings::overview();
            $strings = array(
                'pending'   => array_sum(array_column($ov['languages'], 'pending')),
                'languages' => array_map(function ($l) {
                    return array('code' => $l['code'], 'name' => $l['name'], 'pending' => $l['pending'], 'total' => $l['total']);
                }, $ov['languages']),
            );
        }

        $manager = new WIT_Translation_Manager();
        $stats   = $manager->get_statistics();
        $recent  = array_map(array($this, 'log_row'), $manager->get_translation_logs(6));

        $problems = WIT_WPML_Native::problems();
        $mcp      = !empty($config['mcp_enabled']);

        return array(
            'setup'     => array(
                'has_api_key' => $settings->has_api_key(),
                'provider'    => $config['ai_provider'],
                'model'       => (string) $config[$config['ai_provider'] . '_model'],
                'memory'      => !empty($config['enable_translation_memory']),
                'mcp_enabled' => $mcp,
                'mcp_ready'   => $mcp && class_exists('WIT_OAuth') && WIT_OAuth::is_enabled() && empty($settings->mcp_requirement_problems()),
                'connections' => ($mcp && class_exists('WIT_OAuth') && WIT_OAuth::is_enabled()) ? count(WIT_OAuth::connections()) : 0,
                'can_manage'  => current_user_can('manage_options'),
            ),
            'wpml'      => array(
                'real'    => WIT_WPML_Native::is_active(),
                'version' => defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : '',
                'st'      => WIT_Strings::is_available(),
                'default' => $default,
            ),
            'problems'  => $problems,
            'languages' => $languages,
            'strings'   => $strings,
            'queue'     => $this->queue_summary(),
            'stats'     => array(
                'total'      => (int) $stats['total'],
                'successful' => (int) $stats['successful'],
                'failed'     => (int) $stats['failed'],
                'recent'     => (int) $stats['recent'],
                'tokens_30d' => (int) $stats['tokens_30d'],
            ),
            'recent'    => $recent,
        );
    }

    // -----------------------------------------------------------------------
    // Posts
    // -----------------------------------------------------------------------

    public function posts(WP_REST_Request $request) {
        $language = $this->language($request);
        if ($language === '') {
            return $this->error('wit_language', __('El idioma no está activo en WPML.', 'wpml-imagina-translate'));
        }

        $types = array_values(array_filter(array_map('sanitize_key', (array) $request->get_param('post_types')), 'post_type_exists'));
        if (empty($types)) {
            $types = array('post', 'page');
        }

        $status = sanitize_key((string) $request->get_param('status'));
        if (!in_array($status, array('pending', 'missing', 'outdated', 'current', 'unknown', 'edited', 'all'), true)) {
            $status = 'pending';
        }

        $list = WIT_Translation_Status::listing(
            $language,
            $types,
            $status,
            sanitize_text_field((string) $request->get_param('search')),
            max(1, (int) $request->get_param('page')),
            min(100, max(1, (int) ($request->get_param('per_page') ?: 25))),
            2000
        );

        $queued = WIT_Queue::instance()->queued_posts($language);
        $items  = array();
        foreach ($list['items'] as $post) {
            $type     = get_post_type_object($post['type']);
            $modified = get_post_field('post_modified_gmt', $post['id']);
            $items[]  = array(
                'id'                   => (int) $post['id'],
                'title'                => html_entity_decode($post['title'], ENT_QUOTES, 'UTF-8'),
                'type'                 => $post['type'],
                'type_label'           => $type ? $type->labels->singular_name : $post['type'],
                // The listing reuses "status" for the translation state.
                'post_status'          => (string) get_post_status($post['id']),
                'modified'             => (string) $modified,
                'translation'          => array(
                    'state'       => $post['status'],
                    'edited'      => (bool) $post['edited'],
                    'id'          => (int) $post['translation_id'],
                    'post_status' => (string) $post['translation_status'],
                    'at'          => (string) $post['translated_at'],
                    'edit_url'    => (string) $post['translation_edit_url'],
                    'view_url'    => $post['translation_id'] ? (string) get_permalink($post['translation_id']) : '',
                ),
                'edit_url'             => (string) $post['edit_url'],
                'view_url'             => (string) get_permalink($post['id']),
                // pending | processing | '' — in the background queue now.
                'queued'               => isset($queued[(int) $post['id']]) ? $queued[(int) $post['id']] : '',
            );
        }

        return array(
            'items'     => $items,
            'total'     => $list['total'],
            'page'      => $list['page'],
            'pages'     => $list['pages'],
            'counts'    => $list['counts'],
            'truncated' => $list['truncated'],
        );
    }

    /**
     * Translate one post now. Same contract as the AJAX handler it replaces:
     * the job's state goes to a transient first, so if the proxy cuts this
     * request the app can poll translate-status while PHP finishes.
     */
    public function translate(WP_REST_Request $request) {
        @ignore_user_abort(true);
        @set_time_limit(600);

        $post_id  = absint($request->get_param('post_id'));
        $language = $this->language($request);

        $allowed = WIT_WPML_Integration::instance()->can_translate($post_id, $language);
        if (is_wp_error($allowed)) {
            return $this->result($allowed);
        }

        $key = 'wit_job_' . $post_id . '_' . sanitize_key($language);
        set_transient($key, array('status' => 'processing', 'started_at' => time()), 30 * MINUTE_IN_SECONDS);

        try {
            $result = (new WIT_Translation_Manager())->translate_post($post_id, $language);
        } catch (\Throwable $e) {
            $result = array('success' => false, 'message' => $e->getMessage() ?: __('Error interno del servidor', 'wpml-imagina-translate'), 'translated_post_id' => 0);
        }

        $payload = array(
            'status'         => !empty($result['success']) ? 'complete' : 'error',
            'message'        => (string) $result['message'],
            'translation_id' => (int) $result['translated_post_id'],
            'edit_url'       => $result['translated_post_id'] ? (string) get_edit_post_link($result['translated_post_id'], 'raw') : '',
        );
        set_transient($key, $payload, 30 * MINUTE_IN_SECONDS);

        if (empty($result['success'])) {
            return $this->error('wit_translate_failed', $payload['message'], 422);
        }

        return $payload;
    }

    public function translate_status(WP_REST_Request $request) {
        $post_id  = absint($request->get_param('post_id'));
        $language = $this->language($request);

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return $this->error('wit_forbidden', __('Sin permisos', 'wpml-imagina-translate'), 403);
        }

        $job = get_transient('wit_job_' . $post_id . '_' . sanitize_key($language));

        return $job !== false ? $job : array('status' => 'not_found');
    }

    public function review(WP_REST_Request $request) {
        return $this->result(WIT_Abilities::get_translation(array(
            'post_id'  => absint($request->get_param('post_id')),
            'language' => $this->language($request),
        )));
    }

    public function correct(WP_REST_Request $request) {
        $input = array(
            'post_id'      => absint($request->get_param('post_id')),
            'language'     => $this->language($request),
            'current_text' => (string) $request->get_param('current_text'),
            'new_text'     => (string) $request->get_param('new_text'),
        );

        if ($input['current_text'] === '' || $input['new_text'] === '') {
            return $this->error('wit_empty', __('Indica el texto actual y el nuevo.', 'wpml-imagina-translate'));
        }

        return $this->result(WIT_Abilities::as_panel(function () use ($input) {
            return WIT_Abilities::correct($input);
        }));
    }

    public function publish(WP_REST_Request $request) {
        $input = array('post_id' => absint($request->get_param('post_id')), 'language' => $this->language($request));

        return $this->result(WIT_Abilities::as_panel(function () use ($input) {
            return WIT_Abilities::publish($input);
        }));
    }

    public function remove(WP_REST_Request $request) {
        $mode  = $request->get_param('mode') === 'trash' ? 'trash' : 'unpublish';
        $input = array('post_id' => absint($request->get_param('post_id')), 'language' => $this->language($request), 'mode' => $mode);

        return $this->result(WIT_Abilities::as_panel(function () use ($input) {
            return WIT_Abilities::remove_translation($input);
        }));
    }

    // -----------------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------------

    private function queue_summary($batch_id = '') {
        $status = WIT_Queue::instance()->status($batch_id);

        return array(
            'pending'    => (int) $status['pending'],
            'processing' => (int) $status['processing'],
            'done'       => (int) $status['done'],
            'error'      => (int) $status['error'],
            'total'      => (int) $status['total'],
            'recent'     => array_map(function ($row) {
                return array(
                    'id'       => (int) $row['id'],
                    'post_id'  => (int) $row['post_id'],
                    'title'    => html_entity_decode((string) $row['title'], ENT_QUOTES, 'UTF-8'),
                    'language' => (string) $row['target_lang'],
                    'status'   => (string) $row['status'],
                    'message'  => (string) $row['message'],
                );
            }, $status['recent']),
        );
    }

    public function queue_status(WP_REST_Request $request) {
        $queue = WIT_Queue::instance();
        $queue->cleanup();

        $summary = $this->queue_summary(sanitize_text_field((string) $request->get_param('batch_id')));

        // Keep nudging WP-Cron while someone watches: on a quiet site
        // nothing else would.
        if ($summary['pending'] > 0) {
            $queue->schedule();
        }

        return $summary;
    }

    public function queue_add(WP_REST_Request $request) {
        $language = $this->language($request);
        if ($language === '') {
            return $this->error('wit_language', __('El idioma no está activo en WPML.', 'wpml-imagina-translate'));
        }

        $ids = array_values(array_filter(array_map('absint', (array) $request->get_param('post_ids'))));

        // "Translate everything pending in this language", from the home
        // screen: the same originals the overview counted.
        if ($request->get_param('all_pending')) {
            $types = array_values(array_filter(array_map('sanitize_key', (array) ($request->get_param('post_types') ?: array('post', 'page'))), 'post_type_exists'));
            $list  = WIT_Translation_Status::listing($language, $types ?: array('post', 'page'), 'pending', '', 1, 2000, 2000);
            $ids   = array_map('intval', array_column($list['items'], 'id'));
        }

        if (empty($ids)) {
            return $this->error('wit_empty', __('No se seleccionó ningún contenido.', 'wpml-imagina-translate'));
        }

        $result = WIT_Queue::instance()->enqueue($ids, $language);

        if ($result['queued'] === 0) {
            return $this->error('wit_nothing_queued', __('No se pudo encolar ningún contenido (ya estaban en cola o no se pueden traducir).', 'wpml-imagina-translate'));
        }

        return $result;
    }

    public function queue_cancel(WP_REST_Request $request) {
        return array('removed' => WIT_Queue::instance()->cancel_pending());
    }

    // -----------------------------------------------------------------------
    // History
    // -----------------------------------------------------------------------

    public function log_row(array $log) {
        $post_id = (int) $log['post_id'];
        // Rows without a post: a batch of String Translation strings (it has
        // a target language) or a change to the plugin's own settings.
        $kind = $post_id > 0 ? 'post' : ((string) $log['target_lang'] !== '' ? 'strings' : 'settings');

        return array(
            'id'          => (int) $log['id'],
            'kind'        => $kind,
            'post_id'     => (int) $log['post_id'],
            'title'       => html_entity_decode((string) $log['post_title'], ENT_QUOTES, 'UTF-8'),
            'from'        => (string) $log['source_lang'],
            'to'          => (string) $log['target_lang'],
            'via'         => (string) $log['ai_provider'],
            'status'      => (string) $log['status'],
            'message'     => (string) $log['message'],
            'tokens_in'   => isset($log['input_tokens']) ? (int) $log['input_tokens'] : 0,
            'tokens_out'  => isset($log['output_tokens']) ? (int) $log['output_tokens'] : 0,
            'strings_ai'  => isset($log['strings_ai']) ? (int) $log['strings_ai'] : 0,
            'strings_mem' => isset($log['strings_memory']) ? (int) $log['strings_memory'] : 0,
            'strings_glo' => isset($log['strings_glossary']) ? (int) $log['strings_glossary'] : 0,
            'date'        => $this->gmt($log['created_at']),
            'edit_url'    => (int) $log['post_id'] > 0 ? (string) get_edit_post_link((int) $log['post_id'], 'raw') : '',
        );
    }

    public function logs(WP_REST_Request $request) {
        $manager  = new WIT_Translation_Manager();
        $per_page = min(100, max(1, (int) ($request->get_param('per_page') ?: 25)));
        $total    = $manager->count_translation_logs();
        $pages    = (int) max(1, ceil($total / $per_page));
        $page     = min(max(1, (int) $request->get_param('page')), $pages);

        return array(
            'items' => array_map(array($this, 'log_row'), $manager->get_translation_logs($per_page, ($page - 1) * $per_page)),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        );
    }

    // -----------------------------------------------------------------------
    // Strings
    // -----------------------------------------------------------------------

    public function strings(WP_REST_Request $request) {
        if (!WIT_Strings::is_available()) {
            return $this->error('wit_no_st', __('WPML String Translation no está activo.', 'wpml-imagina-translate'), 404);
        }
        $language = $this->language($request);
        if ($language === '') {
            return $this->error('wit_language', __('El idioma no está activo en WPML.', 'wpml-imagina-translate'));
        }

        $status = (string) $request->get_param('status');

        return WIT_Strings::listing($language, array(
            'domain'   => sanitize_text_field((string) $request->get_param('domain')),
            'status'   => in_array($status, array('pending', 'needs_update', 'translated', 'all'), true) ? $status : 'pending',
            'search'   => sanitize_text_field((string) $request->get_param('search')),
            'page'     => max(1, (int) $request->get_param('page')),
            'per_page' => min(100, max(1, (int) ($request->get_param('per_page') ?: 50))),
        ));
    }

    public function string_domains(WP_REST_Request $request) {
        $language = $this->language($request);
        if ($language === '' || !WIT_Strings::is_available()) {
            return array();
        }
        return WIT_Strings::domains($language);
    }

    public function strings_translate(WP_REST_Request $request) {
        if (!WIT_Strings::is_available()) {
            return $this->error('wit_no_st', __('WPML String Translation no está activo.', 'wpml-imagina-translate'), 404);
        }
        $language = $this->language($request);
        if ($language === '') {
            return $this->error('wit_language', __('El idioma no está activo en WPML.', 'wpml-imagina-translate'));
        }

        $ids = array_slice(array_values(array_filter(array_map('absint', (array) $request->get_param('ids')))), 0, WIT_Strings::BATCH);
        if (empty($ids)) {
            return $this->error('wit_empty', __('No se seleccionó ninguna cadena.', 'wpml-imagina-translate'));
        }

        @set_time_limit(300);

        $result       = WIT_Strings::translate($ids, $language);
        $translations = array();
        foreach ($result['results'] as $row) {
            if ($row['status'] === 'saved') {
                $translations[(string) $row['id']] = $row['translation'];
            }
        }

        return array('saved' => $result['saved'], 'errors' => $result['errors'], 'translations' => (object) $translations);
    }

    public function strings_save(WP_REST_Request $request) {
        $language = $this->language($request);
        if ($language === '') {
            return $this->error('wit_language', __('El idioma no está activo en WPML.', 'wpml-imagina-translate'));
        }

        return $this->result(WIT_Strings::save_manual(absint($request->get_param('id')), $language, (string) $request->get_param('value')));
    }

    public function strings_next(WP_REST_Request $request) {
        $language = $this->language($request);
        if ($language === '' || !WIT_Strings::is_available()) {
            return array('ids' => array(), 'remaining' => 0);
        }

        $skip   = array_values(array_filter(array_map('absint', (array) $request->get_param('skip'))));
        $status = $request->get_param('status') === 'needs_update' ? 'needs_update' : 'pending';
        $list   = WIT_Strings::listing($language, array(
            'domain'   => sanitize_text_field((string) $request->get_param('domain')),
            'status'   => $status,
            'search'   => sanitize_text_field((string) $request->get_param('search')),
            'per_page' => WIT_Strings::BATCH + count($skip),
        ));

        $ids = array_values(array_diff(array_column($list['items'], 'id'), $skip));

        return array('ids' => array_slice($ids, 0, WIT_Strings::BATCH), 'remaining' => max(0, $list['total'] - count($skip)));
    }

    // -----------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------

    public function settings_get(WP_REST_Request $request) {
        $settings = WIT_Settings::instance();
        $s        = $settings->get_settings();
        $memory   = WIT_Translation_Memory::instance()->stats();

        $keys = array();
        foreach (array('openai', 'claude', 'gemini') as $name) {
            $key           = (string) $s[$name . '_api_key'];
            // Never the key itself: whether one is stored, and its last
            // four characters so the owner can tell which one it is.
            $keys[$name]   = array('stored' => $key !== '', 'hint' => $key !== '' ? '…' . substr($key, -4) : '');
        }

        $mcp_enabled = !empty($s['mcp_enabled']);
        $connections = array();
        if ($mcp_enabled && class_exists('WIT_OAuth') && WIT_OAuth::is_enabled()) {
            foreach (WIT_OAuth::connections() as $c) {
                $connections[] = array(
                    'grant'     => (string) $c['grant_id'],
                    'client'    => $c['client_name'] !== '' ? (string) $c['client_name'] : 'Claude',
                    'user'      => (string) $c['user'],
                    'created'   => (string) $c['created_at'],
                    'last_used' => (string) $c['last_used_at'],
                );
            }
        }

        return array(
            'provider'      => $s['ai_provider'],
            'keys'          => $keys,
            'models'        => array('openai' => $s['openai_model'], 'claude' => $s['claude_model'], 'gemini' => $s['gemini_model']),
            'prompt'        => (string) $s['translation_prompt'],
            'glossary'      => (string) $s['glossary'],
            'translate_meta_fields' => !empty($s['translate_meta_fields']),
            'meta_fields'   => (string) $s['meta_fields_list'],
            'use_wpml_fields' => !empty($s['use_wpml_fields']),
            'batch_size'    => (int) $s['batch_size'],
            'memory'        => array('enabled' => !empty($s['enable_translation_memory']), 'entries' => (int) $memory['entries'], 'reuses' => (int) $memory['reuses']),
            'mcp'           => array(
                'enabled'     => $mcp_enabled,
                'url'         => class_exists('WIT_OAuth') ? WIT_OAuth::resource_url() : '',
                'problems'    => $settings->mcp_requirement_problems(),
                'connections' => $connections,
            ),
            'wpml_real'     => WIT_WPML_Native::is_active(),
        );
    }

    public function settings_put(WP_REST_Request $request) {
        $body    = (array) $request->get_json_params();
        $map     = array(
            'provider'              => 'ai_provider',
            'prompt'                => 'translation_prompt',
            'glossary'              => 'glossary',
            'translate_meta_fields' => 'translate_meta_fields',
            'meta_fields'           => 'meta_fields_list',
            'use_wpml_fields'       => 'use_wpml_fields',
            'batch_size'            => 'batch_size',
            'memory_enabled'        => 'enable_translation_memory',
            'mcp_enabled'           => 'mcp_enabled',
        );
        $changes = array();

        foreach ($map as $from => $to) {
            if (array_key_exists($from, $body)) {
                $changes[$to] = $body[$from];
            }
        }

        foreach (array('openai', 'claude', 'gemini') as $name) {
            if (isset($body['models'][$name])) {
                $changes[$name . '_model'] = (string) $body['models'][$name];
            }
            if (isset($body['keys'][$name]) && is_string($body['keys'][$name]) && $body['keys'][$name] !== '') {
                $changes[$name . '_api_key'] = $body['keys'][$name];
            }
            if (!empty($body['clear_keys'][$name])) {
                $changes['clear_' . $name . '_api_key'] = 1;
            }
        }

        WIT_Settings::instance()->save_from_panel($changes);

        return $this->settings_get($request);
    }

    public function models(WP_REST_Request $request) {
        $provider = sanitize_key((string) $request->get_param('provider'));
        if (!in_array($provider, array('openai', 'claude', 'gemini'), true)) {
            return $this->error('wit_provider', __('Proveedor no reconocido', 'wpml-imagina-translate'));
        }

        $key = trim(preg_replace('/[\x00-\x1F\x7F\s]/u', '', (string) $request->get_param('api_key')));
        if ($key === '') {
            $key = (string) WIT_Settings::instance()->get($provider . '_api_key');
        }
        if ($key === '') {
            return $this->error('wit_no_key', __('Introduce la API key primero.', 'wpml-imagina-translate'));
        }

        $result = WIT_Translator_Engine::fetch_models($provider, $key);

        return $result['success']
            ? array('models' => $result['models'])
            : $this->error('wit_models', (string) $result['error'], 502);
    }

    public function test_connection(WP_REST_Request $request) {
        $result = (new WIT_Translator_Engine())->test_connection();

        return $result['success']
            ? array('message' => $result['message'], 'translation' => $result['translation'])
            : $this->error('wit_connection', (string) $result['message'], 502);
    }

    public function memory_clear(WP_REST_Request $request) {
        return array('removed' => WIT_Translation_Memory::instance()->clear());
    }

    public function connection_revoke(WP_REST_Request $request) {
        if (!class_exists('WIT_OAuth')) {
            return $this->error('wit_no_mcp', __('La conexión con Claude no está disponible.', 'wpml-imagina-translate'), 404);
        }
        WIT_OAuth::instance()->revoke_grant((string) $request->get_param('grant'));

        return array('revoked' => true);
    }
}
