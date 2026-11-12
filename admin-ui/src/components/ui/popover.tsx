import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * Popover minimalista (sin Radix). Maneja:
 *   - click en el trigger → toggle abierto/cerrado.
 *   - click fuera del panel → cerrar.
 *   - tecla Escape → cerrar.
 *
 * Posicionamiento: absolute justo debajo del trigger, alineado a la
 * derecha por defecto. Para casos con poco espacio se puede pasar
 * `align="start"`.
 *
 * Uso:
 *   const [open, setOpen] = useState(false);
 *   <Popover open={open} onOpenChange={setOpen}
 *     trigger={<Button>Columnas</Button>}>
 *     <PopoverContent>…items…</PopoverContent>
 *   </Popover>
 */

interface PopoverProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  trigger: React.ReactNode;
  children: React.ReactNode;
  align?: 'start' | 'end';
  className?: string;
}

export function Popover({
  open,
  onOpenChange,
  trigger,
  children,
  align = 'end',
  className,
}: PopoverProps) {
  const rootRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    if (!open) return;
    const onClickOutside = (e: MouseEvent) => {
      const target = e.target as Node | null;
      if (target && rootRef.current && !rootRef.current.contains(target)) {
        onOpenChange(false);
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onOpenChange(false);
    };
    document.addEventListener('mousedown', onClickOutside);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClickOutside);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, onOpenChange]);

  return (
    <div ref={rootRef} className="wit-relative wit-inline-block">
      <span onClick={() => onOpenChange(!open)}>{trigger}</span>
      {open && (
        <div
          role="dialog"
          className={cn(
            'wit-absolute wit-z-[100000] wit-mt-1 wit-min-w-[12rem] wit-rounded-md wit-border wit-border-border wit-bg-popover wit-text-popover-foreground wit-shadow-lg wit-animate-in wit-fade-in-0',
            align === 'end' ? 'wit-right-0' : 'wit-left-0',
            className,
          )}
        >
          {children}
        </div>
      )}
    </div>
  );
}

export function PopoverContent({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn('wit-p-1', className)} {...props} />
  );
}
