<?php
/**
 * WIT_Glossary — brand terms and fixed translations.
 */

WIT_Tests::group('Glosario — parseo');

$glossary = new WIT_Glossary(
    "# Comentario, se ignora\n"
    . "\n"
    . "Imagina\n"
    . "Servicios = Services\n"
    . "[en] Inicio = Home\n"
    . "[fr,de] Contacto = Kontakt\n"
);

WIT_Tests::ok(!$glossary->is_empty(), 'el glosario tiene reglas');

$rules = WIT_Glossary::parse("Imagina\nServicios = Services\n# nada\n\n");
WIT_Tests::same(2, count($rules), 'ignora comentarios y líneas vacías');
WIT_Tests::same(null, $rules[0]['translation'], 'una sola columna significa "no traducir"');
WIT_Tests::same('Services', $rules[1]['translation'], 'dos columnas dan traducción fija');

WIT_Tests::group('Glosario — resolución de cadenas completas');

WIT_Tests::same('Imagina', $glossary->resolve('Imagina', 'en'), 'término protegido se devuelve igual');
WIT_Tests::same('Services', $glossary->resolve('Servicios', 'en'), 'traducción fija en cualquier idioma');
WIT_Tests::same('Services', $glossary->resolve('servicios', 'en'), 'la coincidencia ignora mayúsculas');
WIT_Tests::same('Home', $glossary->resolve('Inicio', 'en'), 'regla de inglés se aplica en inglés');
WIT_Tests::same(null, $glossary->resolve('Inicio', 'fr'), 'regla de inglés NO se aplica en francés');
WIT_Tests::same('Kontakt', $glossary->resolve('Contacto', 'de'), 'regla multiidioma se aplica en alemán');
WIT_Tests::same(null, $glossary->resolve('Contacto', 'it'), 'regla multiidioma no se aplica fuera de su lista');
WIT_Tests::same(null, $glossary->resolve('Cualquier otra frase', 'en'), 'lo no cubierto devuelve null');

WIT_Tests::group('Glosario — instrucciones en el prompt');

$section = $glossary->prompt_section(array('Bienvenido a Imagina, vea nuestros Servicios'), 'en');
WIT_Tests::ok(strpos($section, 'Imagina') !== false, 'incluye el término presente en el lote');
WIT_Tests::ok(strpos($section, 'Servicios -> Services') !== false, 'incluye la traducción fija');
WIT_Tests::ok(strpos($section, 'Kontakt') === false, 'excluye reglas de otros idiomas');

$section = $glossary->prompt_section(array('Una frase sin ningún término del glosario'), 'en');
WIT_Tests::same('', $section, 'sin términos relevantes no añade nada al prompt');

$empty = new WIT_Glossary('');
WIT_Tests::ok($empty->is_empty(), 'un glosario vacío se detecta como vacío');
WIT_Tests::same('', $empty->prompt_section(array('Hola'), 'en'), 'un glosario vacío no añade prompt');
WIT_Tests::same(null, $empty->resolve('Hola', 'en'), 'un glosario vacío no resuelve nada');
