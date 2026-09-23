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
NOISE='wp_version_check|wp_update_themes|wp_update_plugins|plugins_api|wordpress\.org|plugins/elementor/|plugins/elementor-pro/|\[Elementor Atomic Widgets\]'
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
    "destacada"   => (int) get_post_thumbnail_id($p1) === (int) get_post_thumbnail_id($f["post"]),
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

  grep -Eo "(Warning|Notice|Fatal error): [^<]{0,110}" "$file" | head -3 | sed 's/^/      PHP: /'
}
page "admin.php?page=wpml-ia-translate"               "Dashboard"        "wit-dashboard"
page "admin.php?page=wpml-ia-translate&target_lang=en&post_types%5B%5D=post&post_types%5B%5D=page" \
                                                      "Dashboard filtrado" "wit-post-checkbox"
page "admin.php?page=wpml-ia-translate-logs"          "Logs"             "wit-logs"
page "admin.php?page=wpml-imagina-translate-settings" "Ajustes"          "wit-provider-section"
page "plugins.php"                                    "Enlace «Ajustes» en la lista de plugins" "wpml-imagina-translate-settings"

LOC=$("${CURL[@]}" -o /dev/null -w '%{redirect_url}' -b "$JAR" \
      "$HOST/wp-admin/options-general.php?page=wpml-imagina-translate-settings")
case "$LOC" in
  *admin.php?page=wpml-imagina-translate-settings) ok "la URL de ajustes anterior a 1.2.2 redirige" ;;
  *) bad "la URL antigua no redirige (Location: «$LOC»)" ;;
esac
check_log "pantallas"

