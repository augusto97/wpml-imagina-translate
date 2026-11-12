/**
 * Cliente REST del admin: `/wp-json/wit/admin/v1/`.
 *
 * `witConfig` lo inyecta el PHP con wp_localize_script al encolar cada
 * pantalla (WIT_Admin_App::enqueue). Todas las peticiones llevan el nonce
 * `wp_rest`; la cookie de sesión de WordPress sigue siendo obligatoria.
 */

declare global {
  interface Window {
    witConfig?: WitConfig;
  }
}

export interface WitConfig {
  adminUrl: string;
  nonce: string;
  locale: string;
  currentUser: string;
  pages: Record<PageSlug, string>;
  canManage: boolean;
  version: string;
  languages: { code: string; name: string; default: boolean }[];
  defaultLanguage: string;
  postTypes: { name: string; label: string }[];
  features: { strings: boolean; realWpml: boolean; abilities: boolean; apiKey: boolean };
}

export type PageSlug = 'dashboard' | 'content' | 'strings' | 'history' | 'settings';

export interface ApiError extends Error {
  status: number;
  code?: string;
}

export function config(): WitConfig {
  const cfg = window.witConfig;
  if (!cfg) {
    throw new Error('witConfig no está disponible: el PHP no encoló la configuración.');
  }
  return cfg;
}

export type Params = Record<string, string | number | boolean | string[] | undefined | null>;

function url(path: string, params?: Params): string {
  const base = config().adminUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
  const u = new URL(base, window.location.origin);
  for (const [key, value] of Object.entries(params ?? {})) {
    if (Array.isArray(value)) {
      // PHP lee `clave[]=a&clave[]=b` como un array.
      for (const item of value) u.searchParams.append(key + '[]', item);
    } else if (value !== undefined && value !== null && value !== '') {
      u.searchParams.set(key, String(value));
    }
  }
  return u.toString();
}

async function request<T>(method: string, path: string, body?: unknown, params?: Params): Promise<T> {
  const response = await fetch(url(path, params), {
    method,
    credentials: 'same-origin',
    headers: {
      'X-WP-Nonce': config().nonce,
      Accept: 'application/json',
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
    },
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  });

  if (!response.ok) {
    let payload: { code?: string; message?: string } = {};
    try {
      payload = (await response.json()) as typeof payload;
    } catch {
      // Un error del servidor en HTML: el status sigue informando.
    }
    const error = new Error(payload.message ?? `Error ${response.status} del servidor`) as ApiError;
    error.status = response.status;
    if (payload.code) error.code = payload.code;
    throw error;
  }

  return (await response.json()) as T;
}

export const api = {
  get: <T>(path: string, params?: Params) => request<T>('GET', path, undefined, params),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body ?? {}),
  del: <T>(path: string) => request<T>('DELETE', path),
};

export function pageUrl(slug: PageSlug, params?: Record<string, string>): string {
  const u = new URL(config().pages[slug], window.location.origin);
  for (const [key, value] of Object.entries(params ?? {})) {
    u.searchParams.set(key, value);
  }
  return u.toString();
}
