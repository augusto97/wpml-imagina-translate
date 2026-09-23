<?php
/**
 * Return the install to the state setup.sh left it in, so run.sh is
 * repeatable without rebuilding WordPress.
 *
 * Deletes every translation the plugin produced and re-registers the Spanish
 * sources with WPML. Dropping icl_translations is the only reliable way to
 * clear the stub's link table, and the stub recreates it on construction.
 *
 * Run with: wp eval-file reset.php
 */

global $wpdb;

$fixture = get_option('wit_test_fixture');

if (!is_array($fixture) || empty($fixture['post'])) {
    echo "ERROR: falta wit_test_fixture; ejecuta setup.sh primero.\n";
    exit(1);
}

$keep_posts = array((int) $fixture['post'], (int) $fixture['page'], (int) $fixture['attachment']);
$keep_terms = array(
    (int) $fixture['cat_parent'],
    (int) $fixture['cat_child'],
    (int) $fixture['cat_marketing'],
    (int) $fixture['tag'],
);

// Everything the plugin created is, by construction, newer than the fixture.
$max_post = max($keep_posts);
$max_term = max($keep_terms);

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('post','page')",
    $max_post
));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id > %d", $max_post));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->term_relationships} WHERE object_id > %d", $max_post));
$wpdb->query($wpdb->prepare(
    "DELETE tt, t FROM {$wpdb->term_taxonomy} tt
     JOIN {$wpdb->terms} t USING(term_id) WHERE t.term_id > %d",
    $max_term
));

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}icl_translations");
$wpdb->query("TRUNCATE {$wpdb->prefix}wit_translation_memory");
$wpdb->query("TRUNCATE {$wpdb->prefix}wit_queue");
$wpdb->query("TRUNCATE {$wpdb->prefix}wit_translation_logs");

// This process still holds the dropped table; the stub recreates it here.
new WIT_WPML_Stub();

clean_term_cache($keep_terms);
clean_post_cache((int) $fixture['post']);
clean_post_cache((int) $fixture['page']);

foreach (array($fixture['post'] => 'post', $fixture['page'] => 'page') as $id => $type) {
    do_action('wpml_set_element_language_details', array(
        'element_id'           => (int) $id,
        'element_type'         => 'post_' . $type,
        'trid'                 => null,
        'language_code'        => 'es',
        'source_language_code' => null,
    ));
}

$taxonomies = array(
    $fixture['cat_parent']    => 'category',
    $fixture['cat_child']     => 'category',
    $fixture['cat_marketing'] => 'category',
    $fixture['tag']           => 'post_tag',
);

foreach ($taxonomies as $term_id => $taxonomy) {
    $term = get_term((int) $term_id, $taxonomy);

    if (!$term || is_wp_error($term)) {
        continue;
    }

    do_action('wpml_set_element_language_details', array(
        // WPML keys taxonomies on term_taxonomy_id, never term_id. The two
        // differ here on purpose — see seed.php.
        'element_id'           => (int) $term->term_taxonomy_id,
        'element_type'         => 'tax_' . $taxonomy,
        'trid'                 => null,
        'language_code'        => 'es',
        'source_language_code' => null,
    ));
}

// The MCP scenario edits the first post's excerpt to make it outdated. Without
// this, a second run on the same install would "edit" it to the value it
// already has, nothing would change, and the outdated check would fail for a
// reason that has nothing to do with the plugin.
wp_update_post(array(
    'ID'           => (int) $fixture['post'],
    'post_title'   => 'Servicios de Imagina',
    'post_excerpt' => 'Resumen de nuestros servicios.',
));

// The hostile-mode section overwrites the second post; restore it with strings
// it shares with the first, so translation-memory reuse is observable.
wp_update_post(array(
    'ID'           => (int) $fixture['page'],
    'post_title'   => 'Otra página',
    'post_content' => "<!-- wp:paragraph -->\n<p>Contacta con nosotros</p>\n<!-- /wp:paragraph -->\n\n"
                    . "<!-- wp:paragraph -->\n<p>Bienvenidos a Imagina</p>\n<!-- /wp:paragraph -->",
));

update_option('wit_fake_mode', 'normal');
delete_transient('wit_fake_429_sent');

echo "Estado reiniciado\n";
