<?php
/**
 * WIT_Field_Rules — decides which structured fields hold translatable text.
 *
 * A regression here sends technical tokens to the API. When the model
 * translates one, the block or widget breaks on the front end.
 */

$is_html = function ($value) {
    return strpos($value, '<') !== false && (bool) preg_match('/<[a-zA-Z!\/][^>]*>/', $value);
};

WIT_Tests::group('Field rules — atributos de bloques Gutenberg');

$attrs = array(
    'align'             => 'center',
    'className'         => 'is-style-outline hero-title',
    'tagName'           => 'main',
    'verticalAlignment' => 'top',
    'textColor'         => 'vivid-red',
    'fontSize'          => 'large',
    'layout'            => array('type' => 'flex', 'justifyContent' => 'space-between'),
    'url'               => 'https://example.com/img.png',
    'linkTarget'        => '_blank',
    'backgroundColor'   => '#ffffff',
    'orientation'       => 'horizontal',
    'metadata'          => array(
        'name'     => 'custom-heading',
        'bindings' => array('content' => array('source' => 'core/post-meta', 'args' => array('key' => 'my_field'))),
    ),
    // Genuine content, Greenshift style.
    'headingContent'    => 'Bienvenidos a nuestra web',
    'buttonContent'     => 'Saber más',
    'alt'               => 'Foto del equipo',
    'placeholder'       => 'Escribe tu correo',
);

$collected = array();
WIT_Field_Rules::collect($attrs, $collected, $is_html);

$must_not_send = array(
    'center', 'is-style-outline hero-title', 'main', 'top', 'vivid-red', 'large',
    'flex', 'space-between', '_blank', 'horizontal',
    'custom-heading', 'core/post-meta', 'my_field',
);

foreach ($must_not_send as $token) {
    WIT_Tests::ok(!isset($collected[$token]), 'nunca envía "' . $token . '"');
}

$must_send = array('Bienvenidos a nuestra web', 'Saber más', 'Foto del equipo', 'Escribe tu correo');

foreach ($must_send as $text) {
    WIT_Tests::ok(isset($collected[$text]), 'envía "' . $text . '"');
}

WIT_Tests::group('Field rules — ajustes de widgets Elementor');

$settings = array(
    'title'                       => 'Bienvenidos a nuestra web',
    'header_size'                 => 'h2',
    'align'                       => 'center',
    'size'                        => 'medium',
    '_element_id'                 => 'hero-title',
    'css_classes'                 => 'elementor-custom hero',
    'title_color'                 => '#FF0000',
    'typography_font_family'      => 'Roboto Slab',
    'link'                        => array('url' => 'https://x.com', 'is_external' => 'on'),
    '_animation'                  => 'fadeInUp',
    'hover_animation'             => 'grow',
    'button_type'                 => 'success',
    'text_shadow_text_shadow_type' => 'yes',
    '__globals__'                 => array('title_color' => 'globals/colors?id=primary'),
    'image'                       => array('url' => 'https://x.com/a.png', 'alt' => 'Foto de equipo', 'source' => 'library'),
    'icon_list'                   => array(
        array('text' => 'Primer elemento', 'selected_icon' => array('value' => 'fas fa-check', 'library' => 'fa-solid')),
    ),
);

$collected = array();
WIT_Field_Rules::collect($settings, $collected, $is_html);

$must_not_send = array(
    'h2', 'medium', 'hero-title', 'elementor-custom hero', 'Roboto Slab',
    'on', 'fadeInUp', 'grow', 'success', 'yes',
    'globals/colors?id=primary', 'library', 'fas fa-check',
);

foreach ($must_not_send as $token) {
    WIT_Tests::ok(!isset($collected[$token]), 'nunca envía "' . $token . '"');
}

foreach (array('Bienvenidos a nuestra web', 'Foto de equipo', 'Primer elemento') as $text) {
    WIT_Tests::ok(isset($collected[$text]), 'envía "' . $text . '"');
}

WIT_Tests::group('Field rules — aplicar no toca lo técnico');

$map = array(
    'Bienvenidos a nuestra web' => 'Welcome to our website',
    'Primer elemento'           => 'First item',
    // Deliberately poisoned: even if these somehow reached the map, the rules
    // must refuse to write them.
    'h2'                        => 'ROTO',
    'fadeInUp'                  => 'ROTO',
    'elementor-custom hero'     => 'ROTO',
);

$count  = 0;
$result = WIT_Field_Rules::apply($settings, $map, $is_html, $count);

