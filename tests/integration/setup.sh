#!/usr/bin/env bash
#
# Build a throwaway WordPress install for the integration suite.
#
# Idempotent: re-running reuses the downloaded WordPress and wp-cli, and
# rebuilds the database and the install from scratch. Nothing is written
# outside $WIT_TEST_DIR except the MariaDB socket (see the note below).
#
# Usage:
#   tests/integration/setup.sh          # build it
#   WIT_TEST_DIR=/var/tmp/wit setup.sh  # somewhere else
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_DIR="${WIT_TEST_DIR:-${TMPDIR:-/tmp}/wit-integration}"
WP_VERSION="${WIT_WP_VERSION:-6.8.2}"
WP_PORT="${WIT_WP_PORT:-8080}"
DB_PORT="${WIT_DB_PORT:-3307}"

# The MariaDB UNIX socket path is limited to 107 characters by the kernel
# struct, and a socket inside a deeply nested temp directory silently blows
# past it ("The socket file path is too long"). Keep it short and outside
# $TEST_DIR.
DB_SOCKET="${WIT_DB_SOCKET:-/tmp/wit-mysql.sock}"

# mariadbd refuses to run as root unless told to explicitly, and a CI runner
# is not root, so ask the system who we are instead of assuming.
DB_USER="${WIT_DB_USER:-$(id -un)}"

WWW="$TEST_DIR/www"
CACHE="$TEST_DIR/cache"

say() { printf '\033[1m==>\033[0m %s\n' "$1"; }
die() { printf '\033[31mERROR:\033[0m %s\n' "$1" >&2; exit 1; }

command -v php     >/dev/null || die "php no está instalado"
command -v mariadbd >/dev/null || command -v mysqld >/dev/null \
  || die "no hay servidor MariaDB/MySQL. En Debian/Ubuntu: apt-get install -y mariadb-server"

mkdir -p "$CACHE" "$TEST_DIR"

# --- WordPress ---------------------------------------------------------------
if [ ! -d "$CACHE/wordpress-$WP_VERSION" ]; then
  say "Descargando WordPress $WP_VERSION"
  if curl -sfL --max-time 300 -o "$CACHE/wp.zip" \
       "https://downloads.wordpress.org/release/wordpress-${WP_VERSION}.zip" 2>/dev/null; then
    unzip -q "$CACHE/wp.zip" -d "$CACHE/tmp-wp"
    mv "$CACHE/tmp-wp/wordpress" "$CACHE/wordpress-$WP_VERSION"
    rm -rf "$CACHE/tmp-wp" "$CACHE/wp.zip"
  else
    # Some sandboxes cannot reach wordpress.org; the GitHub mirror is the
    # same tree, tagged per release.
    say "wordpress.org no responde, usando el mirror de GitHub"
    git clone -q --depth 1 --branch "$WP_VERSION" \
      https://github.com/WordPress/WordPress "$CACHE/wordpress-$WP_VERSION"
    rm -rf "$CACHE/wordpress-$WP_VERSION/.git"
  fi
fi

# --- wp-cli ------------------------------------------------------------------
if [ ! -f "$CACHE/wp-cli.phar" ]; then
  say "Descargando wp-cli"
  curl -sfL --max-time 180 -o "$CACHE/wp-cli.phar" \
    https://github.com/wp-cli/wp-cli/releases/download/v2.11.0/wp-cli-2.11.0.phar \
    || die "no se pudo descargar wp-cli"
fi

# wp-cli 2.11 emits a wall of PHP 8.4 deprecations from its bundled libraries.
# They are not ours and they drown the output, so they are silenced here.
cat > "$TEST_DIR/wp" <<EOF
#!/bin/sh
exec php -d error_reporting='E_ALL & ~E_DEPRECATED' "$CACHE/wp-cli.phar" --allow-root --path="$WWW" "\$@"
EOF
chmod +x "$TEST_DIR/wp"
WP="$TEST_DIR/wp"

