import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Loader2, Square, XCircle } from 'lucide-react';
import { api } from '@/lib/api';
import { notify } from '@/lib/toast';
import { languageName } from '@/lib/languages';
import type { QueueSummary } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';

/**
 * La cola en segundo plano, en vivo. Se refresca cada 4 s mientras hay
 * trabajo; sin trabajo pendiente no se pinta nada.
 */
export function QueueCard({ initial }: { initial?: QueueSummary }) {
  const client = useQueryClient();
  const { data } = useQuery({
    queryKey: ['queue'],
    queryFn: () => api.get<QueueSummary>('queue'),
    ...(initial ? { initialData: initial } : {}),
    refetchInterval: (q) => {
      const s = q.state.data;
      return s && s.pending + s.processing > 0 ? 4000 : false;
    },
  });

  const cancel = useMutation({
    mutationFn: () => api.del<{ removed: number }>('queue'),
    onSuccess: (r) => {
      notify(`Cola detenida: ${r.removed} contenidos ya no se traducirán.`, 'info');
      void client.invalidateQueries({ queryKey: ['queue'] });
    },
  });

  if (!data || data.pending + data.processing === 0) return null;

  const finished = data.done + data.error;
  const total = finished + data.pending + data.processing;

  return (
    <Card>
      <CardHeader className="wit-flex-row wit-items-center wit-justify-between wit-space-y-0">
        <CardTitle className="wit-flex wit-items-center wit-gap-2">
          <Loader2 className="wit-h-4 wit-w-4 wit-animate-spin wit-text-primary" />
          Traduciendo en segundo plano
        </CardTitle>
        <Button variant="outline" size="sm" onClick={() => cancel.mutate()} disabled={cancel.isPending}>
          <Square className="wit-h-3.5 wit-w-3.5" /> Detener
        </Button>
      </CardHeader>
      <CardContent className="wit-space-y-3">
        <div className="wit-flex wit-items-center wit-justify-between wit-text-sm">
          <span className="wit-text-muted-foreground">Puedes cerrar esta página: el servidor sigue.</span>
          <span className="wit-tabular-nums wit-font-medium">{finished} / {total}</span>
        </div>
        <Progress value={total ? (finished / total) * 100 : 0} />
        <ul className="wit-space-y-1">
          {data.recent.filter((r) => r.status === 'done' || r.status === 'error').slice(0, 4).map((r) => (
            <li key={r.id} className="wit-flex wit-items-center wit-gap-2 wit-text-sm">
              {r.status === 'done'
                ? <CheckCircle2 className="wit-h-4 wit-w-4 wit-text-emerald-600" />
                : <XCircle className="wit-h-4 wit-w-4 wit-text-destructive" />}
              <span className="wit-truncate">{r.title}</span>
              <span className="wit-text-muted-foreground">→ {languageName(r.language)}</span>
              {r.status === 'error' && <span className="wit-truncate wit-text-destructive">{r.message}</span>}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
