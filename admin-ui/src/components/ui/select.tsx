import * as React from 'react';
import { cn } from '@/lib/utils';

/** Select nativo con el estilo de los inputs: accesible y fiable en móvil. */
export const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(
  ({ className, children, ...props }, ref) => (
    <select
      ref={ref}
      className={cn(
        'wit-h-9 wit-rounded-md wit-border wit-border-input wit-bg-background wit-px-3 wit-text-sm focus-visible:wit-outline-none focus-visible:wit-ring-2 focus-visible:wit-ring-ring disabled:wit-opacity-50',
        className,
      )}
      {...props}
    >
      {children}
    </select>
  ),
);
Select.displayName = 'Select';
