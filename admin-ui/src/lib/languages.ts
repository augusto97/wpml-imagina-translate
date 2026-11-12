import { config } from './api';

/** Languages a post can be translated into: every active one but the default. */
export function targetLanguages() {
  const cfg = config();
  return cfg.languages.filter((l) => l.code !== cfg.defaultLanguage);
}

export function languageName(code: string): string {
  return config().languages.find((l) => l.code === code)?.name ?? code.toUpperCase();
}

/** Remembers the last language chosen, so every screen opens on it. */
export function rememberedLanguage(fallback: string): string {
  try {
    return window.localStorage.getItem('wit:language') ?? fallback;
  } catch {
    return fallback;
  }
}

export function rememberLanguage(code: string): void {
  try {
    window.localStorage.setItem('wit:language', code);
  } catch {
    // Private mode: nothing to remember, nothing broken.
  }
}

/** Read and write the screen's own query string, so links and reloads keep filters. */
export function queryParam(name: string): string {
  return new URLSearchParams(window.location.search).get(name) ?? '';
}

export function setQueryParams(params: Record<string, string | number | undefined>): void {
  const url = new URL(window.location.href);
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === '' || value === 0) url.searchParams.delete(key);
    else url.searchParams.set(key, String(value));
  }
  window.history.replaceState(null, '', url.toString());
}
