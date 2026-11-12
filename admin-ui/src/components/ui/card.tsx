import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * Card primitives — port directo de shadcn/ui adaptado al prefijo
 * `wit-`. Densidad compacta por defecto (padding `p-3`/`p-4`,
 * CLAUDE.md §5).
 */

export const Card = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn(
      'wit-rounded-lg wit-border wit-border-border wit-bg-card wit-text-card-foreground wit-shadow-sm',
      className,
    )}
    {...props}
  />
));
Card.displayName = 'Card';

export const CardHeader = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn('wit-flex wit-flex-col wit-space-y-1 wit-p-4 wit-pb-2', className)}
    {...props}
  />
));
CardHeader.displayName = 'CardHeader';

export const CardTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, ...props }, ref) => (
  <h3
    ref={ref}
    className={cn(
      'wit-text-sm wit-font-semibold wit-tracking-tight wit-text-foreground',
      className,
    )}
    {...props}
  />
));
CardTitle.displayName = 'CardTitle';

export const CardDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn('wit-text-sm wit-text-muted-foreground', className)}
    {...props}
  />
));
CardDescription.displayName = 'CardDescription';

export const CardContent = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div ref={ref} className={cn('wit-p-4 wit-pt-2', className)} {...props} />
));
CardContent.displayName = 'CardContent';

export const CardFooter = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn('wit-flex wit-items-center wit-p-4 wit-pt-0', className)}
    {...props}
  />
));
CardFooter.displayName = 'CardFooter';
