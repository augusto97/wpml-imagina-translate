<?php
/**
 * Seed hostile test content. Run with: wp eval-file seed.php
 */

// wp eval-file runs this inside a function scope.
global $wpdb;

// Force term_id != term_taxonomy_id for the terms created below.
//
// This is the single most important thing this fixture does. WPML keys
// taxonomy rows on term_taxonomy_id, but the plugin used to pass term_id.
// On a site where the two counters happen to match, that bug is invisible —
// and they match on any site that has never lost a row. Deleting a term is
// not enough: it removes both rows, so both counters advance together.
//
// Rows in wp_terms with no wp_term_taxonomy counterpart is what actually
// happens in the wild (failed imports, plugins deleting taxonomy rows
// directly, botched migrations) and it is what pushes the two apart.
$wpdb->query("INSERT INTO {$wpdb->terms} (name, slug) VALUES ('huerfano-1','huerfano-1'),('huerfano-2','huerfano-2'),('huerfano-3','huerfano-3')");

$parent = wp_insert_term('Servicios', 'category', array('description' => 'Todo lo que ofrecemos'));
$child  = wp_insert_term('Diseño web', 'category', array('parent' => $parent['term_id'], 'description' => 'Páginas a medida'));
$tag    = wp_insert_term('Imagina', 'post_tag');

// Featured image: a real attachment row (no file needed for this test).
$attachment_id = wp_insert_attachment(array(
    'post_title'     => 'Foto del equipo',
    'post_mime_type' => 'image/png',
    'post_status'    => 'inherit',
), '/tmp/equipo.png');
update_post_meta($attachment_id, '_wp_attachment_image_alt', 'El equipo de Imagina');

$content = <<<'HTML'
<!-- wp:heading {"level":2,"className":"is-style-default hero-title","textColor":"vivid-red"} -->
<h2 class="wp-block-heading is-style-default hero-title has-vivid-red-color has-text-color">Bienvenidos a Imagina</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size">Café con leche &amp; churros — desde 1999.<br>Segunda línea del mismo párrafo.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Hola&nbsp;mundo con <a href="https://imagina.example/servicios?ref=center" title="Ver servicios" class="btn center">enlace</a> y <strong>negrita</strong>. 5 &lt; 10 &gt; 2.</p>
<!-- /wp:paragraph -->

<!-- wp:cover {"url":"https://imagina.example/hero.png","id":42,"alt":"Foto del equipo","dimRatio":50,"layout":{"type":"constrained"}} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-42" alt="Foto del equipo" src="https://imagina.example/hero.png" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","placeholder":"Escribe un título…"} -->
<p class="has-text-align-center">Nuestro equipo</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Primer servicio</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Segundo servicio &mdash; el mejor</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vivid-red","className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-vivid-red-background-color has-background wp-element-button" href="https://imagina.example/contacto" target="_blank" rel="noreferrer noopener">Contacta con nosotros</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:group {"metadata":{"name":"Bloque destacado"},"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>La mejor agencia con la que hemos trabajado.</p>
<!-- /wp:paragraph --><cite>Cliente satisfecho</cite></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:group -->

<!-- wp:html -->
<div class="custom" style="color:#ff0000">Bloque HTML <em>libre</em></div>
<!-- /wp:html -->

<!-- wp:shortcode -->
[gallery ids="1,2,3"]
<!-- /wp:shortcode -->

<!-- wp:image {"id":42,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://imagina.example/hero.png" alt="Oficina de Imagina" class="wp-image-42"/><figcaption class="wp-element-caption">Nuestra oficina en Madrid</figcaption></figure>
<!-- /wp:image -->
HTML;

$post_id = wp_insert_post(array(
    'post_title'   => 'Servicios de Imagina',
    'post_content' => $content,
    'post_excerpt' => 'Resumen de nuestros servicios.',
    'post_status'  => 'publish',
    'post_type'    => 'post', // posts carry category/post_tag; pages do not
    'post_name'    => 'servicios',
));

wp_set_object_terms($post_id, array($parent['term_id'], $child['term_id']), 'category');
wp_set_object_terms($post_id, array($tag['term_id']), 'post_tag');
set_post_thumbnail($post_id, $attachment_id);
update_post_meta($post_id, '_yoast_wpseo_title', 'Servicios | Imagina');
update_post_meta($post_id, '_yoast_wpseo_metadesc', 'Diseño web y marketing en Madrid.');
update_post_meta($post_id, '_custom_technical', 'do-not-touch-me');

// A second, plain post that shares strings with the page, so memory reuse
// can be observed.
$post2_id = wp_insert_post(array(
    'post_title'   => 'Otra página',
    'post_content' => "<!-- wp:paragraph -->\n<p>Contacta con nosotros</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Bienvenidos a Imagina</p>\n<!-- /wp:paragraph -->",
    'post_status'  => 'publish',
    'post_type'    => 'page',
));

// Register 'page' for categories so the taxonomy path is exercised on pages.
// A second root category, attached to the same post.
$marketing = wp_insert_term('Marketing', 'category', array('description' => 'Campañas y redes'));
wp_set_object_terms($post_id, array($marketing['term_id']), 'category', true);

// Record what was seeded, so nothing downstream has to hardcode an id.
// The ids shift whenever this fixture changes, and the drift above guarantees
// they are not the obvious ones.
update_option('wit_test_fixture', array(
    'post'          => (int) $post_id,
    'page'          => (int) $post2_id,
    'attachment'    => (int) $attachment_id,
    'cat_parent'    => (int) $parent['term_id'],
    'cat_child'     => (int) $child['term_id'],
    'cat_marketing' => (int) $marketing['term_id'],
    'tag'           => (int) $tag['term_id'],
), false);

echo "PAGE_ID={$post_id}\nPOST2_ID={$post2_id}\nATTACHMENT_ID={$attachment_id}\n";
echo "CAT_PARENT term_id={$parent['term_id']} tt_id={$parent['term_taxonomy_id']}\n";
echo "CAT_CHILD  term_id={$child['term_id']} tt_id={$child['term_taxonomy_id']}\n";
echo "TAG        term_id={$tag['term_id']} tt_id={$tag['term_taxonomy_id']}\n";
echo "MARKETING  term_id={$marketing['term_id']} tt_id={$marketing['term_taxonomy_id']}\n";

// A fixture that quietly stopped producing the drift would make the taxonomy
// regression test vacuous, so fail loudly instead.
if ((int) $marketing['term_id'] === (int) $marketing['term_taxonomy_id']) {
    echo "\nERROR: term_id y term_taxonomy_id coinciden; la semilla no reproduce el escenario.\n";
    exit(1);
}
echo "OK: term_id y term_taxonomy_id divergen (el escenario del fallo de taxonomías)\n";
