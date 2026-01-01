# WPML Imagina Translate

Plugin de WordPress para traducir automáticamente contenido usando tu propia API key de IA. Integración perfecta con WPML.

## 🚀 ¿Por qué este plugin?

WPML cobra caro por traducciones automáticas con IA. Este plugin te permite usar tu propia API key de OpenAI, Claude o Gemini, ahorrando costos significativos mientras mantienes total control sobre tus traducciones.

## ✨ Características

### Core Features
- **Multi-proveedor de IA**: OpenAI (GPT-4, GPT-4o), Anthropic Claude, Google Gemini
- **Batch Translation**: Traduce múltiples posts de una vez
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

### Seguridad
- Preserva shortcodes, HTML, y código
- No traduce atributos HTML ni clases CSS
- Blacklist configurable de contenido a no traducir
- Posts creados como borrador para revisión

## 📦 Instalación

### Requisitos
- WordPress 5.8 o superior
- PHP 7.4 o superior
- WPML Multilingual CMS (activo)
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
   - Ve a Settings → WPML IA Translate
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
- `gpt-4o-mini` - Más barato, excelente calidad
- `gpt-4o` - Mejor calidad, más costoso

**Costos aproximados:**
- GPT-4o Mini: ~$0.15 por millón de tokens (~$0.01 por página)
- GPT-4o: ~$2.50 por millón de tokens (~$0.15 por página)

#### Anthropic Claude
1. Ve a [console.anthropic.com](https://console.anthropic.com)
2. Crea una API key
3. Copia y pega en el plugin

**Modelos recomendados:**
- `claude-3-5-haiku-20241022` - Más barato
- `claude-3-5-sonnet-20241022` - Mejor calidad

**Costos aproximados:**
- Claude 3.5 Haiku: ~$1 por millón de tokens (~$0.06 por página)
- Claude 3.5 Sonnet: ~$3 por millón de tokens (~$0.18 por página)

#### Google Gemini
1. Ve a [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)
2. Crea una API key
3. Copia y pega en el plugin

**Modelos recomendados:**
- `gemini-1.5-flash` - Muy rápido y barato
- `gemini-1.5-pro` - Mayor capacidad

**Costos aproximados:**
- Gemini 1.5 Flash: GRATIS hasta 15 req/min
- Gemini 1.5 Pro: ~$1.25 por millón de tokens

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
**Solución:** Ve a Settings → WPML IA Translate y configura tu API key.

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
