import { useEffect, useMemo, useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getCoreRowModel, useReactTable, type ColumnDef, type RowSelectionState } from '@tanstack/react-table';
import { Check, CheckCircle2, Languages, Loader2, Pencil, Search, Sparkles, Square, Type, X } from 'lucide-react';
import { api, config } from '@/lib/api';
import { notify, notifyError } from '@/lib/toast';
import { languageName, queryParam, rememberLanguage, rememberedLanguage, setQueryParams, targetLanguages } from '@/lib/languages';
import { formatNumber } from '@/lib/format';
import type { StringItem } from '@/types';
import { AppShell } from '@/components/app-shell';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Tabs } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { Progress } from '@/components/ui/progress';
import { ConfirmDialog } from '@/components/ui/dialog';
import { DataTable } from '@/components/data-table';
import { Pagination } from '@/components/pagination';
import { EmptyState } from '@/components/empty-state';
import { Callout } from '@/components/callout';
import { ApiKeyCallout } from '@/components/shared/api-key-callout';

type StringStatus = 'pending' | 'needs_update' | 'translated' | 'all';

interface StringsResponse {
  items: StringItem[];
  total: number;
  page: number;
  pages: number;
}

interface Domain {
  domain: string;
  total: number;
  pending: number;
}

interface TranslateResponse {
  saved: number;
  errors: { id: number; error: string }[];
  translations: Record<string, string>;
}

interface NextResponse {
  ids: number[];
  remaining: number;
}

interface RunState {
  active: boolean;
  done: number;
  errors: number;
  total: number;
  lastError: string;
}

const STATUSES: { value: StringStatus; label: string }[] = [
  { value: 'pending', label: 'Sin traducir' },
  { value: 'needs_update', label: 'El original cambió' },
  { value: 'translated', label: 'Traducidas' },
  { value: 'all', label: 'Todas' },
];
const PER_PAGE = 50;

function initialLanguage(): string {
  const langs = targetLanguages();
  const wanted = queryParam('to') || rememberedLanguage('');
  return langs.some((l) => l.code === wanted) ? wanted : langs[0]?.code ?? '';
}

function useDebounced<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

/**
 * Cadenas de temas, plugins, widgets y menús (WPML String Translation):
 * traducir con IA en lotes de 50, o corregir una a mano.
 */
