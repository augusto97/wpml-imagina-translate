import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookA, Bot, Brain, Check, Copy, KeyRound, Loader2, PlugZap, RefreshCw, Sparkles, SlidersHorizontal, Trash2 } from 'lucide-react';
import { api } from '@/lib/api';
import { notify, notifyError } from '@/lib/toast';
import { queryParam, setQueryParams } from '@/lib/languages';
import { formatDateTime, formatNumber, formatRelativeTime, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Settings } from '@/types';
import { AppShell } from '@/components/app-shell';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Callout } from '@/components/callout';

type Provider = Settings['provider'];
type TabId = 'provider' | 'translation' | 'glossary' | 'claude';

const TABS: { value: TabId; label: string; icon: React.ReactNode }[] = [
  { value: 'provider', label: 'Proveedor de IA', icon: <Bot className="wit-h-4 wit-w-4" /> },
  { value: 'translation', label: 'Traducción', icon: <SlidersHorizontal className="wit-h-4 wit-w-4" /> },
  { value: 'glossary', label: 'Glosario', icon: <BookA className="wit-h-4 wit-w-4" /> },
  { value: 'claude', label: 'Claude (MCP)', icon: <Sparkles className="wit-h-4 wit-w-4" /> },
];

const PROVIDERS: Record<Provider, { name: string; keyUrl: string; keyHost: string; keyHint: string }> = {
  openai: { name: 'OpenAI', keyUrl: 'https://platform.openai.com/api-keys', keyHost: 'platform.openai.com', keyHint: 'sk-…' },
  claude: { name: 'Claude (API de Anthropic)', keyUrl: 'https://console.anthropic.com/settings/keys', keyHost: 'console.anthropic.com', keyHint: 'sk-ant-…' },
  gemini: { name: 'Google Gemini', keyUrl: 'https://aistudio.google.com/app/apikey', keyHost: 'aistudio.google.com', keyHint: 'AIza…' },
};

/** Lo que se edita en la pantalla; las claves van aparte (solo se escriben). */
interface Draft {
  provider: Provider;
  models: Record<Provider, string>;
  prompt: string;
  glossary: string;
  translate_meta_fields: boolean;
  meta_fields: string;
  use_wpml_fields: boolean;
  batch_size: number;
  memory_enabled: boolean;
  mcp_enabled: boolean;
}

function draftFrom(s: Settings): Draft {
  return {
    provider: s.provider,
    models: { ...s.models },
    prompt: s.prompt,
    glossary: s.glossary,
    translate_meta_fields: s.translate_meta_fields,
    meta_fields: s.meta_fields,
    use_wpml_fields: s.use_wpml_fields,
    batch_size: s.batch_size,
    memory_enabled: s.memory.enabled,
    mcp_enabled: s.mcp.enabled,
  };
}

const EMPTY_KEYS: Record<Provider, string> = { openai: '', claude: '', gemini: '' };

