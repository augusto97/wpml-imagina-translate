import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Pantallas del admin. Cada una compila a un bundle propio que el PHP
 * encola solo en su página (WIT_Admin_App), como en Imagina Updater.
 * Para añadir una: `src/pages/<slug>/index.tsx` y el slug aquí.
 */
const PAGES = ['dashboard', 'content', 'strings', 'history', 'settings'] as const;

/**
 * Escribe `app.asset.php` con una versión derivada de TODO lo compilado
 * (JS y CSS), para que el navegador nunca sirva un bundle viejo tras
 * actualizar el plugin. Mismo formato que @wordpress/scripts.
 */
function emitWordpressAssetFile(): Plugin {
  return {
    name: 'emit-wordpress-asset-file',
    apply: 'build',
    writeBundle(options, bundle) {
      const outDir = options.dir;
      if (!outDir) return;
      const hash = createHash('sha256');
      for (const name of Object.keys(bundle).sort()) {
        const item = bundle[name];
        if (!item) continue;
        hash.update(name);
        hash.update(item.type === 'chunk' ? item.code : String(item.source));
      }
      const version = hash.digest('hex').slice(0, 12);
      writeFileSync(
        `${outDir}/app.asset.php`,
        `<?php\n// Generado por Vite. No editar a mano.\nreturn array(\n\t'dependencies' => array(),\n\t'version' => '${version}',\n\t'pages' => array('${PAGES.join("', '")}'),\n);\n`,
      );
    },
  };
}

export default defineConfig({
  plugins: [react(), emitWordpressAssetFile()],
  // Servido desde wp-content/plugins/<slug>/assets/app/: rutas relativas
  // al propio CSS (las fuentes Inter), no a la raíz del sitio.
  base: './',
  resolve: {
    alias: { '@': resolve(__dirname, 'src') },
  },
  build: {
    outDir: '../assets/app',
    emptyOutDir: true,
    sourcemap: false,
    // Una sola hoja de estilos para todas las pantallas: todas importan
    // el mismo globals.css.
    cssCodeSplit: false,
    rollupOptions: {
      input: Object.fromEntries(PAGES.map((p) => [p, resolve(__dirname, `src/pages/${p}/index.tsx`)])),
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (assetInfo) =>
          assetInfo.name?.endsWith('.css') ? 'app.css' : 'assets/[name]-[hash][extname]',
      },
    },
  },
});
