import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

/**
 * Button — port de shadcn/ui con prefijo wit-.
 *
 * Variants: default (primario), secondary, outline, ghost, destructive.
 * Sizes: sm, default, lg, icon. Densidad compacta = `default` apunta a
 * `h-9` en lugar de `h-10` del shadcn upstream (CLAUDE.md §5).
 */

const buttonVariants = cva(
  'wit-inline-flex wit-items-center wit-justify-center wit-gap-2 wit-whitespace-nowrap [&_svg]:wit-shrink-0 wit-rounded-md wit-text-sm wit-font-medium wit-transition-colors focus-visible:wit-outline-none focus-visible:wit-ring-2 focus-visible:wit-ring-ring focus-visible:wit-ring-offset-2 disabled:wit-pointer-events-none disabled:wit-opacity-50',
  {
    variants: {
      variant: {
        default:
          'wit-bg-primary wit-text-primary-foreground hover:wit-bg-primary/90',
        secondary:
          'wit-bg-secondary wit-text-secondary-foreground hover:wit-bg-secondary/80',
        outline:
          'wit-border wit-border-border wit-bg-background hover:wit-bg-accent hover:wit-text-accent-foreground',
        ghost:
          'hover:wit-bg-accent hover:wit-text-accent-foreground',
        destructive:
          'wit-bg-destructive wit-text-destructive-foreground hover:wit-bg-destructive/90',
      },
      size: {
        sm: 'wit-h-8 wit-px-3 wit-text-xs',
        default: 'wit-h-9 wit-px-4',
        lg: 'wit-h-10 wit-px-6',
        icon: 'wit-h-9 wit-w-9',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, type = 'button', ...props }, ref) => (
    <button
      ref={ref}
      type={type}
      className={cn(buttonVariants({ variant, size }), className)}
      {...props}
    />
  ),
);
Button.displayName = 'Button';

export { buttonVariants };
