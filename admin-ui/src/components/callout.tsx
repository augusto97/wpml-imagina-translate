import { AlertTriangle, CheckCircle2, Info } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Aviso dentro de la página (no las notices de WordPress). */
export function Callout({ kind = 'info', title, children, action, className }: {
  kind?: 'info' | 'warning' | 'success' | 'error';
  title?: string;
  children?: React.ReactNode;
  action?: React.ReactNode;
  className?: string;
}) {
  const Icon = kind === 'success' ? CheckCircle2 : kind === 'info' ? Info : AlertTriangle;
  return (
    <div
      className={cn(
        'wit-flex wit-items-start wit-gap-3 wit-rounded-lg wit-border wit-p-3 wit-text-sm',
        kind === 'info' && 'wit-border-blue-200 wit-bg-blue-50 wit-text-blue-900',
        kind === 'warning' && 'wit-border-amber-200 wit-bg-amber-50 wit-text-amber-900',
        kind === 'success' && 'wit-border-emerald-200 wit-bg-emerald-50 wit-text-emerald-900',
        kind === 'error' && 'wit-border-red-200 wit-bg-red-50 wit-text-red-900',
        className,
      )}
    >
      <Icon className="wit-mt-0.5 wit-h-4 wit-w-4 wit-flex-shrink-0" />
      <div className="wit-flex-1 wit-space-y-1">
        {title && <p className="wit-font-medium">{title}</p>}
        {children && <div className="wit-leading-relaxed">{children}</div>}
      </div>
      {action && <div className="wit-flex-shrink-0">{action}</div>}
    </div>
  );
}
