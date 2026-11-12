<?php
/**
 * Complete WPML's setup wizard from code, the way its last step does:
 * Spanish as the default language, English and French as translations —
 * the same languages the WPML test double offers, so every section of the
 * suite runs unchanged against the real WPML.
 *
 * Run once, right after activating WPML: wp eval-file wpml-setup.php
 */

wp_set_current_user(1);

if (!function_exists('wpml_get_setup_instance')) {
    echo "ERROR: WPML no está activo\n";
    exit(1);
}

\WPML\Setup\Option::setOriginalLang('es');
\WPML\Setup\Option::setTranslationLangs(array('en', 'fr'));

$setup = wpml_get_setup_instance();
$setup->finish_step1('es');
$setup->finish_step2(array('es', 'en', 'fr'));
$setup->finish_installation();

global $sitepress;

$active = array_keys((array) apply_filters('wpml_active_languages', null, array('skip_missing' => 0)));
sort($active);

echo 'WPML ' . ICL_SITEPRESS_VERSION
    . ' | ST ' . (defined('WPML_ST_VERSION') ? WPML_ST_VERSION : 'no')
    . ' | setup_complete=' . var_export((bool) $sitepress->get_setting('setup_complete'), true)
    . ' | por defecto=' . apply_filters('wpml_default_language', null)
    . ' | activos=' . implode(',', $active) . "\n";
