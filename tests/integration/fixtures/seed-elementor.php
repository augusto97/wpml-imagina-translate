<?php
/**
 * Seed an Elementor page, saved through Elementor's own document API the way
 * the editor saves it. Only run when Elementor is active.
 *
 * Every widget mixes text that must be translated with settings that must
 * not be: header_size, colours, CSS classes, element ids, animations, link
 * URLs, icon names, the atomic widget's tag. A translation that touches any
 * of those breaks the page.
 *
 * Run with: wp eval-file seed-elementor.php
 */

if (!class_exists('\Elementor\Plugin') || !\Elementor\Plugin::$instance) {
    echo "SKIP: Elementor no está activo\n";
    return;
}

wp_set_current_user(1);

$page_id = wp_insert_post(array(
    'post_title'  => 'Página Elementor',
    'post_status' => 'publish',
    'post_type'   => 'page',
));

$elements = array(
    array(
        'id'       => 'c1a2b3c',
        'elType'   => 'container',
        'settings' => array('content_width' => 'boxed', 'flex_direction' => 'column'),
        'elements' => array(
            array(
                'id'         => 'h1a2b3c',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => array(
                    'title'        => 'Bienvenidos a Imagina',
                    'header_size'  => 'h2',
                    'align'        => 'center',
                    'title_color'  => '#FF0000',
                    '_css_classes' => 'hero-title',
                    '_element_id'  => 'hero',
                ),
                'elements'   => array(),
            ),
            array(
                'id'         => 't1a2b3c',
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => array(
                    'editor' => '<p>Café con leche &amp; churros — <strong>desde 1999</strong>.</p><p>Segunda línea del texto</p>',
                ),
                'elements'   => array(),
            ),
            array(
                'id'         => 'b1a2b3c',
                'elType'     => 'widget',
                'widgetType' => 'button',
                'settings'   => array(
                    'text'        => 'Contacta con nosotros',
                    'link'        => array('url' => 'https://imagina.example/contacto', 'is_external' => 'on', 'nofollow' => ''),
                    'button_type' => 'success',
                    'size'        => 'md',
                    '_animation'  => 'fadeInUp',
                ),
                'elements'   => array(),
            ),
            array(
                'id'         => 'l1a2b3c',
                'elType'     => 'widget',
                'widgetType' => 'icon-list',
                'settings'   => array(
                    'icon_list' => array(
                        array('_id' => 'aa11bb2', 'text' => 'Primer servicio', 'selected_icon' => array('value' => 'fas fa-check', 'library' => 'fa-solid')),
                        array('_id' => 'cc33dd4', 'text' => 'Segundo servicio', 'selected_icon' => array('value' => 'fas fa-star', 'library' => 'fa-solid')),
                    ),
                ),
                'elements'   => array(),
            ),
            array(
                'id'         => 'm1a2b3c',
                'elType'     => 'widget',
                'widgetType' => 'image',
                'settings'   => array(
                    'image'          => array('url' => 'https://imagina.example/oficina.png', 'id' => '', 'alt' => 'Oficina de Imagina', 'source' => 'library'),
                    'caption_source' => 'custom',
                    'caption'        => 'Nuestra oficina en Madrid',
                ),
                'elements'   => array(),
            ),
            array(
                'id'         => 'e1a2b3c',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'version'    => '0.4',
                'settings'   => array(
                    'tag'   => array('$$type' => 'string', 'value' => 'h3'),
                    'title' => array('$$type' => 'html-v3', 'value' => array(
                        'content'  => array('$$type' => 'string', 'value' => 'Título atómico'),
                        'children' => array(),
                    )),
                ),
                'elements'   => array(),
            ),
        ),
    ),
);

$document = \Elementor\Plugin::$instance->documents->get($page_id, false);
// What the editor does on first save: without it the post is not "built with
// Elementor", the front end ignores _elementor_data and nothing renders.
if ($document) {
    $document->set_is_built_with_elementor(true);
}
$saved    = $document ? $document->save(array('elements' => $elements)) : false;

$fixture                    = get_option('wit_test_fixture');
$fixture['elementor_page']  = (int) $page_id;
update_option('wit_test_fixture', $fixture, false);

echo 'ELEMENTOR_PAGE=' . $page_id . ' guardada=' . var_export((bool) $saved, true)
    . ' modo=' . get_post_meta($page_id, '_elementor_edit_mode', true) . "\n";
