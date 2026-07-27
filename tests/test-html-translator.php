<?php
/**
 * WIT_HTML_Translator — the class that decides which bytes of a block's HTML
 * may change. Every failure here corrupts published content.
 */

WIT_Tests::group('HTML translator — fidelidad byte a byte');

$untouched = array(
    '<p>Caf&eacute; con leche &amp; churros</p>',
    '<figure class="wp-block-image size-large"><img src="x.png" alt="Foto"/><figcaption>Pie</figcaption></figure>',
    '<a href="https://x.com/center" title="Ir" class="btn center">Ver mas</a>',
    '<p>5 &lt; 10 &gt; 2</p>',
    "<div data-x='a>b'>raro</div>",
    '<ul><li>Uno</li><li>Dos &mdash; tres</li></ul>',
    '<!-- wp:paragraph --><p>Dentro</p><!-- /wp:paragraph -->',
    '<p>Texto con <strong>negrita</strong> y <em>cursiva</em></p>',
);

foreach ($untouched as $html) {
    $count = 0;
    WIT_Tests::same(
        $html,
        WIT_HTML_Translator::apply($html, array(), $count),
        'sin coincidencias devuelve el HTML idéntico: ' . mb_substr($html, 0, 44)
    );
}

WIT_Tests::group('HTML translator — entidades');

// The old DOMDocument+str_replace approach silently failed on these, because
// nodeValue returns decoded text that never matched the raw HTML.
$count = 0;
$result = WIT_HTML_Translator::apply(
    '<p>Caf&eacute; con leche &amp; churros</p>',
    array('Café con leche & churros' => 'Coffee with milk & churros'),
    $count
);
WIT_Tests::same('<p>Coffee with milk &amp; churros</p>', $result, 'texto con entidades se traduce');
WIT_Tests::same(1, $count, 'cuenta una sustitución');

$originals = array();
WIT_HTML_Translator::collect('<p>Hola&nbsp;mundo entero</p>', $originals);
WIT_Tests::ok(
    isset($originals["Hola\u{00a0}mundo entero"]),
    'el nbsp se decodifica al recolectar'
);

$count  = 0;
$result = WIT_HTML_Translator::apply(
    '<p>Hola&nbsp;mundo entero</p>',
    array("Hola\u{00a0}mundo entero" => 'Hello whole world'),
    $count
);
WIT_Tests::same('<p>Hello whole world</p>', $result, 'texto con nbsp se traduce');

WIT_Tests::group('HTML translator — aislamiento de atributos');

$count  = 0;
$result = WIT_HTML_Translator::apply(
    '<a href="https://x.com/center" class="btn center">Ver mas</a>',
    array('center' => 'CENTRO', 'btn center' => 'MALO', 'Ver mas' => 'See more'),
    $count
);
WIT_Tests::ok(strpos($result, 'class="btn center"') !== false, 'no escribe dentro de class');
WIT_Tests::ok(strpos($result, 'href="https://x.com/center"') !== false, 'no escribe dentro de href');
WIT_Tests::ok(strpos($result, '>See more<') !== false, 'sí traduce el texto visible');

$count  = 0;
$result = WIT_HTML_Translator::apply(
    '<p>Traducir</p><script>var x = "Traducir";</script><style>.a{content:"Traducir"}</style>',
    array('Traducir' => 'Translate'),
    $count
);
WIT_Tests::same(1, $count, 'script y style quedan fuera');
WIT_Tests::ok(strpos($result, 'var x = "Traducir"') !== false, 'el JS no se toca');

WIT_Tests::group('HTML translator — atributos visibles');

// core/cover keeps `alt` in the block JSON and prints it into the markup.
// Translating only one of the two invalidates the block.
$html = '<div class="wp-block-cover"><img alt="Foto del equipo" src="a.png"/><p>Equipo</p></div>';

$originals = array();
WIT_HTML_Translator::collect($html, $originals);
WIT_Tests::ok(isset($originals['Foto del equipo']), 'recolecta el valor de alt');
WIT_Tests::ok(isset($originals['Equipo']), 'recolecta el texto visible');

$count  = 0;
$result = WIT_HTML_Translator::apply($html, array('Foto del equipo' => 'Team photo'), $count);
WIT_Tests::ok(strpos($result, 'alt="Team photo"') !== false, 'traduce alt');
WIT_Tests::ok(strpos($result, 'src="a.png"') !== false, 'no toca src');

$count  = 0;
$result = WIT_HTML_Translator::apply(
    '<img alt="original" src="x.png"/>',
    array('original' => 'say "hi" & <b>bye</b>'),
    $count
);
WIT_Tests::ok(
    (bool) preg_match('/^<img alt="[^"]*" src="x\.png"\/>$/', $result),
    'las comillas en la traducción no rompen el atributo'
);

WIT_Tests::group('HTML translator — la IA no puede inyectar markup');

$count  = 0;
$result = WIT_HTML_Translator::apply(
    '<p>hola</p>',
    array('hola' => '<script>alert(1)</script>'),
    $count
);
WIT_Tests::same('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $result, 'el script se escapa a texto inerte');

WIT_Tests::group('HTML translator — filtro de traducibles');

$cases = array(
    array('Hola mundo', true,  'frase normal'),
    array('a',          false, 'un solo carácter'),
    array('123 456',    false, 'solo números'),
    array('#ffffff',    false, 'color hex'),
    array('https://example.com', false, 'URL'),
    array('//cdn.example.com/a.js', false, 'URL sin protocolo'),
    array('data:image/png;base64,AAAA', false, 'data URI'),
    array('— · —',      false, 'solo puntuación'),
);

foreach ($cases as $case) {
    WIT_Tests::same($case[1], WIT_HTML_Translator::is_translatable($case[0]), 'is_translatable: ' . $case[2]);
}
