import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowRight, CheckCircle2, Circle, Coins, FileText, Languages, RefreshCw, Sparkles, Type, Wand2,
} from 'lucide-react';
import { api, config, pageUrl } from '@/lib/api';
import { formatNumber, formatRelativeTime, plural } from '@/lib/format';
import { notify } from '@/lib/toast';
import { rememberLanguage } from '@/lib/languages';
import type { Overview } from '@/types';
import { AppShell } from '@/components/app-shell';
import { Callout } from '@/components/callout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { StackedProgress } from '@/components/ui/progress';
import { ConfirmDialog } from '@/components/ui/dialog';
import { QueueCard } from '@/components/shared/queue-card';
import { Via } from '@/components/shared/via';

export function DashboardPage() {
  const client = useQueryClient();
  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['overview'],
    queryFn: () => api.get<Overview>('overview'),
  });

  const name = config().currentUser;

  return (
    <AppShell
      page="dashboard"
      title={name ? `Hola, ${name.split(' ')[0]}` : 'Inicio'}
      description="Qué queda por traducir en cada idioma y qué está pasando ahora."
      actions={
        <Button variant="outline" size="sm" onClick={() => void client.invalidateQueries()} disabled={isFetching}>
          <RefreshCw className={isFetching ? 'wit-h-4 wit-w-4 wit-animate-spin' : 'wit-h-4 wit-w-4'} />
          Actualizar
        </Button>
      }
    >
      {isError ? (
        <Callout kind="error" title="No se pudo cargar el resumen" action={<Button size="sm" variant="outline" onClick={() => void refetch()}>Reintentar</Button>}>
          {error instanceof Error ? error.message : ''}
        </Callout>
      ) : isLoading || !data ? (
        <DashboardSkeleton />
      ) : (
        <div className="wit-space-y-5">
          <Setup data={data} />
          {data.problems.map((p) => <Callout key={p} kind="warning">{p}</Callout>)}
          <Kpis data={data} />
          <QueueCard initial={data.queue} />
          <div className="wit-grid wit-grid-cols-1 wit-gap-5 lg:wit-grid-cols-3">
            <div className="wit-space-y-5 lg:wit-col-span-2">
              <LanguageProgress data={data} />
              {data.strings && <StringsCard data={data} />}
            </div>
            <div className="wit-space-y-5">
              <ClaudeCard data={data} />
              <RecentCard data={data} />
            </div>
          </div>
        </div>
      )}
    </AppShell>
  );
}

function DashboardSkeleton() {
  return (
    <div className="wit-space-y-5">
      <div className="wit-grid wit-grid-cols-2 wit-gap-3 lg:wit-grid-cols-4">
        {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="wit-h-24" />)}
      </div>
      <Skeleton className="wit-h-64" />
    </div>
  );
}

