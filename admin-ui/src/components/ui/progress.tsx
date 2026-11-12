import { cn } from '@/lib/utils';

export interface ProgressSegment {
  value: number;
  className: string;
  label: string;
}

/** Barra apilada: varios estados en una sola línea (al día / desactualizado / pendiente). */
export function StackedProgress({ segments, total, className }: { segments: ProgressSegment[]; total: number; className?: string }) {
  return (
    <div className={cn('wit-flex wit-h-2 wit-w-full wit-overflow-hidden wit-rounded-full wit-bg-slate-100', className)} role="img"
      aria-label={segments.map((s) => `${s.label}: ${s.value}`).join(', ')}>
      {total > 0 &&
        segments.map((s) =>
          s.value > 0 ? (
            <div key={s.label} className={cn('wit-h-full wit-transition-all', s.className)} style={{ width: `${(s.value / total) * 100}%` }} />
          ) : null,
        )}
    </div>
  );
}

export function Progress({ value, className }: { value: number; className?: string }) {
  return (
    <div className={cn('wit-h-2 wit-w-full wit-overflow-hidden wit-rounded-full wit-bg-slate-100', className)}
      role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(value)}>
      <div className="wit-h-full wit-bg-primary wit-transition-all" style={{ width: `${Math.min(100, Math.max(0, value))}%` }} />
    </div>
  );
}