export function StringsPage() {
  const client = useQueryClient();
  const cfg = config();
  const languages = targetLanguages();

  const [language, setLanguage] = useState(initialLanguage);
  const [status, setStatus] = useState<StringStatus>(() => {
    const s = queryParam('status') as StringStatus;
    return STATUSES.some((x) => x.value === s) ? s : 'pending';
  });
  const [domain, setDomain] = useState(() => queryParam('domain'));
  const [searchInput, setSearchInput] = useState(() => queryParam('s'));
  const search = useDebounced(searchInput.trim(), 350);
  const [page, setPage] = useState(() => Math.max(1, Number(queryParam('paged')) || 1));
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [run, setRun] = useState<RunState | null>(null);
  const [confirmAll, setConfirmAll] = useState(false);
  const cancelRef = useRef(false);

  const filters = [language, status, domain, search].join('|');
  const [lastFilters, setLastFilters] = useState(filters);
  if (filters !== lastFilters) {
    setLastFilters(filters);
    setPage(1);
    setRowSelection({});
  }

  useEffect(() => {
    setQueryParams({ to: language, status: status === 'pending' ? undefined : status, domain, s: search, paged: page > 1 ? page : undefined });
  }, [language, status, domain, search, page]);

  const domains = useQuery({
    queryKey: ['string-domains', language],
    queryFn: () => api.get<Domain[]>('strings/domains', { language }),
    enabled: language !== '',
  });

  const { data, isLoading, isFetching, isError, error } = useQuery({
    queryKey: ['strings', language, status, domain, search, page],
    queryFn: () => api.get<StringsResponse>('strings', { language, status, domain, search, page, per_page: PER_PAGE }),
    enabled: language !== '',
    placeholderData: keepPreviousData,
  });

  const refresh = () => {
    void client.invalidateQueries({ queryKey: ['strings'] });
    void client.invalidateQueries({ queryKey: ['string-domains'] });
  };

  const translateSelected = useMutation({
    mutationFn: (ids: number[]) => api.post<TranslateResponse>('strings/translate', { language, ids }),
    onSuccess: (r) => {
      if (r.errors.length) {
        notify(`${r.saved} traducidas; ${r.errors.length} no: ${r.errors[0]?.error ?? ''}`, r.saved ? 'info' : 'error');
      } else {
        notify(`${r.saved} ${r.saved === 1 ? 'cadena traducida' : 'cadenas traducidas'} al ${languageName(language)}.`);
      }
      setRowSelection({});
      refresh();
    },
  });

  /** Todas las pendientes del filtro, de 50 en 50, mientras la pestaña siga abierta. */
  const runAll = async () => {
    setConfirmAll(false);
    cancelRef.current = false;
    const runStatus = status === 'needs_update' ? 'needs_update' : 'pending';
    const skip: number[] = [];
    const state: RunState = { active: true, done: 0, errors: 0, total: 0, lastError: '' };
    try {
      let next = await api.get<NextResponse>('strings/next', { language, domain, search, status: runStatus, skip: [] });
      state.total = next.remaining;
      setRun({ ...state });
      while (next.ids.length > 0 && !cancelRef.current) {
        const r = await api.post<TranslateResponse>('strings/translate', { language, ids: next.ids });
        state.done += r.saved;
        state.errors += r.errors.length;
        const failed = r.errors.map((e) => e.id).filter((id) => id > 0);
        if (r.errors[0]) state.lastError = r.errors[0].error;
        skip.push(...failed);
        setRun({ ...state });
        // Nada guardado y nada nuevo que saltar: seguir daría vueltas.
        if (r.saved === 0 && failed.length === 0) break;
        refresh();
        next = await api.get<NextResponse>('strings/next', { language, domain, search, status: runStatus, skip: skip.map(String) });
      }
      notify(
        cancelRef.current
          ? `Detenido: ${formatNumber(state.done)} cadenas traducidas.`
          : `Listo: ${formatNumber(state.done)} cadenas traducidas al ${languageName(language)}${state.errors ? `, ${state.errors} con error` : ''}.`,
        state.errors && !state.done ? 'error' : 'success',
      );
    } catch (e) {
      notifyError(e);
    } finally {
      setRun({ ...state, active: false });
      refresh();
    }
  };

  const items = data?.items ?? [];
  const selectedIds = items.filter((r) => rowSelection[String(r.id)]).map((r) => r.id);
  const busy = translateSelected.isPending || (run?.active ?? false);

  const columns = useMemo<ColumnDef<StringItem>[]>(() => [
    {
      id: 'select',
      header: ({ table }) => (
        <input
          type="checkbox"
          aria-label="Seleccionar todas las de esta página"
          checked={table.getIsAllPageRowsSelected()}
          ref={(el) => { if (el) el.indeterminate = table.getIsSomePageRowsSelected(); }}
          onChange={table.getToggleAllPageRowsSelectedHandler()}
        />
      ),
      cell: ({ row }) => (
        <input type="checkbox" aria-label="Seleccionar esta cadena" checked={row.getIsSelected()} onChange={row.getToggleSelectedHandler()} />
      ),
    },
    {
      id: 'original',
      header: 'Original',
      cell: ({ row }) => (
        <div className="wit-max-w-sm wit-space-y-1">
          <p className="wit-whitespace-pre-wrap wit-break-words wit-text-foreground">{row.original.text}</p>
          <p className="wit-truncate wit-font-mono wit-text-[11px] wit-text-muted-foreground" title={`${row.original.domain} · ${row.original.name}`}>
            {row.original.domain}
          </p>
        </div>
      ),
    },
    {
      id: 'translation',
      header: () => `Traducción · ${languageName(language)}`,
      cell: ({ row }) => <TranslationEditor item={row.original} language={language} onSaved={refresh} />,
    },
  ], [language]);

  const table = useReactTable({
    data: items,
    columns,
    getRowId: (r) => String(r.id),
    state: { rowSelection },
    onRowSelectionChange: setRowSelection,
    enableRowSelection: true,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
  });

  if (!cfg.features.strings || languages.length === 0) {
    return (
      <AppShell page="strings" title="Cadenas">
        <Card>
          <EmptyState
            icon={Type}
            title={cfg.features.strings ? 'No hay idiomas a los que traducir' : 'Necesitas WPML String Translation'}
            description={cfg.features.strings ? 'Activa al menos un idioma además del principal en WPML → Idiomas.' : 'Las cadenas de temas, plugins y menús se traducen con el complemento String Translation de WPML.'}
          />
        </Card>
      </AppShell>
    );
  }

  const domainList = domains.data ?? [];
  const pendingInDomain = domain ? domainList.find((d) => d.domain === domain)?.pending ?? 0 : domainList.reduce((n, d) => n + d.pending, 0);
  const canRunAll = (status === 'pending' || status === 'needs_update') && (data?.total ?? 0) > 0;

  return (
    <AppShell
      page="strings"
      title="Cadenas"
      description="Textos de temas, plugins, widgets y menús que WPML guarda en String Translation."
    >
      <div className="wit-space-y-4">
        <ApiKeyCallout />

        {run && (
          <Card>
            <CardContent className="wit-space-y-2 wit-p-4">
              <div className="wit-flex wit-items-center wit-justify-between wit-gap-3 wit-text-sm">
                <span className="wit-flex wit-items-center wit-gap-2 wit-font-medium">
                  {run.active ? <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin wit-text-primary" /> : <CheckCircle2 className="wit-h-4 wit-w-4 wit-text-emerald-600" />}
                  {run.active ? `Traduciendo cadenas al ${languageName(language)}…` : 'Traducción de cadenas terminada'}
                </span>
                <span className="wit-flex wit-items-center wit-gap-3">
                  <span className="wit-tabular-nums">{formatNumber(run.done)} / {formatNumber(run.total)}</span>
                  {run.active ? (
                    <Button size="sm" variant="outline" onClick={() => { cancelRef.current = true; }}>
                      <Square className="wit-h-3.5 wit-w-3.5" />Detener
                    </Button>
                  ) : (
                    <Button size="icon" variant="ghost" className="wit-h-8 wit-w-8" aria-label="Cerrar" onClick={() => setRun(null)}>
                      <X className="wit-h-4 wit-w-4" />
                    </Button>
                  )}
                </span>
              </div>
              <Progress value={run.total ? Math.min(100, ((run.done + run.errors) / run.total) * 100) : 0} />
              <p className="wit-text-xs wit-text-muted-foreground">
                {run.active ? 'Se traducen de 50 en 50 desde esta pestaña: si la cierras, se detiene tras el lote en curso.' : ''}
                {run.errors > 0 && <span className="wit-text-destructive"> {run.errors} con error. {run.lastError}</span>}
              </p>
            </CardContent>
          </Card>
        )}

        <Card className="wit-overflow-hidden">
          <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-2 wit-border-b wit-border-border wit-p-3">
            <label className="wit-flex wit-items-center wit-gap-2">
              <Languages className="wit-h-4 wit-w-4 wit-text-muted-foreground" />
              <Select value={language} onChange={(e) => { setLanguage(e.target.value); rememberLanguage(e.target.value); }} aria-label="Idioma de destino">
                {languages.map((l) => <option key={l.code} value={l.code}>{l.name}</option>)}
              </Select>
            </label>
            <Select value={domain} onChange={(e) => setDomain(e.target.value)} aria-label="Dominio" className="wit-max-w-[16rem]">
              <option value="">Todos los dominios</option>
              {domainList.map((d) => (
                <option key={d.domain} value={d.domain}>{d.domain}{d.pending ? ` · ${d.pending} sin traducir` : ''}</option>
              ))}
              {domain && !domainList.some((d) => d.domain === domain) && <option value={domain}>{domain}</option>}
            </Select>
            <div className="wit-relative wit-min-w-[12rem] wit-flex-1">
              <Search className="wit-pointer-events-none wit-absolute wit-left-2.5 wit-top-1/2 wit-h-4 wit-w-4 -wit-translate-y-1/2 wit-text-muted-foreground" />
              <Input type="search" value={searchInput} onChange={(e) => setSearchInput(e.target.value)} placeholder="Buscar en el original o la traducción…" className="wit-pl-8" aria-label="Buscar" />
            </div>
            {isFetching && !isLoading && <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin wit-text-muted-foreground" aria-label="Actualizando" />}
          </div>

          <Tabs
            items={STATUSES.map((s) => ({ ...s, ...(s.value === status && data ? { count: data.total } : s.value === 'pending' && domains.data ? { count: pendingInDomain } : {}) }))}
            value={status}
            onChange={setStatus}
            className="wit-px-2"
          />

          {(selectedIds.length > 0 || canRunAll) && (
            <div className="wit-flex wit-flex-wrap wit-items-center wit-justify-between wit-gap-2 wit-border-b wit-border-border wit-bg-slate-50 wit-px-3 wit-py-2 wit-text-sm">
              {selectedIds.length > 0 ? (
                <>
                  <span className="wit-font-medium">
                    {selectedIds.length} {selectedIds.length === 1 ? 'seleccionada' : 'seleccionadas'}
                    <button type="button" className="wit-ml-2 wit-text-xs wit-font-normal wit-text-muted-foreground hover:wit-text-foreground hover:wit-underline" onClick={() => setRowSelection({})}>
                      Quitar selección
                    </button>
                  </span>
                  <Button size="sm" onClick={() => translateSelected.mutate(selectedIds)} disabled={busy}>
                    {translateSelected.isPending ? <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin" /> : <Sparkles className="wit-h-4 wit-w-4" />}
                    {translateSelected.isPending ? 'Traduciendo…' : `Traducir ${selectedIds.length} con IA`}
                  </Button>
                </>
              ) : (
                <>
                  <span className="wit-text-muted-foreground">
                    {formatNumber(data?.total ?? 0)} {status === 'needs_update' ? 'cadenas cuyo original cambió' : 'cadenas sin traducir'}{domain ? ` en ${domain}` : ''}{search ? ` que contienen «${search}»` : ''}.
                  </span>
                  <Button size="sm" variant="secondary" onClick={() => setConfirmAll(true)} disabled={busy}>
                    <Sparkles className="wit-h-4 wit-w-4" />Traducir todas
                  </Button>
                </>
              )}
            </div>
          )}

          {isError ? (
            <div className="wit-p-3"><Callout kind="error">{error instanceof Error ? error.message : 'No se pudieron cargar las cadenas.'}</Callout></div>
          ) : isLoading ? (
            <div className="wit-space-y-2 wit-p-3">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="wit-h-12" />)}</div>
          ) : items.length === 0 ? (
            search
              ? <EmptyState icon={Search} title={`Nada coincide con «${search}»`} />
              : status === 'pending'
                ? <EmptyState icon={CheckCircle2} title={`Todas las cadenas están traducidas al ${languageName(language)}`} description={domain ? `En el dominio ${domain}.` : 'Cuando un tema o plugin registre textos nuevos, aparecerán aquí.'} />
                : <EmptyState icon={Type} title="No hay cadenas en esta pestaña" />
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
                        aria-label="Seleccionar esta cadena"
                        checked={!!rowSelection[String(r.id)]}
                        onChange={(e) => setRowSelection((sel) => ({ ...sel, [String(r.id)]: e.target.checked }))}
                      />
                      <div className="wit-min-w-0 wit-flex-1 wit-space-y-2">
                        <div>
                          <p className="wit-whitespace-pre-wrap wit-break-words">{r.text}</p>
                          <p className="wit-truncate wit-font-mono wit-text-[11px] wit-text-muted-foreground">{r.domain}</p>
                        </div>
                        <TranslationEditor item={r} language={language} onSaved={refresh} />
                      </div>
                    </div>
                  )}
                />
              </div>
              <Pagination page={data?.page ?? 1} pages={data?.pages ?? 1} total={data?.total ?? 0} onChange={setPage} noun="cadenas" />
            </>
          )}
        </Card>
      </div>

      <ConfirmDialog
        open={confirmAll}
        onOpenChange={setConfirmAll}
        title={`Traducir ${formatNumber(data?.total ?? 0)} cadenas al ${languageName(language)}`}
        confirmLabel="Empezar"
        onConfirm={() => void runAll()}
      >
        Se envían al proveedor de IA de 50 en 50 desde esta pestaña; puedes detenerlo cuando quieras. Las que tengan marcadores (%s, {'{name}'}…) que la IA no respete se descartan para no romper la página.
      </ConfirmDialog>
    </AppShell>
  );
}

