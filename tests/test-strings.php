<?php
/**
 * WIT_Strings — the parts of String Translation support that need no WPML:
 * what is sent to the translator, and when a translation is refused.
 *
 * A gettext string that loses a placeholder breaks the page that prints it:
 * on PHP 8, sprintf() with too few arguments is a fatal error.
 */

WIT_Tests::group('Cadenas — marcadores');

WIT_Tests::same(array('%1$s', '%2$s'), WIT_Strings::placeholders('Posted on %1$s by %2$s'), 'printf posicionales');
WIT_Tests::same(array('%d', '%s'), WIT_Strings::placeholders('%s has %d items'), 'printf simples, ordenados');
WIT_Tests::same(array('%%', '%.2f'), WIT_Strings::placeholders('%.2f%% off'), 'precisión y porcentaje literal');
WIT_Tests::same(array('{name}', '{{count}}'), WIT_Strings::placeholders('Hi {name}, {{count}} new'), 'marcadores con llaves');
WIT_Tests::same(array('[gallery ids="1"]'), WIT_Strings::placeholders('See [gallery ids="1"]'), 'un shortcode registrado cuenta');
WIT_Tests::same(array(), WIT_Strings::placeholders('Field [Required]'), 'una palabra entre corchetes no es un shortcode');
WIT_Tests::same(array(), WIT_Strings::placeholders('100% natural'), '"100% natural" no es un marcador');

$original = 'Posted on %1$s by %2$s';
WIT_Tests::ok(WIT_Strings::placeholders('Publicado el %1$s por %2$s') === WIT_Strings::placeholders($original), 'traducción correcta: mismos marcadores');
WIT_Tests::ok(WIT_Strings::placeholders('Publicado por %2$s el %1$s') === WIT_Strings::placeholders($original), 'el orden puede cambiar');
WIT_Tests::ok(WIT_Strings::placeholders('Publicado el %1$s') !== WIT_Strings::placeholders($original), 'perder uno se detecta');
WIT_Tests::ok(WIT_Strings::placeholders('Publicado el %1$s por %2$s %3$s') !== WIT_Strings::placeholders($original), 'añadir uno se detecta');

WIT_Tests::group('Cadenas — qué se manda a traducir');

WIT_Tests::same(array('Leave a reply'), WIT_Strings::units('Leave a reply'), 'texto plano: la cadena entera');
WIT_Tests::same(array(), WIT_Strings::units('%s'), 'solo un marcador: nada');
WIT_Tests::same(array(), WIT_Strings::units('—'), 'solo puntuación: nada');
$units = WIT_Strings::units('Read our <a href="/privacy">privacy policy</a>');
WIT_Tests::same(array('Read our', 'privacy policy'), $units, 'con HTML: los nodos de texto (recortados), nunca el marcado');
WIT_Tests::same(array('Continue reading'), WIT_Strings::units('Continue reading <span class="screen-reader-text">%s</span>'), 'un <span> con solo %s no se manda');

WIT_Tests::group('Cadenas — reconstrucción');

WIT_Tests::same('Deja un comentario', WIT_Strings::rebuild('Leave a reply', array('Leave a reply' => 'Deja un comentario')), 'texto plano');
WIT_Tests::same(null, WIT_Strings::rebuild('Leave a reply', array()), 'sin traducción: null, no el original');
$rebuilt = WIT_Strings::rebuild('Read our <a href="/privacy">privacy policy</a>', array('Read our' => 'Lee nuestra', 'privacy policy' => 'política de privacidad'));
WIT_Tests::same('Lee nuestra <a href="/privacy">política de privacidad</a>', $rebuilt, 'con HTML: el enlace intacto y el espacio conservado');
WIT_Tests::same(null, WIT_Strings::rebuild('Read our <a href="/privacy">privacy policy</a>', array('Read our' => 'Lee nuestra')), 'falta un nodo: null');
