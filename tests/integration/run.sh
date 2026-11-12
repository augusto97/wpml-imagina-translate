#!/usr/bin/env bash
#
# Integration suite. Requires tests/integration/setup.sh to have run.
#
# Exercises the plugin the way a site does: real WordPress, real database,
# real HTTP for the admin screens, and a provider that answers badly on
# purpose. Exits non-zero if anything fails, so the summary can be trusted.
#
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_DIR="${WIT_TEST_DIR:-${TMPDIR:-/tmp}/wit-integration}"
WP_PORT="${WIT_WP_PORT:-8080}"
WWW="$TEST_DIR/www"
WP="$TEST_DIR/wp"
LOG="$WWW/wp-content/debug.log"
AILOG="$WWW/wp-content/wit-ai-requests.log"
HOST="http://127.0.0.1:$WP_PORT"
FIXTURES="$PLUGIN_DIR/tests/integration/fixtures"

[ -x "$WP" ] || { echo "Falta la instalación. Ejecuta tests/integration/setup.sh primero." >&2; exit 1; }

FAIL=0

# The dev server is started below and must be stopped on the way out, or bash
# waits on it forever and the script never exits.
SERVER_PID=""
cleanup() { [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null; return 0; }
trap cleanup EXIT INT TERM

# In GitHub Actions every failure also becomes an error annotation: they show
# up on the commit and the PR, and — unlike the raw log — they are readable
# through the REST API.
annotate() {
  [ "${GITHUB_ACTIONS:-}" = "true" ] || return 0
  local msg="$2"
  msg="${msg//'%'/'%25'}"; msg="${msg//$'\r'/'%0D'}"; msg="${msg//$'\n'/'%0A'}"
  printf '::error title=%s::%s\n' "$1" "$msg"
}
SECTION=""
# Every check, from bash or from the Python checkers, lands in one file, so the
# totals in the CI summary count all of them.
CHECKS="$TEST_DIR/checks.log"; : > "$CHECKS"
ok()  { printf '  \033[32m✓\033[0m %s\n' "$1" | tee -a "$CHECKS"; }
bad() { printf '  \033[31m✗\033[0m %s\n' "$1" | tee -a "$CHECKS"; FAIL=1; annotate "${SECTION:-Integración}" "$1"; }
section() { SECTION="$1"; printf '\n\033[1m%s\033[0m\n' "$1"; }

# WordPress itself cannot always reach wordpress.org from a sandbox; those
# warnings are the environment's, not the plugin's.
# Elementor's own logger adds notices about its remote services and its bundled
# Twig; they name Elementor's files, never this plugin's.
# WPML String Translation logs its own housekeeping (status recomputes) with
# error_log(); those lines carry no file path.
NOISE='wp_version_check|wp_update_themes|wp_update_plugins|plugins_api|wordpress\.org|plugins/elementor/|plugins/elementor-pro/|\[Elementor Atomic Widgets\]|WPML String Translation: st_'
check_log() {
  if grep -Ev "$NOISE" "$LOG" 2>/dev/null | grep -q .; then
    bad "debug.log con entradas ($1): $(grep -Ev "$NOISE" "$LOG" | head -3)"
    grep -Ev "$NOISE" "$LOG" | head -5 | sed 's/^/      /'
  else
    ok "debug.log limpio ($1)"
  fi
  : > "$LOG"
}

CURL=(curl -s --max-time 60)

# The MCP sections need the Abilities API (WordPress 6.9+). On older versions
# they are replaced by a check that the plugin degrades cleanly, rather than
# silently skipped.
HAS_ABILITIES=$("$WP" eval 'echo function_exists("wp_register_ability") ? "1" : "0";')
# Real WPML and String Translation add their own MCP tools.
REAL_WPML_ST=$("$WP" eval 'echo (class_exists("SitePress") && defined("WPML_ST_VERSION")) ? "1" : "0";' 2>/dev/null | tail -1)

# Ids shift whenever the fixture changes; read them instead of assuming.
POST_ID=$("$WP" eval 'echo get_option("wit_test_fixture")["post"];')
PAGE_ID=$("$WP" eval 'echo get_option("wit_test_fixture")["page"];')
[ -n "$POST_ID" ] || { echo "No se pudo leer wit_test_fixture. ¿Ejecutaste setup.sh?" >&2; exit 1; }

"$WP" eval-file "$FIXTURES/reset.php" >/dev/null
: > "$LOG"; : > "$AILOG"

# =============================================================================
section "A. El proveedor responde mal a propósito"
# =============================================================================
# Each mode is a failure a real model produces and the plugin must absorb.
for MODE in preamble drop_item rate_limit; do
  "$WP" option update wit_fake_mode "$MODE" >/dev/null
  "$WP" transient delete wit_fake_429_sent >/dev/null 2>&1
  # A memory hit would answer from cache and never reach the provider, so the
  # mode under test would not actually be exercised.
  "$WP" eval 'WIT_Translation_Memory::instance()->clear();' >/dev/null
  : > "$LOG"; : > "$AILOG"

  "$WP" eval "wp_set_current_user(1); \$f = get_option('wit_test_fixture'); wp_update_post(array('ID'=>\$f['page'],'post_title'=>'Título $MODE','post_content'=>'<!-- wp:paragraph --><p>Primera frase $MODE</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Segunda frase $MODE</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Tercera frase $MODE</p><!-- /wp:paragraph -->'));" >/dev/null

  OUT=$("$WP" eval '
    wp_set_current_user(1);
    $f = get_option("wit_test_fixture");
    $r = (new WIT_Translation_Manager())->translate_post($f["page"], "fr");
    $p = get_post($r["translated_post_id"]);
    echo ($r["success"] ? "ok" : "FAIL:" . $r["message"])
       . "|" . substr_count($p->post_content, "[FR]")
       . "|" . $p->post_title;')
  CALLS=$(wc -l < "$AILOG")
  STRINGS=${OUT#*|}; STRINGS=${STRINGS%%|*}
  TITLE=${OUT##*|}

  if [ "${OUT%%|*}" = "ok" ] && [ "$STRINGS" = "3" ] && [ "$TITLE" = "[FR] Título $MODE" ]; then
    ok "$MODE: 3/3 cadenas, título limpio ($CALLS llamadas)"
  else
    bad "$MODE: $OUT ($CALLS llamadas)"
  fi
  check_log "$MODE"
done
"$WP" option update wit_fake_mode normal >/dev/null

# =============================================================================
section "B. Fidelidad del contenido y enlazado con WPML"
# =============================================================================
"$WP" eval-file "$FIXTURES/reset.php" >/dev/null
: > "$LOG"; : > "$AILOG"

B=$("$WP" eval '
wp_set_current_user(1);
$f  = get_option("wit_test_fixture");
$m  = new WIT_Translation_Manager();
$r1 = $m->translate_post($f["post"], "en"); $p1 = (int) $r1["translated_post_id"];
$r2 = $m->translate_post($f["page"], "en"); $p2 = (int) $r2["translated_post_id"];
$r3 = $m->translate_post($f["post"], "en");

global $wpdb;
$mem = $wpdb->get_row("SELECT COUNT(*) e, COALESCE(SUM(hits),0) h FROM {$wpdb->prefix}wit_translation_memory");
$c1  = get_post($p1)->post_content;
$c2  = get_post($p2)->post_content;

$cats = array();
foreach (wp_get_object_terms($p1, "category") as $t) {
    $cats[] = $t->name . "/" . $t->slug . "/" . ($t->parent ? "hijo" : "raiz");
}
sort($cats);

// A term whose translation is linked to the WRONG source is the defect this
// suite exists for: check the round trip resolves back to the same term.
$src_cat   = (int) $f["cat_parent"];                                    // "Servicios"
$en_cat_id = apply_filters("wpml_object_id", $src_cat, "category", false, "en");
$back      = $en_cat_id ? apply_filters("wpml_object_id", $en_cat_id, "category", false, "es") : 0;
$src_term  = get_term($src_cat, "category");

echo json_encode(array(
    "crea"        => $r1["success"] && $p1 > 0,
    "actualiza"   => (int) $r3["translated_post_id"] === $p1,
    "memoria"     => (int) $mem->e . "/" . (int) $mem->h,
    "reutiliza"   => (int) $mem->h > 0,
    "glosario_fijo"   => strpos($c2, "Get in touch") !== false,
    "glosario_marca"  => strpos($c2, "Imagina") !== false,
    "shortcode"   => strpos($c1, "[gallery ids=\"1,2,3\"]") !== false,
    "nbsp"        => strpos($c1, "\xc2\xa0") !== false,
    "entidades"   => strpos($c1, "&amp;") !== false && strpos($c1, "&lt;") !== false,
    "atributos"   => strpos($c1, "class=\"btn center\"") !== false
                     && strpos($c1, "href=\"https://imagina.example/servicios?ref=center\"") !== false,
    "alt_block"   => substr_count($c1, "[EN] Foto del equipo") === 2, // block JSON + markup
    "meta_copiada"=> get_post_meta($p1, "_custom_technical", true) === "do-not-touch-me",
    "meta_interna"=> get_post_meta($p1, "_edit_lock", true) === "",
    // The image in the language of the translation: with the real WPML,
    // media has translations of its own; with the double it is the same.
    "destacada"   => (int) get_post_thumbnail_id($p1) === (int) apply_filters("wpml_object_id", get_post_thumbnail_id($f["post"]), "attachment", true, "en"),
    "borrador"    => get_post($p1)->post_status === "draft",
    "bloques"     => count(array_filter(parse_blocks($c1), function ($b) { return $b["blockName"] !== null; })),
    "cats"        => $cats,
    "tax_ida_vuelta" => $en_cat_id && (int) $back === $src_cat,
    // Guards against a fixture that stopped reproducing the scenario, which
    // would make the check above pass for the wrong reason.
    "hay_divergencia" => (int) $src_term->term_id !== (int) $src_term->term_taxonomy_id,
    "tax_nombre"  => $en_cat_id ? get_term($en_cat_id, "category")->name : "",
));')

j() { printf '%s' "$B" | python3 -c "import json,sys;print(json.load(sys.stdin)['$1'])" 2>/dev/null; }
t() { [ "$(j "$1")" = "True" ] && ok "$2" || bad "$2 (=$(j "$1"))"; }

t crea            "crea la traducción"
t actualiza       "re-traducir actualiza en vez de duplicar"
t reutiliza       "memoria reutilizada ($(j memoria) entradas/reusos)"
t glosario_fijo   "glosario: traducción fija aplicada"
t glosario_marca  "glosario: marca protegida"
t shortcode       "shortcode intacto"
t nbsp            "nbsp preservado"
t entidades       "entidades HTML preservadas"
t atributos       "clases y URLs sin tocar"
t alt_block       "alt traducido en markup Y en el JSON del bloque"
t meta_copiada    "campos personalizados copiados"
t meta_interna    "meta interna de WordPress NO copiada"
t destacada       "imagen destacada resuelta"
t borrador        "se crea como borrador"
t hay_divergencia "la semilla reproduce term_id != term_taxonomy_id"
t tax_ida_vuelta  "taxonomía enlazada al término correcto (ida y vuelta)"
[ "$(j bloques)" = "10" ] && ok "10 bloques Gutenberg válidos" || bad "bloques válidos: $(j bloques), esperaba 10"
echo "      categorías: $(j cats)"
check_log "contenido"

# =============================================================================
section "C. La cola, tal como la ejecuta WP-Cron"
# =============================================================================
C=$("$WP" eval '
wp_set_current_user(1);
$f  = get_option("wit_test_fixture");
$q  = WIT_Queue::instance();
$en = $q->enqueue(array($f["post"], $f["page"], 999999), "fr");  // 999999 no existe: debe omitirse
wp_set_current_user(0);                          // cron no tiene usuario
$q->process();
$st = $q->status();
$fr = apply_filters("wpml_object_id", $f["post"], "post", false, "fr");
echo json_encode(array(
    "encolados" => $en["queued"], "omitidos" => $en["skipped"],
    "hechos" => $st["done"], "errores" => $st["error"], "pendientes" => $st["pending"],
    "enlazado" => (bool) $fr,
));')
ce() { printf '%s' "$C" | python3 -c "import json,sys;print(json.load(sys.stdin)['$1'])" 2>/dev/null; }
[ "$(ce encolados)" = "2" ] && [ "$(ce omitidos)" = "1" ] && ok "encola 2, omite el inexistente" || bad "encolados=$(ce encolados) omitidos=$(ce omitidos)"
[ "$(ce hechos)" = "2" ] && [ "$(ce errores)" = "0" ] && ok "drena sin usuario actual: 2 hechos, 0 errores" || bad "hechos=$(ce hechos) errores=$(ce errores)"
[ "$(ce enlazado)" = "True" ] && ok "la traducción francesa queda enlazada" || bad "no enlazada"
check_log "cola"

# =============================================================================
section "D. Pantallas de administración por HTTP real"
# =============================================================================
# Kill a server left behind by an interrupted run. Anchored to the start of
# the command line: an unanchored pattern also matches any shell whose own
# command line merely mentions it — including the one that launched this.
for pid in $(pgrep -f "^[^ ]*php[0-9.]* -S 127.0.0.1:$WP_PORT" 2>/dev/null); do
  kill "$pid" 2>/dev/null
done
sleep 0.5
# Several workers: a request that triggers a sub-request would deadlock against
# a single-threaded server. The router makes it route like a real host — see
# router.php for why the suite needs it before PHP 8.4.
PHP_CLI_SERVER_WORKERS=6 "${WIT_PHP:-php}" -S "127.0.0.1:$WP_PORT" -t "$WWW" \
  "$PLUGIN_DIR/tests/integration/router.php" >"$TEST_DIR/php-server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 20); do "${CURL[@]}" -o /dev/null "$HOST/" && break; sleep 0.5; done

JAR="$TEST_DIR/cookies.txt"; rm -f "$JAR"
"${CURL[@]}" -c "$JAR" -b "$JAR" -o /dev/null "$HOST/wp-login.php"
"${CURL[@]}" -c "$JAR" -b "$JAR" -o /dev/null -X POST "$HOST/wp-login.php" \
  --data-urlencode "log=admin" --data-urlencode "pwd=admin" \
  --data-urlencode "wp-submit=Log In" --data-urlencode "testcookie=1" \
  --data-urlencode "redirect_to=$HOST/wp-admin/"
grep -q "wordpress_logged_in" "$JAR" && ok "sesión de administrador" || bad "no se pudo iniciar sesión"

# The screens are React apps built into assets/app (admin-ui/). Without the
# build every screen would print a notice instead, so say that first.
[ -f "$PLUGIN_DIR/assets/app/app.asset.php" ] \
  && ok "la interfaz está compilada (assets/app)" \
  || bad "falta assets/app: ejecuta «npm ci && npm run build» en admin-ui/"

: > "$LOG"
page() { # ruta, etiqueta, subcadena esperada
  local raw code html file
  # One request, not two: fetching twice doubles the time and lets the check
  # and the saved copy disagree if the two responses ever differ.
  raw=$("${CURL[@]}" -b "$JAR" -w '\n%{http_code}' "$HOST/wp-admin/$1")
  code=${raw##*$'\n'}
  html=${raw%$'\n'*}

  file="$TEST_DIR/page-$(printf '%s' "$2" | tr ' /' '__').html"
  printf '%s' "$html" > "$file"

  if [ "$code" != "200" ]; then
    bad "$2 — HTTP $code (esperaba 200)"
  elif ! grep -q "$3" "$file"; then
    bad "$2 — HTTP 200 pero falta «$3» ($(wc -c < "$file") bytes en $file)"
  else
    ok "$2"
  fi

  grep -Eo "<b>(Warning|Notice|Fatal error)</b>: [^<]{0,110}" "$file" | head -3 | sed 's/^/      PHP: /'
}
# Each screen prints only its mount point and loads its own bundle, as an ES
# module, with the configuration the app reads first.
screen() { # slug de la página, pantalla, etiqueta
  page "admin.php?page=$1" "$3" "id=\"wit-$2\""
  local file="$TEST_DIR/page-$(printf '%s' "$3" | tr ' /' '__').html"
  # Attribute order changes between WordPress versions (id before src in 7.x).
  grep -Eq "<script type=\"module\"[^>]* src=\"[^\"]*/assets/app/$2\.js" "$file" \
    && ok "$3: carga su bundle como módulo" || bad "$3: no carga assets/app/$2.js como type=module"
  grep -q 'var witConfig = {' "$file" && ok "$3: configuración localizada" || bad "$3: falta witConfig"
}
screen "wpml-ia-translate"               dashboard "Inicio"
screen "wpml-ia-translate-content"       content   "Contenidos"
screen "wpml-ia-translate-logs"          history   "Historial"
screen "wpml-imagina-translate-settings" settings  "Ajustes"
page "plugins.php"                                    "Enlace «Ajustes» en la lista de plugins" "wpml-imagina-translate-settings"

LOC=$("${CURL[@]}" -o /dev/null -w '%{redirect_url}' -b "$JAR" \
      "$HOST/wp-admin/options-general.php?page=wpml-imagina-translate-settings")
case "$LOC" in
  *admin.php?page=wpml-imagina-translate-settings) ok "la URL de ajustes anterior a 1.2.2 redirige" ;;
  *) bad "la URL antigua no redirige (Location: «$LOC»)" ;;
esac
check_log "pantallas"

# =============================================================================
section "E. API REST del panel"
# =============================================================================
# The nonce the screens send with every request (wp_rest), from the config
# WordPress localized into the page.
nonce_of() { grep -o 'var witConfig = {[^;]*}' "$1" | grep -o '"nonce":"[a-f0-9]*"' | head -1 | cut -d'"' -f4; }
RNONCE=$(nonce_of "$TEST_DIR/page-Inicio.html")
[ -n "$RNONCE" ] && ok "nonce de la API en witConfig" || bad "no se encontró el nonce en witConfig"
RJAR="$JAR"

# rest MÉTODO RUTA [JSON] → prints the HTTP code; the body lands in rest.json.
# ?rest_route= instead of /wp-json/: works whatever the permalinks are.
REST_OUT="$TEST_DIR/rest.json"
rest() {
  local method="$1" path="$2" body="${3:-}" url
  url="$HOST/?rest_route=/wit/admin/v1/${path%%\?*}"
  [[ "$path" == *\?* ]] && url="$url&${path#*\?}"
  local args=(-g -X "$method" -o "$REST_OUT" -w '%{http_code}' -H 'Content-Type: application/json')
  [ -n "$RJAR" ] && args+=(-b "$RJAR")
  [ -n "$RNONCE" ] && args+=(-H "X-WP-Nonce: $RNONCE")
  [ -n "$body" ] && args+=(--data "$body")
  "${CURL[@]}" "${args[@]}" "$url"
}
# A Python expression over the last response, as d.
jget() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(eval(sys.argv[2], {}, {"d": d}))' "$REST_OUT" "$1" 2>/dev/null; }
# A JSON object from key/value pairs, escaped properly.
jbody() { python3 -c 'import json,sys; a=sys.argv[1:]; print(json.dumps({a[i]: (int(a[i+1]) if a[i+1].isdigit() else a[i+1]) for i in range(0, len(a), 2)}))' "$@"; }
# rcheck ETIQUETA CÓDIGO MÉTODO RUTA [JSON] [CONDICIÓN]
rcheck() {
  local label="$1" want="$2" code; shift 2
  code=$(rest "$1" "$2" "${3:-}")
  if [ "$code" != "$want" ]; then
    bad "$label — HTTP $code (esperaba $want): $(head -c 220 "$REST_OUT")"
  elif [ -n "${4:-}" ] && [ "$(jget "$4")" != "True" ]; then
    bad "$label — respuesta inesperada: $(head -c 220 "$REST_OUT")"
  else
    ok "$label"
  fi
}

: > "$LOG"
rcheck "resumen del inicio"           200 GET  "overview" "" "'setup' in d and len(d['languages']) == 2 and 'queue' in d"
rcheck "listado de contenidos"        200 GET  "posts?language=en&status=all" "" "d['total'] >= 2 and 'translation' in d['items'][0] and 'queued' in d['items'][0]"
rcheck "listado filtrado por tipo"    200 GET  "posts?language=en&status=all&post_types[]=page" "" "d['total'] >= 1 and all(i['type'] == 'page' for i in d['items'])"
rcheck "traducir un contenido"        200 POST "translate" "$(jbody post_id "$POST_ID" language fr)" "d['status'] == 'complete' and d['translation_id'] > 0"
FR_ID=$(jget "d['translation_id']")
rcheck "estado del trabajo"           200 GET  "translate-status?post_id=$POST_ID&language=fr" "" "d['status'] == 'complete'"
rcheck "revisión, original y traducción" 200 GET "review?post_id=$POST_ID&language=fr" "" "d['original']['title'] != '' and d['translation']['title'].startswith('[FR]')"
FR_TITLE=$(jget "d['translation']['title']")
rcheck "corregir una frase"           200 POST "correct" "$(jbody post_id "$POST_ID" language fr current_text "$FR_TITLE" new_text "$FR_TITLE (revisado)")"
[ "$("$WP" post get "$FR_ID" --field=post_title 2>/dev/null)" = "$FR_TITLE (revisado)" ] \
  && ok "la corrección queda en la traducción" || bad "la corrección no llegó al título de la traducción"
rcheck "publicar la traducción"       200 POST "publish" "$(jbody post_id "$POST_ID" language fr)"
[ "$("$WP" post get "$FR_ID" --field=post_status 2>/dev/null)" = "publish" ] && ok "queda publicada" || bad "no quedó publicada"
rcheck "despublicarla"                200 POST "remove" "$(jbody post_id "$POST_ID" language fr mode unpublish)"
[ "$("$WP" post get "$FR_ID" --field=post_status 2>/dev/null)" = "draft" ] && ok "vuelve a borrador" || bad "no volvió a borrador"
# WP-Cron held while the queued state is observed (wit-test-hooks.php).
"$WP" option update wit_test_hold_cron 1 >/dev/null
rcheck "encolar un contenido"         200 POST "queue" "{\"post_ids\":[$PAGE_ID],\"language\":\"en\"}" "d['queued'] == 1"
rcheck "el listado lo marca en cola"  200 GET  "posts?language=en&status=all&post_types[]=page" "" "any(i['id'] == $PAGE_ID and i['queued'] == 'pending' for i in d['items'])"
rcheck "progreso de la cola"          200 GET  "queue" "" "d['pending'] >= 1"
rcheck "detener la cola"              200 DELETE "queue" "" "d['removed'] >= 1"
"$WP" option delete wit_test_hold_cron >/dev/null
rcheck "historial"                    200 GET  "logs" "" "d['total'] >= 1 and d['items'][0]['kind'] == 'post' and d['items'][0]['date'] != ''"
rcheck "ajustes"                      200 GET  "settings" "" "d['keys']['openai']['stored'] and d['keys']['openai']['hint'].startswith('…')"
OPENAI_KEY=$("$WP" eval 'echo get_option("wit_settings")["openai_api_key"];' 2>/dev/null | tail -1)
grep -qF "$OPENAI_KEY" "$REST_OUT" && bad "los ajustes devuelven la API key entera" || ok "sin la API key en la respuesta (solo sus 4 últimos caracteres)"
rcheck "modelos del proveedor"        200 POST "models" '{"provider":"openai"}' "len(d['models']) > 0"
rcheck "probar la conexión"           200 POST "test-connection"
rcheck "vaciar la memoria"            200 DELETE "memory" "" "'removed' in d"
if [ "$REAL_WPML_ST" != "1" ]; then
  rcheck "sin String Translation, las cadenas responden 404" 404 GET "strings?language=en"
fi

echo "  — deben rechazarse —"
rcheck "idioma inexistente"           400 POST "translate" "$(jbody post_id "$POST_ID" language xx)"
SAVED_NONCE="$RNONCE"
RNONCE="";        rcheck "sin nonce (solo la cookie)" 401 GET "overview"
RNONCE="abc123";  rcheck "nonce inválido"             403 GET "overview"
RJAR=""; RNONCE=""
rcheck "anónimo"                      401 GET  "overview"
rcheck "anónimo no traduce"           401 POST "translate" "$(jbody post_id "$POST_ID" language fr)"
RNONCE="$SAVED_NONCE"
rcheck "un nonce robado sin su cookie" 403 POST "translate" "$(jbody post_id "$POST_ID" language fr)"
RJAR="$JAR"
check_log "api del panel"

# =============================================================================
section "F. Traducir por MCP: las herramientas, con un chat simulado"
# =============================================================================
if [ "$HAS_ABILITIES" != "1" ]; then
  echo "  (WordPress $("$WP" core version) no tiene la Abilities API: se comprueba que MCP se desactiva limpio)"
  "$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = true; update_option("wit_settings", $s, false);' >/dev/null
  code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST "$HOST/wp-json/wit/v1/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}')
  [ "$code" = "404" ] && ok "sin Abilities API, el endpoint MCP no existe (404) aunque esté activado" || bad "endpoint MCP respondió $code en WordPress sin Abilities API"
  # The Ajustes screen lists these requirements from the API, before MCP is
  # even switched on.
  rcheck "Ajustes explica que hace falta WordPress 6.9" 200 GET "settings" "" "any('WordPress 6.9' in p for p in d['mcp']['problems'])"
  "$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = false; update_option("wit_settings", $s, false);' >/dev/null
  check_log "sin abilities"
else
# The chat is simulated ([MCP-EN] prefix). What is under test is everything
# around it — and above all that the API is never called.
"$WP" eval-file "$FIXTURES/reset.php" >/dev/null
: > "$LOG"; : > "$AILOG"

F_OUT=$("$WP" eval-file "$FIXTURES/mcp-plan.php" 2>/dev/null)
printf '%s' "$F_OUT" > "$TEST_DIR/mcp-plan.json"

python3 - "$TEST_DIR/mcp-plan.json" <<'CHECK' | tee -a "$CHECKS" || FAIL=1
import json, os, sys
raw = open(sys.argv[1]).read()
try:
    d = json.loads(raw[raw.index('{'):])
except Exception:
    print("  \033[31m✗\033[0m mcp-plan.php no devolvió JSON:\n" + raw[:2000])
    if os.environ.get("GITHUB_ACTIONS") == "true":
        print("::error title=F. mcp-plan sin JSON::" + raw[-3000:].replace("%", "%25").replace("\n", "%0A"))
    sys.exit(1)

expect = [
    ("api_requests", 0, "ni una llamada a la API al traducir por MCP"),
    ("api_requests_final", 0, "ni una llamada a la API en todo el escenario"),
    ("abilities_registered", True, "las 14 abilities están registradas"),
    ("rejects_11_posts", True, "más de 10 contenidos por tanda se rechaza"),
    ("has_title", True, "prepare incluye el título"),
    ("has_meta", True, "prepare incluye los campos SEO"),
    ("has_terms", True, "prepare incluye los términos que faltan"),
    ("glossary_excluded", True, "lo que resuelve el glosario no se manda al chat"),
    ("shortcode_excluded", True, "los shortcodes no se mandan al chat"),
    ("no_html_sent", True, "al chat solo llega texto plano, nunca HTML"),
    ("dedup_across_posts", True, "una cadena compartida entre posts se manda una vez"),
    ("instructions", True, "las instrucciones nombran el idioma en inglés"),
    ("glossary_guidance", True, "prepare adjunta las reglas del glosario"),
    ("partial_is_incomplete", True, "guardar a medias queda como incompleto"),
    ("partial_wrote_nothing", True, "guardar a medias no escribe nada"),
    ("partial_lists_missing", True, "guardar a medias dice qué falta"),
    ("saved_post", "created", "completar lo pendiente crea la traducción del post"),
    ("saved_page", "created", "y la de la página, en la misma tanda"),
    ("no_divergence", True, "plan y pipeline piden exactamente las mismas cadenas"),
    ("title", "[MCP-EN] Servicios de Imagina", "título traducido por el chat"),
    ("is_draft", True, "se crea como borrador"),
    ("glossary_fix", True, "la regla fija del glosario se aplica"),
    ("shortcode", True, "shortcode intacto"),
    ("entities", True, "entidades HTML intactas"),
    ("attributes", True, "clases y URLs intactas"),
    ("alt_both", True, "alt traducido en markup y en el JSON del bloque"),
    ("blocks_valid", True, "10 bloques Gutenberg válidos"),
    ("meta", "[MCP-EN] Servicios | Imagina", "campo SEO traducido por el chat"),
    ("custom_meta", True, "campos personalizados copiados"),
    ("tax_roundtrip", True, "taxonomía enlazada al término correcto"),
    ("history_via_mcp", True, "el historial lo marca como vía Claude (MCP)"),
    ("status_current", "current", "estado: al día"),
    ("same_second_edit_detected", True, "una edición en el mismo segundo también se detecta"),
    ("status_outdated", "outdated", "editar el original la deja desactualizada"),
    ("list_outdated", True, "list_posts la muestra como desactualizada"),
    ("retranslate_only_new", True, "re-traducir pide solo la cadena que cambió"),
    ("retranslate_updates_same", True, "y actualiza la misma traducción"),
    ("status_current_again", "current", "vuelve a estar al día"),
    ("correct_ok", True, "corregir una frase"),
    ("correct_applied", True, "la corrección se aplica"),
    ("correct_rest_kept", True, "el resto de la traducción no se toca"),
    ("correct_memory", True, "la corrección actualiza la memoria"),
    ("correct_rejects_unknown", True, "corregir un texto inexistente da error"),
    ("correct_no_script", True, "una corrección no puede colar <script>"),
    ("published", True, "publicar"),
    ("publish_kept_blocks", True, "publicar no destruye el marcado"),
    ("glossary_add", True, "añadir regla al glosario"),
    ("glossary_remove", True, "quitar regla del glosario"),
    ("glossary_key_kept", True, "editar el glosario no toca las API keys"),
    ("glossary_rejects_bad", True, "una regla mal formada se rechaza"),
    ("subscriber_denied", True, "un suscriptor no puede usar las herramientas"),
    ("editor_can_read", True, "un editor puede consultar"),
    ("editor_no_glossary_edit", True, "un editor no puede editar el glosario"),
]
bad = 0
for key, want, label in expect:
    got = d.get(key, "<ausente>")
    if got == want:
        print(f"  \033[32m✓\033[0m {label}")
    else:
        print(f"  \033[31m✗\033[0m {label} (={got!r}, esperaba {want!r})"); bad += 1
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(f"::error title=F. MCP herramientas::{label} (={got!r}, esperaba {want!r})")
cats = d.get("categories", [])
if len(cats) == 3 and all(c.startswith("[MCP-EN] ") for c in cats):
    print("  \033[32m✓\033[0m categorías creadas con los nombres del chat")
else:
    print(f"  \033[31m✗\033[0m categorías: {cats}"); bad += 1
sys.exit(1 if bad else 0)
CHECK
check_log "mcp herramientas"

# =============================================================================
section "G. Conectar por MCP con el cliente oficial, OAuth incluido"
# =============================================================================
# The official MCP SDK client runs the flow Claude runs, then attacks it.
"$WP" eval-file "$FIXTURES/reset.php" >/dev/null
"$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = true; update_option("wit_settings", $s, false);' >/dev/null
: > "$LOG"; : > "$AILOG"

CLIENT_DIR="$PLUGIN_DIR/tests/integration/client"
if [ ! -d "$CLIENT_DIR/node_modules/@modelcontextprotocol/sdk" ]; then
  ( cd "$CLIENT_DIR" && npm ci --no-audit --no-fund --silent ) || bad "npm ci falló"
fi

( cd "$CLIENT_DIR" && timeout 180 node mcp-client.mjs "$HOST" admin admin "$POST_ID" ) > "$TEST_DIR/mcp-client.json" 2>&1

python3 - "$TEST_DIR/mcp-client.json" "$(wc -l < "$AILOG")" "$REAL_WPML_ST" <<'CHECK' | tee -a "$CHECKS" || FAIL=1
import json, os, sys
raw = open(sys.argv[1]).read()
try:
    d = json.loads(raw.strip().splitlines()[-1])
except Exception:
    print("  \033[31m✗\033[0m el cliente no devolvió JSON:\n" + raw[:2000])
    if os.environ.get("GITHUB_ACTIONS") == "true":
        print("::error title=G. cliente MCP sin JSON::" + raw[-3000:].replace("%", "%25").replace("\n", "%0A"))
    sys.exit(1)
if "exception" in d:
    print("  \033[31m✗\033[0m excepción en el cliente:\n      " + d["exception"][:1500].replace("\n", "\n      "))
    if os.environ.get("GITHUB_ACTIONS") == "true":
        print("::error title=G. excepción del cliente MCP::" + d["exception"][:3000].replace("%", "%25").replace("\n", "%0A"))
labels = [
    ("unauth_401", "sin token: 401"),
    ("www_authenticate_points_to_metadata", "el 401 apunta a los metadatos (WWW-Authenticate)"),
    ("prm_resource_matches", "los metadatos del recurso coinciden con la URL"),
    ("root_as_metadata", "metadatos del servidor OAuth en /.well-known"),
    ("oidc_has_required_fields", "el documento OpenID pasa la validación estricta"),
    ("first_connect_requires_auth", "el SDK detecta que hace falta autenticarse"),
    ("dcr_registered", "registro dinámico del cliente (DCR)"),
    ("authorize_url_has_pkce", "la autorización lleva PKCE S256"),
    ("consent_page_shows_host", "la pantalla de consentimiento muestra el destino"),
    ("consent_redirects_with_code", "aprobar devuelve el código"),
    ("consent_returns_iss", "y el parámetro iss (RFC 9207)"),
    ("tokens_issued", "canje de código por tokens"),
    ("connected", "conexión MCP establecida"),
    ("instructions_mention_subscription", "las instrucciones explican que se usa la suscripción"),
    ("tools_have_annotations", "las herramientas llevan anotaciones"),
    ("tool_names_valid", "nombres de herramienta válidos para MCP"),
    ("limit_is_tool_error", "más de 10 posts: error de herramienta, no de protocolo"),
    ("unknown_post_is_tool_error", "post inexistente: error de herramienta"),
    ("token_rejected_elsewhere", "el token NO sirve para el resto de la API REST"),
    ("foreign_origin_403", "Origin ajeno: 403"),
    ("evil_redirect_rejected", "registrar un redirect ajeno se rechaza"),
    ("wrong_verifier_invalid_grant", "PKCE incorrecto: invalid_grant"),
    ("code_burned_after_failure", "y el código queda quemado"),
    ("deny_returns_access_denied", "cancelar devuelve access_denied"),
    ("refresh_rotates", "el refresh token rota"),
    ("old_access_superseded", "el access token anterior deja de valer"),
    ("new_access_works", "el nuevo funciona"),
    ("refresh_reuse_invalid_grant", "reutilizar un refresh token: invalid_grant"),
    ("reuse_revokes_connection", "y la conexión entera se revoca"),
    ("refresh_after_revocation_fails", "tras revocar, no se puede refrescar"),
]
bad = 0
for key, label in labels:
    if d.get(key) is True:
        print(f"  \033[32m✓\033[0m {label}")
    else:
        print(f"  \033[31m✗\033[0m {label} (={d.get(key, '<ausente>')!r})"); bad += 1
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(f"::error title=G. MCP HTTP::{label} (={d.get(key, '<ausente>')!r})")
expected_tools = {"translation_overview", "list_posts", "post_translation_status", "prepare_translation", "save_translation",
                  "get_translation", "correct_translation", "publish_translation", "remove_translation", "site_settings",
                  "update_settings", "get_glossary", "update_glossary", "translation_history"}
if len(sys.argv) > 3 and sys.argv[3].strip() == "1":
    expected_tools |= {"strings_overview", "list_strings", "prepare_strings", "save_strings", "set_wpml_translation_mode"}
if set(d.get("tools", [])) == expected_tools:
    print(f"  \033[32m✓\033[0m las {len(expected_tools)} herramientas expuestas, con sus nombres")
else:
    print(f"  \033[31m✗\033[0m herramientas: {d.get('tools')}"); bad += 1
if d.get("saved_status") == "created" and d.get("prepared_strings", 0) > 0:
    print(f"  \033[32m✓\033[0m traducción completa por HTTP ({d.get('prepared_strings')} cadenas)")
else:
    print(f"  \033[31m✗\033[0m traducción por HTTP: {d.get('saved_status')} / {d.get('prepared_strings')}"); bad += 1
if sys.argv[2].strip() == "0":
    print("  \033[32m✓\033[0m ni una llamada a la API")
else:
    print(f"  \033[31m✗\033[0m {sys.argv[2].strip()} llamadas a la API"); bad += 1
sys.exit(1 if bad else 0)
CHECK
check_log "mcp http"
fi

# =============================================================================
section "I. Elementor real: por API y por MCP"
# =============================================================================
HAS_ELEMENTOR=$("$WP" eval 'echo (class_exists("\\Elementor\\Plugin") && !empty(get_option("wit_test_fixture")["elementor_page"])) ? "1" : "0";' 2>/dev/null | tail -1)
if [ "$HAS_ELEMENTOR" != "1" ] && [ "${WIT_ELEMENTOR_VERSION:-4.0.8}" = "none" ]; then
  echo "  (WIT_ELEMENTOR_VERSION=none: sección omitida a propósito)"
elif [ "$HAS_ELEMENTOR" != "1" ]; then
  # Skipping silently would turn this section into one that always passes.
  bad "Elementor debía estar instalado y no lo está (¿falló la descarga en setup.sh?)"
else
  "$WP" eval-file "$FIXTURES/reset.php" >/dev/null
  "$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = true; update_option("wit_settings", $s, false);' >/dev/null
  : > "$LOG"; : > "$AILOG"
  "$WP" eval-file "$FIXTURES/elementor-scenario.php" > "$TEST_DIR/elementor.json" 2>/dev/null

  python3 - "$TEST_DIR/elementor.json" <<'CHECK' | tee -a "$CHECKS" || FAIL=1
import json, os, sys
raw = open(sys.argv[1]).read()
try:
    # Elementor's logger may print after the JSON; read just the object.
    d, _ = json.JSONDecoder().raw_decode(raw[raw.index('{'):])
except Exception:
    print("  \033[31m✗\033[0m elementor-scenario.php no devolvió JSON:\n" + raw[-2000:])
    if os.environ.get("GITHUB_ACTIONS") == "true":
        print("::error title=I. Elementor sin JSON::" + raw[-3000:].replace("%", "%25").replace("\n", "%0A"))
    sys.exit(1)

bad = 0
def check(ok, label, detail=""):
    global bad
    if ok:
        print(f"  \033[32m✓\033[0m {label}")
    else:
        bad += 1
        print(f"  \033[31m✗\033[0m {label} {detail}")
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(f"::error title=I. Elementor::{label} {detail}")

widget_checks = [
    ("builder_mode", "sigue siendo una página de Elementor"),
    ("valid_json", "_elementor_data es JSON válido"),
    ("heading", "encabezado traducido"),
    ("editor", "editor de texto traducido"),
    ("editor_markup", "el HTML del editor de texto intacto"),
    ("button", "botón: la regla del glosario se aplica"),
    ("list", "lista de iconos (repetidor) traducida"),
    ("alt", "alt de la imagen traducido"),
    ("caption", "leyenda traducida"),
    ("atomic", "widget atómico (Elementor 4) traducido"),
    ("atomic_tag", "etiqueta del widget atómico intacta"),
    ("atomic_wrappers", "envoltorios $$type intactos"),
    ("header_size", "header_size intacto"),
    ("css_and_ids", "clases CSS, id y color intactos"),
    ("link_and_anim", "URL del enlace, animación y tipo de botón intactos"),
    ("icons", "iconos e ids de repetidor intactos"),
    ("image_url", "URL de la imagen intacta"),
    ("element_ids", "ids de elementos intactos"),
    ("document_loads", "Elementor carga el documento"),
    ("renders", "Elementor lo renderiza"),
    ("render_translated", "el render muestra el texto traducido"),
    ("render_no_source", "el render no deja texto en el idioma original"),
    ("render_link", "el render conserva el enlace"),
]

print("  — por la API —")
check(d.get("api_success") is True, "la traducción se crea", d.get("api_message", ""))
for key, label in widget_checks:
    check(d.get("api", {}).get(key) is True, label)

if d.get("mcp_skipped"):
    print("  — por MCP: omitido, este WordPress no tiene la Abilities API —")
else:
    print("  — por MCP —")
    check(d.get("mcp_api_requests") == 0, "ni una llamada a la API", f"({d.get('mcp_api_requests')})")
    check(d.get("mcp_has_widgets") is True, "prepare incluye los textos de los widgets")
    check(d.get("mcp_no_html") is True, "al chat no le llega HTML")
    check(d.get("mcp_no_technical") is True, "al chat no le llega ningún ajuste técnico")
    check(d.get("mcp_glossary_skip") is True, "lo que resuelve el glosario no se manda")
    check(d.get("mcp_saved") == "created" and d.get("mcp_no_warning") is True, "se guarda completa", f"({d.get('mcp_saved')})")
    for key, label in widget_checks:
        check(d.get("mcp", {}).get(key) is True, label)
    check(d.get("status_current") == "current", "estado: al día")
    check(d.get("review_lists_widgets") is True, "get_translation muestra los textos de los widgets")
    check(d.get("correct_ok") is True and d.get("correct_applied") is True, "corregir una frase dentro de un widget")
    check(d.get("correct_rest_kept") is True, "el resto de la página no se toca")
    check(d.get("correct_renders") is True, "la corrección se ve en el render")
    check(d.get("status_outdated_after_edit") == "outdated", "editar el original desde Elementor la deja desactualizada")
    check(d.get("retranslate_only_changed") is True, "re-traducir pide solo el texto que cambió")

sys.exit(1 if bad else 0)
CHECK
  check_log "elementor"
fi

# =============================================================================
section "J. Elementor Pro: por API y por MCP, y el formulario enviado de verdad"
# =============================================================================
HAS_PRO=$("$WP" eval 'echo (defined("ELEMENTOR_PRO_VERSION") && !empty(get_option("wit_test_fixture")["elementor_pro_page"])) ? "1" : "0";' 2>/dev/null | tail -1)
if [ "$HAS_PRO" != "1" ]; then
  # Optional by design: Pro is not free and CI never has it.
  echo "  (Elementor Pro no instalado — pásalo con WIT_ELEMENTOR_PRO_ZIP a setup.sh —, sección omitida)"
else
  "$WP" eval-file "$FIXTURES/reset.php" >/dev/null
  "$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = true; update_option("wit_settings", $s, false);' >/dev/null
  : > "$LOG"; : > "$AILOG"
  "$WP" eval-file "$FIXTURES/elementor-pro-scenario.php" > "$TEST_DIR/elementor-pro.json" 2>/dev/null

  python3 - "$TEST_DIR/elementor-pro.json" <<'CHECK' | tee -a "$CHECKS" || FAIL=1
import json, os, sys
raw = open(sys.argv[1]).read()
try:
    d, _ = json.JSONDecoder().raw_decode(raw[raw.index('{'):])
except Exception:
    print("  \033[31m✗\033[0m elementor-pro-scenario.php no devolvió JSON:\n" + raw[-2000:]); sys.exit(1)
bad = 0
def check(ok, label):
    global bad
    print(("  \033[32m✓\033[0m " if ok else "  \033[31m✗\033[0m ") + label)
    if not ok:
        bad += 1
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(f"::error title=J. Elementor Pro::{label}")
labels = [
    ("form_labels", "formulario: etiquetas y placeholders traducidos"),
    ("form_messages", "formulario: mensajes de éxito y error traducidos"),
    ("form_button", "formulario: botón traducido"),
    ("submit_actions", "formulario: las acciones al enviar (email, redirección) intactas"),
    ("required", "formulario: los campos obligatorios siguen siéndolo"),
    ("custom_ids", "formulario: los ids de campo intactos"),
    ("field_types", "formulario: los tipos de campo intactos"),
    ("email_setup", "formulario: destinatario, remitente y [all-fields] intactos"),
    ("redirect", "formulario: URL de redirección intacta"),
    ("email_placeholder", "formulario: un placeholder que es un email no se traduce"),
    ("options_valued", "opciones «Etiqueta|valor»: etiqueta traducida, valor conservado"),
    ("options_unvalued", "opciones sin valor: se fija el original, lo enviado no cambia"),
    ("price_text", "tabla de precios: textos traducidos"),
    ("currency", "tabla de precios: moneda, precio y enlace intactos"),
    ("headline_words", "titular animado: cada palabra rotatoria traducida, mismas líneas"),
    ("headline_setup", "titular animado: estilo, animación y marcador intactos"),
    ("countdown_text", "cuenta atrás: etiquetas y mensaje traducidos"),
    ("countdown_setup", "cuenta atrás: fecha, tipo y acción al terminar intactos"),
    ("flip", "flip box: textos traducidos, efecto intacto"),
    ("slides", "slides: textos traducidos, enlaces y colores intactos"),
    ("quote", "cita: textos traducidos, interruptor y estilo intactos"),
    ("renders", "Elementor renderiza la traducción"),
    ("render_options", "el formulario renderizado envía los valores originales"),
]
for path, title in (("api", "por la API"), ("mcp", "por MCP")):
    if path == "mcp" and d.get("mcp_skipped"):
        print("  — por MCP: omitido, este WordPress no tiene la Abilities API —"); continue
    print(f"  — {title} —")
    for key, label in labels:
        check(d.get(path, {}).get(key) is True, label)
if not d.get("mcp_skipped"):
    check(d.get("mcp_api_requests") == 0, "MCP: ni una llamada a la API")
    check(d.get("mcp_no_technical") is True, "MCP: al chat no le llega ningún valor técnico del formulario")
    check(d.get("mcp_option_labels") is True, "MCP: al chat le llegan las etiquetas de las opciones, no los valores")
    check(d.get("mcp_headline_lines") is True, "MCP: las palabras rotatorias llegan una a una")
sys.exit(1 if bad else 0)
CHECK

  # The check that matters: submit the form as a visitor would. With the old
  # field rules the translated form answered "thank you" and sent nothing.
  echo "  — el formulario, enviado de verdad —"
  MAILLOG="$WWW/wp-content/wit-mail.log"
  submit() {
    "${CURL[@]}" -X POST "$HOST/wp-admin/admin-ajax.php" \
      --data-urlencode "action=elementor_pro_forms_send_form" --data-urlencode "post_id=$1" \
      --data-urlencode "queried_id=$1" --data-urlencode "form_id=pf00001" \
      --data-urlencode "form_fields[nombre]=$2" --data-urlencode "form_fields[email]=ana@cliente.example" \
      --data-urlencode "form_fields[tema]=presupuesto" --data-urlencode "form_fields[mensaje]=Hola" \
      --data-urlencode "form_fields[turno]=Mañana" --data-urlencode "form_fields[acepto]=on"
  }
  read -r PRO_SRC PRO_EN PRO_FR < <(python3 -c "import json,sys;r=open(sys.argv[1]).read();d,_=json.JSONDecoder().raw_decode(r[r.index('{'):]);print(d['source'],d.get('api_id',0),d.get('mcp_id',0))" "$TEST_DIR/elementor-pro.json")
  for pair in "original:$PRO_SRC" "traducido por API:$PRO_EN" "traducido por MCP:$PRO_FR"; do
    id=${pair##*:}; name=${pair%%:*}
    [ "$id" = "0" ] && continue
    : > "$MAILLOG"
    resp=$(submit "$id" "Ana")
    redirect=$(printf '%s' "$resp" | python3 -c "import json,sys;d=json.load(sys.stdin);x=(d.get('data') or {}).get('data');print(x.get('redirect_url','') if isinstance(x,dict) else '')" 2>/dev/null)
    mails=$(grep -c . "$MAILLOG" 2>/dev/null || echo 0)
    values=$(python3 -c "import json;m=json.loads(open('$MAILLOG').readline());print('ok' if m['to']=='info@imagina.example' and 'presupuesto' in m['message'] and 'Mañana' in m['message'] else 'mal')" 2>/dev/null)
    [ "$mails" = "1" ] && [ "$values" = "ok" ] && ok "$name: el email llega a info@ con los valores originales" || bad "$name: emails=$mails valores=$values"
    [ "$redirect" = "https://imagina.example/gracias" ] && ok "$name: redirige" || bad "$name: no redirige ($redirect)"
    printf '%s' "$(submit "$id" "")" | grep -q '"success":false' && ok "$name: sin nombre se rechaza (el campo sigue siendo obligatorio)" || bad "$name: acepta el envío sin el campo obligatorio"
  done
  check_log "elementor pro"
fi

# =============================================================================
section "K. Auditoría 1.4.0: cada fallo encontrado, como comprobación"
# =============================================================================
"$WP" eval-file "$FIXTURES/reset.php" >/dev/null
"$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = true; update_option("wit_settings", $s, false);' >/dev/null
: > "$LOG"; : > "$AILOG"
"$WP" eval-file "$FIXTURES/audit-140.php" > "$TEST_DIR/audit.json" 2>/dev/null

python3 - "$TEST_DIR/audit.json" "$HAS_ABILITIES" <<'CHECK' | tee -a "$CHECKS" || FAIL=1
import json, os, sys
raw = open(sys.argv[1]).read()
try:
    d, _ = json.JSONDecoder().raw_decode(raw[raw.index('{'):])
except Exception:
    print("  \033[31m✗\033[0m audit-140.php no devolvió JSON:\n" + raw[-2000:])
    if os.environ.get("GITHUB_ACTIONS") == "true":
        print("::error title=K. auditoría sin JSON::" + raw[-3000:].replace("%", "%25").replace("\n", "%0A"))
    sys.exit(1)
mcp = sys.argv[2] == "1"
bad = 0
def check(key, label, want=True, needs_mcp=False):
    global bad
    if needs_mcp and not mcp:
        return
    got = d.get(key, "<ausente>")
    if got == want:
        print(f"  \033[32m✓\033[0m {label}")
    else:
        bad += 1
        print(f"  \033[31m✗\033[0m {label} (={got!r}, esperaba {want!r})")
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(f"::error title=K. Auditoría 1.4.0::{label} (={got!r}, esperaba {want!r})")

print("  — el original nunca se sobrescribe —")
check("setup_translation", "se crea la traducción de partida")
check("translation_into_default_refused", "traducir una traducción al idioma original se rechaza")
check("original_untouched", "y el original queda intacto (antes se sobrescribía con «[ES] [EN] …»)")
check("same_language_refused", "traducir al propio idioma se rechaza (antes creaba un duplicado)")
check("queue_refuses_translation_as_source", "la cola tampoco acepta una traducción como origen")
check("mcp_correct_on_translation_refused", "MCP: corregir con el ID de una traducción se rechaza", needs_mcp=True)
check("mcp_publish_on_translation_refused", "MCP: publicar con el ID de una traducción se rechaza", needs_mcp=True)
check("original_still_untouched", "y el original sigue intacto", needs_mcp=True)
print("  — un modelo hostil no puede guardar scripts —")
check("hostile_translated", "la traducción con un modelo hostil termina")
check("hostile_title_clean", "título sin <script> ni onerror (antes se guardaban tal cual)")
check("hostile_excerpt_clean", "extracto limpio")
check("hostile_meta_clean", "título SEO limpio")
check("hostile_render_clean", "contenido renderizado limpio")
check("hostile_text_kept", "el texto traducido se conserva")
check("hostile_memory_clean", "la memoria no guarda el payload")
print("  — tokens registrados —")
check("log_has_tokens", "el historial guarda los tokens de entrada y salida")
check("log_has_strings", "y cuántas cadenas tradujo la IA")
check("log_message_es", "mensaje del historial en español")
check("stats_tokens_30d", "el panel suma los tokens de 30 días")
print("  — traducciones en la papelera —")
check("trashed_reported_missing", "una traducción en la papelera cuenta como pendiente (antes: «al día»)")
check("retranslate_restores_same_post", "re-traducir reutiliza esa traducción (no crea otra)")
check("restored_as_draft", "y la saca de la papelera como borrador (antes escribía dentro de la papelera)")
print("  — ediciones manuales —")
check("fresh_translation_not_edited", "una traducción recién hecha no cuenta como editada")
check("hand_edit_detected", "una edición a mano se detecta")
check("status_reports_edit", "el estado lo indica")
check("listing_filters_edited", "el listado filtra «editadas a mano»")
check("prepare_warns_edited", "MCP: prepare avisa de que hay ediciones a mano", needs_mcp=True)
check("save_asks_confirmation", "MCP: guardar pide confirmación en vez de reemplazarlas", needs_mcp=True)
check("edit_survives_unconfirmed_save", "MCP: y la edición sobrevive", needs_mcp=True)
check("confirmed_save_overwrites", "MCP: con overwrite_edited se guarda sin reenviar cadenas", needs_mcp=True)
check("after_overwrite_not_edited", "MCP: después ya no cuenta como editada", needs_mcp=True)
check("mcp_still_no_api_calls", "MCP: ni una llamada a la API", needs_mcp=True)
check("correction_not_flagged", "MCP: una corrección pedida a Claude no cuenta como edición a mano", needs_mcp=True)
print("  — quitar traducciones por MCP —")
check("unpublish_to_draft", "despublicar la devuelve a borrador", needs_mcp=True)
check("trash_to_trash", "enviarla a la papelera", needs_mcp=True)
check("trash_not_deleted", "sin borrarla del todo", needs_mcp=True)
check("trash_again_says_none", "una vez en la papelera ya no hay traducción que quitar", needs_mcp=True)
check("actions_logged", "las dos acciones quedan en el historial", needs_mcp=True)
print("  — ajustes por MCP —")
check("site_settings_languages", "site_settings describe los idiomas de WPML", needs_mcp=True)
check("site_settings_no_secrets", "sin ninguna API key en la respuesta", needs_mcp=True)
check("settings_changed", "update_settings cambia memoria y campos a traducir", needs_mcp=True)
check("settings_keys_kept", "sin tocar API keys ni glosario", needs_mcp=True)
check("elementor_meta_refused", "no deja añadir _elementor_data como campo", needs_mcp=True)
check("whitelist_ignores_secrets", "la lista blanca ignora API keys, prompt y el interruptor MCP", needs_mcp=True)
print("  — permisos —")
check("author_cannot_see_others_logs", "un Autor no ve en el historial los posts que no puede editar")
check("author_cannot_cancel_admin_queue", "un Autor no puede cancelar la cola de un administrador")
check("author_cannot_update_settings", "un Autor no puede cambiar ajustes por MCP", needs_mcp=True)
check("admin_sees_all_logs", "el administrador sí lo ve todo")
check("admin_can_cancel", "y puede cancelar la cola")
print("  — la cola —")
check("stuck_item_was_blocking", "un elemento atascado bloqueaba volver a encolarlo")
check("stuck_item_requeued", "ahora se recupera solo")
check("thrice_interrupted_fails", "si se interrumpe 3 veces se marca como error y la cola sigue")
check("losing_pass_gets_nothing", "dos pasadas a la vez no se llevan las mismas filas (antes: doble traducción)")
check("lock_excludes", "el cerrojo excluye de verdad")
if mcp:
    print("  — OAuth —")
check("code_race_statuses", "un código canjeado dos veces a la vez: uno 200 y otro 400", [200, 400], needs_mcp=True)
check("code_race_one_pair", "y un solo par de tokens (antes: dos)", needs_mcp=True)
check("refresh_race_statuses", "un refresh token usado dos veces a la vez: uno 200 y otro 400", [200, 400], needs_mcp=True)
check("refresh_race_grant_revoked", "y la conexión entera se revoca", needs_mcp=True)
print("  — el panel ve todo —")
check("listing_total_over_100_limit_logic", "más de 100 contenidos")
check("listing_two_pages", "repartidos en páginas de 50 (antes: solo los 100 más recientes)")
check("listing_search", "la búsqueda encuentra uno concreto")
check("listing_counts_pending", "los recuentos de las pestañas cuadran")
sys.exit(1 if bad else 0)
CHECK

EN_ID=$(python3 -c "import json,sys;r=open(sys.argv[1]).read();d,_=json.JSONDecoder().raw_decode(r[r.index('{'):]);print(d.get('en_id',0))" "$TEST_DIR/audit.json" 2>/dev/null)

echo "  — por HTTP —"
rcheck "API: traducir una traducción al idioma original se rechaza" 400 POST "translate" "$(jbody post_id "$EN_ID" language es)"
rcheck "Panel, página 2"   200 GET "posts?language=en&post_types[]=post&status=all&page=2&per_page=50" "" "d['page'] == 2 and any(i['title'].startswith('Contenido de relleno') for i in d['items'])"
rcheck "Panel, búsqueda"   200 GET "posts?language=en&post_types[]=post&status=all&search=relleno+42" "" "any(i['title'] == 'Contenido de relleno 42' for i in d['items'])"

# As an Author: the screens they can use load, the ones they cannot are
# refused, and the history hides another user's private draft.
"$WP" user update wit_audit_author --user_pass=autor-audit >/dev/null 2>&1
SECRET=$("$WP" eval 'wp_set_current_user(1); $id = wp_insert_post(array("post_title" => "Borrador privado HTTP", "post_status" => "private", "post_type" => "post")); (new WIT_Translation_Manager())->log_event($id, "en", "prueba", "openai"); echo $id;' 2>/dev/null | tail -1)
AJAR="$TEST_DIR/cookies-author.txt"; rm -f "$AJAR"
"${CURL[@]}" -c "$AJAR" -b "$AJAR" -o /dev/null "$HOST/wp-login.php"
"${CURL[@]}" -c "$AJAR" -b "$AJAR" -o /dev/null -X POST "$HOST/wp-login.php" \
  --data-urlencode "log=wit_audit_author" --data-urlencode "pwd=autor-audit" \
  --data-urlencode "wp-submit=Log In" --data-urlencode "testcookie=1"
APAGE=$("${CURL[@]}" -b "$AJAR" "$HOST/wp-admin/admin.php?page=wpml-ia-translate-logs")
# Here-strings, not printf | grep -q: under pipefail, grep closing the pipe
# early kills printf with SIGPIPE on a large page and the match reads as a miss.
grep -q 'id="wit-history"' <<< "$APAGE" && ok "Historial carga para un Autor" || bad "la pantalla Historial no carga para un Autor"
grep -q '"canManage":""\|"canManage":false' <<< "$APAGE" && ok "la app sabe que un Autor no gestiona ajustes" || bad "witConfig.canManage no es falso para un Autor"
SAVED_NONCE="$RNONCE"; RJAR="$AJAR"
RNONCE=$(grep -o 'var witConfig = {[^;]*}' <<< "$APAGE" | grep -o '"nonce":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
rcheck "Historial como Autor" 200 GET "logs?per_page=100" "" "all(i['title'] != 'Borrador privado HTTP' for i in d['items'])"
rcheck "un Autor no lee los ajustes"     403 GET "settings"
rcheck "un Autor no cambia los ajustes"  403 PUT "settings" '{"glossary":"x"}'
rcheck "un Autor no revoca conexiones"   403 DELETE "connections/0123456789abcdef0123456789abcdef"
RJAR="$JAR"; RNONCE="$SAVED_NONCE"
"$WP" post delete "$SECRET" --force >/dev/null 2>&1
check_log "auditoría"

# =============================================================================
section "L. WPML real y String Translation"
# =============================================================================
REAL_WPML=$("$WP" eval 'echo class_exists("SitePress") ? "1" : "0";' 2>/dev/null | tail -1)
if [ "$REAL_WPML" != "1" ]; then
  if [ -n "${WIT_WPML_ZIP:-}" ]; then
    bad "se pidió WPML real (WIT_WPML_ZIP) y no está activo"
  else
    # Optional by design: WPML is not free. CI runs it when the ZIPs are
    # available in the repository.
    echo "  (con el doble de WPML: pasa WIT_WPML_ZIP y WIT_WPML_ST_ZIP a setup.sh para probar contra WPML real)"
  fi
else
  "$WP" eval-file "$FIXTURES/reset.php" >/dev/null
  "$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = true; $s["use_wpml_fields"] = true; update_option("wit_settings", $s, false);' >/dev/null
  : > "$LOG"; : > "$AILOG"
  for step in translate after-edit after-retranslate strings after-strings; do
    "$WP" eval-file "$FIXTURES/wpml-native.php" "$step" > "$TEST_DIR/wpml-$step.json" 2>/dev/null
  done

  python3 - "$TEST_DIR" "$HAS_ABILITIES" <<'CHECK' | tee -a "$CHECKS" || FAIL=1
import json, os, sys
d = {}
for step in ("translate", "after-edit", "after-retranslate", "strings", "after-strings"):
    raw = open(os.path.join(sys.argv[1], "wpml-%s.json" % step)).read()
    try:
        part, _ = json.JSONDecoder().raw_decode(raw[raw.index('{'):])
        d.update(part)
    except Exception:
        print(f"  \033[31m✗\033[0m paso «{step}» sin JSON:\n" + raw[-1500:]); sys.exit(1)
mcp = sys.argv[2] == "1"
bad = 0
def check(key, label, needs_mcp=False):
    global bad
    if needs_mcp and not mcp:
        return
    ok = d.get(key) is True
    print(("  \033[32m✓\033[0m " if ok else "  \033[31m✗\033[0m ") + label + ("" if ok else f" (={d.get(key, '<ausente>')!r})"))
    if not ok:
        bad += 1
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(f"::error title=L. WPML real::{label}")
check("real_wpml", "WPML real activo")
check("st", "String Translation activo")
print("  — WPML sabe de nuestras traducciones —")
check("translated", "se traduce un post")
check("wpml_recorded_complete", "WPML la registra como completa (antes no tenía ningún estado)")
check("wpml_flags_needs_update", "al editar el original WPML la marca «necesita actualización»")
check("plugin_says_outdated", "y el plugin la ve desactualizada")
check("retranslated", "se vuelve a traducir")
check("wpml_current_again", "WPML vuelve a darla por completa")
print("  — campos y taxonomías según WPML —")
check("field_translate_set", "se configura un campo en WPML como «Traducir»")
check("wpml_field_translated", "ese campo se traduce aunque no esté en la lista del plugin")
check("field_ignore_set", "se configura otro como «No traducir»")
check("wpml_ignored_field_not_copied", "ese no se copia a la traducción")
check("post_format_not_translated_in_wpml", "WPML no traduce los formatos de entrada")
check("shared_taxonomy_same_term", "la traducción usa el mismo formato, sin crear términos que WPML no conoce")
print("  — ajustes de WPML por MCP —")
check("mcp_wpml_tool_registered", "la herramienta existe con WPML real", needs_mcp=True)
check("mcp_post_type_mode", "cambiar un tipo a «mostrar como traducido»", needs_mcp=True)
check("mcp_post_type_mode_back", "y devolverlo a «traducir»", needs_mcp=True)
check("mcp_field_mode", "cambiar el modo de un campo", needs_mcp=True)
check("mcp_bad_mode_refused", "un modo de campo para un tipo de contenido se rechaza", needs_mcp=True)
check("site_settings_sees_real_wpml", "site_settings informa de WPML real y su Translation Management", needs_mcp=True)
print("  — cadenas de String Translation —")
check("registered", "cadenas de un tema y un texto de administración registradas")
check("pending_es", "las 5 del tema (en inglés) están pendientes de traducir al español")
check("own_language_not_listed", "una cadena no aparece como pendiente en su propio idioma")
check("api_saved_4", "por la API se guardan 4")
check("api_rejects_broken_placeholder", "y se rechaza la que perdió su %d")
check("api_broken_not_saved", "sin guardarla")
check("api_plain", "cadena simple traducida")
check("api_placeholders", "%1$s y %2$s conservados")
check("api_html_intact", "el enlace de una cadena con HTML intacto")
check("api_placeholder_node_untouched", "un <span> que solo contiene %s no se manda a traducir")
check("api_requests_made", "por la API sí se llama al proveedor")
check("strings_logged", "queda en el historial")
check("mcp_prepare", "MCP: prepare_strings devuelve el texto de administración", needs_mcp=True)
check("mcp_saved", "MCP: save_strings lo guarda", needs_mcp=True)
check("mcp_value", "MCP: con el texto del chat", needs_mcp=True)
check("mcp_rejects_lost_placeholder", "MCP: una respuesta del chat sin %2$s se rechaza", needs_mcp=True)
check("mcp_no_api_calls", "MCP: ni una llamada a la API", needs_mcp=True)
check("mcp_overview", "MCP: strings_overview ve la carpeta de idiomas escribible", needs_mcp=True)
check("files_written", "String Translation escribe sus ficheros de traducción")
sys.exit(1 if bad else 0)
CHECK

  # What a visitor sees: the theme string, translated, on a real request.
  DEMO=$("$WP" eval 'wp_set_current_user(1); $p = get_page_by_path("wit-demo-cadenas"); echo $p ? $p->ID : wp_insert_post(array("post_title" => "Demo cadenas", "post_name" => "wit-demo-cadenas", "post_type" => "page", "post_status" => "publish", "post_content" => "[wit_demo]"));' 2>/dev/null | tail -1)
  FRONT=$("${CURL[@]}" -L "$HOST/wit-demo-cadenas/")
  grep -q '<span id="wit-g">\[ES\] Leave a reply</span>' <<< "$FRONT" \
    && ok "en la web, la cadena del tema sale traducida (gettext servido por String Translation)" \
    || bad "en la web la cadena del tema no sale traducida: $(grep -o '<span id="wit-g">[^<]*' <<< "$FRONT")"
  screen "wpml-ia-translate-strings" strings "Cadenas"
  echo "  — la pantalla Cadenas, por su API —"
  rcheck "cadenas al español"            200 GET "strings?language=es&status=all" "" "d['total'] >= 5 and {'id','domain','text','translation','status','from_file'} <= set(d['items'][0])"
  rcheck "dominios con pendientes"       200 GET "strings/domains?language=es" "" "any(x['domain'] == 'wit-theme' for x in d)"
  DATE_ID=$("$WP" eval 'echo icl_register_string("wit-theme", "wit-date-format", "F j, Y", false, "en");' 2>/dev/null | tail -1)
  PH_ID=$("$WP" eval 'echo icl_register_string("wit-theme", "wit-ph-manual", "%d new comments", false, "en");' 2>/dev/null | tail -1)
  rcheck "a mano: sin el marcador se rechaza" 400 POST "strings/save" "$(jbody id "$PH_ID" language es value "comentarios nuevos")" "'%d' in d['message']"
  rcheck "a mano: con el marcador se guarda"  200 POST "strings/save" "$(jbody id "$PH_ID" language es value "%d comentarios nuevos")" "d['translation'] == '%d comentarios nuevos'"
  rcheck "y se lee como traducida"            200 GET "strings?language=es&status=translated&search=new+comments" "" "any(i['id'] == $PH_ID and i['translation'] == '%d comentarios nuevos' for i in d['items'])"
  : > "$AILOG"
  rcheck "un formato de fecha se guarda tal cual" 200 POST "strings/translate" "{\"ids\":[$DATE_ID],\"language\":\"es\"}" "d['saved'] == 1 and d['translations']['$DATE_ID'] == 'F j, Y'"
  grep -q 'F j, Y' "$AILOG" && bad "«F j, Y» se envió a la IA" || ok "sin enviarlo a la IA (antes podía volver como «j F, A» y romper las fechas)"
  rcheck "siguiente lote de pendientes"  200 GET "strings/next?language=fr" "" "'ids' in d and 'remaining' in d"
  "$WP" post delete "$DEMO" --force >/dev/null 2>&1
  check_log "wpml real"
fi

# =============================================================================
section "M. Las pantallas en un navegador de verdad"
# =============================================================================
# Playwright and its Chromium: from the project if installed there, else the
# global install. CI installs it; locally, without it, the section says so.
PW_PATH="$PLUGIN_DIR/tests/integration/client/node_modules:$(npm root -g 2>/dev/null)"
if NODE_PATH="$PW_PATH" node -e 'require("playwright")' >/dev/null 2>&1; then
  "$WP" eval-file "$FIXTURES/reset.php" >/dev/null
  : > "$LOG"
  NODE_PATH="$PW_PATH" node "$PLUGIN_DIR/tests/integration/browser/screens.cjs" "$HOST" "$REAL_WPML_ST" > "$TEST_DIR/browser.log" 2>&1
  while IFS= read -r line; do
    case "$line" in
      ok:*)  ok "${line#ok: }" ;;
      bad:*) bad "${line#bad: }" ;;
    esac
  done < "$TEST_DIR/browser.log"
  grep -q '^ok:\|^bad:' "$TEST_DIR/browser.log" || bad "el navegador no llegó a ejecutarse: $(tail -3 "$TEST_DIR/browser.log")"
  check_log "navegador"
elif [ "${GITHUB_ACTIONS:-}" = "true" ]; then
  bad "Playwright no está instalado en CI"
else
  echo "  (sin Playwright: instala «playwright» y su Chromium para probar las pantallas en un navegador)"
fi

# =============================================================================
section "H. Guardado de ajustes desde la pantalla Ajustes"
# =============================================================================
: > "$LOG"
# Exactly what the screen sends: only what changed, keys write-only.
BODY=$(python3 -c 'import json; print(json.dumps({
  "provider": "claude",
  "keys": {"claude": "sk-ant-nueva", "openai": ""},
  "models": {"claude": "claude-haiku-4-5-20251001"},
  "prompt": "Translate to {target_language}.",
  "glossary": "Imagina\nHola = Hello",
  "translate_meta_fields": True, "meta_fields": "_yoast_wpseo_title, <script>",
  "batch_size": 7, "memory_enabled": True,
}))')
rcheck "guardar ajustes" 200 PUT "settings" "$BODY" "d['provider'] == 'claude' and d['keys']['claude']['stored'] and d['batch_size'] == 7"
grep -q 'sk-ant-nueva' "$REST_OUT" && bad "la respuesta devuelve la clave recién guardada" || ok "la respuesta no devuelve la clave guardada"

E=$("$WP" eval '
$s = get_option("wit_settings");
echo json_encode(array(
    "proveedor"     => $s["ai_provider"] === "claude",
    "clave_nueva"   => $s["claude_api_key"] === "sk-ant-nueva",
    // An empty key means "keep it", not "delete it": the screen never shows
    // the stored key, so leaving the field blank must not wipe it.
    "clave_intacta" => $s["openai_api_key"] === "sk-test-fake",
    "lote"          => (int) $s["batch_size"] === 7,
    "glosario"      => substr_count($s["glossary"], "\n") === 1,
    "campos"        => $s["meta_fields_list"] === "_yoast_wpseo_title,script",
));')
f() { printf '%s' "$E" | python3 -c "import json,sys;print(json.load(sys.stdin)['$1'])" 2>/dev/null; }
for k in proveedor:"proveedor guardado" clave_nueva:"clave nueva guardada" \
         clave_intacta:"la clave de otro proveedor NO se borra al enviarla vacía" \
         lote:"tamaño de lote guardado" glosario:"saltos de línea del glosario preservados" \
         campos:"la lista de campos se sanea (nada que no sea una clave)"; do
  [ "$(f "${k%%:*}")" = "True" ] && ok "${k#*:}" || bad "${k#*:}"
done

# Clearing a key is an explicit action, and touches only that key.
rcheck "quitar una clave" 200 PUT "settings" '{"clear_keys":{"claude":true}}' "not d['keys']['claude']['stored'] and d['keys']['openai']['stored']"
# A setting the screen did not send keeps its value.
rcheck "lo que no se envía no cambia" 200 PUT "settings" '{"glossary":"Imagina"}' "d['batch_size'] == 7 and d['prompt'] == 'Translate to {target_language}.' and d['glossary'] == 'Imagina'"
check_log "ajustes"

# Leave the install usable for the next run.
"$WP" eval-file "$FIXTURES/reset.php" >/dev/null
"$WP" eval '
$s = get_option("wit_settings");
$s["ai_provider"] = "openai"; $s["openai_api_key"] = "sk-test-fake";
$s["batch_size"] = 2; $s["enable_translation_memory"] = true;
$s["glossary"] = "Imagina\nContacta con nosotros = Get in touch";
update_option("wit_settings", $s, false);' >/dev/null

# A summary annotation in CI: proof of what actually ran, readable through the
# API even when the raw log is not — a section that silently skipped would
# otherwise look exactly like one that passed.
if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
  el_version=$("$WP" eval 'echo defined("ELEMENTOR_VERSION") ? ELEMENTOR_VERSION : "ninguno";' 2>/dev/null | tail -1)
  wpml_kind=$("$WP" eval 'echo class_exists("SitePress") ? "WPML " . ICL_SITEPRESS_VERSION . (defined("WPML_ST_VERSION") ? " + ST " . WPML_ST_VERSION : "") : "doble de WPML";' 2>/dev/null | tail -1)
  printf '::notice title=Resumen de integración::WordPress %s · %s · Elementor %s · Abilities API %s · %s comprobaciones correctas, %s fallidas\n' \
    "$("$WP" core version 2>/dev/null)" "$wpml_kind" "$el_version" "$([ "$HAS_ABILITIES" = "1" ] && echo sí || echo no)" \
    "$(grep -c '✓' "$CHECKS")" "$(grep -c '✗' "$CHECKS")"
fi

echo
if [ $FAIL = 0 ]; then
  printf '\033[32mTodo correcto\033[0m\n'
else
  printf '\033[31mHay fallos\033[0m\n'
fi
exit $FAIL
