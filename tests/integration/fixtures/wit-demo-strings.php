<?php
/**
 * Plugin Name: Demo strings (test double)
 * Description: Stands in for a theme. [wit_demo] prints a gettext string of
 *              the "wit-theme" domain and an admin text registered with WPML
 *              String Translation, so a front-end request shows whether their
 *              translations reach visitors. Test-only.
 */
add_shortcode('wit_demo', function () {
    return '<span id="wit-g">' . esc_html__('Leave a reply', 'wit-theme') . '</span>'
        . '<span id="wit-s">' . esc_html(apply_filters('wpml_translate_single_string', 'Todos los derechos reservados', 'wit-widgets', 'footer-copy')) . '</span>';
});
