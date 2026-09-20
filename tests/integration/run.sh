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

ok()  { printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad() { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=1; }
section() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# WordPress itself cannot always reach wordpress.org from a sandbox; those
# warnings are the environment's, not the plugin's.
NOISE='wp_version_check|wp_update_themes|wp_update_plugins|plugins_api|wordpress\.org'
check_log() {
  if grep -Ev "$NOISE" "$LOG" 2>/dev/null | grep -q .; then
    bad "debug.log con entradas ($1):"
    grep -Ev "$NOISE" "$LOG" | head -5 | sed 's/^/      /'
  else
    ok "debug.log limpio ($1)"
  fi
  : > "$LOG"
}

CURL=(curl -s --max-time 60)

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
# Kill a server left behind by an interrupted run. Matched by pattern, but this
# script's own command line does not contain it, so it cannot kill itself.
for pid in $(pgrep -f "php -S 127.0.0.1:$WP_PORT" 2>/dev/null); do
  kill "$pid" 2>/dev/null
done
sleep 0.5
# Several workers: a request that triggers a sub-request would deadlock against
# a single-threaded server.
PHP_CLI_SERVER_WORKERS=6 php -S "127.0.0.1:$WP_PORT" -t "$WWW" \
  >"$TEST_DIR/php-server.log" 2>&1 &
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
section "F. Guardado de ajustes por el formulario real"
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

echo
if [ $FAIL = 0 ]; then
  printf '\033[32mTodo correcto\033[0m\n'
else
  printf '\033[31mHay fallos\033[0m\n'
fi
exit $FAIL
