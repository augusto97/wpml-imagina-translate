import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * Skeleton — placeholder animado para estados de carga.
 *
 * Uso: `<Skeleton className="wit-h-4 wit-w-24" />`.
 */
export function Skeleton({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('wit-animate-pulse wit-rounded-md wit-bg-muted', className)}
      {...props}
    />
  );
}
