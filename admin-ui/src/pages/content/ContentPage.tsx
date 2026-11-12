import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getCoreRowModel, useReactTable, type ColumnDef, type RowSelectionState } from '@tanstack/react-table';
import { CheckCircle2, ChevronDown, ExternalLink, Eye, FileText, Languages, Loader2, MoreHorizontal, PencilLine, RefreshCw, Search, Sparkles } from 'lucide-react';
import { api, config, type ApiError } from '@/lib/api';
import { notify, notifyError } from '@/lib/toast';
import { languageName, queryParam, rememberLanguage, rememberedLanguage, setQueryParams, targetLanguages } from '@/lib/languages';
import { formatNumber } from '@/lib/format';
import type { PostRow, PostsResponse, StatusFilter } from '@/types';
import { AppShell } from '@/components/app-shell';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Tabs } from '@/components/ui/tabs';
import { Popover } from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { ConfirmDialog } from '@/components/ui/dialog';
import { DataTable } from '@/components/data-table';
import { Pagination } from '@/components/pagination';
import { EmptyState } from '@/components/empty-state';
import { Callout } from '@/components/callout';
import { QueueCard } from '@/components/shared/queue-card';
import { ApiKeyCallout } from '@/components/shared/api-key-callout';
import { TranslationCell } from './translation-cell';
import { ReviewDrawer } from './ReviewDrawer';

const STATUS_VALUES: StatusFilter[] = ['pending', 'missing', 'outdated', 'current', 'edited', 'unknown', 'all'];
const PER_PAGE = 25;

interface TranslateResult {
  status: 'complete' | 'error' | 'processing' | 'not_found';
  message?: string;
  translation_id?: number;
  edit_url?: string;
}

/** Lo que pide confirmación antes de traducir una fila ya traducida. */
interface PendingConfirm {
  rows: PostRow[];
  mode: 'now' | 'queue' | 'all';
}

function initialLanguage(): string {
  const langs = targetLanguages();
  const wanted = queryParam('to') || rememberedLanguage('');
  return langs.some((l) => l.code === wanted) ? wanted : langs[0]?.code ?? '';
}

function initialTypes(): string[] {
  const available = config().postTypes.map((t) => t.name);
  const fromUrl = queryParam('types').split(',').filter((t) => available.includes(t));
  if (fromUrl.length) return fromUrl;
  const defaults = ['post', 'page'].filter((t) => available.includes(t));
  return defaults.length ? defaults : available.slice(0, 2);
}

function useDebounced<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

const wait = (ms: number) => new Promise((resolve) => window.setTimeout(resolve, ms));

/**
 * Si el proxy corta la petición (502/504 tras 60-100 s), PHP sigue
 * traduciendo: se consulta el estado del trabajo hasta que termine.
 */
async function translateOne(postId: number, language: string): Promise<TranslateResult> {
  try {
    return await api.post<TranslateResult>('translate', { post_id: postId, language });
  } catch (error) {
    const status = (error as ApiError).status;
    const cut = status === undefined || status === 0 || status === 502 || status === 503 || status === 504;
    if (!cut) throw error;
  }
  const deadline = Date.now() + 10 * 60 * 1000;
  while (Date.now() < deadline) {
    await wait(5000);
    const job = await api.get<TranslateResult>('translate-status', { post_id: postId, language });
    if (job.status === 'complete') return job;
    if (job.status === 'error') throw new Error(job.message || 'La traducción falló.');
  }
  throw new Error('La traducción sigue en curso en el servidor. Recarga en unos minutos para ver el resultado.');
}

