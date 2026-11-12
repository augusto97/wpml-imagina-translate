<?php
/**
 * Plugin Name: Test hooks (test double)
 * Description: Switches the integration suite flips with wp-cli. Test-only.
 *
 * wit_test_hold_cron: WP-Cron finds nothing to run while set. The suite runs
 * PHP's server with several workers, so a queue started over HTTP is often
 * drained before the next request can look at it; held, the "queued" state
 * can be checked without racing the worker.
 */
add_filter('pre_get_ready_cron_jobs', function ($pre) {
    return get_option('wit_test_hold_cron') ? array() : $pre;
});
