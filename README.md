# WPML Imagina Translate

Plugin de WordPress para traducir automáticamente contenido usando tu propia API key de IA. Integración perfecta con WPML.

## 🚀 ¿Por qué este plugin?

WPML cobra caro por traducciones automáticas con IA. Este plugin te permite usar tu propia API key de OpenAI, Claude o Gemini, ahorrando costos significativos mientras mantienes total control sobre tus traducciones.

## ✨ Características

### Core Features
- **Multi-proveedor de IA**: OpenAI (GPT-4, GPT-4o), Anthropic Claude, Google Gemini
- **Memoria de traducción**: no vuelve a pagar por una frase ya traducida, y garantiza que se traduzca igual en todo el sitio
- **Glosario**: términos de marca que nunca se traducen y traducciones fijas por idioma
- **Cola en segundo plano**: encolas cientos de páginas y cierras el navegador; el servidor sigue
- **Smart Content Parser**: Preserva bloques de Gutenberg, HTML, y estructura
- **Meta Fields**: Traduce automáticamente SEO (Yoast, RankMath), excerpts, y campos personalizados
- **Dashboard Intuitivo**: Interfaz simple para gestionar traducciones
- **Translation Logs**: Historial completo de todas las traducciones
- **Progress Tracking**: Barra de progreso en tiempo real para traducciones batch

### Integración WPML
- Detecta posts pendientes de traducción automáticamente
- Crea posts traducidos vinculados correctamente en WPML
- Copia taxonomías, featured images, y metadatos
- Actualiza traducciones existentes

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
- Las API keys nunca se imprimen en el HTML de la página de ajustes
- La opción con las keys no se autocarga en cada petición del front-end
- La key de Gemini viaja en cabecera, no en la URL (no queda en logs)
- El texto devuelto por la IA se escapa antes de insertarse: no puede inyectar
  markup ni scripts
- Verificación de nonce y de capacidad `edit_post` por cada post
- El idioma destino se valida contra los idiomas activos de WPML

## 📦 Instalación

### Requisitos
- WordPress 6.0 o superior (probado hasta 7.1)
- PHP 7.4 o superior (probado hasta 8.4)
- WPML Multilingual CMS 4.7 o superior (probado con 4.9.x y 5.0 beta)
- Elementor 3.x o 4.x (opcional)
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
   - Ve a IA Translate → Dashboard
   - Selecciona idioma destino
   - Traduce posts

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

### Traducir Posts Individuales

1. Ve a **IA Translate → Dashboard**
2. Selecciona el idioma destino
3. (Opcional) Selecciona tipos de post (posts, páginas, CPTs)
4. Click en "Buscar Posts Pendientes"
5. Click en "Traducir Ahora" en el post deseado

### Traducción Batch (Múltiples Posts)

1. Ve a **IA Translate → Dashboard**
2. Selecciona el idioma destino
3. Click en "Buscar Posts Pendientes"
4. Selecciona los posts que quieres traducir
5. Click en "Traducir Seleccionados"
6. Observa el progreso en tiempo real

### Ver Logs de Traducción

1. Ve a **IA Translate → Logs**
2. Revisa el historial completo de traducciones
3. Identifica errores y posts traducidos exitosamente

## 🏗️ Arquitectura Técnica

### Estructura de Archivos

```
wpml-imagina-translate/
├── wpml-imagina-translate.php          # Plugin principal
├── includes/
│   ├── class-settings.php              # Gestión de configuración
│   ├── class-translator-engine.php     # Motor de traducción (APIs)
│   ├── class-content-parser.php        # Parser de Gutenberg
│   ├── class-wpml-integration.php      # Integración con WPML
│   ├── class-translation-manager.php   # Orquestador principal
│   └── class-batch-processor.php       # Procesamiento en lote
├── admin/
│   ├── class-translation-dashboard.php # Dashboard admin
│   ├── class-admin-ajax.php            # Handlers AJAX
│   └── views/
│       ├── dashboard.php               # Vista del dashboard
│       └── logs.php                    # Vista de logs
├── assets/
│   ├── css/
│   │   └── admin.css                   # Estilos admin
│   └── js/
│       └── admin.js                    # JavaScript admin
└── README.md                           # Este archivo
```

### Flujo de Traducción

1. **Usuario selecciona posts** → Dashboard
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
- [ ] Memoria de traducción con caché local
- [ ] Glosario personalizado (términos técnicos)
- [ ] Soporte para Elementor y Divi
- [ ] Detección de cambios y re-traducción automática
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

```bash
php tests/run.php
```

No necesita WordPress, ni base de datos, ni Composer. Cubre el tokenizador de
HTML (fidelidad byte a byte, entidades, aislamiento de atributos), las reglas de
campos traducibles, el glosario y el protocolo de lote.

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

Arquitectura:

| Clase | Responsabilidad |
|---|---|
| `WIT_HTML_Translator` | Tokeniza HTML y sustituye texto por posición, sin re-serializar |
| `WIT_Field_Rules` | Decide qué claves de `attrs`/`settings` contienen texto traducible |
| `WIT_Translator_Engine` | Llamadas a la API, protocolo de lote con marcadores, reintentos |
| `WIT_Content_Parser` | Gutenberg (`parse_blocks`/`serialize_blocks`) y editor clásico |
| `WIT_Elementor_Handler` | `_elementor_data`, incluidos los widgets *atomic* de la v4 |
| `WIT_WPML_Integration` | Creación y vinculación de traducciones vía hooks de WPML |
| `WIT_Translation_Memory` | Caché de cadenas ya traducidas por par de idiomas |
| `WIT_Glossary` | Términos protegidos y traducciones fijas |
| `WIT_Queue` | Cola drenada por WP-Cron para lotes grandes |
