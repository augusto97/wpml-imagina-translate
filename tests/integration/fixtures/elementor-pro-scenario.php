<?php
/**
 * An Elementor Pro page translated both ways — API ("[EN] ") and MCP
 * ("[MCP-FR] ") — checking every value a Pro widget stores that looks like
 * text but is not.
 *
 * The decisive check is not here but in run.sh: the translated form is
 * submitted over HTTP like a visitor would, and must still send its email and
 * redirect. With the old field rules it answered "thank you" and sent nothing.
 *
 * Run with: wp eval-file elementor-pro-scenario.php  (prints one JSON object)
 */

wp_set_current_user(1);

if (!defined('ELEMENTOR_PRO_VERSION')) {
    echo wp_json_encode(array('skipped' => 'Elementor Pro no está activo'));
    return;
}

$fixture = get_option('wit_test_fixture');
$page    = (int) $fixture['elementor_pro_page'];
$ailog   = WP_CONTENT_DIR . '/wit-ai-requests.log';
$wpml    = WIT_WPML_Integration::instance();
$out     = array('source' => $page);

$widget = function (array $data, $id) {
    foreach ($data[0]['elements'] as $element) {
        if ($element['id'] === $id) {
            return $element['settings'];
        }
    }
    return array();
};

$inspect = function ($translation_id, $p) use ($widget) {
    $data = json_decode(get_post_meta($translation_id, '_elementor_data', true), true);
    $r    = array('valid_json' => is_array($data));
    if (!is_array($data)) {
        return $r;
    }

    $form     = $widget($data, 'pf00001');
    $price    = $widget($data, 'pp00001');
    $headline = $widget($data, 'ph00001');
    $count    = $widget($data, 'pd00001');
    $flip     = $widget($data, 'pb00001');
    $slides   = $widget($data, 'ps00001');
    $quote    = $widget($data, 'pq00001');
    $fields   = array();
    foreach ($form['form_fields'] as $f) {
        $fields[$f['custom_id'] ?? '?'] = $f;
    }

    // Form: what a visitor reads is translated…
    $r['form_labels']    = $fields['nombre']['field_label'] === $p . 'Tu nombre' && $fields['mensaje']['placeholder'] === $p . 'Cuéntanos tu proyecto';
    $r['form_messages']  = $form['success_message'] === $p . 'Gracias, te responderemos pronto.' && $form['required_field_message'] === $p . 'Este campo es obligatorio.';
    $r['form_button']    = $form['button_text'] === $p . 'Enviar mensaje';
    // …and what the form runs on is not.
    $r['submit_actions'] = $form['submit_actions'] === array('email', 'redirect');
    $r['required']       = $fields['nombre']['required'] === 'true' && $fields['email']['required'] === 'true';
    $r['custom_ids']     = array_keys($fields) === array('nombre', 'email', 'tema', 'mensaje', 'turno', 'acepto');
    $r['field_types']    = $fields['email']['field_type'] === 'email' && $fields['tema']['field_type'] === 'select';
    $r['email_setup']    = $form['email_to'] === 'info@imagina.example' && $form['email_from'] === 'web@imagina.example' && $form['email_content'] === '[all-fields]';
    $r['redirect']       = $form['redirect_to'] === 'https://imagina.example/gracias';
    $r['email_placeholder'] = $fields['email']['placeholder'] === 'nombre@empresa.com';
    // Options: labels translated, submitted values unchanged.
    $r['options_valued']   = $fields['tema']['field_options'] === $p . "Pedir presupuesto|presupuesto\n" . $p . 'Soporte técnico|soporte';
    $r['options_unvalued'] = $fields['turno']['field_options'] === $p . "Mañana|Mañana\n" . $p . 'Tarde|Tarde';

    $r['price_text']     = $price['heading'] === $p . 'Plan Profesional' && $price['period'] === $p . 'al mes' && $price['features_list'][0]['item_text'] === $p . 'Soporte prioritario';
    $r['currency']       = $price['currency_symbol'] === 'euro' && $price['price'] === '49' && $price['link']['url'] === 'https://imagina.example/contratar';

    $r['headline_words'] = $headline['rotating_text'] === $p . "diseñadores\n" . $p . "desarrolladores\n" . $p . 'estrategas';
    $r['headline_setup'] = $headline['marker'] === 'circle' && $headline['animation_type'] === 'typing' && $headline['headline_style'] === 'rotate';

    $r['countdown_text'] = $count['label_days'] === $p . 'Días' && $count['message_after_expire'] === $p . 'La oferta ha terminado';
    $r['countdown_setup'] = $count['due_date'] === '2027-01-01 12:00' && $count['expire_actions'] === array('message') && $count['countdown_type'] === 'due_date';

    $r['flip']           = $flip['title_text_b'] === $p . 'Empieza hoy' && $flip['flip_effect'] === 'flip' && $flip['flip_direction'] === 'up';
    $r['slides']         = $slides['slides'][1]['heading'] === $p . 'Resultados medibles' && $slides['slides'][1]['link']['url'] === 'https://imagina.example/casos' && $slides['slides'][1]['background_color'] === '#2271b1';
    $r['quote']          = $quote['author_name'] === $p . 'Cliente satisfecho' && $quote['tweet_button'] === 'yes' && $quote['blockquote_skin'] === 'border';

    $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($translation_id);
    $r['renders']        = strpos($html, $p . 'Plan Profesional') !== false && strpos($html, $p . 'Tu nombre') !== false;
    $r['render_options'] = strpos($html, 'value="presupuesto"') !== false && strpos($html, 'value="Mañana"') !== false;

    return $r;
};

