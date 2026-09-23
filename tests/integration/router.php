<?php
/**
 * Router for PHP's built-in web server, behaving like a real WordPress host.
 *
 * Apache with WordPress's .htaccess, or nginx with try_files, sends every
 * request for a path that is not an existing file or directory to index.php.
 * The built-in server does not: before PHP 8.4 it answers 404 by itself for
 * any path whose segments look like file names — which includes every OAuth
 * discovery URL, because they all contain "/.well-known/". The suite passed on
 * PHP 8.4 and failed on 8.3 for that reason alone.
 *
 * Usage: php -S 127.0.0.1:8080 -t <docroot> tests/integration/router.php
 */

$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = $path === null ? '/' : urldecode($path);

// Real files and directories (wp-login.php, wp-admin/, assets) are served as
// the built-in server normally would.
if ($path !== '/' && (is_file($root . $path) || is_dir($root . $path))) {
    return false;
}

// Everything else is WordPress's to route, pretty permalinks included.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';

chdir($root);
require $root . '/index.php';
