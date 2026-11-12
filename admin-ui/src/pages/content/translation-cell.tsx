import { Loader2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatRelativeTime } from '@/lib/format';
import type { PostRow } from '@/types';

const STATE: Record<PostRow['translation']['state'], { label: string; variant: 'success' | 'warning' | 'secondary' | 'outline' }> = {
  current: { label: 'Al día', variant: 'success' },
  outdated: { label: 'Desactualizada', variant: 'warning' },
  missing: { label: 'Sin traducir', variant: 'secondary' },
  unknown: { label: 'Traducida fuera del plugin', variant: 'outline' },
};

/** Estado de la traducción de una fila, con lo que importa para decidir. */
export function TranslationCell({ row }: { row: PostRow }) {
  const t = row.translation;
  const state = STATE[t.state];
  return (
    <div className="wit-flex wit-flex-col wit-gap-1">
      <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-1.5">
        {row.queued ? (
          <Badge variant="default" className="wit-gap-1">
            <Loader2 className={row.queued === 'processing' ? 'wit-h-3 wit-w-3 wit-animate-spin' : 'wit-h-3 wit-w-3'} />
            {row.queued === 'processing' ? 'Traduciendo' : 'En cola'}
          </Badge>
        ) : (
          <Badge variant={state.variant}>{state.label}</Badge>
        )}
        {t.edited && (
          <Badge variant="destructive" title="Alguien la cambió después de traducirla: traducir de nuevo reemplazaría esos cambios.">
            Editada a mano
          </Badge>
        )}
        {t.id > 0 && t.post_status === 'draft' && <Badge variant="outline">Borrador</Badge>}
        {t.id > 0 && t.post_status === 'publish' && <Badge variant="outline">Publicada</Badge>}
      </div>
      {t.at && <span className="wit-text-xs wit-text-muted-foreground">Traducida {formatRelativeTime(t.at)}</span>}
    </div>
  );
}
