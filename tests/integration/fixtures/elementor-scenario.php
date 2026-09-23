<?php
/**
 * An Elementor page translated both ways: through the API (fake provider,
 * "[EN] ") and through MCP (simulated chat, "[MCP-FR] ").
 *
 * For each translation: every visible text is translated, every technical
 * setting is untouched, the data is still valid for Elementor, and Elementor
 * itself renders the translated page.
 *
 * Run with: wp eval-file elementor-scenario.php  (prints one JSON object)
 */

wp_set_current_user(1);

if (!class_exists('\Elementor\Plugin') || !\Elementor\Plugin::$instance) {
    echo wp_json_encode(array('skipped' => 'Elementor no está activo'));
    return;
}

$fixture = get_option('wit_test_fixture');
$page    = (int) $fixture['elementor_page'];
$ailog   = WP_CONTENT_DIR . '/wit-ai-requests.log';
$out     = array();
$wpml    = WIT_WPML_Integration::instance();

$find = function (array $elements, $id) use (&$find) {
    foreach ($elements as $element) {
        if (isset($element['id']) && $element['id'] === $id) {
            return $element;
        }
        if (!empty($element['elements'])) {
            $hit = $find($element['elements'], $id);
            if ($hit) {
                return $hit;
            }
        }
    }
    return null;
};

/**
 * Inspect one translated Elementor page.
 */
$inspect = function ($translation_id, $prefix) use ($find) {
    $r    = array();
    $raw  = get_post_meta($translation_id, '_elementor_data', true);
    $data = json_decode(is_string($raw) ? $raw : wp_json_encode($raw), true);

    $r['builder_mode'] = get_post_meta($translation_id, '_elementor_edit_mode', true) === 'builder';
    $r['valid_json']   = is_array($data);

    if (!is_array($data)) {
        return $r;
    }

    $heading = $find($data, 'h1a2b3c')['settings'];
    $editor  = $find($data, 't1a2b3c')['settings'];
    $button  = $find($data, 'b1a2b3c')['settings'];
    $list    = $find($data, 'l1a2b3c')['settings'];
    $image   = $find($data, 'm1a2b3c')['settings'];
    $atomic  = $find($data, 'e1a2b3c')['settings'];

    // Visible text: translated.
    $r['heading']   = $heading['title'] === $prefix . 'Bienvenidos a Imagina';
    $r['editor']    = strpos($editor['editor'], $prefix . 'Café con leche') !== false && strpos($editor['editor'], $prefix . 'desde 1999') !== false;
    $r['button']    = $button['text'] === 'Get in touch'; // glossary rule, all languages
    $r['list']      = $list['icon_list'][0]['text'] === $prefix . 'Primer servicio' && $list['icon_list'][1]['text'] === $prefix . 'Segundo servicio';
    $r['alt']       = $image['image']['alt'] === $prefix . 'Oficina de Imagina';
    $r['caption']   = $image['caption'] === $prefix . 'Nuestra oficina en Madrid';
    $r['atomic']    = $atomic['title']['value']['content']['value'] === $prefix . 'Título atómico';

    // Structure and technical settings: untouched.
    $r['editor_markup']   = strpos($editor['editor'], '<strong>') !== false && substr_count($editor['editor'], '<p>') === 2;
    $r['header_size']     = $heading['header_size'] === 'h2';
    $r['css_and_ids']     = $heading['_css_classes'] === 'hero-title' && $heading['_element_id'] === 'hero' && $heading['title_color'] === '#FF0000';
    $r['link_and_anim']   = $button['link']['url'] === 'https://imagina.example/contacto' && $button['_animation'] === 'fadeInUp' && $button['button_type'] === 'success';
    $r['icons']           = $list['icon_list'][0]['selected_icon']['value'] === 'fas fa-check' && $list['icon_list'][0]['_id'] === 'aa11bb2';
    $r['image_url']       = $image['image']['url'] === 'https://imagina.example/oficina.png' && $image['caption_source'] === 'custom';
    $r['atomic_tag']      = $atomic['tag'] === array('$$type' => 'string', 'value' => 'h3');
    $r['atomic_wrappers'] = $atomic['title']['$$type'] === 'html-v3' && $atomic['title']['value']['content']['$$type'] === 'string';
    $r['element_ids']     = (bool) $find($data, 'c1a2b3c');

    // Elementor itself: loads the document and renders the translation.
    \Elementor\Plugin::$instance->documents->get($translation_id, false);
    $document = \Elementor\Plugin::$instance->documents->get($translation_id, false);
    $r['document_loads'] = $document && is_array($document->get_elements_data());

    $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($translation_id);
    $r['renders']            = strlen($html) > 500;
    $r['render_translated']  = strpos($html, $prefix . 'Bienvenidos a Imagina') !== false
        && strpos($html, $prefix . 'Título atómico') !== false
        && strpos($html, 'Get in touch') !== false;
    $r['render_no_source']   = strpos($html, '>Primer servicio<') === false && strpos($html, '>Contacta con nosotros<') === false;
    $r['render_link']        = strpos($html, 'href="https://imagina.example/contacto"') !== false;

    return $r;
};