export function SettingsPage() {
  const client = useQueryClient();
  const [tab, setTab] = useState<TabId>(() => {
    const t = queryParam('tab') as TabId;
    return TABS.some((x) => x.value === t) ? t : 'provider';
  });
  useEffect(() => setQueryParams({ tab: tab === 'provider' ? undefined : tab }), [tab]);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['settings'],
    queryFn: () => api.get<Settings>('settings'),
  });

  const [draft, setDraft] = useState<Draft | null>(null);
  const [keys, setKeys] = useState<Record<Provider, string>>(EMPTY_KEYS);
  const [clearKeys, setClearKeys] = useState<Partial<Record<Provider, boolean>>>({});

  useEffect(() => {
    if (data && !draft) setDraft(draftFrom(data));
  }, [data, draft]);

  const base = data ? draftFrom(data) : null;
  const dirty = !!draft && !!base && (
    JSON.stringify(draft) !== JSON.stringify(base)
    || Object.values(keys).some((k) => k.trim() !== '')
    || Object.values(clearKeys).some(Boolean)
  );

  // Avisar antes de salir con cambios sin guardar.
  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  const save = useMutation({
    mutationFn: () => {
      if (!draft || !base) throw new Error('Los ajustes no están cargados.');
      const body: Record<string, unknown> = {};
      for (const k of Object.keys(draft) as (keyof Draft)[]) {
        if (JSON.stringify(draft[k]) !== JSON.stringify(base[k])) body[k] = draft[k];
      }
      const typed = Object.fromEntries(Object.entries(keys).filter(([, v]) => v.trim() !== '').map(([k, v]) => [k, v.trim()]));
      if (Object.keys(typed).length) body.keys = typed;
      if (Object.values(clearKeys).some(Boolean)) body.clear_keys = clearKeys;
      return api.put<Settings>('settings', body);
    },
    onSuccess: (fresh) => {
      client.setQueryData(['settings'], fresh);
      setDraft(draftFrom(fresh));
      setKeys(EMPTY_KEYS);
      setClearKeys({});
      // El resto de pantallas leen si hay API key desde witConfig.
      if (window.witConfig) window.witConfig.features.apiKey = fresh.keys[fresh.provider].stored;
      notify('Ajustes guardados.');
    },
  });

  const update = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => (d ? { ...d, [key]: value } : d));
  const discard = () => {
    if (data) setDraft(draftFrom(data));
    setKeys(EMPTY_KEYS);
    setClearKeys({});
  };

  return (
    <AppShell page="settings" title="Ajustes" description="Con qué IA se traduce, cómo, y la conexión con Claude.">
      <div className="wit-space-y-4 wit-pb-16">
        <Tabs items={TABS} value={tab} onChange={setTab} />

        {isError && <Callout kind="error">{error instanceof Error ? error.message : 'No se pudieron cargar los ajustes.'}</Callout>}
        {(isLoading || !draft || !data) && !isError && (
          <div className="wit-space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="wit-h-28" />)}</div>
        )}

        {draft && data && tab === 'provider' && (
          <ProviderTab
            data={data}
            draft={draft}
            update={update}
            keys={keys}
            setKey={(p, v) => setKeys((k) => ({ ...k, [p]: v }))}
            clearKeys={clearKeys}
            setClear={(p, v) => setClearKeys((c) => ({ ...c, [p]: v }))}
            dirty={dirty}
            saveFirst={() => save.mutateAsync()}
          />
        )}
        {draft && data && tab === 'translation' && <TranslationTab data={data} draft={draft} update={update} />}
        {draft && data && tab === 'glossary' && <GlossaryTab draft={draft} update={update} />}
        {draft && data && tab === 'claude' && <ClaudeTab data={data} draft={draft} update={update} dirty={dirty} />}
      </div>

      {/* Barra de guardado: solo cuando hay algo que guardar */}
      <div
        className={cn(
          'wit-fixed wit-bottom-0 wit-right-0 wit-z-[9990] wit-border-t wit-border-border wit-bg-white/95 wit-backdrop-blur wit-transition-transform wit-duration-200 wit-left-0 md:wit-left-[160px]',
          dirty ? 'wit-visible wit-translate-y-0' : 'wit-invisible wit-translate-y-full',
        )}
        aria-hidden={!dirty}
      >
        <div className="wit-mx-auto wit-flex wit-max-w-[1200px] wit-items-center wit-justify-between wit-gap-3 wit-px-5 wit-py-3">
          <span className="wit-text-sm wit-text-muted-foreground">Tienes cambios sin guardar.</span>
          <div className="wit-flex wit-gap-2">
            <Button variant="ghost" size="sm" onClick={discard} disabled={save.isPending} tabIndex={dirty ? 0 : -1}>Descartar</Button>
            <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending} tabIndex={dirty ? 0 : -1}>
              {save.isPending ? <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin" /> : <Check className="wit-h-4 wit-w-4" />}
              Guardar cambios
            </Button>
          </div>
        </div>
      </div>
    </AppShell>
  );
}

type Update = <K extends keyof Draft>(key: K, value: Draft[K]) => void;

function Field({ label, htmlFor, hint, children }: { label: string; htmlFor?: string; hint?: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="wit-space-y-1.5">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {hint && <p className="wit-text-xs wit-leading-relaxed wit-text-muted-foreground">{hint}</p>}
    </div>
  );
}