/** Lo que falta para empezar, como lista de pasos: solo si falta algo. */
function Setup({ data }: { data: Overview }) {
  const { setup } = data;
  if (setup.has_api_key || setup.mcp_ready || !setup.can_manage) return null;

  const steps = [
    { done: setup.has_api_key, title: 'Conecta un proveedor de IA', text: 'Pega la API key de OpenAI, Claude o Gemini y prueba la conexión.', href: pageUrl('settings', { tab: 'provider' }), cta: 'Configurar' },
    { done: setup.memory, title: 'Activa la memoria de traducción', text: 'No vuelves a pagar por frases ya traducidas.', href: pageUrl('settings', { tab: 'translation' }), cta: 'Activar' },
    { done: setup.mcp_ready, title: 'O traduce desde Claude (opcional)', text: 'Con tu suscripción de Claude, sin gastar tokens de API.', href: pageUrl('settings', { tab: 'claude' }), cta: 'Ver cómo' },
  ];

  return (
    <Card className="wit-border-primary/30">
      <CardHeader>
        <CardTitle className="wit-flex wit-items-center wit-gap-2 wit-text-base"><Wand2 className="wit-h-4 wit-w-4 wit-text-primary" />Empieza en dos minutos</CardTitle>
        <CardDescription>Todavía no hay un proveedor de IA configurado, así que no se puede traducir.</CardDescription>
      </CardHeader>
      <CardContent className="wit-space-y-2">
        {steps.map((s) => (
          <div key={s.title} className="wit-flex wit-items-center wit-gap-3 wit-rounded-md wit-border wit-border-border wit-p-3">
            {s.done ? <CheckCircle2 className="wit-h-5 wit-w-5 wit-text-emerald-600" /> : <Circle className="wit-h-5 wit-w-5 wit-text-slate-300" />}
            <div className="wit-flex-1">
              <p className="wit-text-sm wit-font-medium">{s.title}</p>
              <p className="wit-text-xs wit-text-muted-foreground">{s.text}</p>
            </div>
            {!s.done && <Button size="sm" variant="outline" onClick={() => (window.location.href = s.href)}>{s.cta}</Button>}
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function Kpis({ data }: { data: Overview }) {
  const pending = data.languages.reduce((n, l) => n + l.counts.pending, 0);
  const current = data.languages.reduce((n, l) => n + l.counts.current, 0);
  const cards = [
    { label: 'Pendientes', value: pending, hint: 'sin traducir o desactualizados', icon: FileText, tone: pending > 0 ? 'wit-text-amber-600' : 'wit-text-emerald-600' },
    { label: 'Al día', value: current, hint: 'traducciones que reflejan el original', icon: CheckCircle2, tone: 'wit-text-emerald-600' },
    { label: 'Últimos 7 días', value: data.stats.recent, hint: 'traducciones hechas', icon: Languages, tone: 'wit-text-primary' },
    { label: 'Tokens de API (30 días)', value: data.stats.tokens_30d, hint: 'lo que factura el proveedor', icon: Coins, tone: 'wit-text-muted-foreground' },
  ];
  return (
    <div className="wit-grid wit-grid-cols-2 wit-gap-3 lg:wit-grid-cols-4">
      {cards.map((c) => (
        <Card key={c.label}>
          <CardContent className="wit-p-4">
            <div className="wit-flex wit-items-center wit-justify-between">
              <p className="wit-text-xs wit-font-medium wit-text-muted-foreground">{c.label}</p>
              <c.icon className={`wit-h-4 wit-w-4 ${c.tone}`} />
            </div>
            <p className="wit-mt-1 wit-text-2xl wit-font-semibold wit-tabular-nums">{formatNumber(c.value)}</p>
            <p className="wit-text-xs wit-text-muted-foreground">{c.hint}</p>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

function LanguageProgress({ data }: { data: Overview }) {
  const client = useQueryClient();
  const [confirm, setConfirm] = useState<Overview['languages'][number] | null>(null);

  const translateAll = useMutation({
    mutationFn: (code: string) => api.post<{ queued: number; skipped: number }>('queue', { language: code, all_pending: true }),
    onSuccess: (r) => {
      notify(`${plural(r.queued, 'contenido en cola', 'contenidos en cola')}. Puedes cerrar la página: el servidor sigue.`);
      setConfirm(null);
      void client.invalidateQueries({ queryKey: ['queue'] });
      void client.invalidateQueries({ queryKey: ['overview'] });
    },
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle className="wit-text-base">Contenidos por idioma</CardTitle>
        <CardDescription>Entradas y páginas del idioma original ({data.wpml.default.toUpperCase()}).</CardDescription>
      </CardHeader>
      <CardContent className="wit-divide-y wit-divide-border wit-p-0">
        {data.languages.map((l) => {
          const c = l.counts;
          const done = c.current + c.unknown;
          return (
            <div key={l.code} className="wit-flex wit-flex-col wit-gap-3 wit-px-4 wit-py-4 sm:wit-flex-row sm:wit-items-center">
              <div className="wit-w-40 wit-flex-shrink-0">
                <p className="wit-text-sm wit-font-medium">{l.name}</p>
                <p className="wit-text-xs wit-text-muted-foreground wit-tabular-nums">{formatNumber(done)} de {formatNumber(c.all)} al día</p>
              </div>
              <div className="wit-flex-1 wit-space-y-1.5">
                <StackedProgress
                  total={c.all}
                  segments={[
                    { value: c.current + c.unknown, className: 'wit-bg-emerald-500', label: 'Al día' },
                    { value: c.outdated, className: 'wit-bg-amber-400', label: 'Desactualizados' },
                    { value: c.missing, className: 'wit-bg-slate-300', label: 'Sin traducir' },
                  ]}
                />
                <div className="wit-flex wit-flex-wrap wit-gap-x-3 wit-text-xs wit-text-muted-foreground">
                  <span><span className="wit-mr-1 wit-inline-block wit-h-2 wit-w-2 wit-rounded-full wit-bg-slate-300" />{c.missing} sin traducir</span>
                  <span><span className="wit-mr-1 wit-inline-block wit-h-2 wit-w-2 wit-rounded-full wit-bg-amber-400" />{c.outdated} desactualizados</span>
                  {c.edited > 0 && <span className="wit-text-rose-700">{c.edited} editados a mano</span>}
                </div>
              </div>
              <div className="wit-flex wit-gap-2">
                <Button variant="outline" size="sm" onClick={() => { rememberLanguage(l.code); window.location.href = pageUrl('content', { to: l.code }); }}>
                  Ver <ArrowRight className="wit-h-3.5 wit-w-3.5" />
                </Button>
                {data.setup.has_api_key && c.pending > 0 && (
                  <Button size="sm" onClick={() => setConfirm(l)}>Traducir {c.pending}</Button>
                )}
              </div>
            </div>
          );
        })}
        {data.languages.length === 0 && (
          <p className="wit-p-4 wit-text-sm wit-text-muted-foreground">WPML no tiene idiomas de traducción activos.</p>
        )}
      </CardContent>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(o) => !o && setConfirm(null)}
        title={confirm ? `Traducir ${plural(confirm.counts.pending, 'contenido', 'contenidos')} al ${confirm.name}` : ''}
        confirmLabel="Traducir en segundo plano"
        busy={translateAll.isPending}
        onConfirm={() => confirm && translateAll.mutate(confirm.code)}
      >
        {confirm && (
          <div className="wit-space-y-2">
            <p>Se traducirán con la API configurada ({data.setup.provider}): {confirm.counts.missing} sin traducir y {confirm.counts.outdated} desactualizados. Cada traducción consume tokens.</p>
            {confirm.counts.edited > 0 && (
              <p className="wit-text-rose-700">Hay traducciones editadas a mano: si están desactualizadas, se reemplazarán. Revísalas antes en Contenidos si quieres conservar esos cambios.</p>
            )}
            <p>Se guardan como borrador. Puedes cerrar la página mientras tanto.</p>
          </div>
        )}
      </ConfirmDialog>
    </Card>
  );
}

function StringsCard({ data }: { data: Overview }) {
  if (!data.strings) return null;
  return (
    <Card>
      <CardHeader className="wit-flex-row wit-items-start wit-justify-between wit-space-y-0">
        <div>
          <CardTitle className="wit-flex wit-items-center wit-gap-2 wit-text-base"><Type className="wit-h-4 wit-w-4" />Cadenas del tema y plugins</CardTitle>
          <CardDescription>Botones, menús, pies de página: WPML String Translation.</CardDescription>
        </div>
        <Button variant="outline" size="sm" onClick={() => (window.location.href = pageUrl('strings'))}>Abrir</Button>
      </CardHeader>
      <CardContent className="wit-flex wit-flex-wrap wit-gap-2">
        {data.strings.languages.map((l) => (
          <a key={l.code} href={pageUrl('strings', { to: l.code })} className="wit-flex wit-items-center wit-gap-2 wit-rounded-md wit-border wit-border-border wit-px-3 wit-py-2 wit-text-sm hover:wit-bg-slate-50">
            <span className="wit-font-medium">{l.name}</span>
            {l.pending > 0 ? <Badge variant="warning">{formatNumber(l.pending)} {l.pending === 1 ? 'pendiente' : 'pendientes'}</Badge> : <Badge variant="success">al día</Badge>}
          </a>
        ))}
      </CardContent>
    </Card>
  );
}

function ClaudeCard({ data }: { data: Overview }) {
  const { setup } = data;
  return (
    <Card>
      <CardHeader>
        <CardTitle className="wit-flex wit-items-center wit-gap-2 wit-text-base"><Sparkles className="wit-h-4 wit-w-4 wit-text-violet-600" />Traducir desde Claude</CardTitle>
        <CardDescription>
          {setup.mcp_ready
            ? setup.connections > 0
              ? `${setup.connections} ${setup.connections === 1 ? 'conexión activa' : 'conexiones activas'}. Pide en el chat qué falta y Claude lo traduce con tu suscripción.`
              : 'Listo para conectar: añade el conector en Claude.'
            : 'Traduce desde el chat de tu suscripción de Claude, sin gastar tokens de API.'}
        </CardDescription>
      </CardHeader>
      {setup.can_manage && (
        <CardContent>
          <Button variant="outline" size="sm" className="wit-w-full" onClick={() => (window.location.href = pageUrl('settings', { tab: 'claude' }))}>
            {setup.mcp_enabled ? 'Ver conexiones' : 'Activar'}
          </Button>
        </CardContent>
      )}
    </Card>
  );
}

function RecentCard({ data }: { data: Overview }) {
  return (
    <Card>
      <CardHeader className="wit-flex-row wit-items-center wit-justify-between wit-space-y-0">
        <CardTitle className="wit-text-base">Actividad reciente</CardTitle>
        <a href={pageUrl('history')} className="wit-text-xs wit-font-medium wit-text-primary hover:wit-underline">Ver todo</a>
      </CardHeader>
      <CardContent className="wit-space-y-3">
        {data.recent.length === 0 && <p className="wit-text-sm wit-text-muted-foreground">Todavía no hay actividad.</p>}
        {data.recent.map((r) => (
          <div key={r.id} className="wit-flex wit-items-start wit-gap-2 wit-text-sm">
            {r.status === 'success' ? <CheckCircle2 className="wit-mt-0.5 wit-h-4 wit-w-4 wit-flex-shrink-0 wit-text-emerald-600" /> : <Circle className="wit-mt-0.5 wit-h-4 wit-w-4 wit-flex-shrink-0 wit-text-destructive" />}
            <div className="wit-min-w-0 wit-flex-1">
              <p className="wit-truncate wit-font-medium">{r.title}</p>
              <p className="wit-flex wit-flex-wrap wit-gap-x-2 wit-text-xs wit-text-muted-foreground">
                {r.to && <span>→ {r.to.toUpperCase()}</span>}
                <Via via={r.via} />
                <span>{formatRelativeTime(r.date)}</span>
              </p>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
