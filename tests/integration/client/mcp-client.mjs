// End-to-end test of the MCP connection with the official MCP SDK client.
//
// Does what Claude does when a user adds the connector: discovers the
// authorization server from the 401, registers itself (DCR), sends the user to
// the consent page, exchanges the code with PKCE, then calls tools. The
// "user" approving consent is simulated with a logged-in WordPress session.
//
// Then attacks the connection: tokens against other REST routes, a foreign
// Origin, disallowed redirect URIs, a wrong PKCE verifier, refresh token
// reuse, revocation.
//
// Usage: node mcp-client.mjs <site-url> <admin-user> <admin-pass> <post-id>
// Prints one JSON object with the result of every check.

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { UnauthorizedError } from '@modelcontextprotocol/sdk/client/auth.js';
import { createHash, randomBytes } from 'node:crypto';

const [SITE, USER, PASS, POST_ID] = process.argv.slice(2);
const MCP_URL = `${SITE}/wp-json/wit/v1/mcp`;
const REDIRECT = 'http://127.0.0.1:8765/callback';
const out = {};

// --- WordPress session, standing in for the user in the browser ------------
async function login() {
  const body = new URLSearchParams({ log: USER, pwd: PASS, 'wp-submit': 'Log In', testcookie: '1' });
  const res = await fetch(`${SITE}/wp-login.php`, {
    method: 'POST', body, redirect: 'manual',
    headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
  });
  return res.headers.getSetCookie().map((c) => c.split(';')[0]).join('; ');
}

// Approve (or deny) the consent screen the way a person would.
async function consent(authorizationUrl, cookies, decision = 'allow') {
  const page = await fetch(authorizationUrl, { headers: { Cookie: cookies }, redirect: 'manual' });
  const html = await page.text();
  if (page.status !== 200) {
    return { status: page.status, location: page.headers.get('location'), html };
  }
  const fields = {};
  for (const m of html.matchAll(/<input type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/g)) {
    fields[m[1]] = m[2].replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;/g, "'");
  }
  const action = (html.match(/<form method="post" action="([^"]+)"/) || [])[1].replace(/&amp;/g, '&').replace(/&#038;/g, '&');
  const res = await fetch(action, {
    method: 'POST', redirect: 'manual',
    headers: { Cookie: cookies, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ ...fields, decision }),
  });
  return { status: res.status, location: res.headers.get('location'), html };
}

// --- In-memory OAuth client provider, as the SDK expects ---------------------
function makeProvider() {
  const store = { client: undefined, tokens: undefined, verifier: undefined, authUrl: undefined };
  return {
    store,
    get redirectUrl() { return REDIRECT; },
    get clientMetadata() {
      return {
        client_name: 'Claude (test)',
        redirect_uris: [REDIRECT],
        grant_types: ['authorization_code', 'refresh_token'],
        response_types: ['code'],
        token_endpoint_auth_method: 'none',
      };
    },
    state: () => 'st-' + randomBytes(8).toString('hex'),
    clientInformation: () => store.client,
    saveClientInformation: (info) => { store.client = info; },
    tokens: () => store.tokens,
    saveTokens: (tokens) => { store.tokens = tokens; },
    redirectToAuthorization: (url) => { store.authUrl = url; },
    saveCodeVerifier: (v) => { store.verifier = v; },
    codeVerifier: () => store.verifier,
  };
}

const text = (result) => JSON.parse(result.content[0].text);

