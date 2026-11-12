/**
 * Avisos efímeros (toasts) para toda la app.
 *
 * Mismo principio que el bus de errores de Imagina Updater: TanStack Query
 * captura el rechazo de `mutate()`, así que sin un bus global un fallo no
 * se ve. Aquí además caben las confirmaciones de éxito, que en una app
 * fácil de usar importan tanto como los errores.
 */

export type ToastKind = 'success' | 'error' | 'info';

export interface ToastEntry {
  id: number;
  kind: ToastKind;
  message: string;
}

type Listener = (entries: ToastEntry[]) => void;

let entries: ToastEntry[] = [];
let nextId = 1;
const listeners = new Set<Listener>();

function emit() {
  for (const listener of listeners) listener(entries);
}

export function subscribe(listener: Listener): () => void {
  listeners.add(listener);
  listener(entries);
  return () => {
    listeners.delete(listener);
  };
}

export function dismiss(id: number): void {
  entries = entries.filter((e) => e.id !== id);
  emit();
}

export function notify(message: string, kind: ToastKind = 'success'): void {
  const entry = { id: nextId++, kind, message };
  entries = [...entries.slice(-3), entry];
  emit();
  window.setTimeout(() => dismiss(entry.id), kind === 'error' ? 9000 : 4500);
}

export function notifyError(error: unknown): void {
  notify(error instanceof Error && error.message ? error.message : 'Algo falló. Inténtalo de nuevo.', 'error');
}