export function ContentPage() {
  const client = useQueryClient();
  const cfg = config();
  const languages = targetLanguages();

  const [language, setLanguage] = useState(initialLanguage);
  const [status, setStatus] = useState<StatusFilter>(() => {
    const s = queryParam('status') as StatusFilter;
    return STATUS_VALUES.includes(s) ? s : 'pending';
  });
  const [types, setTypes] = useState<string[]>(initialTypes);
  const [typesOpen, setTypesOpen] = useState(false);
  const [searchInput, setSearchInput] = useState(() => queryParam('s'));
  const search = useDebounced(searchInput.trim(), 350);
  const [page, setPage] = useState(() => Math.max(1, Number(queryParam('paged')) || 1));
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [running, setRunning] = useState<Set<number>>(new Set());
  const [review, setReview] = useState<PostRow | null>(null);
  const [confirm, setConfirm] = useState<PendingConfirm | null>(null);

  // Cambiar un filtro vuelve a la primera página y olvida la selección
  // (no al cargar: la página de la URL se respeta).
  const filters = [language, status, search, types.join(',')].join('|');
  const [lastFilters, setLastFilters] = useState(filters);
  if (filters !== lastFilters) {
    setLastFilters(filters);
    setPage(1);
    setRowSelection({});
  }

  useEffect(() => {
    setQueryParams({
      to: language,
      status: status === 'pending' ? undefined : status,
      types: types.join(',') === initialTypesDefault() ? undefined : types.join(','),
      s: search,
      paged: page > 1 ? page : undefined,
    });
  }, [language, status, types, search, page]);

  const { data, isLoading, isFetching, isError, error } = useQuery({
    queryKey: ['posts', language, status, types, search, page],
    queryFn: () => api.get<PostsResponse>('posts', { language, status, post_types: types, search, page, per_page: PER_PAGE }),
    enabled: language !== '',
    placeholderData: keepPreviousData,
    // Mientras haya filas en la cola, la lista se mantiene al día sola.
    refetchInterval: (q) => (q.state.data?.items.some((r) => r.queued) ? 5000 : false),
  });

  const refreshAll = () => {
    void client.invalidateQueries({ queryKey: ['posts'] });
    void client.invalidateQueries({ queryKey: ['queue'] });
  };

  const runNow = async (row: PostRow) => {
    setRunning((s) => new Set(s).add(row.id));
    try {
      await translateOne(row.id, language);
      notify(`«${row.title}» traducido al ${languageName(language)}. Quedó como borrador si era nuevo: revísalo y publícalo.`);
    } catch (e) {
      notifyError(e);
    } finally {
      setRunning((s) => {
        const next = new Set(s);
        next.delete(row.id);
        return next;
      });
      refreshAll();
    }
  };

  const enqueue = useMutation({
    mutationFn: (body: { post_ids?: number[]; all_pending?: boolean; post_types?: string[] }) =>
      api.post<{ queued: number; skipped?: number }>('queue', { language, ...body }),
    onSuccess: (r) => {
      notify(`${formatNumber(r.queued)} ${r.queued === 1 ? 'contenido en cola' : 'contenidos en cola'}. Se traducen en segundo plano; puedes seguir trabajando.`);
      setRowSelection({});
      setConfirm(null);
      refreshAll();
    },
  });

  const translateRow = (row: PostRow) => {
    if (row.translation.edited || row.translation.state === 'current' || row.translation.state === 'unknown') {
      setConfirm({ rows: [row], mode: 'now' });
    } else {
      void runNow(row);
    }
  };

  const items = data?.items ?? [];
  const selectedRows = items.filter((r) => rowSelection[String(r.id)]);

  const columns = useMemo<ColumnDef<PostRow>[]>(() => [
    {
      id: 'select',
      header: ({ table }) => (
        <input
          type="checkbox"
          aria-label="Seleccionar todos los de esta página"
          checked={table.getIsAllPageRowsSelected()}
          ref={(el) => { if (el) el.indeterminate = table.getIsSomePageRowsSelected(); }}
          onChange={table.getToggleAllPageRowsSelectedHandler()}
        />
      ),
      cell: ({ row }) => (
        <input
          type="checkbox"
          aria-label={`Seleccionar «${row.original.title}»`}
          checked={row.getIsSelected()}
          disabled={!row.getCanSelect()}
          onChange={row.getToggleSelectedHandler()}
        />
      ),
    },
    {
      id: 'title',
      header: 'Contenido',
      cell: ({ row }) => {
        const r = row.original;
        return (
          <div className="wit-min-w-0 wit-max-w-md">
            <a href={r.edit_url} className="wit-block wit-truncate wit-font-medium wit-text-foreground hover:wit-text-primary" title={r.title}>
              {r.title || '(sin título)'}
            </a>
            <p className="wit-mt-0.5 wit-flex wit-items-center wit-gap-1.5 wit-text-xs wit-text-muted-foreground">
              <span>{r.type_label}</span>
              {r.post_status !== 'publish' && <span>· {postStatusLabel(r.post_status)}</span>}
            </p>
          </div>
        );
      },
    },
    {
      id: 'translation',
      header: () => `Traducción · ${languageName(language)}`,
      cell: ({ row }) => <TranslationCell row={row.original} />,
    },
    {
      id: 'actions',
      header: () => <span className="wit-sr-only">Acciones</span>,
      cell: ({ row }) => (
        <RowActions
          row={row.original}
          busy={running.has(row.original.id)}
          onTranslate={() => translateRow(row.original)}
          onReview={() => setReview(row.original)}
        />
      ),
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [language, running]);

  const table = useReactTable({
    data: items,
    columns,
    getRowId: (r) => String(r.id),
    state: { rowSelection },
    onRowSelectionChange: setRowSelection,
    enableRowSelection: (r) => !r.original.queued,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
  });

  if (languages.length === 0) {
    return (
      <AppShell page="content" title="Contenidos">
        <Card>
          <EmptyState icon={Languages} title="No hay idiomas a los que traducir" description="Activa al menos un idioma además del principal en WPML → Idiomas." />
        </Card>
      </AppShell>
    );
  }

  const counts = data?.counts;
  const tabs = STATUS_VALUES
    .filter((s) => s !== 'unknown' || (counts?.unknown ?? 0) > 0)
    .map((s) => ({ value: s, label: STATUS_LABEL[s], ...(counts ? { count: counts[s] } : {}) }));

  const typeLabel = types.length === cfg.postTypes.length
    ? 'Todos los tipos'
    : cfg.postTypes.filter((t) => types.includes(t.name)).map((t) => t.label).join(', ') || 'Ningún tipo';

  const editedSelected = selectedRows.filter((r) => r.translation.edited).length;
  // Lo que ya está en la cola no cuenta como «pendiente por encolar».
  const allQueued = items.length > 0 && (data?.pages ?? 1) === 1 && items.every((r) => r.queued);
  const pendingCount = allQueued ? 0 : counts?.pending ?? 0;

  return (
    <AppShell
      page="content"
      title="Contenidos"
      description="Elige un idioma, traduce con un clic o selecciona varios para traducirlos en segundo plano."
    >
      <div className="wit-space-y-4">
        <ApiKeyCallout />
        <QueueCard />

        <Card className="wit-overflow-hidden">
          {/* Barra de filtros */}
          <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-2 wit-border-b wit-border-border wit-p-3">
            <label className="wit-flex wit-items-center wit-gap-2 wit-text-sm">
              <Languages className="wit-h-4 wit-w-4 wit-text-muted-foreground" />
              <span className="wit-sr-only">Idioma</span>
              <Select
                value={language}
                onChange={(e) => { setLanguage(e.target.value); rememberLanguage(e.target.value); }}
                aria-label="Idioma de destino"
              >
                {languages.map((l) => <option key={l.code} value={l.code}>{l.name}</option>)}
              </Select>
            </label>

            <Popover
              open={typesOpen}
              onOpenChange={setTypesOpen}
              align="start"
              trigger={
                <Button variant="outline" size="sm" className="wit-h-9" onClick={() => setTypesOpen((o) => !o)}>
                  <FileText className="wit-h-4 wit-w-4" />
                  <span className="wit-max-w-[12rem] wit-truncate">{typeLabel}</span>
                  <ChevronDown className="wit-h-3.5 wit-w-3.5 wit-opacity-60" />
                </Button>
              }
            >
              <div className="wit-w-56 wit-space-y-0.5 wit-p-1">
                {cfg.postTypes.map((t) => {
                  const on = types.includes(t.name);
                  return (
                    <label key={t.name} className="wit-flex wit-cursor-pointer wit-items-center wit-gap-2 wit-rounded wit-px-2 wit-py-1.5 wit-text-sm hover:wit-bg-slate-100">
                      <input
                        type="checkbox"
                        checked={on}
                        disabled={on && types.length === 1}
                        onChange={() => setTypes((cur) => (on ? cur.filter((x) => x !== t.name) : [...cur, t.name]))}
                      />
                      {t.label}
                    </label>
                  );
                })}
              </div>
            </Popover>

            <div className="wit-relative wit-min-w-[12rem] wit-flex-1">
              <Search className="wit-pointer-events-none wit-absolute wit-left-2.5 wit-top-1/2 wit-h-4 wit-w-4 -wit-translate-y-1/2 wit-text-muted-foreground" />
              <Input
                type="search"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                placeholder="Buscar por título…"
                className="wit-pl-8"
                aria-label="Buscar por título"
              />
            </div>

            {isFetching && !isLoading && <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin wit-text-muted-foreground" aria-label="Actualizando" />}
          </div>

          <Tabs items={tabs} value={status} onChange={setStatus} className="wit-px-2" />

          {/* Acciones sobre la selección, o sobre todo lo pendiente */}
          {(selectedRows.length > 0 || (status === 'pending' && pendingCount > 0)) && (
            <div className="wit-flex wit-flex-wrap wit-items-center wit-justify-between wit-gap-2 wit-border-b wit-border-border wit-bg-slate-50 wit-px-3 wit-py-2 wit-text-sm">
              {selectedRows.length > 0 ? (
                <>
                  <span className="wit-font-medium">
                    {selectedRows.length} {selectedRows.length === 1 ? 'seleccionado' : 'seleccionados'}
                    <button type="button" className="wit-ml-2 wit-text-xs wit-font-normal wit-text-muted-foreground hover:wit-text-foreground hover:wit-underline" onClick={() => setRowSelection({})}>
                      Quitar selección
                    </button>
                  </span>
                  <Button size="sm" onClick={() => setConfirm({ rows: selectedRows, mode: 'queue' })} disabled={enqueue.isPending}>
                    <Sparkles className="wit-h-4 wit-w-4" />
                    Traducir {selectedRows.length} al {languageName(language)}
                  </Button>
                </>
              ) : (
                <>
                  <span className="wit-text-muted-foreground">
                    {formatNumber(pendingCount)} {pendingCount === 1 ? 'contenido pendiente' : 'contenidos pendientes'} en {languageName(language)}.
                  </span>
                  <Button size="sm" variant="secondary" onClick={() => setConfirm({ rows: [], mode: 'all' })} disabled={enqueue.isPending}>
                    <Sparkles className="wit-h-4 wit-w-4" />
                    Traducir todos los pendientes
                  </Button>
                </>
              )}
            </div>
          )}

          {data?.truncated && (
            <div className="wit-border-b wit-border-border wit-p-3">
              <Callout kind="info">Se revisaron los 2.000 contenidos más recientes. Usa la búsqueda para encontrar los más antiguos.</Callout>
            </div>
          )}

          {isError ? (
            <div className="wit-p-3"><Callout kind="error">{error instanceof Error ? error.message : 'No se pudo cargar la lista.'}</Callout></div>
          ) : isLoading ? (
            <div className="wit-space-y-2 wit-p-3">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="wit-h-12" />)}</div>
          ) : items.length === 0 ? (
            <EmptyForFilter status={status} language={languageName(language)} search={search} onShowAll={() => setStatus('all')} />
          ) : (
            <>
              <div className="wit-overflow-x-auto">
                <DataTable
                  table={table}
                  renderMobile={(r) => (
                    <div className="wit-flex wit-items-start wit-gap-3">
                      <input
                        type="checkbox"
                        className="wit-mt-1"
                        aria-label={`Seleccionar «${r.title}»`}
                        checked={!!rowSelection[String(r.id)]}
                        disabled={!!r.queued}
                        onChange={(e) => setRowSelection((sel) => ({ ...sel, [String(r.id)]: e.target.checked }))}
                      />
                      <div className="wit-min-w-0 wit-flex-1 wit-space-y-2">
                        <div>
                          <a href={r.edit_url} className="wit-block wit-truncate wit-font-medium wit-text-foreground">{r.title || '(sin título)'}</a>
                          <p className="wit-text-xs wit-text-muted-foreground">{r.type_label}{r.post_status !== 'publish' ? ` · ${postStatusLabel(r.post_status)}` : ''}</p>
                        </div>
                        <TranslationCell row={r} />
                        <div className="wit-flex wit-justify-start">
                          <RowActions row={r} busy={running.has(r.id)} onTranslate={() => translateRow(r)} onReview={() => setReview(r)} />
                        </div>
                      </div>
                    </div>
                  )}
                />
              </div>
              <Pagination page={data?.page ?? 1} pages={data?.pages ?? 1} total={data?.total ?? 0} onChange={setPage} noun="contenidos" />
            </>
          )}
        </Card>
      </div>

      <ReviewDrawer row={review} language={language} onClose={() => setReview(null)} />

      <TranslateConfirm
        confirm={confirm}
        language={languageName(language)}
        pendingCount={pendingCount}
        editedSelected={editedSelected}
        busy={enqueue.isPending}
        onCancel={() => setConfirm(null)}
        onConfirm={() => {
          if (!confirm) return;
          if (confirm.mode === 'now' && confirm.rows[0]) {
            const row = confirm.rows[0];
            setConfirm(null);
            void runNow(row);
          } else if (confirm.mode === 'queue') {
            enqueue.mutate({ post_ids: confirm.rows.map((r) => r.id) });
          } else {
            enqueue.mutate({ all_pending: true, post_types: types });
          }
        }}
      />
    </AppShell>
  );
}

function initialTypesDefault(): string {
  const available = config().postTypes.map((t) => t.name);
  return ['post', 'page'].filter((t) => available.includes(t)).join(',');
}

const STATUS_LABEL: Record<StatusFilter, string> = {
  pending: 'Pendientes',
  missing: 'Sin traducir',
  outdated: 'Desactualizadas',
  current: 'Al día',
  edited: 'Editadas a mano',
  unknown: 'Fuera del plugin',
  all: 'Todos',
};

function postStatusLabel(status: string): string {
  return ({ draft: 'Borrador', pending: 'Pendiente de revisión', private: 'Privado', future: 'Programado' } as Record<string, string>)[status] ?? status;
}

function RowActions({ row, busy, onTranslate, onReview }: {
  row: PostRow;
  busy: boolean;
  onTranslate: () => void;
  onReview: () => void;
}) {
  const [open, setOpen] = useState(false);
  const t = row.translation;
  const hasTranslation = t.id > 0;

  const primary = t.state === 'missing'
    ? { label: 'Traducir', icon: Sparkles, variant: 'default' as const }
    : t.state === 'outdated'
      ? { label: 'Actualizar', icon: RefreshCw, variant: 'default' as const }
      : null;

  return (
    <div className="wit-flex wit-items-center wit-gap-1.5 sm:wit-justify-end">
      {busy ? (
        <Button size="sm" variant="secondary" disabled>
          <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin" />Traduciendo…
        </Button>
      ) : row.queued ? null : primary ? (
        <Button size="sm" variant={primary.variant} onClick={onTranslate}>
          <primary.icon className="wit-h-4 wit-w-4" />{primary.label}
        </Button>
      ) : null}

      {hasTranslation && !busy && (
        <Button size="sm" variant="outline" onClick={onReview}>
          <Eye className="wit-h-4 wit-w-4" />Revisar
        </Button>
      )}

      <Popover
        open={open}
        onOpenChange={setOpen}
        trigger={
          <Button size="icon" variant="ghost" className="wit-h-8 wit-w-8" aria-label="Más acciones" onClick={() => setOpen((o) => !o)}>
            <MoreHorizontal className="wit-h-4 wit-w-4" />
          </Button>
        }
      >
        <div className="wit-w-56 wit-p-1 wit-text-sm" onClick={() => setOpen(false)}>
          {!primary && !busy && !row.queued && (
            <MenuItem icon={RefreshCw} onClick={onTranslate}>Volver a traducir</MenuItem>
          )}
          <MenuItem icon={PencilLine} href={row.edit_url}>Editar el original</MenuItem>
          {row.view_url && <MenuItem icon={ExternalLink} href={row.view_url} external>Ver el original</MenuItem>}
          {hasTranslation && t.edit_url && <MenuItem icon={PencilLine} href={t.edit_url}>Editar la traducción</MenuItem>}
          {hasTranslation && t.view_url && <MenuItem icon={ExternalLink} href={t.view_url} external>Ver la traducción</MenuItem>}
        </div>
      </Popover>
    </div>
  );
}

function MenuItem({ icon: Icon, children, href, external, onClick }: {
  icon: typeof Eye;
  children: React.ReactNode;
  href?: string;
  external?: boolean;
  onClick?: () => void;
}) {
  const cls = 'wit-flex wit-w-full wit-items-center wit-gap-2 wit-rounded wit-px-2 wit-py-1.5 wit-text-left wit-text-foreground hover:wit-bg-slate-100 wit-no-underline';
  if (href) {
    return (
      <a href={href} className={cls} {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
        <Icon className="wit-h-4 wit-w-4 wit-text-muted-foreground" />{children}
      </a>
    );
  }
  return (
    <button type="button" className={cls} onClick={onClick}>
      <Icon className="wit-h-4 wit-w-4 wit-text-muted-foreground" />{children}
    </button>
  );
}

function EmptyForFilter({ status, language, search, onShowAll }: { status: StatusFilter; language: string; search: string; onShowAll: () => void }) {
  if (search) {
    return <EmptyState icon={Search} title={`Nada coincide con «${search}»`} description="Prueba con otra palabra o cambia de pestaña." />;
  }
  if (status === 'pending') {
    return (
      <EmptyState
        icon={CheckCircle2}
        title={`Todo está al día en ${language}`}
        description="No hay contenidos sin traducir ni traducciones desactualizadas para los tipos elegidos."
        action={<Button variant="outline" size="sm" onClick={onShowAll}>Ver todos los contenidos</Button>}
      />
    );
  }
  return <EmptyState icon={FileText} title="No hay contenidos en esta pestaña" />;
}

function TranslateConfirm({ confirm, language, pendingCount, editedSelected, busy, onCancel, onConfirm }: {
  confirm: PendingConfirm | null;
  language: string;
  pendingCount: number;
  editedSelected: number;
  busy: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  if (!confirm) return null;

  let title = '';
  let body: React.ReactNode = null;
  let label = 'Traducir';
  let destructive = false;

  if (confirm.mode === 'now') {
    const row = confirm.rows[0];
    const t = row?.translation;
    title = 'Volver a traducir';
    label = 'Traducir de nuevo';
    if (t?.edited) {
      destructive = true;
      body = <>Alguien editó a mano la traducción de «{row?.title}». Traducir de nuevo <strong>reemplaza esos cambios</strong> por una traducción nueva. Si solo quieres corregir una frase, usa «Revisar».</>;
    } else if (t?.state === 'unknown') {
      body = <>«{row?.title}» ya tiene una traducción al {language} hecha fuera de este plugin. Se reemplazará por una traducción nueva.</>;
    } else {
      body = <>La traducción de «{row?.title}» ya está al día. Traducir de nuevo consume tokens del proveedor de IA.</>;
    }
  } else if (confirm.mode === 'queue') {
    const n = confirm.rows.length;
    title = `Traducir ${n} ${n === 1 ? 'contenido' : 'contenidos'} al ${language}`;
    label = 'Poner en cola';
    body = (
      <div className="wit-space-y-2">
        <p>Se traducen en segundo plano, uno tras otro. Puedes cerrar esta página: el servidor sigue.</p>
        {editedSelected > 0 && (
          <Callout kind="warning">
            {editedSelected === 1 ? '1 de ellos tiene' : `${editedSelected} de ellos tienen`} la traducción editada a mano: se reemplazarán esos cambios.
          </Callout>
        )}
      </div>
    );
  } else {
    title = pendingCount === 1 ? `Traducir el contenido pendiente al ${language}` : `Traducir los ${formatNumber(pendingCount)} pendientes al ${language}`;
    label = 'Poner en cola';
    body = (
      <p>
        Incluye los que no tienen traducción y los desactualizados de los tipos elegidos. Se traducen en segundo plano; cada uno consume tokens del proveedor de IA. Las traducciones editadas a mano que estén desactualizadas también se reemplazan.
      </p>
    );
  }

  return (
    <ConfirmDialog open onOpenChange={(o) => !o && onCancel()} title={title} confirmLabel={label} destructive={destructive} busy={busy} onConfirm={onConfirm}>
      {body}
    </ConfirmDialog>
  );
}
