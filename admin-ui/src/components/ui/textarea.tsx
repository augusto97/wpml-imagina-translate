import * as React from 'react';
import { cn } from '@/lib/utils';

export const Textarea = React.forwardRef<
  HTMLTextAreaElement,
  React.TextareaHTMLAttributes<HTMLTextAreaElement>
>(({ className, ...props }, ref) => (
  <textarea
    ref={ref}
    className={cn(
      'wit-flex wit-min-h-[60px] wit-w-full wit-rounded-md wit-border wit-border-input wit-bg-background wit-px-3 wit-py-2 wit-text-sm wit-shadow-sm',
      'placeholder:wit-text-muted-foreground',
      'focus-visible:wit-outline-none focus-visible:wit-ring-2 focus-visible:wit-ring-ring focus-visible:wit-ring-offset-2',
      'disabled:wit-cursor-not-allowed disabled:wit-opacity-50',
      className,
    )}
    {...props}
  />
));
Textarea.displayName = 'Textarea';
