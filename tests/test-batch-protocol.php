<?php
/**
 * WIT_Translator_Engine — batch protocol and chunking.
 *
 * The numbered-list protocol this replaced misaligned translations whenever a
 * source string contained a newline, which silently put the wrong translation
 * into the wrong place.
 */

$engine = new WIT_Translator_Engine();

$parse = function ($response, $expected) use ($engine) {
    return WIT_Tests::call($engine, 'parse_batch_response', array($response, $expected));
};

WIT_Tests::group('Protocolo de lote — valores multilínea');

$parsed = $parse("[[[1]]]\nHola\nMundo\n[[[2]]]\nAdiós\n[[[3]]]\n2024 fue genial", 3);

WIT_Tests::same("Hola\nMundo", $parsed[1], 'conserva los saltos de línea internos');
WIT_Tests::same('Adiós', $parsed[2], 'el segundo elemento no se contamina');
WIT_Tests::same('2024 fue genial', $parsed[3], 'una traducción que empieza por dígitos no se pierde');

WIT_Tests::group('Protocolo de lote — respuestas imperfectas');

$parsed = $parse("Claro, aquí tienes:\n\n**[[[1]]]**\nUno\n\n- [[[2]]]\nDos\n", 2);
WIT_Tests::same('Uno', $parsed[1], 'ignora el preámbulo y el markdown alrededor del marcador');
WIT_Tests::same('Dos', $parsed[2], 'ignora la viñeta de lista');

$parsed = $parse("[[[2]]]\nDos\n[[[1]]]\nUno", 2);
WIT_Tests::same('Uno', $parsed[1], 'el orden de la respuesta no importa');
WIT_Tests::same('Dos', $parsed[2], 'el orden de la respuesta no importa (2)');

$parsed = $parse("[[[1]]]\nUno\n[[[3]]]\nTres", 3);
WIT_Tests::ok(!isset($parsed[2]), 'un elemento que falta se detecta en vez de desplazar los demás');
WIT_Tests::same('Tres', $parsed[3], 'el elemento posterior conserva su posición correcta');

$parsed = $parse("[[[1]]]\nUno\n[[[99]]]\nBasura", 2);
WIT_Tests::ok(!isset($parsed[99]), 'los índices fuera de rango se descartan');

$parsed = $parse('Respuesta sin ningún marcador', 2);
WIT_Tests::same(array(), $parsed, 'una respuesta sin marcadores no inventa resultados');

WIT_Tests::group('Troceado por caracteres y por número de elementos');

$chunk = function ($texts) use ($engine) {
    return WIT_Tests::call($engine, 'chunk', array($texts));
};

$chunks = $chunk(array_fill(0, 10, str_repeat('x', 3000)));
WIT_Tests::same(3, count($chunks), '10 textos de 3000 caracteres caben en 3 peticiones');

foreach ($chunks as $index => $slice) {
    $length = array_sum(array_map('mb_strlen', $slice));
    WIT_Tests::ok(
        $length <= WIT_Translator_Engine::MAX_CHUNK_CHARS,
        'el trozo ' . $index . ' respeta el límite de caracteres (' . $length . ')'
    );
}

$chunks = $chunk(array_fill(0, 100, 'hola'));
foreach ($chunks as $index => $slice) {
    WIT_Tests::ok(
        count($slice) <= WIT_Translator_Engine::MAX_CHUNK_ITEMS,
        'el trozo ' . $index . ' respeta el límite de elementos'
    );
}

// Keys must survive chunking, or translations land on the wrong strings.
$texts  = array(5 => 'uno', 9 => 'dos', 11 => 'tres');
$chunks = $chunk($texts);
$keys   = array();
foreach ($chunks as $slice) {
    $keys = array_merge($keys, array_keys($slice));
}
WIT_Tests::same(array(5, 9, 11), $keys, 'el troceado conserva las claves originales');

WIT_Tests::group('Nombres de idioma');

$name = function ($code) use ($engine) {
    return WIT_Tests::call($engine, 'get_language_name', array($code));
};

WIT_Tests::same('Spanish', $name('es'), 'código simple');
WIT_Tests::same('Brazilian Portuguese', $name('pt-br'), 'código regional conocido');
WIT_Tests::same('Simplified Chinese', $name('zh-hans'), 'código con variante de escritura');
WIT_Tests::same('German', $name('de-ch'), 'código regional desconocido cae al idioma base');
WIT_Tests::same('', $name(''), 'código vacío no inventa idioma');

WIT_Tests::group('Glosario integrado en el motor');

WIT_Settings::$overrides = array('glossary' => "Imagina\nServicios = Services");

$engine_with_glossary = new WIT_Translator_Engine();
$results = $engine_with_glossary->translate_batch(array('Imagina', 'Servicios'), 'en', 'es');

WIT_Tests::same('Imagina', $results[0]['translation'], 'el término protegido se resuelve sin llamar a la API');
WIT_Tests::same('Services', $results[1]['translation'], 'la traducción fija se resuelve sin llamar a la API');

$usage = $engine_with_glossary->get_usage();
WIT_Tests::same(2, $usage['glossary'], 'ambas se contabilizan como resueltas por glosario');
WIT_Tests::same(0, $usage['api'], 'no se gastó ninguna llamada a la API');

WIT_Settings::$overrides = array();