# --- MariaDB -----------------------------------------------------------------
say "Arrancando MariaDB en el puerto $DB_PORT"
mkdir -p "$(dirname "$DB_SOCKET")" "$TEST_DIR/mysql"
if [ ! -d "$TEST_DIR/mysql/mysql" ]; then
  mariadb-install-db --user="$DB_USER" --datadir="$TEST_DIR/mysql" \
    --auth-root-authentication-method=normal >/dev/null 2>&1 \
    || die "mariadb-install-db falló"
fi

if ! mariadb --socket="$DB_SOCKET" -uroot -e "SELECT 1" >/dev/null 2>&1; then
  nohup mariadbd --user="$DB_USER" --datadir="$TEST_DIR/mysql" --socket="$DB_SOCKET" \
    --port="$DB_PORT" --bind-address=127.0.0.1 \
    --pid-file="$TEST_DIR/mysql.pid" --log-error="$TEST_DIR/mysql.err" >/dev/null 2>&1 &
  for _ in $(seq 1 30); do
    mariadb --socket="$DB_SOCKET" -uroot -e "SELECT 1" >/dev/null 2>&1 && break
    sleep 1
  done
fi
mariadb --socket="$DB_SOCKET" -uroot -e "SELECT 1" >/dev/null 2>&1 \
  || die "MariaDB no arrancó; revisa $TEST_DIR/mysql.err"

mariadb --socket="$DB_SOCKET" -uroot <<SQL
DROP DATABASE IF EXISTS wptest;
CREATE DATABASE wptest DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wp'@'127.0.0.1' IDENTIFIED BY 'wp';
GRANT ALL ON wptest.* TO 'wp'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# --- WordPress install -------------------------------------------------------
say "Instalando WordPress en $WWW"
rm -rf "$WWW"
cp -r "$CACHE/wordpress-$WP_VERSION" "$WWW"

"$WP" config create --dbname=wptest --dbuser=wp --dbpass=wp \
  --dbhost="127.0.0.1:$DB_PORT" --skip-check --force --extra-php <<'PHP'
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);
// The suite drives the queue by hand; a cron firing underneath it would make
// results depend on timing.
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'development');
PHP

# siteurl must match the host the suite actually requests. wp-cli derives it
# from the filesystem path, which produced "http://localhost:8080/www" and made
# every admin request 302 away before the plugin ever loaded.
"$WP" core install --url="http://127.0.0.1:$WP_PORT" --title="WIT Integration" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email

"$WP" option update siteurl "http://127.0.0.1:$WP_PORT"
"$WP" option update home    "http://127.0.0.1:$WP_PORT"

# --- Plugin + fixtures -------------------------------------------------------
say "Enlazando el plugin y los dobles de prueba"
# A symlink, so edits in the working tree are live without reinstalling.
ln -sfn "$PLUGIN_DIR" "$WWW/wp-content/plugins/wpml-imagina-translate"

mkdir -p "$WWW/wp-content/plugins/sitepress-multilingual-cms" "$WWW/wp-content/mu-plugins"
cp "$PLUGIN_DIR/tests/integration/fixtures/sitepress-stub.php" \
   "$WWW/wp-content/plugins/sitepress-multilingual-cms/sitepress.php"
cp "$PLUGIN_DIR/tests/integration/fixtures/wit-fake-ai.php" \
   "$WWW/wp-content/mu-plugins/wit-fake-ai.php"

"$WP" plugin activate sitepress-multilingual-cms wpml-imagina-translate

say "Sembrando contenido de prueba"
"$WP" eval-file "$PLUGIN_DIR/tests/integration/fixtures/seed.php"

"$WP" eval '
$s = get_option("wit_settings");
$s["ai_provider"] = "openai";
$s["openai_api_key"] = "sk-test-fake";
$s["openai_model"] = "gpt-4o-mini";
$s["enable_translation_memory"] = true;
$s["batch_size"] = 2;
$s["glossary"] = "Imagina\nContacta con nosotros = Get in touch";
update_option("wit_settings", $s, false);
echo "Ajustes de prueba aplicados\n";'

: > "$WWW/wp-content/debug.log"

cat <<EOF

$(say "Listo")
  Instalación : $WWW
  wp-cli      : $WP <comando>
  Base datos  : mariadb --socket=$DB_SOCKET -uroot wptest
  Admin       : admin / admin

Ejecuta la suite con:
  tests/integration/run.sh
EOF
