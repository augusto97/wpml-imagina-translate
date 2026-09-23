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

WIT_Tests::group('Respuesta de una sola cadena — preámbulo y comillas');

// Found in a real install: with a chatty model the WHOLE reply, preamble
// included, became the post title. The batch path was immune (markers); the
// single path had no defence at all.
$unwrap = function ($raw, $source = '') use ($engine) {
    return WIT_Tests::call($engine, 'unwrap_single', array($raw, $source));
};

WIT_Tests::same(
    'Título en francés',
    $unwrap("Sure! Here is the translation:\n\n[[[1]]]\nTítulo en francés", 'Título'),
    'descarta el preámbulo cuando el marcador está presente'
);
WIT_Tests::same(
    "Primera línea\nSegunda línea",
    $unwrap("[[[1]]]\nPrimera línea\nSegunda línea", 'x'),
    'conserva los saltos de línea internos'
);
WIT_Tests::same(
    'Traducción limpia',
    $unwrap('**[[[1]]]**' . "\n" . 'Traducción limpia', 'x'),
    'tolera markdown alrededor del marcador'
);

// Without the marker the behaviour must be no worse than before.
WIT_Tests::same(
    'Traducción sin marcador',
    $unwrap('Traducción sin marcador', 'Original'),
    'sin marcador devuelve la respuesta tal cual'
);
WIT_Tests::same(
    'Hello world',
    $unwrap('"Hello world"', 'Hola mundo'),
    'quita las comillas que envuelven toda la respuesta'
);
WIT_Tests::same(
    '"Hola" dijo él',
    $unwrap('"Hola" dijo él', 'Original'),
    'no toca comillas que no envuelven la cadena entera'
);
WIT_Tests::same(
    '"Hello"',
    $unwrap('"Hello"', '"Hola"'),
    'si el original venía entrecomillado, las comillas son contenido'
);

WIT_Tests::group('Terminador [[[end]]] — cháchara final');

// Without a terminator the last item absorbs whatever the model adds after it.
// Seen in a real install: a title came back as "…preamble\n\nHope that helps!".
WIT_Tests::same(
    'Traducción',
    $unwrap("[[[1]]]\nTraducción\n[[[end]]]\n\nHope that helps!", 'x'),
    'descarta el texto posterior al terminador (cadena única)'
);
WIT_Tests::same(
    'Traducción',
    $unwrap("Sure!\n\n[[[1]]]\nTraducción\n[[[end]]]\nEspero que sirva.", 'x'),
    'descarta preámbulo Y cháchara final a la vez'
);

$parsed = $parse("[[[1]]]\nUno\n[[[2]]]\nDos\n[[[end]]]\n\nEspero que te sirva.", 2);
WIT_Tests::same('Dos', $parsed[2], 'en lote, el último elemento no absorbe la despedida');
WIT_Tests::same('Uno', $parsed[1], 'el resto del lote no se ve afectado');

$parsed = $parse("[[[1]]]\nUno\n[[[2]]]\nDos", 2);
WIT_Tests::same('Dos', $parsed[2], 'sin terminador funciona igual que antes');

$parsed = $parse("[[[1]]]\nUno\n[[[2]]]\n**[[[END]]]** ya está", 2);
WIT_Tests::ok(!isset($parsed[2]), 'terminador en mayúsculas y con markdown también corta');

WIT_Tests::group('Modo MCP — la traducción llega del chat, nunca de la API');

// No HTTP function exists in this environment: if the engine tried to call a
// provider, this group would die with a fatal error instead of passing.
$map = array(
    'Hola mundo'     => 'Hello world',
    'Plain source'   => 'Clean <script>alert(1)</script>text',
    '<b>Rich</b> x'  => '<b>Rico</b> x',
);

$result = WIT_Translator_Engine::with_external_translations($map, function () {
    $engine = new WIT_Translator_Engine();
    return array(
        'batch'  => $engine->translate_batch(array('Hola mundo', 'Sin traducción', 'Plain source', '<b>Rich</b> x'), 'en', 'es'),
        'single' => $engine->translate('Hola mundo', 'en', 'es'),
        'active' => WIT_Translator_Engine::is_external_active(),
    );
});

WIT_Tests::same('Hello world', $result['batch'][0]['translation'], 'la cadena se toma del mapa del chat');
WIT_Tests::ok(!empty($result['batch'][1]['error']), 'una cadena que el chat no envió falla, no cae a la API');
WIT_Tests::same(array('Sin traducción'), WIT_Translator_Engine::external_missing(), 'y queda registrada como ausente');
WIT_Tests::same('Clean text', $result['batch'][2]['translation'], 'origen en texto plano: se eliminan las etiquetas que traiga el chat');
WIT_Tests::same('<b>Rico</b> x', $result['batch'][3]['translation'], 'origen con marcado: el marcado se respeta');
WIT_Tests::same('Hello world', $result['single']['translation'], 'translate() de una sola cadena también usa el mapa');
WIT_Tests::ok($result['active'], 'dentro del bloque el modo externo está activo');
WIT_Tests::ok(!WIT_Translator_Engine::is_external_active(), 'al salir del bloque se desactiva');

$nested = false;
try {
    WIT_Translator_Engine::with_external_translations(array(), function () {
        WIT_Translator_Engine::with_external_translations(array(), function () {});
    });
} catch (LogicException $e) {
    $nested = true;
}
WIT_Tests::ok($nested, 'anidar dos mapas externos se rechaza (sería ambiguo cuál aplica)');
WIT_Tests::ok(!WIT_Translator_Engine::is_external_active(), 'y una excepción dentro no deja el modo externo pegado');

WIT_Settings::$overrides = array('glossary' => "Imagina\nContacta = Get in touch");
$after = new WIT_Translator_Engine();
WIT_Tests::same(
    array('Hola'),
    $after->unresolved(array('Imagina', 'Contacta', 'Hola'), 'en', 'es'),
    'unresolved() excluye lo que el glosario resuelve entero'
);
WIT_Settings::$overrides = array();
