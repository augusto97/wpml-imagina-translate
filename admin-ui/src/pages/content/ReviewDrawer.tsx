import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, ExternalLink, EyeOff, Globe, Pencil, Trash2, X } from 'lucide-react';
import { api } from '@/lib/api';
import { notify } from '@/lib/toast';
import { languageName } from '@/lib/languages';
import type { PostRow, Review } from '@/types';
import { Drawer } from '@/components/ui/drawer';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { Callout } from '@/components/callout';
import { ConfirmDialog } from '@/components/ui/dialog';

/**
 * Revisión de una traducción junto a su original: corregir una frase sin
 * regenerar el resto, publicar, despublicar o enviar a la papelera.
 */
export function ReviewDrawer({ row, language, onClose }: { row: PostRow | null; language: string; onClose: () => void }) {
  const client = useQueryClient();
  const open = row !== null;
  const postId = row?.id ?? 0;
  const [trash, setTrash] = useState(false);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['review', postId, language],
    queryFn: () => api.get<Review>('review', { post_id: postId, language }),
    enabled: open,
  });

  const refresh = () => {
    void client.invalidateQueries({ queryKey: ['review', postId, language] });
    void client.invalidateQueries({ queryKey: ['posts'] });
  };

  const publish = useMutation({
    mutationFn: () => api.post('publish', { post_id: postId, language }),
    onSuccess: () => { notify('Traducción publicada.'); refresh(); },
  });
  const remove = useMutation({
    mutationFn: (mode: 'unpublish' | 'trash') => api.post('remove', { post_id: postId, language, mode }),
    onSuccess: (_r, mode) => {
      notify(mode === 'trash' ? 'Traducción enviada a la papelera. Si vuelves a traducir, se recupera.' : 'Traducción despublicada: vuelve a ser un borrador.', 'info');
      setTrash(false);
      refresh();
      if (mode === 'trash') onClose();
    },
  });

  const status = data?.translation.post_status;

  return (
    <Drawer
      open={open}
      onOpenChange={(o) => !o && onClose()}
      title={row ? row.title : ''}
      description={`Traducción al ${languageName(language)}`}
      className="sm:wit-max-w-3xl"
      footer={
        data && (
          <div className="wit-flex wit-w-full wit-flex-wrap wit-items-center wit-justify-between wit-gap-2">
            <Button variant="ghost" size="sm" className="wit-text-destructive" onClick={() => setTrash(true)}>
              <Trash2 className="wit-h-4 wit-w-4" />Papelera
            </Button>
            <div className="wit-flex wit-gap-2">
              <Button variant="outline" size="sm" onClick={() => window.open(data.translation.edit_url, '_blank', 'noopener')}>
                <ExternalLink className="wit-h-4 wit-w-4" />Abrir en el editor
              </Button>
              {status === 'publish' ? (
                <Button variant="outline" size="sm" onClick={() => remove.mutate('unpublish')} disabled={remove.isPending}>
                  <EyeOff className="wit-h-4 wit-w-4" />Despublicar
                </Button>
              ) : (
                <Button size="sm" onClick={() => publish.mutate()} disabled={publish.isPending}>
                  <Globe className="wit-h-4 wit-w-4" />Publicar
                </Button>
              )}
            </div>
          </div>
        )
      }
    >
      {isLoading && <div className="wit-space-y-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="wit-h-10" />)}</div>}
      {isError && <Callout kind="error">{error instanceof Error ? error.message : 'No se pudo cargar la traducción.'}</Callout>}
      {data && (
        <div className="wit-space-y-4">
          {data.status === 'outdated' && (
            <Callout kind="warning">El original cambió después de traducirlo. Vuelve a traducir para ponerla al día.</Callout>
          )}
          <p className="wit-text-xs wit-text-muted-foreground">
            Pulsa el lápiz de cualquier frase para corregirla. Solo cambia esa frase; el resto de la traducción y tus otras ediciones se conservan, y la memoria aprende la nueva redacción.
          </p>
          <div className="wit-grid wit-grid-cols-2 wit-gap-x-4 wit-border-b wit-border-border wit-pb-2 wit-text-xs wit-font-medium wit-uppercase wit-tracking-wide wit-text-muted-foreground">
            <span>Original</span><span>Traducción</span>
          </div>
          <Pair label="Título" original={data.original.title} translation={data.translation.title} postId={postId} language={language} onSaved={refresh} />
          {data.original.excerpt && (
            <Pair label="Extracto" original={data.original.excerpt} translation={data.translation.excerpt} postId={postId} language={language} onSaved={refresh} />
          )}
          {Object.entries(data.original.meta).map(([key, value]) => (
            <Pair key={key} label={key} original={value} translation={data.translation.meta[key] ?? ''} postId={postId} language={language} onSaved={refresh} />
          ))}
          {data.original.strings.map((text, i) => (
            <Pair key={i} original={text} translation={data.translation.strings[i] ?? ''} postId={postId} language={language} onSaved={refresh} />
          ))}
          {data.truncated && <Callout kind="info">{data.truncated}</Callout>}
        </div>
      )}

      <ConfirmDialog
        open={trash}
        onOpenChange={setTrash}
        title="Enviar la traducción a la papelera"
        confirmLabel="Enviar a la papelera"
        destructive
        busy={remove.isPending}
        onConfirm={() => remove.mutate('trash')}
      >
        Deja de verse en el sitio. Se puede restaurar desde la papelera de WordPress durante 30 días, y si vuelves a traducir el contenido, se recupera.
      </ConfirmDialog>
    </Drawer>
  );
}

