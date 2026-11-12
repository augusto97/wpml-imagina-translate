import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
  'wit-inline-flex wit-items-center wit-rounded-md wit-border wit-px-2 wit-py-0.5 wit-text-xs wit-font-medium wit-transition-colors',
  {
    variants: {
      variant: {
        default:
          'wit-border-transparent wit-bg-primary wit-text-primary-foreground',
        secondary:
          'wit-border-transparent wit-bg-secondary wit-text-secondary-foreground',
        success:
          'wit-border-transparent wit-bg-emerald-500/15 wit-text-emerald-700',
        warning:
          'wit-border-transparent wit-bg-amber-500/15 wit-text-amber-700',
        destructive:
          'wit-border-transparent wit-bg-destructive/15 wit-text-destructive',
        outline:
          'wit-border-border wit-text-foreground',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  },
);

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>,
    VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <span className={cn(badgeVariants({ variant }), className)} {...props} />
  );
}
