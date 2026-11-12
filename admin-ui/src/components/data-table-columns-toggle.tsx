import { useState } from 'react';
import { type Table } from '@tanstack/react-table';
import { Columns3 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent } from '@/components/ui/popover';

/**
 * Dropdown de visibilidad de columnas para una tabla TanStack.
 *
 * Filtra automáticamente las columnas "no toggleables" (las que
 * no tienen `accessorKey` o `id` legible, ej. la columna de
 * acciones). El admin nunca debería poder ocultar la columna de
 * acciones.
 *
 * Etiqueta de cada columna: prioridad
 *   1. `meta.label` (string custom)
 *   2. `id`
 *   3. `accessorKey`
 *   4. fallback "Columna"
 */

interface Props<TData> {
  table: Table<TData>;
  /** Columnas que NUNCA aparecen en el toggle (ej. ['actions']). */
  pinnedIds?: string[];
}

export function DataTableColumnsToggle<TData>({
  table,
  pinnedIds = [],
}: Props<TData>) {
  const [open, setOpen] = useState(false);

  const toggleable = table
    .getAllLeafColumns()
    .filter((c) => c.getCanHide() && !pinnedIds.includes(c.id));

  if (toggleable.length === 0) return null;

  return (
    <Popover
      open={open}
      onOpenChange={setOpen}
      trigger={
        <Button variant="outline" size="sm">
          <Columns3 className="wit-h-4 wit-w-4" />
          Columnas
        </Button>
      }
    >
      <PopoverContent className="wit-min-w-[14rem] wit-p-2">
        <p className="wit-px-2 wit-py-1 wit-text-xs wit-font-medium wit-uppercase wit-tracking-wide wit-text-muted-foreground">
          Mostrar columnas
        </p>
        <ul className="wit-space-y-0.5">
          {toggleable.map((column) => {
            const meta = column.columnDef.meta as { label?: string } | undefined;
            const label =
              meta?.label ??
              column.id ??
              'Columna';
            return (
              <li key={column.id}>
                <label className="wit-flex wit-cursor-pointer wit-items-center wit-gap-2 wit-rounded-sm wit-px-2 wit-py-1.5 wit-text-sm hover:wit-bg-muted">
                  <input
                    type="checkbox"
                    checked={column.getIsVisible()}
                    onChange={(e) => column.toggleVisibility(e.target.checked)}
                  />
                  <span className="wit-text-foreground">{label}</span>
                </label>
              </li>
            );
          })}
        </ul>
      </PopoverContent>
    </Popover>
  );
}
