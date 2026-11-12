import type { Config } from 'tailwindcss';
import animate from 'tailwindcss-animate';

/**
 * Tailwind config para el admin SPA del servidor.
 *
 * Decisiones clave (CLAUDE.md §5):
 * - `prefix: 'wit-'` evita colisiones con clases del propio wp-admin
 *   y de cualquier otro plugin que use Tailwind sin prefijar.
 * - `corePlugins.preflight: false`: NO resetear estilos globales del
 *   admin de WP. El reset que sí queremos vive scoped en `.wit-app`
 *   dentro de `globals.css`.
 * - Tokens semánticos (`bg-wit-background`, `text-wit-foreground`,
 *   etc.) leen variables CSS definidas en `globals.css`. Esto permite
 *   cambiar la paleta corporativa modificando solo las variables.
 */
const config = {
  prefix: 'wit-',
  darkMode: 'class', // anclado para Fase 5.B (modo oscuro diferido).
  // important con un selector prefija TODAS las utility classes con
  // `.wit-app`, p.ej. `.wit-px-3` se vuelve `.wit-app .wit-px-3`.
  // Esto sube la specificity de las utilities a (0, 2, 0), suficiente
  // para ganarle al `input[type=text] { ... }` (0, 1, 1) que define
  // `wp-admin/css/forms.css` y al reset propio (0, 1, 1) en globals.css.
  important: '.wit-app',
  content: ['./src/**/*.{ts,tsx}', './index.html'],
  corePlugins: {
    preflight: false,
  },
  theme: {
    container: {
      center: true,
      padding: '1rem',
    },
    extend: {
      colors: {
        border: 'hsl(var(--wit-border))',
        input: 'hsl(var(--wit-input))',
        ring: 'hsl(var(--wit-ring))',
        background: 'hsl(var(--wit-background))',
        foreground: 'hsl(var(--wit-foreground))',
        primary: {
          DEFAULT: 'hsl(var(--wit-primary))',
          foreground: 'hsl(var(--wit-primary-foreground))',
        },
        secondary: {
          DEFAULT: 'hsl(var(--wit-secondary))',
          foreground: 'hsl(var(--wit-secondary-foreground))',
        },
        muted: {
          DEFAULT: 'hsl(var(--wit-muted))',
          foreground: 'hsl(var(--wit-muted-foreground))',
        },
        accent: {
          DEFAULT: 'hsl(var(--wit-accent))',
          foreground: 'hsl(var(--wit-accent-foreground))',
        },
        destructive: {
          DEFAULT: 'hsl(var(--wit-destructive))',
          foreground: 'hsl(var(--wit-destructive-foreground))',
        },
        card: {
          DEFAULT: 'hsl(var(--wit-card))',
          foreground: 'hsl(var(--wit-card-foreground))',
        },
        popover: {
          DEFAULT: 'hsl(var(--wit-popover))',
          foreground: 'hsl(var(--wit-popover-foreground))',
        },
      },
      borderRadius: {
        lg: 'var(--wit-radius)',
        md: 'calc(var(--wit-radius) - 2px)',
        sm: 'calc(var(--wit-radius) - 4px)',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      keyframes: {
        'accordion-down': {
          from: { height: '0' },
          to: { height: 'var(--radix-accordion-content-height)' },
        },
        'accordion-up': {
          from: { height: 'var(--radix-accordion-content-height)' },
          to: { height: '0' },
        },
      },
      animation: {
        'accordion-down': 'accordion-down 0.2s ease-out',
        'accordion-up': 'accordion-up 0.2s ease-out',
      },
    },
  },
  plugins: [animate],
} satisfies Config;

export default config;