WIT_Tests::same('Welcome to our website', $result['title'], 'traduce el título');
WIT_Tests::same('h2', $result['header_size'], 'header_size intacto');
WIT_Tests::same('fadeInUp', $result['_animation'], '_animation intacto');
WIT_Tests::same('elementor-custom hero', $result['css_classes'], 'css_classes intacto');
WIT_Tests::same('First item', $result['icon_list'][0]['text'], 'traduce dentro de repetidores');
WIT_Tests::same('fas fa-check', $result['icon_list'][0]['selected_icon']['value'], 'el icono intacto');

WIT_Tests::group('Field rules — poda de subárbol');

// selected_icon is blocked, so its child key "value" must not be judged alone.
WIT_Tests::ok(WIT_Field_Rules::is_blocked_key('selected_icon'), 'selected_icon está bloqueada');
WIT_Tests::ok(WIT_Field_Rules::is_blocked_key('metadata'), 'metadata está bloqueada');
WIT_Tests::ok(WIT_Field_Rules::is_blocked_key('_animation'), 'las claves con _ inicial están bloqueadas');
WIT_Tests::ok(!WIT_Field_Rules::is_blocked_key('title'), 'title no está bloqueada');
WIT_Tests::ok(!WIT_Field_Rules::is_blocked_key('editor'), 'editor no está bloqueada');

WIT_Tests::group('Field rules — valores que nunca son prosa');

// Found translating an Elementor Pro page: a key that merely sounds like
// content let switcher states and e-mail addresses through.
foreach (array('yes', 'no', 'true', 'false', 'on', 'off', 'YES') as $token) {
    WIT_Tests::ok(!WIT_Field_Rules::is_translatable_field('tweet_button', $token), '"' . $token . '" nunca se traduce, aunque la clave suene a contenido');
}
WIT_Tests::ok(!WIT_Field_Rules::is_translatable_field('placeholder', 'nombre@empresa.com'), 'una dirección de email no se traduce');
WIT_Tests::ok(WIT_Field_Rules::is_translatable_field('placeholder', 'Escribe tu nombre'), 'un placeholder normal sí');
WIT_Tests::ok(WIT_Field_Rules::is_translatable_field('title', 'Yes we can'), '"yes" dentro de una frase no se confunde con un interruptor');

WIT_Tests::group('Elementor — opciones de formulario y listas por línea');

$handler = new WIT_Elementor_Handler();
$form    = array(array('elType' => 'widget', 'widgetType' => 'form', 'id' => 'f1', 'settings' => array(
    'form_fields' => array(
        array('custom_id' => 'tema', 'field_options' => "Pedir presupuesto|presupuesto\nSoporte técnico|soporte"),
        array('custom_id' => 'turno', 'field_options' => "Mañana\nTarde"),
    ),
    'rotating_text' => "diseñadores\ndesarrolladores\nestrategas",
)));

$originals = array();
WIT_Tests::call($handler, 'collect_elements', array($form, &$originals));
$collected = array_keys($originals);

WIT_Tests::ok(in_array('Pedir presupuesto', $collected, true) && in_array('Soporte técnico', $collected, true), 'se recogen las etiquetas de las opciones');
WIT_Tests::ok(!in_array('presupuesto', $collected, true) && !in_array('soporte', $collected, true), 'los valores de las opciones NO se recogen');
WIT_Tests::ok(!in_array("Pedir presupuesto|presupuesto\nSoporte técnico|soporte", $collected, true), 'el bloque de opciones no se manda entero');
WIT_Tests::ok(in_array('desarrolladores', $collected, true), 'rotating_text: cada línea es una cadena');

$map = array();
foreach ($collected as $text) {
    $map[$text] = 'EN ' . $text;
}
$map['Tarde'] = 'After|noon'; // a translation that happens to contain the separator

$applied = WIT_Tests::call($handler, 'apply_elements', array($form, $map));
$fields  = $applied[0]['settings']['form_fields'];

WIT_Tests::same("EN Pedir presupuesto|presupuesto\nEN Soporte técnico|soporte", $fields[0]['field_options'], 'con valor: se traduce la etiqueta y el valor se conserva');
WIT_Tests::same("EN Mañana|Mañana\nAfternoon|Tarde", $fields[1]['field_options'], 'sin valor: se fija el original como valor, lo enviado no cambia de idioma');
WIT_Tests::same("EN diseñadores\nEN desarrolladores\nEN estrategas", $applied[0]['settings']['rotating_text'], 'rotating_text: el número de líneas no cambia');
