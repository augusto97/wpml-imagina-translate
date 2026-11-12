import { useCallback, useEffect, useState } from 'react';
import type { VisibilityState } from '@tanstack/react-table';

/**
 * Estado de visibilidad de columnas persistido en localStorage.
 *
 * @param storageKey  Identificador único de la tabla
 *                    (ej. `iaud:plugins:cols`).
 * @param defaults    Estado por defecto. Cualquier columna no
 *                    declarada aquí se asume visible (TanStack
 *                    Table trata `undefined` como `true`).
 */
export function useColumnVisibility(
  storageKey: string,
  defaults: VisibilityState = {},
) {
  const [visibility, setVisibilityState] = useState<VisibilityState>(() => {
    try {
      const raw = window.localStorage.getItem(storageKey);
      if (raw) {
        const parsed = JSON.parse(raw) as VisibilityState;
        if (parsed && typeof parsed === 'object') {
          return { ...defaults, ...parsed };
        }
      }
    } catch {
      /* storage no disponible (modo private, política, etc.) */
    }
    return defaults;
  });

  useEffect(() => {
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(visibility));
    } catch {
      /* idem */
    }
  }, [storageKey, visibility]);

  // Acepta tanto un updater function como un valor directo, igual
  // que TanStack Table espera para `onColumnVisibilityChange`.
  const setVisibility = useCallback(
    (
      updater: VisibilityState | ((prev: VisibilityState) => VisibilityState),
    ) => {
      setVisibilityState((prev) =>
        typeof updater === 'function' ? updater(prev) : updater,
      );
    },
    [],
  );

  return [visibility, setVisibility] as const;
}
