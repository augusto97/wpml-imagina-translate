# Suite de integración

Los tests de `tests/` son unitarios: comprueban lógica pura sin WordPress. Esta
suite hace lo contrario — instala WordPress de verdad, con base de datos, y
conduce el plugin como lo haría un sitio real.

Existe porque hizo falta. Los tests unitarios pasaban al 100 % y aun así el
plugin tenía **siete fallos**, todos invisibles sin una instalación real:

| Fallo | Por qué los tests unitarios no lo veían |
|---|---|
| Taxonomías enlazadas a términos equivocados | Necesita las tablas de WPML y un sitio donde `term_id` y `term_taxonomy_id` no coincidan |
| Shortcodes enviados a la API y devueltos rotos | Necesita parsear bloques Gutenberg reales |
| El preámbulo del modelo acababa como título | Necesita un proveedor que conteste como contesta un modelo real |
| La cháchara final se colaba en la última traducción | Igual |
| La URL antigua de ajustes daba 403 en vez de redirigir | Necesita el orden real de hooks de `wp-admin` |
| Las traducciones nuevas perdían los campos personalizados | Necesita `get_post_meta` sobre un post real |
| Re-traducir no refrescaba taxonomías ni imagen destacada | Necesita dos ejecuciones sobre el mismo post |

## Uso

```bash
tests/integration/setup.sh     # construye la instalación (una vez)
tests/integration/run.sh       # ejecuta la suite (repetible)
```

`run.sh` sale con código distinto de cero si algo falla, así que sirve para CI.
Se puede repetir tantas veces como haga falta: reinicia el estado al empezar y
al terminar.

### Requisitos

- PHP 7.4+ con `mysqli`, `curl`, `mbstring`, `dom`, `zip`
- Un servidor MariaDB o MySQL instalado (no hace falta que esté arrancado;
  `setup.sh` levanta su propia instancia con su propio *datadir*)
- `curl`, `unzip`, `git`, `python3`

En Debian/Ubuntu: `apt-get install -y mariadb-server php-cli php-mysql curl unzip git`

### Variables

| Variable | Por defecto | Para qué |
|---|---|---|
| `WIT_TEST_DIR` | `$TMPDIR/wit-integration` | Dónde vive la instalación |
| `WIT_WP_VERSION` | `6.8.2` | Versión de WordPress |
| `WIT_WP_PORT` | `8080` | Puerto del servidor de pruebas |
| `WIT_DB_PORT` | `3307` | Puerto de MariaDB (no choca con el 3306 del sistema) |
| `WIT_DB_SOCKET` | `/tmp/wit-mysql.sock` | Socket de MariaDB — **ver la nota de abajo** |
| `WIT_DB_USER` | el usuario actual | Usuario del sistema con el que corre MariaDB |

## Qué hay dentro

### `fixtures/sitepress-stub.php` — doble de WPML

WPML es comercial y no se puede meter en el repositorio. Este stub implementa
la **superficie de hooks documentada** que el plugin usa, sobre una tabla
`icl_translations` real con las claves `UNIQUE` reales de WPML.

Reproduce dos comportamientos que importan:

1. **Las taxonomías se indexan por `term_taxonomy_id`, no por `term_id`.**
   Solo `wpml_object_id` acepta y devuelve `term_id`; el resto de hooks
   trabajan con `term_taxonomy_id`. Esta asimetría es exactamente el fallo que
   tenía el plugin.
2. **WPML asigna idioma a cada post y término nuevo** por su cuenta, y el
   plugin tiene que sobrescribir eso con el `trid` correcto.

**No es WPML.** No cubre traducción de cadenas, el conmutador de idiomas, los
modos de URL, ni el editor de traducciones. Que la suite pase no garantiza que
funcione contra WPML real — garantiza que el contrato documentado se respeta.
Un cambio que dependa de comportamiento no documentado de WPML pasará aquí y
fallará en producción.

### `fixtures/wit-fake-ai.php` — proveedor de IA falso

Intercepta `pre_http_request` y responde a los tres proveedores localmente. Sin
key, sin red, sin coste, y **determinista**: traduce anteponiendo `[EN]`,
`[FR]`… lo que permite afirmar exactamente qué debería haber cambiado.

Además registra **cada cadena que llega a la API** en
`wp-content/wit-ai-requests.log`, que es como se detectó que los shortcodes se
estaban enviando.

Tiene cuatro modos, conmutables con la opción `wit_fake_mode`:

| Modo | Qué simula |
|---|---|
| `normal` | Respuesta correcta |
| `preamble` | Modelo hablador: *"Sure! Here is the translation:"*, markdown alrededor de los marcadores y una despedida al final |
| `drop_item` | Omite el elemento 2 de cada lote, forzando el reintento individual |
| `rate_limit` | Primera llamada 429; el reintento con *backoff* funciona |

Cada modo es un fallo que los modelos reales cometen. Dos de los siete fallos
salieron del modo `preamble`.

### `fixtures/seed.php` — contenido hostil