// --- API ------------------------------------------------------------------
file_put_contents($ailog, '');
$api = (new WIT_Translation_Manager())->translate_post($page, 'en');
$out['api_success'] = !empty($api['success']);
$out['api_id']      = (int) $wpml->get_translation_id($page, 'en');
$out['api']         = $out['api_id'] ? $inspect($out['api_id'], '[EN] ') : array();

if (!function_exists('wp_get_ability')) {
    $out['mcp_skipped'] = true;
    echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
    return;
}

// --- MCP ------------------------------------------------------------------
file_put_contents($ailog, '');
$prepare = wp_get_ability('wit/prepare-translation')->execute(array('language' => 'fr', 'post_ids' => array($page)));
$texts   = is_wp_error($prepare) ? array() : array_column($prepare['strings'], 'text');

$out['mcp_no_technical'] = !array_intersect($texts, array('email', 'redirect', 'true', 'yes', 'euro', 'circle', 'message', 'presupuesto', 'soporte', 'nombre@empresa.com', 'info@imagina.example'));
$out['mcp_option_labels'] = in_array('Pedir presupuesto', $texts, true) && in_array('Mañana', $texts, true);
$out['mcp_headline_lines'] = in_array('desarrolladores', $texts, true) && !in_array("diseñadores\ndesarrolladores\nestrategas", $texts, true);

$translations = array();
foreach (is_wp_error($prepare) ? array() : $prepare['strings'] as $item) {
    $translations[$item['id']] = '[MCP-FR] ' . $item['text'];
}
$save = wp_get_ability('wit/save-translation')->execute(array('language' => 'fr', 'post_ids' => array($page), 'translations' => $translations));
$out['mcp_saved']        = is_wp_error($save) ? $save->get_error_message() : $save['results'][0]['status'];
$out['mcp_api_requests'] = count(array_filter(explode("\n", (string) file_get_contents($ailog))));
$out['mcp_id']           = (int) $wpml->get_translation_id($page, 'fr');
$out['mcp']              = $out['mcp_id'] ? $inspect($out['mcp_id'], '[MCP-FR] ') : array();

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
