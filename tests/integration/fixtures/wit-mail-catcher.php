<?php
/**
 * Plugin Name: Mail catcher (test double)
 * Description: Records every wp_mail() call instead of sending it, so a test can
 *              prove a translated form still sends its email. Test-only.
 */
add_filter('pre_wp_mail', function ($null, $atts) {
    file_put_contents(
        WP_CONTENT_DIR . '/wit-mail.log',
        wp_json_encode(array(
            'to'      => $atts['to'],
            'subject' => $atts['subject'],
            'message' => is_string($atts['message']) ? $atts['message'] : '',
        ), JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND
    );
    return true; // handled: nothing leaves the machine
}, 10, 2);
