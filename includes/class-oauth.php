<?php
/**
 * OAuth - lets a user connect Claude to this site without handing it a password.
 *
 * Claude's custom connectors authenticate with OAuth 2.0. The flow a user sees:
 *
 *   1. In Claude: Customize → Connectors → Add custom connector → paste the
 *      MCP URL from this plugin's settings.
 *   2. Claude registers itself here (Dynamic Client Registration, RFC 7591)
 *      and sends the user to this site's consent page.
 *   3. The user logs in to WordPress if needed, sees what Claude will be able
 *      to do and as whom, and clicks Allow.
 *   4. Claude exchanges the one-time code for tokens (PKCE, RFC 7636) and
 *      refreshes them on its own from then on.
 *
 * Claude acts as the WordPress user who approved, with that user's
 * capabilities and nothing more. Tokens authenticate only the MCP endpoint —
 * never the rest of the REST API, never wp-admin — and every connection can be
 * revoked from the settings page.
 *
 * Requirements taken from Claude's connector documentation rather than from
 * the generic spec alone: DCR with public clients ("none" auth), S256 PKCE on
 * every request, the redirect URI https://claude.ai/api/mcp/auth_callback plus
 * port-agnostic loopback redirects for Claude Code, a 401 carrying
 * WWW-Authenticate with resource_metadata, a form-urlencoded token endpoint,
 * RFC 6749 error codes on refresh failure, and rotating refresh tokens.
 *
 * Tokens are stored hashed. A database leak yields nothing usable.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_OAuth {

    const NAMESPACE_ = 'wit/v1';
    const SCOPE      = 'wit';

    const CODE_TTL    = 300;                 // 5 minutes
    const ACCESS_TTL  = HOUR_IN_SECONDS;
    const REFRESH_TTL = 60 * DAY_IN_SECONDS;

    /** Registered clients kept before the oldest unused ones are pruned. */
    const MAX_CLIENTS = 500;

    /** Front-end query arg that serves the consent page. */
    const AUTHORIZE_ARG = 'wit-oauth';

    private static $instance = null;

    /** @var object|null The access token row that authenticated this request. */
    private static $current_token = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!self::is_enabled()) {
            return;
        }

        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('init', array($this, 'serve_root_well_known'), 1);
        add_action('init', array($this, 'maybe_serve_authorize'), 5);
        add_filter('determine_current_user', array($this, 'authenticate_bearer'), 30);
        add_filter('rest_authentication_errors', array($this, 'allow_public_oauth_routes'), 999);
    }

    /**
     * Whether the MCP connection is switched on.
     *
     * Off by default: exposing a write-capable endpoint to the internet is a
     * decision the site owner makes, not one an update makes for them.
     *
     * @return bool
     */
    public static function is_enabled() {
        $settings = WIT_Settings::instance()->get_settings();

        return !empty($settings['mcp_enabled']) && WIT_Abilities::is_supported();
    }

    // -----------------------------------------------------------------------
    // URLs
    // -----------------------------------------------------------------------

    /** The MCP endpoint, exactly as the user pastes it into Claude. */
    public static function resource_url() {
        return untrailingslashit(rest_url(self::NAMESPACE_ . '/mcp'));
    }

    /** Authorization server issuer. */
    public static function issuer() {
        return untrailingslashit(rest_url(self::NAMESPACE_ . '/oauth'));
    }

    public static function protected_resource_url() {
        return untrailingslashit(rest_url(self::NAMESPACE_ . '/oauth/protected-resource'));
    }

    public static function authorize_url() {
        return add_query_arg(self::AUTHORIZE_ARG, 'authorize', home_url('/'));
    }

    // -----------------------------------------------------------------------
    // Metadata
    // -----------------------------------------------------------------------

    /**
     * RFC 9728 protected resource metadata.
     *
     * @return array
     */
    public static function protected_resource_metadata() {
        return array(
            'resource'                 => self::resource_url(),
            'authorization_servers'    => array(self::issuer()),
            'scopes_supported'         => array(self::SCOPE),
            'bearer_methods_supported' => array('header'),
            'resource_name'            => 'WPML Imagina Translate — ' . get_bloginfo('name'),
        );
    }

    /**
     * RFC 8414 authorization server metadata.
     *
     * @return array
     */
    public static function authorization_server_metadata($oidc = false) {
        $metadata = array(
            'issuer'                                => self::issuer(),
            'authorization_endpoint'                => self::authorize_url(),
            'token_endpoint'                        => untrailingslashit(rest_url(self::NAMESPACE_ . '/oauth/token')),
            'registration_endpoint'                 => untrailingslashit(rest_url(self::NAMESPACE_ . '/oauth/register')),
            'revocation_endpoint'                   => untrailingslashit(rest_url(self::NAMESPACE_ . '/oauth/revoke')),
            'scopes_supported'                      => array(self::SCOPE),
            'response_types_supported'              => array('code'),
            'response_modes_supported'              => array('query'),
            'grant_types_supported'                 => array('authorization_code', 'refresh_token'),
            'token_endpoint_auth_methods_supported' => array('none'),
            'revocation_endpoint_auth_methods_supported' => array('none'),
            'code_challenge_methods_supported'      => array('S256'),
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation'                 => 'https://github.com/augusto97/wpml-imagina-translate',
        );

        if ($oidc) {
            // Served at the openid-configuration paths, which clients parse as
            // OpenID Connect Discovery — and the MCP SDK does so strictly: a
            // document without these fields fails validation outright instead
            // of falling through to the next discovery URL. For a WordPress in
            // a subdirectory that path is the only one reachable, so without
            // them the connection could not be made at all.
            //
            // No ID token is ever issued ("openid" is not a supported scope),
            // so the key set is empty and these describe capabilities that a
            // client can only use by asking for a scope that will be refused.
            $metadata['jwks_uri']                              = untrailingslashit(rest_url(self::NAMESPACE_ . '/oauth/jwks'));
            $metadata['subject_types_supported']               = array('public');
            $metadata['id_token_signing_alg_values_supported'] = array('RS256');
        }

        return $metadata;
    }

    public function register_routes() {
        $public = '__return_true';

        register_rest_route(self::NAMESPACE_, '/oauth/protected-resource', array(
            'methods'             => 'GET',
            'callback'            => function () { return $this->json(self::protected_resource_metadata()); },
            'permission_callback' => $public,
        ));

        // Discovery for an issuer with a path. Clients try, in order:
        //   /.well-known/oauth-authorization-server/<path>   (root, see below)
        //   /.well-known/openid-configuration/<path>         (root, see below)
        //   <path>/.well-known/openid-configuration          (this one)
        // The last is the only one inside WordPress's own URL space, so it is
        // the one that works for a site installed in a subdirectory.
        foreach (array('openid-configuration' => true, 'oauth-authorization-server' => false) as $document => $oidc) {
            register_rest_route(self::NAMESPACE_, '/oauth/\.well-known/' . $document, array(
                'methods'             => 'GET',
                'callback'            => function () use ($oidc) { return $this->json(self::authorization_server_metadata($oidc)); },
                'permission_callback' => $public,
            ));
        }

        register_rest_route(self::NAMESPACE_, '/oauth/jwks', array(
            'methods'             => 'GET',
            'callback'            => function () { return $this->json(array('keys' => array())); },
            'permission_callback' => $public,
        ));

        register_rest_route(self::NAMESPACE_, '/oauth/register', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_register'),
            'permission_callback' => $public,
        ));

        register_rest_route(self::NAMESPACE_, '/oauth/token', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_token'),
            'permission_callback' => $public,
        ));

        register_rest_route(self::NAMESPACE_, '/oauth/revoke', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_revoke'),
            'permission_callback' => $public,
        ));
    }

    /**
     * Serve the root-level well-known documents when WordPress receives them.
     *
     * Most servers route unknown paths to index.php, so a site at the domain
     * root can answer these. A site in a subdirectory cannot — its root is not
     * WordPress — which is why the in-REST discovery route exists too.
     */
    public function serve_root_well_known() {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';

        if (strpos($path, '/.well-known/') !== 0) {
            return;
        }

        $issuer_path   = (string) wp_parse_url(self::issuer(), PHP_URL_PATH);
        $resource_path = (string) wp_parse_url(self::resource_url(), PHP_URL_PATH);

        $documents = array(
            '/.well-known/oauth-authorization-server' . $issuer_path => 'as',
            '/.well-known/openid-configuration' . $issuer_path       => 'oidc',
            '/.well-known/oauth-protected-resource' . $resource_path => 'prm',
        );

        $path = untrailingslashit($path);

        if (!isset($documents[$path])) {
            return;
        }

        $kind = $documents[$path];
        $body = $kind === 'prm' ? self::protected_resource_metadata() : self::authorization_server_metadata($kind === 'oidc');

        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=300');
        echo wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Let the public OAuth endpoints through plugins that close the REST API
     * to anonymous visitors.
     *
     * Registration, token exchange and discovery are anonymous by design —
     * like wp-login.php — and a blanket "logged in users only" rule would
     * break every connection attempt with an error that points nowhere. Only
     * these routes are exempted; the MCP endpoint authenticates for real.
     *
     * @param WP_Error|null|true $result
     * @return WP_Error|null|true
     */
    public function allow_public_oauth_routes($result) {
        if (!is_wp_error($result)) {
            return $result;
        }

        $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

        return strpos($route, '/' . self::NAMESPACE_ . '/oauth/') === 0 ? null : $result;
    }

    // -----------------------------------------------------------------------
    // Dynamic Client Registration (RFC 7591)
    // -----------------------------------------------------------------------

    public function handle_register(WP_REST_Request $request) {
        $body = $request->get_json_params();

        if (!is_array($body)) {
            return $this->oauth_error('invalid_client_metadata', 'The request body must be JSON.', 400);
        }

        $redirect_uris = isset($body['redirect_uris']) ? (array) $body['redirect_uris'] : array();

        if (empty($redirect_uris)) {
            return $this->oauth_error('invalid_redirect_uri', 'redirect_uris is required.', 400);
        }

        foreach ($redirect_uris as $uri) {
            if (!is_string($uri) || !self::is_allowed_redirect_uri($uri)) {
                return $this->oauth_error(
                    'invalid_redirect_uri',
                    'Only Claude callback URLs and loopback addresses are accepted.',
                    400
                );
            }
        }

        $grant_types = isset($body['grant_types']) ? (array) $body['grant_types'] : array('authorization_code', 'refresh_token');
        if (array_diff($grant_types, array('authorization_code', 'refresh_token'))) {
            return $this->oauth_error('invalid_client_metadata', 'Unsupported grant type.', 400);
        }

        $client_id   = 'wit_' . bin2hex(random_bytes(16));
        $client_name = isset($body['client_name']) ? mb_substr(sanitize_text_field((string) $body['client_name']), 0, 200) : '';

        global $wpdb;

        $this->prune();

        $wpdb->insert(self::table('clients'), array(
            'client_id'     => $client_id,
            'client_name'   => $client_name,
            'redirect_uris' => wp_json_encode(array_values($redirect_uris)),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ));

        // Registered as a public client whatever was asked for: that is what
        // Claude uses, and a client secret this plugin would have to store
        // adds risk without adding protection over PKCE.
        return $this->json(array(
            'client_id'                  => $client_id,
            'client_id_issued_at'        => time(),
            'client_name'                => $client_name,
            'redirect_uris'              => array_values($redirect_uris),
            'grant_types'                => array('authorization_code', 'refresh_token'),
            'response_types'             => array('code'),
            'token_endpoint_auth_method' => 'none',
            'scope'                      => self::SCOPE,
        ), 201);
    }

    /**
     * Redirect URIs a client may register.
     *
     * Open registration with arbitrary redirects would let anyone mint a
     * client that sends a victim's approval code to their own server; the
     * consent screen shows the host, but a short list is a better defence than
     * trusting everyone to read it.
     *
     * @param string $uri
     * @return bool
     */
    public static function is_allowed_redirect_uri($uri) {
        $allowed = array(
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
        );

        $parts = wp_parse_url($uri);

        $allowed_now = in_array($uri, $allowed, true)
            // Claude Code: RFC 8252 loopback redirect on an ephemeral port.
            || (isset($parts['scheme'], $parts['host'])
                && $parts['scheme'] === 'http'
                && in_array($parts['host'], array('localhost', '127.0.0.1'), true)
                && empty($parts['user']) && empty($parts['pass']));

        /**
         * Filter whether a redirect URI may be registered.
         *
         * @param bool   $allowed
         * @param string $uri
         */
        return (bool) apply_filters('wit_oauth_allowed_redirect_uri', $allowed_now, $uri);
    }

    /**
     * Whether $given is one of the registered URIs.
     *
     * Exact match, except that loopback URIs match with any port: Claude Code
     * picks a new ephemeral port every session.
     *
     * @param string   $given
     * @param string[] $registered
     * @return bool
     */
    private static function redirect_matches($given, array $registered) {
        if (in_array($given, $registered, true)) {
            return true;
        }

        $g = wp_parse_url($given);

        if (!isset($g['scheme'], $g['host']) || $g['scheme'] !== 'http'
            || !in_array($g['host'], array('localhost', '127.0.0.1'), true)) {
            return false;
        }

        foreach ($registered as $uri) {
            $r = wp_parse_url($uri);
            if (isset($r['scheme'], $r['host'])
                && $r['scheme'] === $g['scheme']
                && $r['host'] === $g['host']
                && (isset($r['path']) ? $r['path'] : '/') === (isset($g['path']) ? $g['path'] : '/')) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Authorization endpoint and consent screen
    // -----------------------------------------------------------------------

    public function maybe_serve_authorize() {
        if (!isset($_GET[self::AUTHORIZE_ARG]) || $_GET[self::AUTHORIZE_ARG] !== 'authorize') {
            return;
        }

        nocache_headers();
        // Clickjacking: the consent button must never be framed.
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');

        $source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $params = array();
        foreach (array('response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource') as $key) {
            $params[$key] = isset($source[$key]) ? (string) wp_unslash($source[$key]) : '';
        }

        // Stage 1 — problems with the client or the redirect. Nothing may be
        // sent to a redirect URI that has not been validated, so these are
        // shown here instead.
        $client = $this->get_client($params['client_id']);

        if (!$client) {
            $this->render_error(__('Esta aplicación no está registrada en este sitio. Vuelve a Claude y conecta de nuevo.', 'wpml-imagina-translate'));
        }

        $registered = (array) json_decode($client->redirect_uris, true);

        if ($params['redirect_uri'] === '' || !self::redirect_matches($params['redirect_uri'], $registered)) {
            $this->render_error(__('La dirección de retorno no coincide con la registrada.', 'wpml-imagina-translate'));
        }

        // Stage 2 — everything else goes back to the client as an OAuth error.
        if ($params['response_type'] !== 'code') {
            $this->redirect_error($params, 'unsupported_response_type', 'Only response_type=code is supported.');
        }

        if ($params['code_challenge'] === '' || $params['code_challenge_method'] !== 'S256') {
            $this->redirect_error($params, 'invalid_request', 'PKCE with S256 is required.');
        }

        if ($params['resource'] !== '' && untrailingslashit($params['resource']) !== self::resource_url()) {
            $this->redirect_error($params, 'invalid_target', 'Unknown resource.');
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::authorize_url_with($params)));
            exit;
        }

        /**
         * Filter the capability needed to connect Claude.
         *
         * @param string $capability
         */
        $capability = apply_filters('wit_mcp_capability', 'edit_posts');

        if (!current_user_can($capability)) {
            $this->redirect_error($params, 'access_denied', 'This account cannot translate content.');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render_consent($client, $params);
        }

        if (!isset($_POST['_wit_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wit_nonce'])), 'wit_oauth_consent_' . $client->client_id)) {
            $this->render_error(__('La solicitud caducó. Vuelve a Claude y conecta de nuevo.', 'wpml-imagina-translate'));
        }

        if (!isset($_POST['decision']) || $_POST['decision'] !== 'allow') {
            $this->redirect_error($params, 'access_denied', 'The user declined.');
        }

        $code = $this->issue_token('code', $client->client_id, get_current_user_id(), bin2hex(random_bytes(16)), array(
            'redirect_uri'   => $params['redirect_uri'],
            'code_challenge' => $params['code_challenge'],
            'resource'       => self::resource_url(),
        ));

        $this->redirect_to_client($params['redirect_uri'], array(
            'code'  => $code,
            'state' => $params['state'],
            // RFC 9207: tells the client which server answered, against mix-up.
            'iss'   => self::issuer(),
        ));
    }

    /**
     * The authorization URL carrying the original request, for coming back to
     * after logging in. Values are encoded: add_query_arg() does not, and a
     * state or redirect URI containing & would otherwise split the query.
     *
     * @param array $params
     * @return string
     */
    private static function authorize_url_with(array $params) {
        return add_query_arg(
            array_map('rawurlencode', array_filter($params, 'strlen')),
            self::authorize_url()
        );
    }

    private function render_consent($client, array $params) {
        $user        = wp_get_current_user();
        $redirect    = wp_parse_url($params['redirect_uri']);
        $host        = isset($redirect['host']) ? $redirect['host'] : '';
        $is_loopback = in_array($host, array('localhost', '127.0.0.1'), true);
        $name        = $client->client_name !== '' ? $client->client_name : 'Claude';
        $site        = get_bloginfo('name');
        $switch_url  = wp_logout_url(wp_login_url(self::authorize_url_with($params)));

        $can = array(
            __('Ver qué contenidos están traducidos, cuáles faltan y cuáles quedaron desactualizados', 'wpml-imagina-translate'),
            __('Traducir contenidos y guardarlos como borrador (la traducción la hace Claude con tu suscripción; no se usa la API del sitio)', 'wpml-imagina-translate'),
            __('Revisar y corregir traducciones existentes', 'wpml-imagina-translate'),
        );

        $type = get_post_type_object('post');
        if ($type && current_user_can($type->cap->publish_posts)) {
            $can[] = __('Publicar traducciones cuando se lo pidas', 'wpml-imagina-translate');
        }
        if (current_user_can('manage_options')) {
            $can[] = __('Editar el glosario de traducción', 'wpml-imagina-translate');
        }

        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html(sprintf(__('Conectar %s', 'wpml-imagina-translate'), $name)); ?></title>
<style>
  :root { --fg:#1d2327; --muted:#50575e; --line:#dcdcde; --accent:#2271b1; --bg:#f0f0f1; --card:#fff; --warn:#996800; }
  @media (prefers-color-scheme: dark) { :root { --fg:#f0f0f1; --muted:#a7aaad; --line:#3c434a; --accent:#72aee6; --bg:#1d2327; --card:#2c3338; --warn:#dba617; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--fg); font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
  main { max-width:480px; margin:48px auto; padding:0 16px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:28px; }
  h1 { font-size:20px; margin:0 0 4px; }
  .site { color:var(--muted); margin:0 0 20px; }
  ul { padding-left:20px; margin:8px 0 20px; }
  li { margin:6px 0; }
  .who, .dest { font-size:13px; color:var(--muted); border-top:1px solid var(--line); padding-top:14px; margin-top:14px; }
  .dest strong { color:var(--fg); font-family:ui-monospace,Menlo,monospace; }
  .warn { color:var(--warn); }
  .actions { display:flex; gap:10px; margin-top:22px; }
  button { flex:1; font:inherit; padding:10px 14px; border-radius:6px; border:1px solid var(--line); background:transparent; color:var(--fg); cursor:pointer; }
  button.allow { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
  a { color:var(--accent); }
</style>
</head>
<body>
<main>
  <div class="card">
    <h1><?php echo esc_html(sprintf(__('%s quiere conectarse', 'wpml-imagina-translate'), $name)); ?></h1>
    <p class="site"><?php echo esc_html(sprintf(__('a WPML Imagina Translate en %s', 'wpml-imagina-translate'), $site)); ?></p>

    <p><?php esc_html_e('Podrá, en tu nombre:', 'wpml-imagina-translate'); ?></p>
    <ul>
      <?php foreach ($can as $item) : ?>
        <li><?php echo esc_html($item); ?></li>
      <?php endforeach; ?>
    </ul>
    <p><?php esc_html_e('No podrá ver ni cambiar las API keys ni ningún otro ajuste, y actuará solo con los permisos de tu cuenta. Puedes revocar el acceso en cualquier momento desde los ajustes del plugin.', 'wpml-imagina-translate'); ?></p>

    <p class="who">
      <?php echo esc_html(sprintf(__('Conectado como %1$s (%2$s).', 'wpml-imagina-translate'), $user->display_name, $user->user_login)); ?>
      <a href="<?php echo esc_url($switch_url); ?>"><?php esc_html_e('¿No eres tú? Cambia de cuenta', 'wpml-imagina-translate'); ?></a>
    </p>

    <p class="dest">
      <?php esc_html_e('Al permitir, volverás a:', 'wpml-imagina-translate'); ?> <strong><?php echo esc_html($host); ?></strong>
      <?php if ($is_loopback) : ?>
        <br><span class="warn"><?php esc_html_e('Es una aplicación en tu propio ordenador (por ejemplo Claude Code). Permítelo solo si acabas de iniciar esta conexión tú.', 'wpml-imagina-translate'); ?></span>
      <?php endif; ?>
    </p>

    <form method="post" action="<?php echo esc_url(self::authorize_url()); ?>">
      <?php foreach ($params as $key => $value) : ?>
        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
      <?php endforeach; ?>
      <?php wp_nonce_field('wit_oauth_consent_' . $client->client_id, '_wit_nonce'); ?>
      <div class="actions">
        <button type="submit" name="decision" value="deny"><?php esc_html_e('Cancelar', 'wpml-imagina-translate'); ?></button>
        <button type="submit" name="decision" value="allow" class="allow"><?php esc_html_e('Permitir', 'wpml-imagina-translate'); ?></button>
      </div>
    </form>
  </div>
</main>
</body>
</html>
        <?php
        exit;
    }

    private function render_error($message) {
        status_header(400);
        wp_die(esc_html($message), esc_html__('No se pudo conectar', 'wpml-imagina-translate'), array('response' => 400));
    }

    private function redirect_error(array $params, $error, $description) {
        $this->redirect_to_client($params['redirect_uri'], array(
            'error'             => $error,
            'error_description' => $description,
            'state'             => $params['state'],
            'iss'               => self::issuer(),
        ));
    }

    private function redirect_to_client($uri, array $query) {
        $query = array_filter($query, function ($value) {
            return $value !== '' && $value !== null;
        });

        // wp_redirect rather than wp_safe_redirect: the destination is
        // Claude's host, validated against the registration above.
        wp_redirect(add_query_arg(array_map('rawurlencode', $query), $uri), 302);
        exit;
    }

    // -----------------------------------------------------------------------
    // Token endpoint
    // -----------------------------------------------------------------------

    public function handle_token(WP_REST_Request $request) {
        $params = $request->get_body_params();
        if (empty($params)) {
            $params = (array) $request->get_json_params();
        }

        $grant     = isset($params['grant_type']) ? (string) $params['grant_type'] : '';
        $client_id = isset($params['client_id']) ? (string) $params['client_id'] : '';
        $client    = $this->get_client($client_id);

        if (!$client) {
            return $this->oauth_error('invalid_client', 'Unknown client.', 401);
        }

        if ($grant === 'authorization_code') {
            return $this->exchange_code($client, $params);
        }

        if ($grant === 'refresh_token') {
            return $this->exchange_refresh($client, $params);
        }

        return $this->oauth_error('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.', 400);
    }

    private function exchange_code($client, array $params) {
        global $wpdb;

        $code     = isset($params['code']) ? (string) $params['code'] : '';
        $verifier = isset($params['code_verifier']) ? (string) $params['code_verifier'] : '';
        $redirect = isset($params['redirect_uri']) ? (string) $params['redirect_uri'] : '';

        $row = $this->find_token($code, 'code');

        if (!$row || $row->client_id !== $client->client_id) {
            return $this->oauth_error('invalid_grant', 'Invalid authorization code.', 400);
        }

        if ((int) $row->used === 1) {
            // A code presented twice was intercepted or replayed. Revoke
            // everything issued from it, as OAuth 2.1 recommends.
            $this->revoke_grant($row->grant_id);
            return $this->oauth_error('invalid_grant', 'Authorization code already used.', 400);
        }

        if ((int) $row->revoked === 1 || strtotime($row->expires_at . ' UTC') < time()) {
            return $this->oauth_error('invalid_grant', 'Authorization code expired.', 400);
        }

        // Single use from here on, whatever happens next: a code that failed
        // a check is burned rather than left open to further attempts.
        $wpdb->update(self::table('tokens'), array('used' => 1), array('id' => $row->id));

        if ($redirect !== $row->redirect_uri) {
            return $this->oauth_error('invalid_grant', 'redirect_uri does not match.', 400);
        }

        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            return $this->oauth_error('invalid_grant', 'Invalid code_verifier.', 400);
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (!hash_equals((string) $row->code_challenge, $challenge)) {
            return $this->oauth_error('invalid_grant', 'PKCE verification failed.', 400);
        }

        if (!$this->user_may_connect((int) $row->user_id)) {
            return $this->oauth_error('invalid_grant', 'The user can no longer use this connection.', 400);
        }

        $wpdb->update(self::table('clients'), array('last_used_at' => gmdate('Y-m-d H:i:s')), array('client_id' => $client->client_id));

        return $this->token_response($client->client_id, (int) $row->user_id, $row->grant_id, $row->resource);
    }

    private function exchange_refresh($client, array $params) {
        global $wpdb;

        $token = isset($params['refresh_token']) ? (string) $params['refresh_token'] : '';
        $row   = $this->find_token($token, 'refresh');

        if (!$row || $row->client_id !== $client->client_id) {
            return $this->oauth_error('invalid_grant', 'Invalid refresh token.', 400);
        }

        if ((int) $row->revoked === 1) {
            // A rotated-out refresh token coming back means two parties hold
            // it. The legitimate one and the thief cannot be told apart, so
            // the whole connection goes.
            $this->revoke_grant($row->grant_id);
            return $this->oauth_error('invalid_grant', 'Refresh token already used.', 400);
        }

        if (strtotime($row->expires_at . ' UTC') < time()) {
            return $this->oauth_error('invalid_grant', 'Refresh token expired.', 400);
        }

        if (!$this->user_may_connect((int) $row->user_id)) {
            $this->revoke_grant($row->grant_id);
            return $this->oauth_error('invalid_grant', 'The user can no longer use this connection.', 400);
        }

        // Rotation: the old refresh token dies in the same response that
        // issues the new one.
        $wpdb->update(self::table('tokens'), array('revoked' => 1), array('id' => $row->id));

        // Earlier access tokens of this grant are superseded as well.
        $wpdb->update(
            self::table('tokens'),
            array('revoked' => 1),
            array('grant_id' => $row->grant_id, 'type' => 'access')
        );

        return $this->token_response($client->client_id, (int) $row->user_id, $row->grant_id, $row->resource);
    }

    private function token_response($client_id, $user_id, $grant_id, $resource) {
        $access  = $this->issue_token('access', $client_id, $user_id, $grant_id, array('resource' => $resource));
        $refresh = $this->issue_token('refresh', $client_id, $user_id, $grant_id, array('resource' => $resource));

        $response = $this->json(array(
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => self::SCOPE,
        ));
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    public function handle_revoke(WP_REST_Request $request) {
        $params = $request->get_body_params();
        if (empty($params)) {
            $params = (array) $request->get_json_params();
        }

        $token = isset($params['token']) ? (string) $params['token'] : '';

        foreach (array('access', 'refresh') as $type) {
            $row = $this->find_token($token, $type);
            if ($row) {
                $this->revoke_grant($row->grant_id);
                break;
            }
        }

        // RFC 7009: 200 whether or not the token existed.
        return $this->json(new stdClass());
    }

    // -----------------------------------------------------------------------
    // Authenticating MCP requests
    // -----------------------------------------------------------------------

    /**
     * Log in the user behind a valid bearer token — on the MCP route only.
     *
     * Scoped by URL on purpose: a token issued for the MCP connection must not
     * open the rest of the REST API, where endpoints far outside translation
     * would accept it.
     *
     * @param int|false $user_id
     * @return int|false
     */
    public function authenticate_bearer($user_id) {
        if (!self::is_mcp_request()) {
            return $user_id;
        }

        $row = self::validate_access_token(self::bearer_from_request());

        if (!$row) {
            return $user_id;
        }

        self::$current_token = $row;

        return (int) $row->user_id;
    }

    /**
     * The token row that authenticated this request, if any.
     *
     * @return object|null
     */
    public static function current_token() {
        return self::$current_token;
    }

    /**
     * @return bool
     */
    public static function is_mcp_request() {
        $route = isset($_GET['rest_route']) ? (string) wp_unslash($_GET['rest_route']) : '';

        if ($route === '') {
            $uri   = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $path  = untrailingslashit((string) wp_parse_url($uri, PHP_URL_PATH));
            $mcp   = untrailingslashit((string) wp_parse_url(self::resource_url(), PHP_URL_PATH));

            return $path !== '' && $path === $mcp;
        }

        return untrailingslashit($route) === '/' . self::NAMESPACE_ . '/mcp';
    }

    /**
     * The bearer token of the current request, from wherever the web server
     * left the Authorization header. Apache under CGI/FastCGI strips it unless
     * rewritten into REDIRECT_HTTP_AUTHORIZATION.
     *
     * @return string
     */
    public static function bearer_from_request() {
        $header = '';

        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
            if (!empty($_SERVER[$key])) {
                $header = (string) wp_unslash($_SERVER[$key]);
                break;
            }
        }

        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }

        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
    }

    /**
     * @param string $token
     * @return object|null
     */
    public static function validate_access_token($token) {
        if ($token === '') {
            return null;
        }

        $row = self::instance()->find_token($token, 'access');

        if (!$row || (int) $row->revoked === 1 || strtotime($row->expires_at . ' UTC') < time()) {
            return null;
        }

        // Audience: a token is only good for the resource it was issued for.
        if (untrailingslashit($row->resource) !== self::resource_url()) {
            return null;
        }

        if (!self::instance()->user_may_connect((int) $row->user_id)) {
            return null;
        }

        // Throttled: one write per connection every five minutes, not one per
        // request.
        if (empty($row->last_used_at) || strtotime($row->last_used_at . ' UTC') < time() - 300) {
            global $wpdb;
            $now = gmdate('Y-m-d H:i:s');
            $wpdb->update(self::table('tokens'), array('last_used_at' => $now), array('id' => $row->id));
            $wpdb->update(self::table('clients'), array('last_used_at' => $now), array('client_id' => $row->client_id));
        }

        return $row;
    }

    /**
     * The 401 that tells Claude where to authenticate.
     *
     * @return WP_REST_Response
     */
    public static function unauthorized_response() {
        $response = new WP_REST_Response(array(
            'error'             => 'invalid_token',
            'error_description' => 'Authentication required.',
        ), 401);

        $response->header('WWW-Authenticate', sprintf(
            'Bearer resource_metadata="%s", scope="%s"',
            self::protected_resource_url(),
            self::SCOPE
        ));

        return $response;
    }

    // -----------------------------------------------------------------------
    // Connections (for the settings page)
    // -----------------------------------------------------------------------

    /**
     * Active connections, newest first.
     *
     * @param int $user_id Limit to one user, or 0 for all.
     * @return array[]
     */
    public static function connections($user_id = 0) {
        global $wpdb;

        $tokens  = self::table('tokens');
        $clients = self::table('clients');
        $now     = gmdate('Y-m-d H:i:s');

        $where  = "t.type = 'refresh' AND t.revoked = 0 AND t.expires_at > %s";
        $params = array($now);

        if ($user_id) {
            $where   .= ' AND t.user_id = %d';
            $params[] = $user_id;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL -- table names are internal, values bound.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.grant_id, t.user_id, t.created_at, c.client_name, c.last_used_at
             FROM {$tokens} t
             LEFT JOIN {$clients} c ON c.client_id = t.client_id
             WHERE {$where}
             ORDER BY t.created_at DESC",
            $params
        ), ARRAY_A);

        foreach ($rows as $index => $row) {
            $user = get_userdata((int) $row['user_id']);
            $rows[$index]['user'] = $user ? $user->display_name . ' (' . $user->user_login . ')' : '—';
        }

        return $rows;
    }

    /**
     * Revoke every token of a connection.
     *
     * @param string $grant_id
     */
    public function revoke_grant($grant_id) {
        global $wpdb;

        $wpdb->update(self::table('tokens'), array('revoked' => 1), array('grant_id' => (string) $grant_id));
    }

    // -----------------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------------

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'wit_oauth_' . $name;
    }

    /**
     * @return string[] CREATE TABLE statements.
     */
    public static function schema() {
        global $wpdb;

        $collate = $wpdb->get_charset_collate();
        $clients = self::table('clients');
        $tokens  = self::table('tokens');

        return array(
            "CREATE TABLE {$clients} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(64) NOT NULL,
            client_name varchar(200) NOT NULL DEFAULT '',
            redirect_uris text NOT NULL,
            created_at datetime NOT NULL,
            last_used_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id)
        ) {$collate};",
            "CREATE TABLE {$tokens} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            type varchar(10) NOT NULL,
            client_id varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            grant_id char(32) NOT NULL,
            resource varchar(255) NOT NULL DEFAULT '',
            redirect_uri text,
            code_challenge varchar(128) DEFAULT NULL,
            used tinyint(1) NOT NULL DEFAULT 0,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            last_used_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY grant_id (grant_id),
            KEY user_id (user_id)
        ) {$collate};",
        );
    }

    /**
     * Mint a random token, store only its hash, return the token itself.
     *
     * @return string
     */
    private function issue_token($type, $client_id, $user_id, $grant_id, array $extra = array()) {
        global $wpdb;

        $ttl = array(
            'code'    => self::CODE_TTL,
            'access'  => self::ACCESS_TTL,
            'refresh' => self::REFRESH_TTL,
        );

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $wpdb->insert(self::table('tokens'), array(
            'token_hash'     => hash('sha256', $token),
            'type'           => $type,
            'client_id'      => $client_id,
            'user_id'        => (int) $user_id,
            'grant_id'       => $grant_id,
            'resource'       => isset($extra['resource']) ? $extra['resource'] : '',
            'redirect_uri'   => isset($extra['redirect_uri']) ? $extra['redirect_uri'] : null,
            'code_challenge' => isset($extra['code_challenge']) ? $extra['code_challenge'] : null,
            'expires_at'     => gmdate('Y-m-d H:i:s', time() + $ttl[$type]),
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ));

        return $token;
    }

    /**
     * @param string $token
     * @param string $type
     * @return object|null
     */
    private function find_token($token, $type) {
        global $wpdb;

        if (!is_string($token) || $token === '') {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('tokens') . ' WHERE token_hash = %s AND type = %s',
            hash('sha256', $token),
            $type
        ));
    }

    /**
     * @param string $client_id
     * @return object|null
     */
    private function get_client($client_id) {
        global $wpdb;

        if ($client_id === '') {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('clients') . ' WHERE client_id = %s',
            $client_id
        ));
    }

    /**
     * Whether a user may still hold a connection: the account exists and
     * still has the capability. Demoting someone cuts their chat off at the
     * next request, not at token expiry.
     *
     * @param int $user_id
     * @return bool
     */
    private function user_may_connect($user_id) {
        $user = get_userdata($user_id);

        return $user && user_can($user, apply_filters('wit_mcp_capability', 'edit_posts'));
    }

    /**
     * Housekeeping on registration: expired tokens, and clients that
     * registered but never completed a connection. DCR registers a new client
     * on every fresh connection attempt, so without this the table only grows.
     */
    private function prune() {
        global $wpdb;

        $tokens  = self::table('tokens');
        $clients = self::table('clients');

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$tokens} WHERE expires_at < %s",
            gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS)
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$clients} WHERE last_used_at IS NULL AND created_at < %s",
            gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$clients}");

        if ($count >= self::MAX_CLIENTS) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$clients} WHERE last_used_at IS NULL ORDER BY created_at ASC LIMIT %d",
                $count - self::MAX_CLIENTS + 1
            ));
        }
    }

    // -----------------------------------------------------------------------
    // Responses
    // -----------------------------------------------------------------------

    private function json($data, $status = 200) {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store');
        $response->header('Access-Control-Allow-Origin', '*');
        return $response;
    }

    private function oauth_error($error, $description, $status) {
        return $this->json(array('error' => $error, 'error_description' => $description), $status);
    }
}
