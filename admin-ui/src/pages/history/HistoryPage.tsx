import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table';
import { CheckCircle2, History, Settings2, Type, XCircle } from 'lucide-react';
import { api } from '@/lib/api';
import { languageName, queryParam, setQueryParams } from '@/lib/languages';
import { formatDateTime, formatNumber, formatRelativeTime } from '@/lib/format';
import type { LogRow } from '@/types';
import { AppShell } from '@/components/app-shell';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { DataTable } from '@/components/data-table';
import { Pagination } from '@/components/pagination';
import { EmptyState } from '@/components/empty-state';
import { Callout } from '@/components/callout';
import { Via } from '@/components/shared/via';

interface LogsResponse {
  items: LogRow[];
  total: number;
  page: number;
  pages: number;
}

/** Cada traducción y cada acción, con de dónde vino y cuánto costó. */
export function HistoryPage() {
  const [page, setPage] = useState(() => Math.max(1, Number(queryParam('paged')) || 1));

  useEffect(() => setQueryParams({ paged: page > 1 ? page : undefined }), [page]);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['logs', page],
    queryFn: () => api.get<LogsResponse>('logs', { page, per_page: 30 }),
    placeholderData: keepPreviousData,
    refetchInterval: 30000,
  });

  const columns = useMemo<ColumnDef<LogRow>[]>(() => [
    {
      id: 'date',
      header: 'Cuándo',
      cell: ({ row }) => (
        <span className="wit-whitespace-nowrap wit-text-muted-foreground" title={formatDateTime(row.original.date)}>
          {formatRelativeTime(row.original.date)}
        </span>
      ),
    },
    {
      id: 'what',
      header: 'Qué',
      cell: ({ row }) => <What row={row.original} />,
    },
    {
      id: 'via',
      header: 'Desde',
      cell: ({ row }) => <span className="wit-whitespace-nowrap wit-text-sm"><Via via={row.original.via} /></span>,
    },
    {
      id: 'usage',
      header: 'Uso',
      cell: ({ row }) => <Usage row={row.original} />,
    },
  ], []);

  const table = useReactTable({
    data: data?.items ?? [],
    columns,
    getRowId: (r) => String(r.id),
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
  });

  return (
    <AppShell page="history" title="Historial" description="Cada traducción y cada cambio: qué se hizo, desde dónde y cuánto consumió.">
      <Card className="wit-overflow-hidden">
        {isError ? (
          <div className="wit-p-3"><Callout kind="error">{error instanceof Error ? error.message : 'No se pudo cargar el historial.'}</Callout></div>
        ) : isLoading ? (
          <div className="wit-space-y-2 wit-p-3">{[0, 1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="wit-h-12" />)}</div>
        ) : !data || data.items.length === 0 ? (
          <EmptyState icon={History} title="Todavía no hay actividad" description="Cuando traduzcas algo, desde aquí o desde Claude, quedará registrado en esta lista." />
        ) : (
          <>
            <div className="wit-overflow-x-auto">
              <DataTable
                table={table}
                renderMobile={(r) => (
                  <div className="wit-space-y-1.5">
                    <What row={r} />
                    <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-x-3 wit-gap-y-1 wit-pl-6 wit-text-xs wit-text-muted-foreground">
                      <span title={formatDateTime(r.date)}>{formatRelativeTime(r.date)}</span>
                      <Via via={r.via} />
                      <Usage row={r} />
                    </div>
                  </div>
                )}
              />
            </div>
            <Pagination page={data.page} pages={data.pages} total={data.total} onChange={setPage} noun="registros" />
          </>
        )}
      </Card>
    </AppShell>
  );
}

function What({ row }: { row: LogRow }) {
  const ok = row.status === 'success';
  const Icon = row.kind === 'strings' ? Type : row.kind === 'settings' ? Settings2 : null;
  const title = row.kind === 'strings' ? 'Cadenas de String Translation' : row.kind === 'settings' ? 'Ajustes' : row.title;

  return (
    <div className="wit-flex wit-min-w-0 wit-max-w-xl wit-items-start wit-gap-2">
      {ok
        ? <CheckCircle2 className="wit-mt-0.5 wit-h-4 wit-w-4 wit-flex-shrink-0 wit-text-emerald-600" aria-label="Correcto" />
        : <XCircle className="wit-mt-0.5 wit-h-4 wit-w-4 wit-flex-shrink-0 wit-text-destructive" aria-label="Error" />}
      <div className="wit-min-w-0">
        <p className="wit-flex wit-items-center wit-gap-1.5 wit-font-medium">
          {Icon && <Icon className="wit-h-3.5 wit-w-3.5 wit-text-muted-foreground" />}
          {row.kind === 'post' && row.edit_url
            ? <a href={row.edit_url} className="wit-truncate wit-text-foreground hover:wit-text-primary">{title}</a>
            : <span className="wit-truncate">{title}</span>}
          {row.to && <span className="wit-whitespace-nowrap wit-text-xs wit-font-normal wit-text-muted-foreground">→ {languageName(row.to)}</span>}
        </p>
        {row.message && (
          <p className={ok ? 'wit-text-xs wit-text-muted-foreground' : 'wit-text-xs wit-text-destructive'}>{row.message}</p>
        )}
      </div>
    </div>
  );
}

function Usage({ row }: { row: LogRow }) {
  const tokens = row.tokens_in + row.tokens_out;
  const parts = [
    row.strings_ai ? `${formatNumber(row.strings_ai)} IA` : '',
    row.strings_mem ? `${formatNumber(row.strings_mem)} memoria` : '',
    row.strings_glo ? `${formatNumber(row.strings_glo)} glosario` : '',
  ].filter(Boolean);

  if (!tokens && !parts.length) return <span className="wit-text-muted-foreground">—</span>;

  return (
    <div className="wit-whitespace-nowrap wit-text-xs wit-text-muted-foreground">
      {tokens > 0 && (
        <p className="wit-font-medium wit-tabular-nums wit-text-foreground" title={`${formatNumber(row.tokens_in)} de entrada · ${formatNumber(row.tokens_out)} de salida`}>
          {formatNumber(tokens)} tokens
        </p>
      )}
      {parts.length > 0 && <p title="Frases traducidas por la IA, reutilizadas de la memoria o fijadas por el glosario">{parts.join(' · ')}</p>}
    </div>
  );
}
