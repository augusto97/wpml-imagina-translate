<?php
/**
 * MCP Server - the endpoint the Claude connector talks to.
 *
 * Model Context Protocol over Streamable HTTP: the client POSTs a JSON-RPC
 * message, this answers with a JSON body. Stateless — no session id, no
 * server-to-client stream — which the spec permits and which keeps it working
 * on any WordPress host, including ones that buffer or time out long-lived
 * responses.
 *
 * Every ability registered by WIT_Abilities becomes a tool. Authentication is
 * WIT_OAuth's bearer token and nothing else: an unauthenticated request gets
 * the 401 that starts Claude's OAuth flow, whatever it asked for.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_MCP_Server {

    /** Newest first; the first is offered when the client asks for another. */
    const PROTOCOL_VERSIONS = array('2025-11-25', '2025-06-18', '2025-03-26');

    const ROUTE = '/mcp';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!WIT_OAuth::is_enabled()) {
            return;
        }

        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_pre_serve_request', array($this, 'serve'), 10, 4);
        add_filter('rest_allowed_cors_headers', array($this, 'cors_allow_headers'));
        add_filter('rest_exposed_cors_headers', array($this, 'cors_expose_headers'));
    }

    public function register_routes() {
        register_rest_route(WIT_OAuth::NAMESPACE_, self::ROUTE, array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_post'),
                'permission_callback' => '__return_true', // authenticated inside, to send the right 401
            ),
            array(
                // No server-initiated stream and no sessions to end.
                'methods'             => 'GET, DELETE',
                'callback'            => function () {
                    $response = new WP_REST_Response(null, 405);
                    $response->header('Allow', 'POST');
                    return $response;
                },
                'permission_callback' => '__return_true',
            ),
        ));
    }

    /**
     * Handle one JSON-RPC message.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_post(WP_REST_Request $request) {
        $origin = $request->get_header('origin');

        if ($origin && !$this->is_allowed_origin($origin)) {
            // The spec requires this: without it a page the user visits could
            // drive the endpoint through DNS rebinding.
            return new WP_REST_Response(array('error' => 'Origin not allowed'), 403);
        }

        if (!WIT_OAuth::current_token()) {
            return WIT_OAuth::unauthorized_response();
        }

        $message = json_decode($request->get_body(), true);

        if (!is_array($message)) {
            return $this->rpc_error(null, -32700, 'Parse error', 400);
        }

        if (array_keys($message) === range(0, count($message) - 1)) {
            // JSON-RPC batches were removed from MCP in 2025-06-18.
            return $this->rpc_error(null, -32600, 'Batch requests are not supported', 400);
        }

        $id     = array_key_exists('id', $message) ? $message['id'] : null;
        $method = isset($message['method']) ? (string) $message['method'] : '';

        // A notification, or a response to a request this server never sends.
        if (!array_key_exists('id', $message) || $method === '') {
            return new WP_REST_Response(null, 202);
        }

        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : array();

        switch ($method) {
            case 'initialize':
                return $this->rpc_result($id, $this->initialize($params));

            case 'ping':
                return $this->rpc_result($id, new stdClass());

            case 'tools/list':
                return $this->rpc_result($id, array('tools' => $this->tools()));

            case 'tools/call':
                return $this->call_tool($id, $params);

            default:
                return $this->rpc_error($id, -32601, 'Method not found: ' . $method);
        }
    }

    private function initialize(array $params) {
        $requested = isset($params['protocolVersion']) ? (string) $params['protocolVersion'] : '';
        $version   = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        return array(
            'protocolVersion' => $version,
            'capabilities'    => array('tools' => array('listChanged' => false)),
            'serverInfo'      => array(
                'name'    => 'wpml-imagina-translate',
                'title'   => 'WPML Imagina Translate — ' . get_bloginfo('name'),
                'version' => WIT_VERSION,
            ),
            'instructions'    => self::instructions(),
        );
    }

    /**
     * Standing instructions for the model, sent once per connection.
     *
     * @return string
     */
    public static function instructions() {
        $user = wp_get_current_user();

        return 'This server manages the translations of the WordPress site "' . get_bloginfo('name') . '" (WPML). '
            . 'You are acting as the WordPress user "' . $user->display_name . '", with their permissions. '
            . "\n\n"
            . 'Typical flow: translation_overview to see what is left, list_posts with status "pending" to get the posts, '
            . 'prepare_translation for up to ' . WIT_Translation_Plan::MAX_POSTS . ' of them, translate the returned strings yourself, then save_translation. '
            . "\n\n"
            . 'YOU do the translating, using the user\'s Claude subscription. The site\'s paid translation API is never used from here, '
            . 'and nothing you do through these tools charges it. '
            . 'When more than ' . WIT_Translation_Plan::MAX_POSTS . ' posts are involved, first tell the user how many there are and how much text '
            . '("totals" in prepare_translation), explain that translating them here consumes their Claude usage, and let them decide whether '
            . 'and how to proceed — batch by batch, never more than ' . WIT_Translation_Plan::MAX_POSTS . ' posts per call. '
            . "\n\n"
            . 'Translations are saved as drafts. Publish only when the user explicitly asks. '
            . 'Take post IDs from list_posts or post_translation_status; never guess them. '
            . 'Answer the user in their own language.';
    }

    /**
     * The tool list, derived from the registered abilities.
     *
     * Tools the current user lacks the base capability for are left out, so
     * an editor is not offered glossary management that would only fail.
     *
     * @return array[]
     */
    private function tools() {
        $tools = array();

        foreach (WIT_Abilities::definitions() as $name => $definition) {
            if (!current_user_can($definition['permission']) || !wp_get_ability($name)) {
                continue;
            }

            $annotations = $definition['annotations'];

            $tools[] = array(
                'name'        => self::tool_name($name),
                'title'       => $definition['label'],
                'description' => $definition['description'],
                'inputSchema' => self::schema_for_json($definition['input']),
                'annotations' => array(
                    'title'           => $definition['label'],
                    'readOnlyHint'    => !empty($annotations['readonly']),
                    'destructiveHint' => !empty($annotations['destructive']),
                    'idempotentHint'  => !empty($annotations['idempotent']),
                    // Only this site, never the wider web.
                    'openWorldHint'   => false,
                ),
            );
        }

        return $tools;
    }

    private function call_tool($id, array $params) {
        $tool = isset($params['name']) ? (string) $params['name'] : '';
        $name = self::ability_name($tool);

        if ($name === '' || !isset(WIT_Abilities::definitions()[$name])) {
            return $this->rpc_error($id, -32602, 'Unknown tool: ' . $tool);
        }

        $ability = wp_get_ability($name);

        if (!$ability) {
            return $this->rpc_error($id, -32602, 'Unknown tool: ' . $tool);
        }

        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();

        try {
            $result = $ability->execute($arguments);
        } catch (\Throwable $e) {
            error_log('WIT MCP ' . $tool . ': ' . $e->getMessage());
            return $this->rpc_result($id, $this->tool_error(__('Error interno al ejecutar la herramienta.', 'wpml-imagina-translate')));
        }

        if (is_wp_error($result)) {
            // A tool error, not a protocol error: the model sees the message
            // and can correct course, which it cannot do with a JSON-RPC error.
            return $this->rpc_result($id, $this->tool_error($result->get_error_message()));
        }

        // structuredContent must be an object.
        $structured = (is_array($result) && ($result === array() || array_keys($result) !== range(0, count($result) - 1)))
            ? $result
            : array('result' => $result);

        return $this->rpc_result($id, array(
            'content'           => array(array(
                'type' => 'text',
                'text' => wp_json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            )),
            'structuredContent' => empty($structured) ? new stdClass() : $structured,
            'isError'           => false,
        ));
    }

    private function tool_error($message) {
        return array(
            'content' => array(array('type' => 'text', 'text' => (string) $message)),
            'isError' => true,
        );
    }

    // -----------------------------------------------------------------------
    // Naming and schemas
    // -----------------------------------------------------------------------

    /**
     * "wit/list-posts" → "list_posts". MCP tool names may not contain "/".
     *
     * @param string $ability
     * @return string
     */
    public static function tool_name($ability) {
        $map = self::tool_aliases();
        $key = array_search($ability, $map, true);

        return $key !== false ? $key : str_replace(array('wit/', '-'), array('', '_'), $ability);
    }

    /**
     * @param string $tool
     * @return string Ability name, or '' when unknown.
     */
    public static function ability_name($tool) {
        foreach (array_keys(WIT_Abilities::definitions()) as $ability) {
            if (self::tool_name($ability) === $tool) {
                return $ability;
            }
        }

        return '';
    }

    /**
     * Tool names that read better than the mechanical conversion.
     *
     * @return array tool => ability
     */
    private static function tool_aliases() {
        return array(
            'post_translation_status' => 'wit/post-status',
        );
    }

    /**
     * An ability's input schema as JSON Schema for the wire.
     *
     * PHP cannot tell an empty list from an empty object, and the Abilities
     * API wants arrays for both. JSON Schema needs `properties` to be an
     * object, so empty ones are converted before encoding.
     *
     * @param array $schema
     * @return array
     */
    private static function schema_for_json(array $schema) {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            if (empty($schema['properties'])) {
                $schema['properties'] = new stdClass();
            } else {
                foreach ($schema['properties'] as $key => $child) {
                    if (is_array($child)) {
                        $schema['properties'][$key] = self::schema_for_json($child);
                    }
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::schema_for_json($schema['items']);
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            $schema['additionalProperties'] = self::schema_for_json($schema['additionalProperties']);
        }

        return $schema;
    }

    // -----------------------------------------------------------------------
    // Transport details
    // -----------------------------------------------------------------------

    private function is_allowed_origin($origin) {
        $host = wp_parse_url($origin, PHP_URL_HOST);

        $allowed = array(
            wp_parse_url(home_url(), PHP_URL_HOST),
            'claude.ai',
            'claude.com',
        );

        /**
         * Filter the hosts allowed to send an Origin header to the MCP endpoint.
         *
         * Requests without an Origin — Claude's servers, Claude Code — are
         * always accepted; this only concerns browsers.
         *
         * @param string[] $allowed
         */
        $allowed = (array) apply_filters('wit_mcp_allowed_origins', $allowed);

        return $host && in_array(strtolower($host), array_map('strtolower', $allowed), true);
    }

    /**
     * Send MCP responses unescaped, and 202s with no body.
     *
     * WordPress would JSON-encode every non-ASCII character as \uXXXX, which
     * multiplies the size of a Spanish text by up to six on the wire; and it
     * would send "null" as the body of a 202, which the spec says is empty.
     *
     * @return bool
     */
    public function serve($served, $result, $request, $server) {
        if ($served || !($request instanceof WP_REST_Request) || untrailingslashit($request->get_route()) !== '/' . WIT_OAuth::NAMESPACE_ . self::ROUTE) {
            return $served;
        }

        if ($result->get_status() === 202 || $result->get_data() === null) {
            return true;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($result->get_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return true;
    }

    public function cors_allow_headers($headers) {
        return array_merge((array) $headers, array('Mcp-Protocol-Version', 'Mcp-Session-Id', 'Last-Event-ID'));
    }

    public function cors_expose_headers($headers) {
        return array_merge((array) $headers, array('WWW-Authenticate', 'Mcp-Session-Id'));
    }

    private function rpc_result($id, $result) {
        return new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result), 200);
    }

    private function rpc_error($id, $code, $message, $status = 200) {
        return new WP_REST_Response(array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => array('code' => $code, 'message' => $message),
        ), $status);
    }
}
