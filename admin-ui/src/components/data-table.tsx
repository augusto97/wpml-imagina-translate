import type * as React from 'react';
import { flexRender, type Table as RTable } from '@tanstack/react-table';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

/**
 * Wrapper minimalista alrededor de TanStack Table v8 con los
 * primitives shadcn ya prefijados.
 *
 * Diseño deliberadamente plano: la paginación, el filtrado y el
 * ordering los maneja el servidor (REST `?page=&search=&status=`).
 * Ese trade-off mantiene los datasets grandes eficientes y el
 * componente sin estado interno complicado.
 *
 * El consumer crea la instancia con `useReactTable` y la pasa por
 * prop. Eso permite cablear `state.columnVisibility` y otros
 * estados controlados (sorting, etc.) directamente desde la página
 * sin que DataTable tenga que reflejar cada feature como prop.
 *
 * Helper opcional `<DataTableColumnsToggle table={table} />` para
 * exponer el dropdown de visibilidad de columnas.
 */
interface DataTableProps<TData> {
  table: RTable<TData>;
  emptyMessage?: string;
  /** Número total de columnas (incluyendo ocultas) para el colspan. */
  colSpan?: number;
  /**
   * En pantallas estrechas, cada fila como una tarjeta apilada en lugar de
   * una tabla que obliga a desplazarse de lado para ver el estado.
   */
  renderMobile?: (row: TData) => React.ReactNode;
}

export function DataTable<TData>({
  table,
  emptyMessage = 'Sin resultados.',
  colSpan,
  renderMobile,
}: DataTableProps<TData>) {
  const visibleColumnCount =
    colSpan ?? table.getVisibleLeafColumns().length;

  const desktop = (
    // En móvil, scroll horizontal en vez de columnas de una letra de ancho.
    <Table className="wit-min-w-[44rem]">
      <TableHeader>
        {table.getHeaderGroups().map((headerGroup) => (
          <TableRow key={headerGroup.id}>
            {headerGroup.headers.map((header) => (
              <TableHead key={header.id}>
                {header.isPlaceholder
                  ? null
                  : flexRender(
                      header.column.columnDef.header,
                      header.getContext(),
                    )}
              </TableHead>
            ))}
          </TableRow>
        ))}
      </TableHeader>
      <TableBody>
        {table.getRowModel().rows.length === 0 ? (
          <TableRow>
            <TableCell
              colSpan={visibleColumnCount}
              className="wit-py-6 wit-text-center wit-text-sm wit-text-muted-foreground"
            >
              {emptyMessage}
            </TableCell>
          </TableRow>
        ) : (
          table.getRowModel().rows.map((row) => (
            <TableRow key={row.id}>
              {row.getVisibleCells().map((cell) => (
                <TableCell key={cell.id}>
                  {flexRender(cell.column.columnDef.cell, cell.getContext())}
                </TableCell>
              ))}
            </TableRow>
          ))
        )}
      </TableBody>
    </Table>
  );

  if (!renderMobile) return desktop;

  return (
    <>
      <ul className="wit-divide-y wit-divide-border sm:wit-hidden">
        {table.getRowModel().rows.map((row) => (
          <li key={row.id} className="wit-px-3 wit-py-3">{renderMobile(row.original)}</li>
        ))}
      </ul>
      <div className="wit-hidden sm:wit-block">{desktop}</div>
    </>
  );
}
