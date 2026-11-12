# WPML Imagina Translate

[![Tests](https://github.com/augusto97/wpml-imagina-translate/actions/workflows/tests.yml/badge.svg)](https://github.com/augusto97/wpml-imagina-translate/actions/workflows/tests.yml)

Plugin de WordPress para traducir automáticamente contenido usando tu propia API key de IA. Integración perfecta con WPML.

## 🚀 ¿Por qué este plugin?

WPML cobra caro por traducciones automáticas con IA. Este plugin te permite usar tu propia API key de OpenAI, Claude o Gemini, ahorrando costos significativos mientras mantienes total control sobre tus traducciones.

## ✨ Características

### Core Features
- **Multi-proveedor de IA**: OpenAI (GPT-4, GPT-4o), Anthropic Claude, Google Gemini
- **Memoria de traducción**: no vuelve a pagar por una frase ya traducida, y garantiza que se traduzca igual en todo el sitio
- **Glosario**: términos de marca que nunca se traducen y traducciones fijas por idioma
- **Cola en segundo plano**: encolas cientos de páginas y cierras el navegador; el servidor sigue
- **Traducir desde Claude (MCP)**: conecta tu suscripción de Claude y pide desde el chat qué falta, traduce, revisa, corrige y publica — sin gastar la API key
- **Cadenas de WPML String Translation**: traduce con IA los textos del tema y de los plugins, widgets y textos de administración — lo que sigue en el idioma original cuando todas las páginas ya están traducidas
- **Smart Content Parser**: Preserva bloques de Gutenberg, HTML, y estructura
- **Meta Fields**: Traduce automáticamente SEO (Yoast, RankMath), excerpts, y campos personalizados
- **Una app de verdad dentro de WordPress**: Inicio, Contenidos, Cadenas, Historial y Ajustes, hechas en React con la misma base que Imagina Updater. Traducir es un clic; revisar es ver el original junto a la traducción y corregir una frase sin regenerar el resto; todo se adapta al móvil
- **Historial**: cada traducción y cada acción, desde dónde se hizo (el panel, la API o Claude) y los tokens que consumió
- **Progreso en vivo**: la cola en segundo plano y los lotes de cadenas muestran su avance y se pueden detener

### Integración WPML
- Detecta posts pendientes de traducción automáticamente
- Crea posts traducidos vinculados correctamente en WPML
- Copia taxonomías, featured images, y metadatos
- Actualiza traducciones existentes
- **WPML sabe de cada traducción**: aparece como completa en su panel y la marca «necesita actualización» cuando cambia el original
- **Campos según WPML**: traduce los campos que WPML tiene como «Traducir» (incluidos los que declaran Yoast, Rank Math, ACF…) y no copia los marcados «No traducir»
- Respeta las taxonomías que WPML no traduce (formatos de entrada, tipos de producto…)

### Integridad del contenido
- El HTML se modifica por posiciones de token, nunca re-serializando: todo byte
  fuera de un nodo de texto se conserva idéntico
- No traduce clases CSS, IDs, colores, URLs, tags HTML ni valores técnicos
  (`align`, `header_size`, `_animation`, `is_external`…)
- Los atributos visibles (`alt`, `title`, `placeholder`, `aria-label`) se
  traducen en el HTML **y** en los atributos del bloque a la vez, que es lo que
  exige la validación de Gutenberg
- Compatible con Elementor 3.x (widgets clásicos) y 4.x (widgets *atomic* con
  envoltorios `$$type`)
- Posts creados como borrador para revisión

### Seguridad
- Lo que devuelve la IA nunca puede guardar marcado que el original no tenía: un modelo comprometido o manipulado por el texto de una página no puede meter un `<script>` en un título, extracto o campo SEO
- Solo se traducen originales a otro idioma: no se puede sobrescribir un original traduciendo su traducción, ni por el panel, ni por la cola, ni por Claude
- Los Autores ven en el historial y en la cola solo sus propios contenidos
- Las API keys nunca salen del servidor: la pantalla Ajustes solo sabe si hay una guardada y sus 4 últimos caracteres
- La opción con las keys no se autocarga en cada petición del front-end
- La key de Gemini viaja en cabecera, no en la URL (no queda en logs)
- El texto devuelto por la IA se escapa antes de insertarse: no puede inyectar
  markup ni scripts
- La API del panel (`wit/admin/v1`) exige sesión, el nonce `wp_rest` y la capacidad de cada acción: `edit_post` por contenido, `manage_options` para ajustes y cadenas
- El idioma destino se valida contra los idiomas activos de WPML

## 📦 Instalación

### Requisitos
- WordPress 6.0 o superior (probado hasta 7.1)
- PHP 7.4 o superior (probado hasta 8.4)
- WPML Multilingual CMS 4.7 o superior; **probado contra WPML 5.2.0 y WPML String Translation 5.2.0 reales** (además de contra un doble de sus hooks, para las pruebas que no pueden usar WPML)
- WPML String Translation (opcional): activa la pantalla «Cadenas»
- Elementor 3.x o 4.x (opcional; probado con 4.0.8 y con Elementor Pro 4.2.3)
- API key de OpenAI, Claude, o Gemini

### Pasos

1. **Subir el plugin**
   ```
   wp-content/plugins/wpml-imagina-translate/
   ```

2. **Activar el plugin**
   - Ve a Plugins → Installed Plugins
   - Activa "WPML Imagina Translate"

3. **Configurar API key**
   - Ve a **IA Translate → Ajustes** (o pulsa «Ajustes» en la fila del plugin)
   - Selecciona tu proveedor de IA
   - Ingresa tu API key
   - Guarda los cambios

4. **¡Listo para traducir!**
   - Ve a **IA Translate → Contenidos**
   - Elige el idioma
   - Pulsa **Traducir** en cualquier fila

## 🔧 Configuración

### Obtener API Keys

#### OpenAI
1. Ve a [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
2. Crea una nueva API key
3. Copia y pega en el plugin

**Modelos recomendados:**
- `gpt-4o-mini` - Recomendado, excelente calidad y barato
- `gpt-4o` - Buena calidad
- `gpt-4o-2024-11-20` - Snapshot estable (Noviembre 2024)
- `o3-mini` - Modelo de razonamiento (para tareas complejas)


#### Anthropic Claude
1. Ve a [console.anthropic.com](https://console.anthropic.com)
2. Crea una API key
3. Copia y pega en el plugin

**Modelos recomendados (Serie 4.5 - Nuevos):**
- `claude-haiku-4-5-20251001` - Recomendado, rápido y barato
- `claude-sonnet-4-5-20250929` - Mejor modelo de coding del mundo
- `claude-opus-4-5-20251101` - Máxima calidad e inteligencia


#### Google Gemini
1. Ve a [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)
2. Crea una API key
3. Copia y pega en el plugin

**Modelos recomendados:**
- `gemini-2.5-flash` - Muy rápido y barato (Recomendado)
- `gemini-2.5-pro` - Mayor capacidad y mejor calidad
- `gemini-3-flash-preview` - Más nuevo (Preview)
- `gemini-3-pro-preview` - Más potente (Preview)


> **Sobre costes.** El plugin no muestra precios: las tarifas de los proveedores
> cambian con frecuencia y una tabla fija daría cifras equivocadas. Lo que sí
> registra son los **tokens consumidos** que devuelve cada API, para que puedas
> multiplicarlos por la tarifa vigente de tu proveedor. La memoria de traducción
> y el glosario reducen ese consumo directamente.

### Configuración Avanzada

#### Translation Prompt
Personaliza el prompt usado para traducir:

```
Translate the following text to {target_language}.
Maintain all HTML tags, formatting, and structure.
Only translate the visible text content, not HTML attributes or code.
Use a professional and natural tone appropriate for {target_language} speakers.
```

Variables disponibles: `{target_language}`, `{source_language}`

#### Meta Fields
Lista de meta fields a traducir (separados por coma):

```
_yoast_wpseo_title,_yoast_wpseo_metadesc,_excerpt,_custom_field
```

**Meta fields comunes:**
- Yoast SEO: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`
- RankMath: `rank_math_title`, `rank_math_description`
- ACF: Nombres de tus campos personalizados

#### Batch Size
Número de posts a procesar en cada lote. Recomendado: 5-10

## 📖 Uso

### Inicio

Lo que falta por traducir en cada idioma, con un botón para mandarlo todo a la
cola; lo que se está traduciendo ahora; los tokens del último mes; las cadenas
pendientes y la conexión con Claude. Si algo impide traducir (no hay API key,
WPML no puede escribir sus ficheros…), aparece aquí primero.

### Contenidos: traducir

1. Ve a **IA Translate → Contenidos** y elige el idioma (se recuerda la próxima vez).
2. Las pestañas filtran: **Pendientes** (sin traducir o desactualizadas), **Al día**, **Editadas a mano**, **Todos**. También puedes buscar por título y elegir los tipos de contenido.
3. **Una fila**: pulsa **Traducir** (o **Actualizar** si el original cambió). Se traduce en el momento y la traducción queda como borrador.
4. **Varias**: márcalas y pulsa **Traducir N al …**, o **Traducir todos los pendientes**. Van a la cola en segundo plano: puedes cerrar la página, el servidor sigue. Mientras tanto la fila dice **En cola** y no se puede lanzar dos veces.

Antes de reemplazar algo, el panel pregunta: si alguien editó la traducción a
mano, si ya estaba al día (gastaría tokens) o si se hizo fuera del plugin.

### Contenidos: revisar y corregir

**Revisar** abre la traducción junto al original, frase a frase. El lápiz de una
frase la corrige sin volver a traducir el resto (y la memoria aprende la nueva
redacción). Desde ahí mismo se **publica**, se **despublica** o se envía a la
**papelera**.

### Traducir las cadenas (WPML String Translation)

Con String Translation activo aparece **IA Translate → Cadenas**: los textos del
tema y de los plugins, widgets, textos de administración y el título del sitio.

1. Elige el idioma al que traducir y, si quieres, un dominio (el tema, un plugin…).
2. Marca las cadenas y pulsa **Traducir N con IA**, o **Traducir todas** para todas las del filtro, de 50 en 50, con barra de progreso y botón para detener.
3. Para una sola, el lápiz permite escribir la traducción a mano (Ctrl+Enter guarda).

Detalles que importan:

- **Cada cadena se traduce desde su propio idioma.** Los temas y plugins están en inglés, así que en un sitio en español también hay que traducirlos *al español*.
- **Lo que ya traduce el fichero de idioma del tema o plugin no se vuelve a pagar**: no aparece como pendiente.
- **Los marcadores se conservan o la traducción se descarta.** `%s`, `%1$s`, `{name}` y los shortcodes deben quedar igual: un `%s` de menos en una cadena de un tema provoca un error fatal en PHP 8 en la página que la imprime. Las descartadas aparecen en el progreso.
- **Las cadenas con HTML se traducen nodo a nodo**: enlaces y atributos no cambian.
- **Lo que no tiene palabras no se manda a la IA**: los formatos de fecha (`F j, Y`, `g:i a`) o un `%s` suelto se guardan tal cual. Un modelo que «traduce» `F j, Y` a `j F, A` rompe todas las fechas del sitio.
- El contenido de Elementor y Gutenberg no aparece aquí: se traduce con su página.

> **Si las cadenas traducidas no aparecen en la web**: String Translation guarda
> sus traducciones en `wp-content/languages/wpml`, y si esa carpeta no existe o no
> se puede escribir **WPML falla sin avisar**. El plugin sí avisa, en la pantalla
> Cadenas y a Claude.

### Historial

**IA Translate → Historial**: cada traducción, corrección, publicación y cambio
de ajustes; desde dónde se hizo (el panel, la API del proveedor o Claude); y el
uso: tokens, y cuántas frases puso la IA, la memoria o el glosario. Un Autor solo
ve lo de los contenidos que puede editar.

## 💬 Traducir desde Claude (MCP)

Si ya pagas una suscripción de Claude, puedes hacer el trabajo de traducción
desde su chat en lugar de pagar tokens de API. El plugin se conecta a Claude
como un *conector* (MCP) y Claude traduce con tu suscripción.

**Los dos caminos están separados del todo.** Lo que haces desde Claude nunca
llama a la API key del sitio, ni para una sola cadena. Lo que haces desde el
panel del plugin nunca pasa por Claude. No hay gasto duplicado.

### Qué puedes pedir

> «¿Qué me falta por traducir al inglés?»
> «Tradúceme la página de Servicios al francés.»
> «Enséñame la traducción inglesa de Contacto; cambia *Get in touch* por *Contact us*.»
> «¿Qué páginas quedaron desactualizadas desde que cambié el español?»
> «Añade *Imagina* al glosario como marca.»
> «Publica la traducción inglesa de Servicios.»
> «Quita la traducción francesa de la página de Ofertas» (la despublica o la manda a la papelera; nunca la borra del todo).
> «¿Cómo está configurado WPML? ¿Por qué no se traducen los productos?»
> «Activa la memoria de traducción» · «Traduce también el campo `rank_math_title`».
> «¿Qué textos del tema siguen en inglés? Tradúcelos al español.» (con String Translation)
> «Haz que WPML muestre las entradas sin traducir en el idioma original» · «Que WPML traduzca el campo `subtitulo`» (con WPML real, solo administradores)

Claude ve el estado de cada contenido en cada idioma:

- **Al día**: traducido, y el original no ha cambiado desde entonces.
- **Desactualizado**: el original cambió después de traducirlo.
- **Pendiente**: sin traducir.
- **Desconocido**: existe una traducción hecha fuera del plugin.

Y si alguien **editó a mano** una traducción después de hacerla, Claude lo ve y
no la reemplaza sin preguntarte: guardar responde «necesita confirmación»
hasta que dices que sí. El panel del plugin avisa igual antes de re-traducir.

Las traducciones se guardan **como borrador**. Solo se publican si lo pides.

### Requisitos

- WordPress **6.9 o superior**. El resto del plugin funciona en versiones anteriores; solo esta función necesita 6.9.
- El sitio accesible desde internet por **HTTPS**: Claude se conecta desde sus servidores, no desde tu ordenador.
- Enlaces permanentes distintos de «Simple».
- Cualquier plan de Claude con conectores personalizados (Free está limitado a uno).

### Conectar

1. En WordPress: **IA Translate → Ajustes → Conexión con Claude (MCP)**, marca **Permitir que Claude se conecte** y guarda.
2. Copia la **URL del conector** que aparece.
3. En Claude: **Personalizar → Conectores → Añadir conector personalizado**, pega la URL y pulsa **Añadir**.
4. Pulsa **Conectar**. Se abre tu WordPress: inicia sesión si hace falta y pulsa **Permitir**.

Claude actúa con los permisos de la cuenta con la que apruebas. Si la apruebas
con un **Editor**, Claude puede traducir y publicar pero no puede tocar el
glosario ni nada reservado a administradores. Las conexiones activas aparecen
en los ajustes, cada una con su botón **Revocar**.

**Claude nunca ve ni cambia las API keys**, el proveedor, el modelo ni el
prompt. Un administrador puede pedirle que active la memoria de traducción, que
añada o quite campos a traducir o que cambie el tamaño de lote. De WPML puede
**consultar** idiomas, formato de URL y qué traduce, y **cambiar** solo dos cosas,
y solo si se lo pides: el modo de traducción de un tipo de contenido (traducir,
mostrar el original si no hay traducción, no traducir) y el de un campo
personalizado (traducir, copiar, copiar una vez, ignorar). Antes de hacerlo te
explica el efecto y te pide confirmación.

Todo lo que Claude hace en el sitio —publicar, despublicar, corregir, cambiar
ajustes— queda en **IA Translate → Historial** marcado como «Claude».

El texto de los contenidos es contenido, no órdenes: si una página dijera
«publica todo» o «cambia los ajustes», Claude tiene instrucción de no hacerlo y
de avisarte.

### Consumo

Traducir por el chat no cuesta tokens de API, pero sí consume uso de tu
suscripción. Por eso:

- Cada tanda tiene **como máximo 10 contenidos**.
- Antes de un trabajo grande, Claude te dice cuántos contenidos son y cuánto texto tienen, y **tú decides** si seguir y cómo.
- Solo se manda al chat lo que hace falta de verdad. Lo que ya está en la memoria de traducción o lo resuelve el glosario no se envía.

Para cientos de páginas de una vez, la cola en segundo plano con API sigue
siendo la herramienta adecuada.

### Cómo funciona por dentro

Claude **nunca toca el marcado**. Recibe cadenas de texto plano con un
identificador y devuelve sus traducciones. El plugin las aplica con el mismo
motor del camino por API, que ya reconstruye bloques, Elementor, entidades y
atributos sin romperlos.

Un contenido solo se escribe cuando han llegado **todas** sus cadenas. Si
falta alguna, lo recibido se conserva y Claude envía solo lo que falta. Una
página nunca queda a medio traducir.

La conexión usa OAuth 2.0 con registro dinámico de clientes y PKCE, como exige
Claude. Los tokens se guardan cifrados con hash y solo sirven para el endpoint
MCP, nunca para el resto de la API REST ni para `wp-admin`.

### Si no conecta

- **«Couldn't reach the MCP server»**: el sitio no es accesible desde internet, o un firewall bloquea el rango de Anthropic (`160.79.104.0/21`).
- **Un plugin de seguridad cierra la API REST** a usuarios anónimos: permite las rutas `/wp-json/wit/v1/`.
- **Apache no deja pasar la cabecera `Authorization`**: comprueba que el `.htaccess` de WordPress contiene `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` (WordPress la incluye por defecto).
- **WordPress en una subcarpeta**: funciona, porque el descubrimiento OAuth se sirve también dentro de la propia API REST.
- **nginx bloquea las rutas con punto**: muchas configuraciones endurecidas llevan `location ~ /\. { deny all; }`, que también bloquea `/.well-known/`, y el descubrimiento OAuth vive ahí por especificación. Cámbiala a `location ~ /\.(?!well-known) { deny all; }`. Es la misma excepción que ya necesitan los certificados de Let's Encrypt, así que en muchos servidores ya está puesta. Para comprobarlo, abre `https://tu-sitio/wp-json/wit/v1/oauth/.well-known/openid-configuration`: debe mostrar JSON, no un 403.

## 🏗️ Arquitectura Técnica

### Estructura de Archivos

```
wpml-imagina-translate/
├── wpml-imagina-translate.php          # Plugin principal
├── includes/
│   ├── class-admin-app.php             # Menú y pantallas: un punto de montaje por pantalla
│   ├── class-admin-rest.php            # API REST del panel (wit/admin/v1)
│   ├── class-settings.php              # Ajustes y su saneado
│   ├── class-translator-engine.php     # Motor de traducción (APIs)
│   ├── class-content-parser.php        # Gutenberg y editor clásico
│   ├── class-elementor-handler.php     # Elementor y Elementor Pro
│   ├── class-wpml-integration.php      # Integración con WPML
│   ├── class-wpml-native.php           # APIs internas de WPML real (estados, campos)
│   ├── class-strings.php               # WPML String Translation
│   ├── class-translation-manager.php   # Orquestador y registro
│   ├── class-queue.php                 # Cola en segundo plano (WP-Cron)
│   ├── class-abilities.php             # Herramientas MCP (Abilities API)
│   ├── class-oauth.php, class-mcp-server.php
│   └── …
├── admin-ui/                           # Fuente de la interfaz (React + TypeScript)
│   └── src/pages/{dashboard,content,strings,history,settings}/
└── assets/app/                         # Interfaz compilada (va en el ZIP, no en git)
```

### Flujo de Traducción

1. **Usuario elige contenidos** → pantalla Contenidos (o Claude por MCP)
2. **Sistema detecta contenido** → Content Parser
3. **Extrae bloques de Gutenberg** → Preserva estructura
4. **Traduce con IA** → Translator Engine (OpenAI/Claude/Gemini)
5. **Traduce meta fields** → SEO, excerpt, campos personalizados
6. **Crea post en WPML** → WPML Integration
7. **Vincula traducción** → Trid (translation group)
8. **Copia metadatos** → Taxonomías, featured image
9. **Registra log** → Translation Manager

### Base de Datos

Tabla: `wp_wit_translation_logs`

```sql
- id: bigint(20)
- post_id: bigint(20)
- source_lang: varchar(10)
- target_lang: varchar(10)
- ai_provider: varchar(50)
- status: varchar(20)
- message: text
- created_at: datetime
```

## 🔍 Comparación de Costos

### Ejemplo: E-commerce con 500 productos

| Método | Costo | Tiempo |
|--------|-------|--------|
| **WPML Credits (DeepL)** | $120 - $200 | 1 hora |
| **Este plugin + GPT-4o Mini** | ~$5 | 30 min |
| **Este plugin + Claude Haiku** | ~$30 | 30 min |
| **Este plugin + Gemini Flash** | **GRATIS** | 45 min |

**Ahorro potencial: $115 - $200 por proyecto**

## 🛠️ Troubleshooting

### Error: "API key no configurada"
**Solución:** Ve a **IA Translate → Ajustes** y configura tu API key.

### Error: "WPML no está activo"
**Solución:** Instala y activa WPML (Multilingual CMS).

### Error: "Respuesta inválida de [proveedor]"
**Solución:**
1. Verifica que tu API key sea correcta
2. Verifica que tengas créditos/saldo en tu cuenta
3. Prueba con otro modelo (ej: GPT-4o Mini en vez de GPT-4o)

### Las traducciones no preservan el formato
**Solución:** Asegúrate de que el prompt incluya instrucciones para mantener HTML:
```
Maintain all HTML tags, formatting, and structure.
```

### Meta fields de SEO no se traducen
**Solución:**
1. Activa "Traducir Meta Fields" en configuración
2. Agrega los meta fields a la lista (ej: `_yoast_wpseo_title,_yoast_wpseo_metadesc`)

## 🚦 Roadmap (Fase 2)

### Features Planeados
- [x] Memoria de traducción con caché local
- [x] Glosario personalizado (términos técnicos)
- [x] Elementor, widgets clásicos y atómicos (probado con 4.0.8), y Elementor Pro (4.2.3)
- [ ] Divi
- [x] Detección de cambios (estado «desactualizado»)
- [ ] Re-traducción automática al cambiar el original
- [ ] Integración visual en WPML UI
- [ ] Soporte para ACF (Advanced Custom Fields)
- [ ] Export/Import de traducciones
- [ ] Estadísticas de costos por traducción
- [ ] Webhooks para notificaciones
- [ ] API REST para integraciones externas

## 📄 Licencia

GPL v2 or later

## 👨‍💻 Autor

**Imagina**
GitHub: [@augusto97](https://github.com/augusto97)

## 🤝 Contribuciones

Las contribuciones son bienvenidas! Por favor:

1. Fork el repositorio
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## ⚠️ Disclaimer

Este plugin usa APIs de terceros (OpenAI, Anthropic, Google). Los costos de uso de las APIs son responsabilidad del usuario. Lee los términos de servicio de cada proveedor antes de usar.

## 📞 Soporte

¿Problemas? Abre un issue en GitHub:
https://github.com/augusto97/wpml-imagina-translate/issues

---

**¿Te gusta este plugin? Dale una ⭐ en GitHub!**

---

## ⚠️ Limitaciones conocidas

Cosas que el plugin **no** hace todavía, para que no te pillen por sorpresa:

- **Patrones sincronizados (`wp:block`)**: el contenido vive en otro post
  (`wp_block`). El post anfitrión se traduce, pero el patrón en sí hay que
  traducirlo por separado y el `ref` no se remapea al patrón traducido.
- **Block Bindings** (`metadata.bindings`, WP 6.5+): cuando el texto de un
  bloque viene de un campo personalizado, el HTML almacenado es solo un
  *fallback* que se reemplaza al renderizar. El plugin deja ese subárbol intacto
  (correcto), pero no traduce el valor de origen.
- **Plantillas FSE**: solo son traducibles las que están en base de datos
  (`source === 'custom'`). Las que son ficheros del tema no se tocan.
- **Menús de navegación** (`wp_navigation`): mismo caso que los patrones.
- **Campos ACF y meta personalizados**: solo se traducen los que declares
  explícitamente en la lista de meta fields.
- **Elementor Global Classes** (v4): son por Kit y no viven en `_elementor_data`;
  si WPML asigna un Kit distinto por idioma habrá que revisarlas a mano.

## 🧪 Tests

### Unitarios

```bash
php tests/run.php
```

No necesita WordPress, ni base de datos, ni Composer. Cubre el tokenizador de
HTML (fidelidad byte a byte, entidades, aislamiento de atributos), las reglas de
campos traducibles, el glosario y el protocolo de lote.

### Integración

```bash
tests/integration/setup.sh     # instala WordPress + MariaDB (una vez)
tests/integration/run.sh       # ejecuta la suite (repetible)
```

Instala WordPress de verdad y conduce el plugin como lo haría un sitio: un
doble de WPML sobre una tabla `icl_translations` real (o WPML real, si le pasas
sus ZIP), un proveedor de IA local que responde mal a propósito, y contenido
hostil. Comprueba fidelidad del contenido, enlazado de taxonomías, la cola bajo
WP-Cron, la API REST del panel por HTTP real con sus permisos, y las pantallas en
un Chromium de verdad (Playwright): que montan sin errores, que traducir, revisar
y guardar ajustes funcionan, y que en un móvil no hay desplazamiento lateral.

Los tests unitarios pasaban al 100 % mientras el plugin tenía siete fallos que
solo esta suite podía ver. Detalles en
[`tests/integration/README.md`](tests/integration/README.md).

## 📦 Construir el instalable

```bash
tools/build-zip.sh          # deja el ZIP en dist/
```

Empaqueta desde `git archive HEAD`, así que solo entra lo commiteado. Compila la
interfaz (`admin-ui/`) a partir de ese mismo HEAD y mete solo el resultado
(`assets/app`), sin fuentes ni `node_modules`. Excluye `tests/`, `tools/` y
`.github/`, y verifica el resultado antes de darlo por bueno: que cada pantalla
tenga su bundle, que todos los PHP parseen, que cada `require_once` resuelva dentro del
archivo, que haya una única carpeta raíz y que la versión del encabezado
coincida con `WIT_VERSION` — si no coinciden, un sitio actualizado se saltaría
la creación de tablas y fallaría en silencio.

CI lo ejecuta en cada push y sube el ZIP como artefacto.

## 🔧 Notas para desarrolladores

Filtros disponibles:

```php
// Estado con el que se crean las traducciones nuevas (por defecto 'draft')
add_filter( 'wit_new_translation_status', fn() => 'publish' );

// Registrar en el log cada cadena enviada y recibida (por defecto solo con WP_DEBUG)
add_filter( 'wit_verbose_debug', '__return_true' );

// No crear términos que falten al traducir (por defecto sí se crean)
add_filter( 'wit_create_missing_terms', '__return_false' );
```

### La interfaz (`admin-ui/`)

El mismo stack que Imagina Updater: React 18, TypeScript estricto, Vite,
Tailwind con prefijo `wit-` (sin preflight: no toca el resto de wp-admin),
componentes de shadcn/ui copiados al proyecto, iconos Lucide y TanStack Query y
Table. Cada pantalla es una página de WordPress cuyo PHP solo imprime
`<div id="wit-{pantalla}">`; su bundle se carga solo en esa página, como módulo,
y habla con `wit/admin/v1` con el nonce `wp_rest`.

```bash
cd admin-ui
npm ci            # una vez
npm run build     # tsc --noEmit + vite build → ../assets/app
```

`assets/app` no está en git: lo genera el build y lo incluye el ZIP. Sin él,
cada pantalla muestra un aviso en lugar de quedarse en blanco.

Arquitectura:

| Clase | Responsabilidad |
|---|---|
| `WIT_Admin_App` | Menú, puntos de montaje y carga de cada pantalla con su configuración |
| `WIT_Admin_REST` | La API del panel: contenidos, revisión, cola, cadenas, historial, ajustes |
| `WIT_HTML_Translator` | Tokeniza HTML y sustituye texto por posición, sin re-serializar |
| `WIT_Field_Rules` | Decide qué claves de `attrs`/`settings` contienen texto traducible |
| `WIT_Translator_Engine` | Llamadas a la API, protocolo de lote con marcadores, reintentos |
| `WIT_Content_Parser` | Gutenberg (`parse_blocks`/`serialize_blocks`) y editor clásico |
| `WIT_Elementor_Handler` | `_elementor_data`, incluidos los widgets *atomic* de la v4 |
| `WIT_WPML_Integration` | Creación y vinculación de traducciones vía hooks de WPML |
| `WIT_Translation_Memory` | Caché de cadenas ya traducidas por par de idiomas |
| `WIT_Glossary` | Términos protegidos y traducciones fijas |
| `WIT_Queue` | Cola drenada por WP-Cron para lotes grandes |