// --- 1. Through the API ----------------------------------------------------
file_put_contents($ailog, '');
$api = (new WIT_Translation_Manager())->translate_post($page, 'en');
$out['api_success']  = !empty($api['success']);
$out['api_message']  = isset($api['message']) ? $api['message'] : '';
$out['api_requests'] = count(array_filter(explode("\n", (string) file_get_contents($ailog))));
$en = (int) $wpml->get_translation_id($page, 'en');
$out['api'] = $en ? $inspect($en, '[EN] ') : array('no_translation' => true);

if (!function_exists('wp_get_ability')) {
    // No Abilities API (WordPress < 6.9): only the API path applies.
    $out['mcp_skipped'] = true;
    echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
    return;
}

// --- 2. Through MCP ----------------------------------------------------------
file_put_contents($ailog, '');
$prepare  = wp_get_ability('wit/prepare-translation')->execute(array('language' => 'fr', 'post_ids' => array($page)));
$strings  = is_wp_error($prepare) ? array() : $prepare['strings'];
$texts    = array_column($strings, 'text');
$contexts = array_values(array_unique(array_column($strings, 'context')));

$out['mcp_prepare_ok']     = !is_wp_error($prepare);
$out['mcp_contexts']       = $contexts;
$out['mcp_has_widgets']    = in_array('Primer servicio', $texts, true) && in_array('Título atómico', $texts, true) && in_array('Oficina de Imagina', $texts, true);
$out['mcp_no_html']        = count(array_filter($texts, function ($t) { return (bool) preg_match('/<[a-z\/!][^>]*>/i', $t); })) === 0;
$out['mcp_no_technical']   = !array_intersect($texts, array('h2', 'h3', 'fadeInUp', 'fas fa-check', 'hero-title', 'success', 'center', 'custom', 'boxed'));
$out['mcp_glossary_skip']  = !in_array('Contacta con nosotros', $texts, true);

$translations = array();
foreach ($strings as $item) {
    $translations[$item['id']] = '[MCP-FR] ' . $item['text'];
}
$save = wp_get_ability('wit/save-translation')->execute(array('language' => 'fr', 'post_ids' => array($page), 'translations' => $translations));
$out['mcp_saved']        = is_wp_error($save) ? $save->get_error_message() : $save['results'][0]['status'];
$out['mcp_no_warning']   = !is_wp_error($save) && empty($save['results'][0]['warning']);
$out['mcp_api_requests'] = count(array_filter(explode("\n", (string) file_get_contents($ailog))));
$fr = (int) $wpml->get_translation_id($page, 'fr');
$out['mcp'] = $fr ? $inspect($fr, '[MCP-FR] ') : array('no_translation' => true);

// --- 3. Freshness, reviewing and correcting on Elementor --------------------
$out['status_current'] = WIT_Translation_Status::of($page, 'fr')['status'];

$review = wp_get_ability('wit/get-translation')->execute(array('post_id' => $page, 'language' => 'fr'));
$out['review_lists_widgets'] = !is_wp_error($review) && in_array('[MCP-FR] Primer servicio', $review['translation']['strings'], true);

$fix = wp_get_ability('wit/correct-translation')->execute(array(
    'post_id' => $page, 'language' => 'fr',
    'current_text' => '[MCP-FR] Primer servicio', 'new_text' => 'Premier service',
));
$out['correct_ok'] = !is_wp_error($fix) && in_array('elementor', $fix['changed'], true);
$after = json_decode(get_post_meta($fr, '_elementor_data', true), true);
$items = $find($after, 'l1a2b3c')['settings']['icon_list'];
$out['correct_applied']   = $items[0]['text'] === 'Premier service';
$out['correct_rest_kept'] = $items[1]['text'] === '[MCP-FR] Segundo servicio' && $items[0]['selected_icon']['value'] === 'fas fa-check';
$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($fr);
$out['correct_renders']   = strpos($html, 'Premier service') !== false;

// Edit the original through Elementor, as the editor would.
$source_doc = \Elementor\Plugin::$instance->documents->get($page, false);
$data       = $source_doc->get_elements_data();
$data[0]['elements'][0]['settings']['title'] = 'Bienvenidos otra vez';
$source_doc->save(array('elements' => $data));
$out['status_outdated_after_edit'] = WIT_Translation_Status::of($page, 'fr')['status'];

$again = wp_get_ability('wit/prepare-translation')->execute(array('language' => 'fr', 'post_ids' => array($page)));
$out['retranslate_only_changed'] = !is_wp_error($again)
    && array_column($again['strings'], 'text') === array('Bienvenidos otra vez');

// Put the original back for the next run.
$data[0]['elements'][0]['settings']['title'] = 'Bienvenidos a Imagina';
$source_doc->save(array('elements' => $data));

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
