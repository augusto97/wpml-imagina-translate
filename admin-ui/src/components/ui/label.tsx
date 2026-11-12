import * as React from 'react';
import { cn } from '@/lib/utils';

export const Label = React.forwardRef<
  HTMLLabelElement,
  React.LabelHTMLAttributes<HTMLLabelElement>
>(({ className, ...props }, ref) => (
  <label
    ref={ref}
    className={cn(
      'wit-text-sm wit-font-medium wit-leading-none wit-text-foreground',
      'peer-disabled:wit-cursor-not-allowed peer-disabled:wit-opacity-70',
      className,
    )}
    {...props}
  />
));
Label.displayName = 'Label';