function Pair({ label, original, translation, postId, language, onSaved }: {
  label?: string;
  original: string;
  translation: string;
  postId: number;
  language: string;
  onSaved: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(translation);

  const correct = useMutation({
    mutationFn: () => api.post('correct', { post_id: postId, language, current_text: translation, new_text: value }),
    onSuccess: () => { notify('Corrección guardada.'); setEditing(false); onSaved(); },
  });

  return (
    <div className="wit-grid wit-grid-cols-2 wit-gap-4 wit-text-sm">
      <div>
        {label && <p className="wit-mb-0.5 wit-text-xs wit-font-medium wit-text-muted-foreground">{label}</p>}
        <p className="wit-whitespace-pre-wrap wit-break-words wit-text-foreground/80">{original}</p>
      </div>
      <div className="wit-group">
        {label && <p className="wit-mb-0.5 wit-text-xs wit-font-medium wit-text-muted-foreground">{label}</p>}
        {editing ? (
          <div className="wit-space-y-2">
            <Textarea value={value} onChange={(e) => setValue(e.target.value)} rows={Math.min(6, Math.max(2, Math.ceil(value.length / 60)))} autoFocus />
            <div className="wit-flex wit-gap-2">
              <Button size="sm" onClick={() => correct.mutate()} disabled={correct.isPending || value.trim() === '' || value === translation}>
                <Check className="wit-h-4 wit-w-4" />Guardar
              </Button>
              <Button size="sm" variant="ghost" onClick={() => { setEditing(false); setValue(translation); }}>
                <X className="wit-h-4 wit-w-4" />Cancelar
              </Button>
            </div>
          </div>
        ) : (
          <div className="wit-flex wit-items-start wit-gap-2">
            <p className="wit-flex-1 wit-whitespace-pre-wrap wit-break-words">{translation || <span className="wit-text-muted-foreground">—</span>}</p>
            {translation && (
              <button type="button" aria-label="Corregir esta frase" onClick={() => { setValue(translation); setEditing(true); }}
                className="wit-rounded wit-p-1 wit-text-muted-foreground wit-opacity-60 hover:wit-bg-slate-100 hover:wit-text-foreground group-hover:wit-opacity-100">
                <Pencil className="wit-h-3.5 wit-w-3.5" />
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
