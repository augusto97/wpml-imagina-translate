import { useEffect, useState } from 'react';
import { AlertCircle, CheckCircle2, Info, X } from 'lucide-react';
import { dismiss, subscribe, type ToastEntry } from '@/lib/toast';
import { cn } from '@/lib/utils';

/**
 * Pinta los avisos abajo a la derecha. Montado dentro de `.wit-app`: las
 * utilities de Tailwind se compilan como `.wit-app .wit-…`.
 */
export function Toaster() {
  const [entries, setEntries] = useState<ToastEntry[]>([]);
  useEffect(() => subscribe(setEntries), []);

  if (entries.length === 0) return null;

  return (
    <div
      className="wit-fixed wit-bottom-4 wit-right-4 wit-z-[100000] wit-flex wit-w-[calc(100%-2rem)] wit-max-w-sm wit-flex-col wit-gap-2"
      role="status"
      aria-live="polite"
    >
      {entries.map((entry) => {
        const Icon = entry.kind === 'error' ? AlertCircle : entry.kind === 'info' ? Info : CheckCircle2;
        return (
          <div
            key={entry.id}
            className={cn(
              'wit-flex wit-items-start wit-gap-2 wit-rounded-md wit-border wit-bg-background wit-p-3 wit-shadow-lg wit-animate-in wit-fade-in wit-slide-in-from-bottom-2',
              entry.kind === 'error' ? 'wit-border-destructive/50' : 'wit-border-border',
            )}
          >
            <Icon
              className={cn(
                'wit-mt-0.5 wit-h-4 wit-w-4 wit-flex-shrink-0',
                entry.kind === 'error' ? 'wit-text-destructive' : entry.kind === 'info' ? 'wit-text-primary' : 'wit-text-emerald-600',
              )}
            />
            <p className="wit-flex-1 wit-text-sm wit-text-foreground">{entry.message}</p>
            <button
              type="button"
              onClick={() => dismiss(entry.id)}
              aria-label="Cerrar aviso"
              className="wit-text-muted-foreground hover:wit-text-foreground"
            >
              <X className="wit-h-4 wit-w-4" />
            </button>
          </div>
        );
      })}
    </div>
  );
}