const STATUS_BADGE: Record<StringItem['status'], { label: string; variant: 'secondary' | 'warning' | 'success' }> = {
  pending: { label: 'Sin traducir', variant: 'secondary' },
  needs_update: { label: 'El original cambió', variant: 'warning' },
  translated: { label: 'Traducida', variant: 'success' },
};

function TranslationEditor({ item, language, onSaved }: { item: StringItem; language: string; onSaved: () => void }) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(item.translation);

  useEffect(() => setValue(item.translation), [item.translation]);

  const save = useMutation({
    mutationFn: () => api.post<{ id: number; translation: string }>('strings/save', { id: item.id, language, value }),
    onSuccess: () => { notify('Traducción guardada.'); setEditing(false); onSaved(); },
  });

  if (editing) {
    return (
      <div className="wit-max-w-sm wit-space-y-2">
        <Textarea
          value={value}
          onChange={(e) => setValue(e.target.value)}
          rows={Math.min(6, Math.max(2, Math.ceil(item.text.length / 50)))}
          autoFocus
          onKeyDown={(e) => {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) save.mutate();
            if (e.key === 'Escape') { setEditing(false); setValue(item.translation); }
          }}
        />
        <div className="wit-flex wit-items-center wit-gap-2">
          <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending || value.trim() === ''}>
            <Check className="wit-h-4 wit-w-4" />Guardar
          </Button>
          <Button size="sm" variant="ghost" onClick={() => { setEditing(false); setValue(item.translation); }}>Cancelar</Button>
          <span className="wit-hidden wit-text-xs wit-text-muted-foreground sm:wit-inline">Ctrl+Enter guarda</span>
        </div>
      </div>
    );
  }

  const badge = STATUS_BADGE[item.status];
  return (
    <div className="wit-group wit-flex wit-max-w-sm wit-items-start wit-gap-2">
      <div className="wit-min-w-0 wit-flex-1 wit-space-y-1">
        {item.translation
          ? <p className="wit-whitespace-pre-wrap wit-break-words">{item.translation}</p>
          : <p className="wit-text-muted-foreground">—</p>}
        <div className="wit-flex wit-flex-wrap wit-gap-1.5">
          <Badge variant={badge.variant}>{badge.label}</Badge>
          {item.from_file && <Badge variant="outline" title="La traducción viene del archivo .mo del tema o plugin. Si la cambias aquí, la tuya tiene prioridad.">Del archivo .mo</Badge>}
        </div>
      </div>
      <button
        type="button"
        aria-label={item.translation ? 'Corregir la traducción' : 'Escribir la traducción'}
        onClick={() => setEditing(true)}
        className="wit-rounded wit-p-1 wit-text-muted-foreground wit-opacity-60 hover:wit-bg-slate-100 hover:wit-text-foreground group-hover:wit-opacity-100"
      >
        <Pencil className="wit-h-3.5 wit-w-3.5" />
      </button>
    </div>
  );
}