function ToggleRow({ id, title, description, checked, onChange, children }: {
  id: string;
  title: string;
  description?: React.ReactNode;
  checked: boolean;
  onChange: (v: boolean) => void;
  children?: React.ReactNode;
}) {
  return (
    <div className="wit-space-y-3">
      <div className="wit-flex wit-items-start wit-justify-between wit-gap-4">
        <div className="wit-space-y-1">
          <Label htmlFor={id} className="wit-cursor-pointer">{title}</Label>
          {description && <p className="wit-text-xs wit-leading-relaxed wit-text-muted-foreground">{description}</p>}
        </div>
        <Switch id={id} checked={checked} onCheckedChange={onChange} />
      </div>
      {children}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Proveedor
// ---------------------------------------------------------------------------

function ProviderTab({ data, draft, update, keys, setKey, clearKeys, setClear, dirty, saveFirst }: {
  data: Settings;
  draft: Draft;
  update: Update;
  keys: Record<Provider, string>;
  setKey: (p: Provider, v: string) => void;
  clearKeys: Partial<Record<Provider, boolean>>;
  setClear: (p: Provider, v: boolean) => void;
  dirty: boolean;
  saveFirst: () => Promise<Settings>;
}) {
  const p = draft.provider;
  const info = PROVIDERS[p];
  const stored = data.keys[p].stored && !clearKeys[p];
  const typed = keys[p].trim();
  const [models, setModels] = useState<Partial<Record<Provider, { id: string; name: string }[]>>>({});
  const [testResult, setTestResult] = useState<{ ok: boolean; text: string } | null>(null);

  const loadModels = useMutation({
    mutationFn: () => api.post<{ models: { id: string; name: string }[] }>('models', { provider: p, api_key: typed }),
    onSuccess: (r) => setModels((m) => ({ ...m, [p]: r.models })),
    meta: { silentError: true },
    onError: (e) => notifyError(e),
  });

  // Con una clave guardada, la lista de modelos se carga sola.
  useEffect(() => {
    if (data.keys[p].stored && !models[p] && !loadModels.isPending) loadModels.mutate();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [p]);

  const test = useMutation({
    mutationFn: async () => {
      if (dirty) await saveFirst();
      return api.post<{ message: string; translation: string }>('test-connection');
    },
    meta: { silentError: true },
    onSuccess: (r) => setTestResult({ ok: true, text: r.translation ? `${r.message} «Hola mundo» → «${r.translation}»` : r.message }),
    onError: (e) => setTestResult({ ok: false, text: e instanceof Error ? e.message : 'La conexión falló.' }),
  });

  const list = models[p] ?? [];
  const current = draft.models[p];
  const options = list.some((m) => m.id === current) || !current ? list : [{ id: current, name: current }, ...list];

  return (
    <div className="wit-space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="wit-text-base">¿Con qué IA se traduce?</CardTitle>
          <CardDescription>Es la que traduce cuando pulsas «Traducir» en este panel o en el editor. Desde Claude por MCP no se usa: allí traduce tu suscripción.</CardDescription>
        </CardHeader>
        <CardContent>
          <div role="radiogroup" aria-label="Proveedor de IA" className="wit-grid wit-gap-2 sm:wit-grid-cols-3">
            {(Object.keys(PROVIDERS) as Provider[]).map((id) => {
              const active = id === p;
              const has = data.keys[id].stored && !clearKeys[id];
              return (
                <button
                  key={id}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  onClick={() => { update('provider', id); setTestResult(null); }}
                  className={cn(
                    'wit-flex wit-flex-col wit-items-start wit-gap-1 wit-rounded-lg wit-border wit-p-3 wit-text-left wit-transition-colors',
                    active ? 'wit-border-primary wit-bg-primary/5 wit-ring-1 wit-ring-primary' : 'wit-border-border hover:wit-bg-slate-50',
                  )}
                >
                  <span className="wit-text-sm wit-font-medium">{PROVIDERS[id].name}</span>
                  <span className="wit-text-xs wit-text-muted-foreground">
                    {has ? <>Clave guardada <span className="wit-font-mono">{data.keys[id].hint}</span></> : keys[id].trim() ? 'Clave nueva sin guardar' : 'Sin clave'}
                  </span>
                </button>
              );
            })}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="wit-text-base">{info.name}</CardTitle>
        </CardHeader>
        <CardContent className="wit-space-y-5">
          <Field
            label="API key"
            htmlFor="wit-api-key"
            hint={<>Consíguela en <a href={info.keyUrl} target="_blank" rel="noopener noreferrer" className="wit-text-primary hover:wit-underline">{info.keyHost}</a>. Se guarda en este sitio y nunca se vuelve a mostrar entera.</>}
          >
            <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-2">
              <Input
                id="wit-api-key"
                type="password"
                autoComplete="off"
                spellCheck={false}
                value={keys[p]}
                onChange={(e) => { setKey(p, e.target.value); if (clearKeys[p]) setClear(p, false); }}
                placeholder={stored ? `Guardada (${data.keys[p].hint}). Escribe otra para reemplazarla.` : info.keyHint}
                className="wit-max-w-md wit-flex-1 wit-font-mono placeholder:wit-font-sans"
              />
              {data.keys[p].stored && !typed && (
                clearKeys[p]
                  ? <Button variant="ghost" size="sm" onClick={() => setClear(p, false)}>Conservar la clave</Button>
                  : <Button variant="ghost" size="sm" className="wit-text-destructive" onClick={() => setClear(p, true)}><Trash2 className="wit-h-4 wit-w-4" />Quitar</Button>
              )}
            </div>
            {clearKeys[p] && <p className="wit-text-xs wit-text-destructive">La clave se borrará al guardar.</p>}
          </Field>

          <Field label="Modelo" htmlFor="wit-model" hint="Los modelos pequeños y rápidos traducen bien y cuestan bastante menos. Cambia a uno mayor si notas fallos de estilo.">
            <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-2">
              {options.length > 0 ? (
                <Select id="wit-model" value={current} onChange={(e) => update('models', { ...draft.models, [p]: e.target.value })} className="wit-max-w-md wit-flex-1">
                  {options.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </Select>
              ) : (
                <Input id="wit-model" value={current} onChange={(e) => update('models', { ...draft.models, [p]: e.target.value })} className="wit-max-w-md wit-flex-1 wit-font-mono" />
              )}
              <Button variant="outline" size="sm" onClick={() => loadModels.mutate()} disabled={loadModels.isPending || (!stored && !typed)}>
                {loadModels.isPending ? <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin" /> : <RefreshCw className="wit-h-4 wit-w-4" />}
                {list.length ? 'Actualizar lista' : 'Cargar modelos'}
              </Button>
            </div>
          </Field>

          <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-3 wit-border-t wit-border-border wit-pt-4">
            <Button variant="secondary" onClick={() => { setTestResult(null); test.mutate(); }} disabled={test.isPending || (!stored && !typed)}>
              {test.isPending ? <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin" /> : <PlugZap className="wit-h-4 wit-w-4" />}
              {dirty ? 'Guardar y probar la conexión' : 'Probar la conexión'}
            </Button>
            {testResult && (
              <span className={cn('wit-text-sm', testResult.ok ? 'wit-text-emerald-700' : 'wit-text-destructive')}>{testResult.text}</span>
            )}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Traducción
// ---------------------------------------------------------------------------

function TranslationTab({ data, draft, update }: { data: Settings; draft: Draft; update: Update }) {
  const client = useQueryClient();
  const [confirmClear, setConfirmClear] = useState(false);

  const clear = useMutation({
    mutationFn: () => api.del<{ removed: number }>('memory'),
    onSuccess: (r) => {
      notify(`Memoria vaciada: ${plural(r.removed, 'frase olvidada', 'frases olvidadas')}.`, 'info');
      setConfirmClear(false);
      void client.invalidateQueries({ queryKey: ['settings'] });
    },
  });

  return (
    <div className="wit-space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="wit-text-base">Instrucciones para la IA</CardTitle>
          <CardDescription>Cómo debe traducir: tono, registro, qué evitar. Se aplica a todos los idiomas.</CardDescription>
        </CardHeader>
        <CardContent>
          <Field label="Prompt" htmlFor="wit-prompt" hint={<>Escribe <code className="wit-rounded wit-bg-slate-100 wit-px-1">{'{target_language}'}</code> donde deba ir el idioma de destino.</>}>
            <Textarea id="wit-prompt" value={draft.prompt} onChange={(e) => update('prompt', e.target.value)} rows={6} />
          </Field>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="wit-text-base">Qué se traduce además del contenido</CardTitle>
        </CardHeader>
        <CardContent className="wit-space-y-5">
          <ToggleRow
            id="wit-wpml-fields"
            title="Campos que WPML marca como «Traducir»"
            description={<>Incluye los que declaran Yoast, Rank Math, ACF y otros plugins compatibles con WPML. Los que WPML marca como «No traducir» tampoco se copian.</>}
            checked={draft.use_wpml_fields}
            onChange={(v) => update('use_wpml_fields', v)}
          />
          <div className="wit-border-t wit-border-border" />
          <ToggleRow
            id="wit-meta"
            title="Campos personalizados adicionales"
            description="Para campos que WPML no conoce. Escribe sus claves internas."
            checked={draft.translate_meta_fields}
            onChange={(v) => update('translate_meta_fields', v)}
          >
            {draft.translate_meta_fields && (
              <Field label="Claves de los campos" htmlFor="wit-meta-list" hint="Separadas por comas. Ejemplo: _yoast_wpseo_title, _yoast_wpseo_metadesc">
                <Textarea id="wit-meta-list" value={draft.meta_fields} onChange={(e) => update('meta_fields', e.target.value)} rows={2} className="wit-font-mono wit-text-xs" />
              </Field>
            )}
          </ToggleRow>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="wit-flex wit-items-center wit-gap-2 wit-text-base"><Brain className="wit-h-4 wit-w-4 wit-text-muted-foreground" />Memoria de traducción</CardTitle>
        </CardHeader>
        <CardContent className="wit-space-y-4">
          <ToggleRow
            id="wit-memory"
            title="Reutilizar lo que ya se tradujo"
            description="Evita pagar dos veces por la misma frase y hace que se traduzca igual en todo el sitio. Recomendado."
            checked={draft.memory_enabled}
            onChange={(v) => update('memory_enabled', v)}
          />
          {data.memory.entries > 0 && (
            <div className="wit-flex wit-flex-wrap wit-items-center wit-justify-between wit-gap-2 wit-rounded-md wit-bg-slate-50 wit-px-3 wit-py-2 wit-text-sm">
              <span>
                <strong className="wit-tabular-nums">{formatNumber(data.memory.entries)}</strong> frases guardadas, reutilizadas <strong className="wit-tabular-nums">{formatNumber(data.memory.reuses)}</strong> veces.
              </span>
              <Button variant="ghost" size="sm" className="wit-text-destructive" onClick={() => setConfirmClear(true)}>
                <Trash2 className="wit-h-4 wit-w-4" />Vaciar
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="wit-text-base">Traducción en segundo plano</CardTitle>
        </CardHeader>
        <CardContent>
          <Field label="Contenidos por tanda" htmlFor="wit-batch" hint="Cuántos contenidos traduce el servidor en cada pasada de la cola. Bájalo si tu hosting corta los procesos largos.">
            <Input id="wit-batch" type="number" min={1} max={50} value={draft.batch_size}
              onChange={(e) => update('batch_size', Math.min(50, Math.max(1, Number(e.target.value) || 1)))} className="wit-w-24" />
          </Field>
        </CardContent>
      </Card>

      <ConfirmDialog open={confirmClear} onOpenChange={setConfirmClear} title="Vaciar la memoria de traducción" confirmLabel="Vaciar" destructive busy={clear.isPending} onConfirm={() => clear.mutate()}>
        Se {data.memory.entries === 1 ? 'olvida la frase guardada' : `olvidan las ${formatNumber(data.memory.entries)} frases guardadas`}. Las traducciones ya hechas no cambian, pero las próximas volverán a pasar por la IA.
      </ConfirmDialog>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Glosario
// ---------------------------------------------------------------------------

const GLOSSARY_EXAMPLES: [string, string][] = [
  ['Imagina', 'Nunca se traduce, en ningún idioma.'],
  ['Servicios = Services', 'Traducción fija en todos los idiomas.'],
  ['[en] Inicio = Home', 'Solo para inglés.'],
  ['[fr,de] Contacto = Kontakt', 'Para varios idiomas.'],
  ['# comentario', 'Las líneas que empiezan por # se ignoran.'],
];

function GlossaryTab({ draft, update }: { draft: Draft; update: Update }) {
  const rules = draft.glossary.split('\n').filter((l) => l.trim() && !l.trim().startsWith('#')).length;
  return (
    <div className="wit-grid wit-gap-4 lg:wit-grid-cols-[1fr_20rem]">
      <Card>
        <CardHeader>
          <CardTitle className="wit-text-base">Glosario</CardTitle>
          <CardDescription>Marcas, nombres y términos que deben traducirse siempre igual. Las reglas mandan sobre el criterio de la IA.</CardDescription>
        </CardHeader>
        <CardContent className="wit-space-y-2">
          <Textarea
            aria-label="Reglas del glosario"
            value={draft.glossary}
            onChange={(e) => update('glossary', e.target.value)}
            rows={14}
            spellCheck={false}
            className="wit-font-mono wit-text-xs"
            placeholder={'Imagina\nServicios = Services\n[en] Inicio = Home'}
          />
          <p className="wit-text-xs wit-text-muted-foreground">{rules} {rules === 1 ? 'regla' : 'reglas'}. Una por línea.</p>
        </CardContent>
      </Card>
      <Card className="wit-h-fit">
        <CardHeader>
          <CardTitle className="wit-text-sm">Cómo se escribe</CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="wit-space-y-3 wit-text-sm">
            {GLOSSARY_EXAMPLES.map(([code, text]) => (
              <li key={code}>
                <code className="wit-rounded wit-bg-slate-100 wit-px-1.5 wit-py-0.5 wit-text-xs">{code}</code>
                <p className="wit-mt-1 wit-text-xs wit-text-muted-foreground">{text}</p>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Claude (MCP)
// ---------------------------------------------------------------------------

function ClaudeTab({ data, draft, update, dirty }: { data: Settings; draft: Draft; update: Update; dirty: boolean }) {
  const client = useQueryClient();
  const [copied, setCopied] = useState(false);
  const [revoke, setRevoke] = useState<Settings['mcp']['connections'][number] | null>(null);
  const live = data.mcp.enabled;
  const ready = live && data.mcp.problems.length === 0;

  const revokeMutation = useMutation({
    mutationFn: (grant: string) => api.del<{ revoked: boolean }>(`connections/${grant}`),
    onSuccess: () => {
      notify('Conexión revocada. Claude ya no puede acceder con ella.', 'info');
      setRevoke(null);
      void client.invalidateQueries({ queryKey: ['settings'] });
    },
  });

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(data.mcp.url);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      notify('No se pudo copiar: selecciona la URL y cópiala a mano.', 'error');
    }
  };

  return (
    <div className="wit-space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="wit-text-base">Traducir desde el chat de Claude</CardTitle>
          <CardDescription>
            Pregúntale a Claude qué falta por traducir, pídele que traduzca, revise o publique. Traduce Claude con tu suscripción: por este camino nunca se usa la API key de este sitio.
          </CardDescription>
        </CardHeader>
        <CardContent className="wit-space-y-4">
          <ToggleRow
            id="wit-mcp"
            title="Permitir que Claude se conecte a este sitio"
            description={draft.mcp_enabled !== live ? 'Guarda los cambios para aplicarlo.' : undefined}
            checked={draft.mcp_enabled}
            onChange={(v) => update('mcp_enabled', v)}
          />
          {/* Requisitos: se ven antes de activarlo, no después de descubrir que no conecta. */}
          {data.mcp.problems.length > 0 && (
            <Callout kind="warning" title={live ? 'Claude todavía no puede conectarse' : 'Antes de activarlo'}>
              <ul className="wit-list-disc wit-space-y-1 wit-pl-4">
                {data.mcp.problems.map((p) => <li key={p}>{p}</li>)}
              </ul>
            </Callout>
          )}
          {draft.mcp_enabled && (
            <Callout kind="info" title="Consumo de tu suscripción">
              Claude traduce como mucho 10 contenidos por petición. Cada tanda consume de los límites de uso de tu plan de Claude; para cientos de contenidos, la cola con API key de este panel suele ser más práctica.
            </Callout>
          )}
        </CardContent>
      </Card>

      {ready && !dirty && (
        <Card>
          <CardHeader>
            <CardTitle className="wit-text-base">Conectar Claude</CardTitle>
          </CardHeader>
          <CardContent className="wit-space-y-4">
            <Field label="URL del conector" htmlFor="wit-mcp-url">
              <div className="wit-flex wit-gap-2">
                <Input id="wit-mcp-url" readOnly value={data.mcp.url} onFocus={(e) => e.target.select()} className="wit-flex-1 wit-font-mono wit-text-xs" />
                <Button variant="outline" onClick={() => void copy()}>
                  {copied ? <Check className="wit-h-4 wit-w-4 wit-text-emerald-600" /> : <Copy className="wit-h-4 wit-w-4" />}
                  {copied ? 'Copiada' : 'Copiar'}
                </Button>
              </div>
            </Field>
            <ol className="wit-list-decimal wit-space-y-1.5 wit-pl-5 wit-text-sm">
              <li>En Claude, abre <strong>Personalizar → Conectores → Añadir conector personalizado</strong>.</li>
              <li>Pega esta URL exactamente como aparece y pulsa <strong>Añadir</strong>.</li>
              <li>Pulsa <strong>Conectar</strong>: se abrirá este sitio para que inicies sesión y apruebes el acceso.</li>
            </ol>
            <p className="wit-text-xs wit-text-muted-foreground">
              Claude actúa con los permisos de la cuenta con la que apruebes. Para limitar lo que puede hacer, apruébalo con una cuenta de rol Editor.
            </p>
          </CardContent>
        </Card>
      )}

      {live && (
        <Card>
          <CardHeader>
            <CardTitle className="wit-flex wit-items-center wit-gap-2 wit-text-base">
              <KeyRound className="wit-h-4 wit-w-4 wit-text-muted-foreground" />Conexiones activas
              <Badge variant="secondary">{data.mcp.connections.length}</Badge>
            </CardTitle>
          </CardHeader>
          <CardContent>
            {data.mcp.connections.length === 0 ? (
              <p className="wit-text-sm wit-text-muted-foreground">Todavía no hay ninguna. Aparecerán aquí cuando apruebes el acceso desde Claude.</p>
            ) : (
              <ul className="wit-divide-y wit-divide-border">
                {data.mcp.connections.map((c) => (
                  <li key={c.grant} className="wit-flex wit-flex-wrap wit-items-center wit-justify-between wit-gap-2 wit-py-2.5">
                    <div className="wit-min-w-0">
                      <p className="wit-text-sm wit-font-medium">{c.client} <span className="wit-font-normal wit-text-muted-foreground">· {c.user}</span></p>
                      <p className="wit-text-xs wit-text-muted-foreground">
                        Conectada {formatRelativeTime(c.created)} · <span title={c.last_used ? formatDateTime(c.last_used) : ''}>{c.last_used ? `último uso ${formatRelativeTime(c.last_used)}` : 'sin usar todavía'}</span>
                      </p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => setRevoke(c)}>Revocar</Button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      )}

      <ConfirmDialog
        open={revoke !== null}
        onOpenChange={(o) => !o && setRevoke(null)}
        title="Revocar la conexión"
        confirmLabel="Revocar"
        destructive
        busy={revokeMutation.isPending}
        onConfirm={() => revoke && revokeMutation.mutate(revoke.grant)}
      >
        {revoke?.client} dejará de poder acceder a este sitio con la cuenta de {revoke?.user}. Para volver a usarlo habrá que conectarlo de nuevo desde Claude.
      </ConfirmDialog>
    </div>
  );
}