# =============================================================================
section "E. Endpoints AJAX"
# =============================================================================
# The page carries several "nonce" values; the plugin's is inside its own
# localized witAdmin object.
NONCE=$(grep -o 'var witAdmin = {[^;]*}' "$TEST_DIR/page-Dashboard_filtrado.html" \
        | grep -o '"nonce":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
[ -n "$NONCE" ] && ok "nonce localizado" || bad "no se encontró el nonce del plugin"

: > "$LOG"
ajax() { # acción, etiqueta, datos extra…
  local action="$1" label="$2" body; shift 2
  body=$("${CURL[@]}" -b "$JAR" -X POST "$HOST/wp-admin/admin-ajax.php" \
         -d "action=$action" -d "nonce=$NONCE" "$@")
  printf '%s' "$body" | grep -q '"success":true' && ok "$label" || bad "$label → $(printf '%s' "$body" | head -c 180)"
  printf '%s' "$body" | grep -Eo "(Warning|Notice|Fatal error): [^<]{0,110}" | head -2 | sed 's/^/      PHP: /'
}
ajax wit_test_connection          "wit_test_connection"          -d "provider=openai"
ajax wit_fetch_models             "wit_fetch_models"             -d "provider=openai"
ajax wit_translate_post           "wit_translate_post"           -d "post_id=$POST_ID" -d "target_language=fr"
ajax wit_check_translation_status "wit_check_translation_status" -d "post_id=$POST_ID" -d "target_language=fr"
ajax wit_enqueue_batch            "wit_enqueue_batch"            -d "post_ids[]=$PAGE_ID" -d "target_language=en"
ajax wit_queue_status             "wit_queue_status"
ajax wit_cancel_queue             "wit_cancel_queue"
ajax wit_clear_memory             "wit_clear_memory"

echo "  — deben rechazarse —"
reject() { # etiqueta, datos…
  local label="$1" body; shift
  body=$("${CURL[@]}" -X POST "$HOST/wp-admin/admin-ajax.php" "$@")
  if [ "$body" = "0" ] || printf '%s' "$body" | grep -q '"success":false'; then
    ok "$label"
  else
    bad "$label NO rechazado → $(printf '%s' "$body" | head -c 120)"
  fi
}
reject "nonce inválido"      -b "$JAR" -d "action=wit_translate_post" -d "nonce=INVALIDO" -d "post_id=$POST_ID" -d "target_language=fr"
reject "idioma inexistente"  -b "$JAR" -d "action=wit_translate_post" -d "nonce=$NONCE"   -d "post_id=$POST_ID" -d "target_language=xx"
reject "sin sesión"                    -d "action=wit_translate_post" -d "nonce=$NONCE"   -d "post_id=$POST_ID" -d "target_language=fr"
check_log "ajax"

# =============================================================================
section "F. Traducir por MCP: las herramientas, con un chat simulado"
# =============================================================================
if [ "$HAS_ABILITIES" != "1" ]; then
  echo "  (WordPress $("$WP" core version) no tiene la Abilities API: se comprueba que MCP se desactiva limpio)"
  "$WP" eval '$s = get_option("wit_settings"); $s["mcp_enabled"] = true; update_option("wit_settings", $s, false);' >/dev/null
  code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST "$HOST/wp-json/wit/v1/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}')
  [ "$code" = "404" ] && ok "sin Abilities API, el endpoint MCP no existe (404) aunque esté activado" || bad "endpoint MCP respondió $code en WordPress sin Abilities API"
  notice=$("${CURL[@]}" -b "$JAR" "$HOST/wp-admin/admin.php?page=wpml-imagina-translate-settings")
  printf '%s' "$notice" | grep -q "Requiere WordPress 6.9" && ok "Ajustes explica que hace falta WordPress 6.9" || bad "Ajustes no avisa del requisito de WordPress 6.9"
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
    ("abilities_registered", True, "las 11 abilities están registradas"),
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

python3 - "$TEST_DIR/mcp-client.json" "$(wc -l < "$AILOG")" <<'CHECK' | tee -a "$CHECKS" || FAIL=1
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
if len(d.get("tools", [])) == 11:
    print("  \033[32m✓\033[0m 11 herramientas expuestas")
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
section "H. Guardado de ajustes por el formulario real"
# =============================================================================
: > "$LOG"
SNONCE=$(grep -o 'name="_wpnonce" value="[a-f0-9]*"' "$TEST_DIR/page-Ajustes.html" | head -1 | cut -d'"' -f4)
"${CURL[@]}" -b "$JAR" -c "$JAR" -o /dev/null -X POST "$HOST/wp-admin/options.php" \
  -d "option_page=wit_settings_group" -d "action=update" -d "_wpnonce=$SNONCE" \
  -d "_wp_http_referer=/wp-admin/admin.php?page=wpml-imagina-translate-settings" \
  -d "wit_settings[ai_provider]=claude" \
  --data-urlencode "wit_settings[claude_api_key]=sk-ant-nueva" \
  -d "wit_settings[claude_model]=claude-haiku-4-5-20251001" \
  -d "wit_settings[openai_api_key]=" -d "wit_settings[openai_model]=gpt-4o-mini" \
  -d "wit_settings[gemini_model]=gemini-2.5-flash" \
  --data-urlencode "wit_settings[translation_prompt]=Translate to {target_language}." \
  --data-urlencode "wit_settings[glossary]=Imagina"$'\n'"Hola = Hello" \
  -d "wit_settings[translate_meta_fields]=1" -d "wit_settings[meta_fields_list]=_yoast_wpseo_title" \
  -d "wit_settings[batch_size]=7" -d "wit_settings[enable_translation_memory]=1"

E=$("$WP" eval '
$s = get_option("wit_settings");
echo json_encode(array(
    "proveedor"     => $s["ai_provider"] === "claude",
    "clave_nueva"   => $s["claude_api_key"] === "sk-ant-nueva",
    // An empty submission means "keep it", not "delete it": the form never
    // renders the stored key, so blanking it must not wipe it.
    "clave_intacta" => $s["openai_api_key"] === "sk-test-fake",
    "lote"          => (int) $s["batch_size"] === 7,
    "glosario"      => substr_count($s["glossary"], "\n") === 1,
));')
f() { printf '%s' "$E" | python3 -c "import json,sys;print(json.load(sys.stdin)['$1'])" 2>/dev/null; }
for k in proveedor:"proveedor guardado" clave_nueva:"clave nueva guardada" \
         clave_intacta:"la clave de otro proveedor NO se borra al enviar vacío" \
         lote:"tamaño de lote guardado" glosario:"saltos de línea del glosario preservados"; do
  [ "$(f "${k%%:*}")" = "True" ] && ok "${k#*:}" || bad "${k#*:}"
done
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
  printf '::notice title=Resumen de integración::WordPress %s · Elementor %s · Abilities API %s · %s comprobaciones correctas, %s fallidas\n' \
    "$("$WP" core version 2>/dev/null)" "$el_version" "$([ "$HAS_ABILITIES" = "1" ] && echo sí || echo no)" \
    "$(grep -c '✓' "$CHECKS")" "$(grep -c '✗' "$CHECKS")"
fi

echo
if [ $FAIL = 0 ]; then
  printf '\033[32mTodo correcto\033[0m\n'
else
  printf '\033[31mHay fallos\033[0m\n'
fi
exit $FAIL
