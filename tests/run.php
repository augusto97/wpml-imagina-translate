<?php
/**
 * Test runner.
 *
 * Usage:  php tests/run.php
 *
 * Needs no WordPress, no database and no Composer — see bootstrap.php.
 * Exits non-zero on failure so it can be wired into CI.
 */

require_once __DIR__ . '/bootstrap.php';

$suites = array(
    'test-html-translator.php',
    'test-field-rules.php',
    'test-glossary.php',
    'test-batch-protocol.php',
);

echo "\n\033[1mWPML Imagina Translate — suite de tests\033[0m\n";

foreach ($suites as $suite) {
    require __DIR__ . '/' . $suite;
}

exit(WIT_Tests::summary());
