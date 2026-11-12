import * as React from 'react';
import { cn } from '@/lib/utils';

export type InputProps = React.InputHTMLAttributes<HTMLInputElement>;

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, type = 'text', ...props }, ref) => (
    <input
      ref={ref}
      type={type}
      className={cn(
        'wit-flex wit-h-9 wit-w-full wit-rounded-md wit-border wit-border-input wit-bg-background wit-px-3 wit-py-1 wit-text-sm wit-shadow-sm wit-transition-colors',
        'placeholder:wit-text-muted-foreground',
        'focus-visible:wit-outline-none focus-visible:wit-ring-2 focus-visible:wit-ring-ring focus-visible:wit-ring-offset-2',
        'disabled:wit-cursor-not-allowed disabled:wit-opacity-50',
        className,
      )}
      {...props}
    />
  ),
);
Input.displayName = 'Input';