try {
  const cookies = await login();
  out.login = cookies.includes('wordpress_logged_in');

  // --- 1. Discovery surface --------------------------------------------------
  const anon = await fetch(MCP_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {} }),
  });
  const www = anon.headers.get('www-authenticate') || '';
  out.unauth_401 = anon.status === 401;
  out.www_authenticate_points_to_metadata = /resource_metadata="[^"]+protected-resource"/.test(www);

  const prm = await (await fetch(`${SITE}/wp-json/wit/v1/oauth/protected-resource`)).json();
  out.prm_resource_matches = prm.resource === MCP_URL;

  const root = await fetch(`${SITE}/.well-known/oauth-authorization-server/wp-json/wit/v1/oauth`);
  out.root_as_metadata = root.status === 200 && (await root.json()).code_challenge_methods_supported?.includes('S256');

  const oidc = await (await fetch(`${SITE}/wp-json/wit/v1/oauth/.well-known/openid-configuration`)).json();
  out.oidc_has_required_fields = Boolean(oidc.jwks_uri && oidc.subject_types_supported && oidc.id_token_signing_alg_values_supported);

  // --- 2. The flow Claude runs ---------------------------------------------
  const provider = makeProvider();
  let transport = new StreamableHTTPClientTransport(new URL(MCP_URL), { authProvider: provider });
  let client = new Client({ name: 'claude-test', version: '1.0.0' });

  try {
    await client.connect(transport);
    out.first_connect_requires_auth = false;
  } catch (e) {
    out.first_connect_requires_auth = e instanceof UnauthorizedError;
  }

  out.dcr_registered = Boolean(provider.store.client?.client_id);
  out.authorize_url_has_pkce = Boolean(provider.store.authUrl?.searchParams.get('code_challenge'))
    && provider.store.authUrl?.searchParams.get('code_challenge_method') === 'S256';

  const approved = await consent(provider.store.authUrl.toString(), cookies);
  out.consent_page_shows_host = /127\.0\.0\.1/.test(approved.html) && /Permitir/.test(approved.html);
  const back = new URL(approved.location);
  out.consent_redirects_with_code = approved.status === 302 && back.searchParams.has('code');
  out.consent_returns_iss = back.searchParams.get('iss') === `${SITE}/wp-json/wit/v1/oauth`;

  await transport.finishAuth(back.searchParams.get('code'));
  out.tokens_issued = Boolean(provider.store.tokens?.access_token && provider.store.tokens?.refresh_token);

  transport = new StreamableHTTPClientTransport(new URL(MCP_URL), { authProvider: provider });
  client = new Client({ name: 'claude-test', version: '1.0.0' });
  await client.connect(transport);
  out.connected = true;

  const instructions = client.getInstructions() || '';
  out.instructions_mention_subscription = /subscription/.test(instructions) && /never used/.test(instructions);

  const { tools } = await client.listTools();
  out.tools = tools.map((t) => t.name).sort();
  out.tools_have_annotations = tools.every((t) => typeof t.annotations?.readOnlyHint === 'boolean');
  out.tool_names_valid = tools.every((t) => /^[A-Za-z0-9_.-]{1,128}$/.test(t.name));

  // --- 3. Translating through the chat, over HTTP ---------------------------
  const overview = text(await client.callTool({ name: 'translation_overview', arguments: {} }));
  out.overview_languages = overview.languages.map((l) => l.code).sort();

  const prepared = text(await client.callTool({
    name: 'prepare_translation',
    arguments: { language: 'fr', post_ids: [Number(POST_ID)] },
  }));
  out.prepared_strings = prepared.strings.length;

  const translations = Object.fromEntries(prepared.strings.map((s) => [s.id, `[MCP-FR] ${s.text}`]));
  const saved = text(await client.callTool({
    name: 'save_translation',
    arguments: { language: 'fr', post_ids: [Number(POST_ID)], translations },
  }));
  out.saved_status = saved.results[0].status;

  const bad = await client.callTool({ name: 'prepare_translation', arguments: { language: 'fr', post_ids: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] } });
  out.limit_is_tool_error = bad.isError === true;

  const unknown = await client.callTool({ name: 'post_translation_status', arguments: { post_id: 999999 } });
  out.unknown_post_is_tool_error = unknown.isError === true;

  // --- 4. Attacks ------------------------------------------------------------
  const access = provider.store.tokens.access_token;

  // The token is for the MCP endpoint only, never the rest of the REST API.
  const me = await fetch(`${SITE}/wp-json/wp/v2/users/me`, { headers: { Authorization: `Bearer ${access}` } });
  out.token_rejected_elsewhere = me.status === 401;

  const foreign = await fetch(MCP_URL, {
    method: 'POST',
    headers: { Authorization: `Bearer ${access}`, 'Content-Type': 'application/json', Origin: 'https://evil.example' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'ping' }),
  });
  out.foreign_origin_403 = foreign.status === 403;

  const evil = await fetch(`${SITE}/wp-json/wit/v1/oauth/register`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ client_name: 'x', redirect_uris: ['https://evil.example/cb'], token_endpoint_auth_method: 'none' }),
  });
  out.evil_redirect_rejected = evil.status === 400 && (await evil.json()).error === 'invalid_redirect_uri';

  // A wrong PKCE verifier burns the code: the right one no longer works either.
  const verifier = randomBytes(32).toString('base64url');
  const challenge = createHash('sha256').update(verifier).digest('base64url');
  const auth2 = new URL(provider.store.authUrl);
  auth2.searchParams.set('code_challenge', challenge);
  auth2.searchParams.set('state', 'manual');
  const approved2 = await consent(auth2.toString(), cookies);
  const code2 = new URL(approved2.location).searchParams.get('code');
  const exchange = (v) => fetch(`${SITE}/wp-json/wit/v1/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'authorization_code', code: code2, redirect_uri: REDIRECT, client_id: provider.store.client.client_id, code_verifier: v }),
  });
  const wrong = await exchange(randomBytes(32).toString('base64url'));
  out.wrong_verifier_invalid_grant = wrong.status === 400 && (await wrong.json()).error === 'invalid_grant';
  const late = await exchange(verifier);
  out.code_burned_after_failure = late.status === 400;

  // Denying consent returns access_denied to the client.
  const denied = await consent(provider.store.authUrl.toString(), cookies, 'deny');
  out.deny_returns_access_denied = new URL(denied.location).searchParams.get('error') === 'access_denied';

  // Refresh rotation, then reuse of the old refresh token kills the connection.
  const refresh = (token) => fetch(`${SITE}/wp-json/wit/v1/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'refresh_token', refresh_token: token, client_id: provider.store.client.client_id }),
  });
  const oldRefresh = provider.store.tokens.refresh_token;
  const r1 = await refresh(oldRefresh);
  const rotated = await r1.json();
  out.refresh_rotates = r1.status === 200 && rotated.refresh_token && rotated.refresh_token !== oldRefresh;

  const ping = (token) => fetch(MCP_URL, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 9, method: 'ping' }),
  });
  out.old_access_superseded = (await ping(access)).status === 401;
  out.new_access_works = (await ping(rotated.access_token)).status === 200;

  const reuse = await refresh(oldRefresh);
  out.refresh_reuse_invalid_grant = reuse.status === 400 && (await reuse.json()).error === 'invalid_grant';
  out.reuse_revokes_connection = (await ping(rotated.access_token)).status === 401;

    const r2 = await refresh(rotated.refresh_token);
  out.refresh_after_revocation_fails = r2.status === 400;
} catch (e) {
  out.exception = String(e && e.stack || e);
}

console.log(JSON.stringify(out));
