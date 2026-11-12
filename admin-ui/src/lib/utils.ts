import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge tiene que conocer el prefijo `wit-` (tailwind.config.ts):
 * sin él no reconoce `wit-flex-row` y `wit-flex-col` como la misma
 * propiedad, no resuelve el conflicto y gana la que aparezca después en el
 * CSS. Así se perdían en silencio las sobrescrituras que hacen las pantallas
 * sobre los componentes (p. ej. las tarjetas del dashboard salían en
 * columna y centradas pese a pedir `flex-row`).
 */
const twMerge = extendTailwindMerge({ prefix: 'wit-' });

/**
 * Combina clases utility de Tailwind con prioridad correcta cuando
 * hay conflictos (ej. `wit-p-2` + `wit-p-4` resuelve a `wit-p-4`).
 *
 * Uso estándar en todos los componentes shadcn/ui.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
