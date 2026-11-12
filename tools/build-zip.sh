#!/usr/bin/env bash
#
# Build the distributable ZIP and verify it before anyone installs it.
#
# The archive contains only what a production site loads: tests and repository
# metadata are excluded. It unpacks to a single wpml-imagina-translate/
# directory, which is what WordPress expects from an uploaded plugin.
#
# Usage:
#   tools/build-zip.sh              # build from HEAD into dist/
#   tools/build-zip.sh /tmp/out     # build somewhere else
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$PLUGIN_DIR/dist}"
SLUG="wpml-imagina-translate"

cd "$PLUGIN_DIR"

# git archive packages HEAD, not the working tree. Uncommitted changes are
# therefore NOT in the ZIP — say so, because a build that silently leaves out
# the work in progress is how a release ships without its new files.
if [ -n "$(git status --porcelain)" ]; then
  echo "AVISO: hay cambios sin commitear; el ZIP contiene HEAD ($(git rev-parse --short HEAD)), no el árbol de trabajo." >&2
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG" "$OUT_DIR"

# git archive, so only committed files ship: no stray local edits, no scratch
# files, no .git.
git archive HEAD | tar -x -C "$STAGE/$SLUG"

# Version read from what is being packaged. Reading it from the working tree
# would label a HEAD archive with whatever version is being edited.
VERSION=$(grep -oP '^ \* Version:\s*\K[0-9.]+' "$STAGE/$SLUG/$SLUG.php")
[ -n "$VERSION" ] || { echo "No se pudo leer la versión del encabezado del plugin" >&2; exit 1; }

# The header version drives maybe_upgrade(); a mismatch means an updated site
# would skip creating new tables and fail silently.
CONST_VERSION=$(grep -oP "define\('WIT_VERSION',\s*'\K[0-9.]+" "$STAGE/$SLUG/$SLUG.php")
if [ "$VERSION" != "$CONST_VERSION" ]; then
  echo "La versión del encabezado ($VERSION) y WIT_VERSION ($CONST_VERSION) no coinciden" >&2
  exit 1
fi
# The admin screens are a React app (admin-ui/). Built here, from the same
# HEAD that is being packaged, so the ZIP never ships a stale local build;
# only the output goes in — sources and node_modules stay out, like Imagina
# Updater's releases.
command -v npm >/dev/null || { echo "Hace falta Node 18+ y npm para compilar la interfaz (admin-ui/)" >&2; exit 1; }
( cd "$STAGE/$SLUG/admin-ui" \
  && npm ci --no-audit --no-fund --loglevel=error >/dev/null \
  && npm run build >/dev/null ) || { echo "Falló la compilación de admin-ui/" >&2; exit 1; }
rm -rf "$STAGE/$SLUG/admin-ui"

rm -rf "$STAGE/$SLUG/tests" "$STAGE/$SLUG/tools" "$STAGE/$SLUG/.gitignore" "$STAGE/$SLUG/.github"
# Third-party ZIPs kept in the repository for testing (WPML, String
# Translation) are never shipped inside this plugin.
find "$STAGE/$SLUG" -maxdepth 1 -iname '*.zip' -delete

ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" -x '*.DS_Store' )

# --- Verify the artifact, not the working tree -------------------------------
VERIFY="$STAGE/verify"
mkdir -p "$VERIFY"
unzip -q "$ZIP" -d "$VERIFY"

fail=0

php_files=$(find "$VERIFY" -name '*.php' | wc -l)
while IFS= read -r f; do
  php -l "$f" >/dev/null 2>&1 || { echo "Error de sintaxis: ${f#$VERIFY/}" >&2; fail=1; }
done < <(find "$VERIFY" -name '*.php')

# Every require_once must resolve inside the archive, or the plugin fatals on
# activation with a file-not-found.
includes=$(grep -oP "WIT_PLUGIN_DIR \. '\K[^']+" "$VERIFY/$SLUG/$SLUG.php" || true)
# A grep that silently stopped matching would make the loop below pass without
# checking anything.
if [ "$(printf '%s' "$includes" | grep -c .)" -lt 5 ]; then
  echo "Se esperaban al menos 5 require_once en el fichero principal; se encontraron $(printf '%s' "$includes" | grep -c .)" >&2
  fail=1
fi
while IFS= read -r rel; do
  [ -n "$rel" ] || continue
  [ -f "$VERIFY/$SLUG/$rel" ] || { echo "Falta un fichero incluido: $rel" >&2; fail=1; }
done <<< "$includes"

roots=$(unzip -Z1 "$ZIP" | cut -d/ -f1 | sort -u | wc -l)
[ "$roots" = "1" ] || { echo "El ZIP debe tener una única carpeta raíz (tiene $roots)" >&2; fail=1; }

if find "$VERIFY" -iname '*.zip' -print -quit | grep -q .; then
  echo "El ZIP contiene otros ZIP; no deberían enviarse" >&2
  fail=1
fi

if find "$VERIFY" -path '*/tests/*' -print -quit | grep -q .; then
  echo "El ZIP incluye tests; deberían estar excluidos" >&2
  fail=1
fi

# Every screen the PHP registers needs its bundle, or it shows a notice
# instead of the app.
APP="$VERIFY/$SLUG/assets/app"
[ -f "$APP/app.asset.php" ] && [ -f "$APP/app.css" ] || { echo "Falta la interfaz compilada (assets/app)" >&2; fail=1; }
screens=$(grep -oP "^\s*'\K[a-z]+(?='\s*=>\s*'wpml-)" "$VERIFY/$SLUG/includes/class-admin-app.php" || true)
[ "$(printf '%s' "$screens" | grep -c .)" -ge 5 ] || { echo "No se pudieron leer las pantallas de class-admin-app.php" >&2; fail=1; }
while IFS= read -r screen; do
  [ -n "$screen" ] || continue
  [ -f "$APP/$screen.js" ] || { echo "Falta el bundle de la pantalla «$screen»" >&2; fail=1; }
done <<< "$screens"
if find "$VERIFY" -path '*/node_modules/*' -print -quit | grep -q . || [ -d "$VERIFY/$SLUG/admin-ui" ]; then
  echo "El ZIP incluye admin-ui/ o node_modules; solo debe llevar assets/app" >&2
  fail=1
fi

[ "$fail" = "0" ] || exit 1

echo "$ZIP"
echo "  versión   : $VERSION"
echo "  ficheros  : $(unzip -Z1 "$ZIP" | wc -l) ($php_files PHP, todos parsean)"
echo "  tamaño    : $(du -h "$ZIP" | cut -f1)"
echo "  incluidos : $(printf '%s' "$includes" | grep -c .) require_once, todos presentes"
echo "  interfaz  : $(printf '%s' "$screens" | grep -c .) pantallas compiladas ($(du -sh "$APP" | cut -f1))"
echo "  sha256    : $(sha256sum "$ZIP" | cut -d' ' -f1)"
