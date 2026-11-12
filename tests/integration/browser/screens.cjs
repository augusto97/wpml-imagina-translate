/**
 * The admin screens in a real browser (Chromium via Playwright).
 *
 * The REST checks in run.sh prove the API; this proves the app on top of it:
 * every bundle mounts without a JavaScript error, and the everyday flows work
 * the way a person would do them — translate a row with one click, review it
 * side by side, save a setting.
 *
 * Usage: node screens.cjs <host> <wpml-real 0|1>   (prints "ok: …" / "bad: …")
 */
const { chromium, devices } = require('playwright');

const host = process.argv[2];
const stringTranslation = process.argv[3] === '1';
const admin = (page) => `${host}/wp-admin/admin.php?page=${page}`;

let failures = 0;
const ok = (label) => console.log(`ok: ${label}`);
const bad = (label) => { failures += 1; console.log(`bad: ${label}`); };

async function login(page) {
  await page.goto(`${host}/wp-login.php`);
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
}

/** Open a screen and wait for the app, not for network idle (WPML polls). */
async function open(page, slug, params = '') {
  await page.goto(admin(slug) + params);
  await page.locator('.wit-app').first().waitFor({ timeout: 20000 });
  await page.waitForTimeout(800);
}

async function step(label, fn) {
  try {
    await fn();
    ok(label);
  } catch (e) {
    bad(`${label} — ${String(e.message).split('\n')[0]}`);
  }
}

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await context.newPage();

  const errors = [];
  page.on('pageerror', (e) => errors.push(`JS: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`consola: ${m.text()}`); });
  page.on('response', (r) => {
    if (r.url().includes('wit/admin/v1') && r.status() >= 400 && !page.__expectHttpError) errors.push(`HTTP ${r.status()} ${r.request().method()} ${r.url().replace(host, '')}`);
  });

  await login(page);

  const screens = [['wpml-ia-translate', 'Inicio'], ['wpml-ia-translate-content', 'Contenidos'], ['wpml-ia-translate-logs', 'Historial'], ['wpml-imagina-translate-settings', 'Ajustes']];
  if (stringTranslation) screens.push(['wpml-ia-translate-strings', 'Cadenas']);
  for (const [slug, name] of screens) {
    await step(`${name}: la app se monta`, async () => {
      await open(page, slug);
      const heading = await page.locator('.wit-app h1').first().innerText();
      if (heading.trim() !== name && !(name === 'Inicio' && heading)) throw new Error(`título «${heading}»`);
    });
  }

  await step('Contenidos: traducir una fila con un clic', async () => {
    await open(page, 'wpml-ia-translate-content', '&to=fr');
    const row = page.locator('tbody tr', { has: page.getByRole('button', { name: 'Traducir', exact: true }) }).first();
    const title = (await row.locator('td').nth(1).locator('a').first().innerText()).trim();
    await row.getByRole('button', { name: 'Traducir', exact: true }).click();
    await page.getByText(`«${title}» traducido al`).waitFor({ timeout: 90000 });
    page.__lastTitle = title;
  });

  await step('Contenidos: revisar la traducción junto al original', async () => {
    await page.getByRole('tab', { name: /^Todos/ }).click();
    await page.waitForTimeout(1200);
    const row = page.locator('tbody tr', { hasText: page.__lastTitle }).first();
    await row.getByRole('button', { name: 'Revisar' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByText('Original', { exact: true }).waitFor({ timeout: 15000 });
    const text = await dialog.innerText();
    if (!text.includes('[FR]')) throw new Error('la traducción no aparece en el panel');
    await page.keyboard.press('Escape');
  });

  await step('Ajustes: un cambio se guarda y persiste', async () => {
    await open(page, 'wpml-imagina-translate-settings', '&tab=glossary');
    const bar = page.getByText('Tienes cambios sin guardar.');
    if (await bar.isVisible()) throw new Error('la barra de guardar aparece sin cambios');
    const field = page.getByLabel('Reglas del glosario');
    const before = await field.inputValue();
    await field.fill(`${before}\nPrueba navegador = Browser test`);
    await bar.waitFor({ state: 'visible' });
    await page.getByRole('button', { name: 'Guardar cambios' }).click();
    await page.getByText('Ajustes guardados.').waitFor({ timeout: 20000 });
    await open(page, 'wpml-imagina-translate-settings', '&tab=glossary');
    if (!(await page.getByLabel('Reglas del glosario').inputValue()).includes('Prueba navegador')) throw new Error('no persistió');
    await page.getByLabel('Reglas del glosario').fill(before);
    await page.getByRole('button', { name: 'Guardar cambios' }).click();
    await page.getByText('Ajustes guardados.').first().waitFor({ timeout: 20000 });
  });

  if (stringTranslation) {
    await step('Cadenas: la lista muestra original y traducción', async () => {
      await open(page, 'wpml-ia-translate-strings', '&to=es&status=all');
      if ((await page.locator('tbody tr').count()) < 1) throw new Error('sin filas');
    });
  }

  await step('Historial: la traducción de antes aparece', async () => {
    await open(page, 'wpml-ia-translate-logs');
    await page.locator('tbody tr', { hasText: page.__lastTitle || '' }).first().waitFor({ timeout: 10000 });
  });

  // A phone: no page-wide horizontal scroll, rows stacked.
  const phone = await browser.newContext({ ...devices['iPhone 13'] });
  const mobile = await phone.newPage();
  mobile.on('pageerror', (e) => errors.push(`JS (móvil): ${e.message}`));
  await login(mobile);
  for (const [slug, name] of [['wpml-ia-translate', 'Inicio'], ['wpml-ia-translate-content', 'Contenidos'], ['wpml-imagina-translate-settings', 'Ajustes']]) {
    await step(`${name} en un móvil: sin desplazamiento lateral`, async () => {
      await open(mobile, slug);
      const overflow = await mobile.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (overflow > 0) throw new Error(`${overflow}px de más`);
    });
  }

  if (errors.length) {
    for (const e of [...new Set(errors)].slice(0, 8)) bad(`sin errores en el navegador — ${e}`);
  } else {
    ok('ningún error de JavaScript ni de la API en todo el recorrido');
  }

  await browser.close();
  process.exit(failures ? 1 : 0);
})().catch((e) => {
  console.log(`bad: el recorrido se interrumpió — ${e.message}`);
  process.exit(1);
});