Crea un post con lo que suele romper las cosas: entidades HTML (`&amp;`,
`&lt;`, `&mdash;`), `&nbsp;`, párrafos multilínea, bloques *cover* e *image*
con `alt` duplicado en el JSON del bloque y en el markup, un bloque
*shortcode*, grupos anidados, un bloque HTML libre, categorías jerárquicas,
metadatos de SEO y un campo personalizado que no debe tocarse.

**Lo más importante que hace** es forzar que `term_id` y `term_taxonomy_id`
**no coincidan**, insertando filas en `wp_terms` sin su contraparte en
`wp_term_taxonomy` — que es lo que pasa de verdad tras importaciones fallidas
o migraciones a medias.

Sin esa divergencia, el fallo de taxonomías es **invisible**: en un sitio donde
los dos contadores coinciden, pasar el `term_id` equivocado da el resultado
correcto por casualidad. Por eso `seed.php` aborta si la divergencia no se
produce, y `run.sh` lo comprueba otra vez antes de dar por bueno el test — un
fixture que dejara de reproducir el escenario convertiría la prueba en un test
vacío que siempre pasa.

### `fixtures/reset.php`

Devuelve la instalación a su estado inicial sin reconstruir WordPress. Lee los
IDs sembrados de la opción `wit_test_fixture`; nada depende de números
concretos, porque la divergencia de arriba garantiza que no son los obvios.

## Qué comprueba `run.sh`

| Sección | Contenido |
|---|---|
| **A** | Los tres modos hostiles del proveedor: el contenido se traduce entero y el título queda limpio |
| **B** | Fidelidad del contenido: entidades, `nbsp`, clases y URLs intactas, `alt` traducido en los dos sitios, shortcode intacto, 10 bloques válidos, campos copiados, meta interna **no** copiada, imagen destacada, borrador, memoria reutilizada, glosario, y la ida y vuelta de la taxonomía |
| **C** | La cola drenando **sin usuario actual**, como la ejecuta WP-Cron |
| **D** | Las cinco pantallas de administración por HTTP real, más la redirección de la URL antigua |
| **E** | Los ocho endpoints AJAX, más los tres casos que **deben** rechazarse: nonce inválido, idioma inexistente, sin sesión |
| **F** | El formulario de ajustes por `options.php`, incluido que enviar una key vacía **no** borre la guardada |

Tras cada sección se comprueba que `debug.log` esté vacío. Un *warning* de PHP
que nadie mira es un fallo que todavía no ha dado la cara.

## Trampas conocidas

Tropecé con todas ellas montando esto; están documentadas para que no cuesten
otra vez una hora.

**El socket de MariaDB no puede pasar de 107 caracteres.** Es un límite del
kernel (`struct sockaddr_un`). Un socket dentro de un directorio temporal
anidado lo supera y el servidor aborta con *"The socket file path is too
long"*. Por eso el socket va por defecto en `/tmp/` y nunca bajo
`$WIT_TEST_DIR`.

**`siteurl` tiene que coincidir con el host que se pide.** wp-cli lo deduce de
la ruta del sistema de archivos, y dejó `http://localhost:8080/www`: todas las
peticiones al admin respondían 302 y se iban antes de que el plugin llegara a
cargarse. `setup.sh` lo fija explícitamente.

**El servidor de desarrollo de PHP es monohilo.** Una petición que dispara una
sub-petición se bloquea contra sí misma. `run.sh` arranca con
`PHP_CLI_SERVER_WORKERS=6`.

**La memoria de traducción enmascara los tests del proveedor.** Si una cadena
ya está en memoria, no llega a la API y el modo hostil que se quería probar no
se ejercita — el test pasa sin haber comprobado nada. `run.sh` vacía la memoria
antes de cada modo.

**La página de administración lleva varios `"nonce"`.** El del plugin está
dentro de su propio objeto `witAdmin`; coger el primero que aparece devuelve el
de WordPress y todos los endpoints responden *"Error de seguridad"*.

**`pkill -f <patrón>` se mata a sí mismo** si el patrón aparece en la línea de
comandos de quien lo lanza. Mata por PID.

## Qué NO cubre

Conviene tenerlo claro antes de fiarse de un resultado en verde:

- **Elementor.** El manejador tiene lógica sustancial (widgets clásicos y
  *atomic* 4.x, `Document::save()`, cachés de render) y aquí no se toca nada.
  Haría falta Elementor instalado.
- **WPML real.** Ver arriba.
- **El navegador.** El JavaScript no se ejecuta: se comprueba que el marcado
  que necesita está presente, no que la interfaz funcione.
- **Modelos reales.** El proveedor falso obedece el protocolo mejor que un
  modelo real en un mal día.
- **Concurrencia.** El bloqueo de la cola no se prueba con dos pasadas
  simultáneas de verdad.

## Nota sobre el paquete

`tests/` completo queda fuera del ZIP distribuible (`git archive` seguido de
`rm -rf tests`), así que nada de esto llega a un sitio en producción.
