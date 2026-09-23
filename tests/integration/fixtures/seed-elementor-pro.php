<?php
/**
 * Seed an Elementor Pro page. Only run when Elementor Pro is active; Pro is
 * not free, so CI never has it and this stays a local test.
 *
 * Each widget carries values that look like text but are not, and that break
 * something silently if translated:
 *
 *   form         custom_id (the [field id] shortcodes and integrations key on
 *                it), field_type, the value half of "Label|value" options,
 *                email addresses, the redirect URL, [all-fields]
 *   price-table  currency_symbol (a select), price, the button link
 *   headline     rotating_text is one entry per line: the line count is the
 *                animation, and marker / animation_type are selects
 *   countdown    due_date, countdown_type, expire_actions
 *   flip-box     flip_effect, flip_direction
 *   slides       repeater ids, link URLs, background colours
 *
 * Run with: wp eval-file seed-elementor-pro.php
 */

if (!defined('ELEMENTOR_PRO_VERSION')) {
    echo "SKIP: Elementor Pro no está activo\n";
    return;
}

wp_set_current_user(1);

$page_id = wp_insert_post(array(
    'post_title'  => 'Página Elementor Pro',
    'post_status' => 'publish',
    'post_type'   => 'page',
));

$widget = function ($id, $type, array $settings) {
    return array('id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array());
};

$elements = array(array(
    'id'       => 'pc00001',
    'elType'   => 'container',
    'settings' => array('content_width' => 'boxed'),
    'elements' => array(
        $widget('pf00001', 'form', array(
            'form_name'   => 'Formulario de contacto',
            'form_fields' => array(
                array('_id' => 'ff00001', 'custom_id' => 'nombre', 'field_type' => 'text', 'field_label' => 'Tu nombre', 'placeholder' => 'Escribe tu nombre', 'required' => 'true', 'width' => '100'),
                array('_id' => 'ff00002', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Correo electrónico', 'placeholder' => 'nombre@empresa.com', 'required' => 'true', 'width' => '100'),
                array('_id' => 'ff00003', 'custom_id' => 'tema', 'field_type' => 'select', 'field_label' => 'Tema', 'field_options' => "Pedir presupuesto|presupuesto\nSoporte técnico|soporte", 'width' => '100'),
                array('_id' => 'ff00004', 'custom_id' => 'mensaje', 'field_type' => 'textarea', 'field_label' => 'Mensaje', 'placeholder' => 'Cuéntanos tu proyecto', 'rows' => '4', 'width' => '100'),
                // No explicit values: Elementor submits the label itself.
                array('_id' => 'ff00006', 'custom_id' => 'turno', 'field_type' => 'radio', 'field_label' => 'Horario preferido', 'field_options' => "Mañana\nTarde", 'width' => '100'),
                array('_id' => 'ff00005', 'custom_id' => 'acepto', 'field_type' => 'acceptance', 'acceptance_text' => 'Acepto la política de privacidad', 'width' => '100'),
            ),
            'button_text'            => 'Enviar mensaje',
            'submit_actions'         => array('email', 'redirect'),
            'email_to'               => 'info@imagina.example',
            'email_subject'          => 'Nuevo mensaje desde la web',
            'email_content'          => '[all-fields]',
            'email_from'             => 'web@imagina.example',
            'email_from_name'        => 'Imagina',
            'redirect_to'            => 'https://imagina.example/gracias',
            'custom_messages'        => 'yes',
            'success_message'        => 'Gracias, te responderemos pronto.',
            'error_message'          => 'Ha ocurrido un error, inténtalo de nuevo.',
            'required_field_message' => 'Este campo es obligatorio.',
            'invalid_message'        => 'El formato no es válido.',
        )),
        $widget('pp00001', 'price-table', array(
            'heading'                => 'Plan Profesional',
            'sub_heading'            => 'Para agencias en crecimiento',
            'currency_symbol'        => 'euro',
            'price'                  => '49',
            'period'                 => 'al mes',
            'features_list'          => array(
                array('_id' => 'pl00001', 'item_text' => 'Soporte prioritario', 'selected_item_icon' => array('value' => 'far fa-check-circle', 'library' => 'fa-regular')),
                array('_id' => 'pl00002', 'item_text' => 'Diez proyectos activos', 'selected_item_icon' => array('value' => 'far fa-check-circle', 'library' => 'fa-regular')),
            ),
            'button_text'            => 'Contratar ahora',
            'link'                   => array('url' => 'https://imagina.example/contratar', 'is_external' => '', 'nofollow' => ''),
            'footer_additional_info' => 'Sin permanencia',
            'show_ribbon'            => 'yes',
            'ribbon_title'           => 'Popular',
        )),
        $widget('ph00001', 'animated-headline', array(
            'headline_style' => 'rotate',
            'animation_type' => 'typing',
            'marker'         => 'circle',
            'before_text'    => 'Somos',
            'rotating_text'  => "diseñadores\ndesarrolladores\nestrategas",
            'after_text'     => 'en Madrid',
            'tag'            => 'h3',
        )),
        $widget('pd00001', 'countdown', array(
            'countdown_type'       => 'due_date',
            'due_date'             => '2027-01-01 12:00',
            'custom_labels'        => 'yes',
            'label_days'           => 'Días',
            'label_hours'          => 'Horas',
            'label_minutes'        => 'Minutos',
            'label_seconds'        => 'Segundos',
            'expire_actions'       => array('message'),
            'message_after_expire' => 'La oferta ha terminado',
        )),
        $widget('pb00001', 'flip-box', array(
            'title_text_a'       => 'Diseño a medida',
            'description_text_a' => 'Cada web es única.',
            'title_text_b'       => 'Empieza hoy',
            'description_text_b' => 'Te acompañamos desde el primer boceto.',
            'button_text'        => 'Saber más',
            'link'               => array('url' => 'https://imagina.example/diseno', 'is_external' => '', 'nofollow' => ''),
            'flip_effect'        => 'flip',
            'flip_direction'     => 'up',
        )),
        $widget('ps00001', 'slides', array(
            'slides' => array(
                array('_id' => 'sl00001', 'heading' => 'Tu marca, en todas partes', 'description' => 'Diseño, desarrollo y marketing.', 'button_text' => 'Ver servicios', 'link' => array('url' => 'https://imagina.example/servicios'), 'background_color' => '#1d2327'),
                array('_id' => 'sl00002', 'heading' => 'Resultados medibles', 'description' => 'Informes cada mes.', 'button_text' => 'Casos de éxito', 'link' => array('url' => 'https://imagina.example/casos'), 'background_color' => '#2271b1'),
            ),
            'slides_height' => array('unit' => 'px', 'size' => 400),
        )),
        $widget('pq00001', 'blockquote', array(
            'blockquote_skin'    => 'border',
            'blockquote_content' => 'La mejor agencia con la que hemos trabajado.',
            'author_name'        => 'Cliente satisfecho',
            'tweet_button'       => 'yes',
            'tweet_button_label' => 'Tuitear',
        )),
    ),
));

$document = \Elementor\Plugin::$instance->documents->get($page_id, false);
if ($document) {
    $document->set_is_built_with_elementor(true);
}
$saved = $document ? $document->save(array('elements' => $elements)) : false;

$fixture                        = get_option('wit_test_fixture');
$fixture['elementor_pro_page']  = (int) $page_id;
update_option('wit_test_fixture', $fixture, false);

echo 'ELEMENTOR_PRO_PAGE=' . $page_id . ' guardada=' . var_export((bool) $saved, true)
    . ' modo=' . get_post_meta($page_id, '_elementor_edit_mode', true) . "\n";
