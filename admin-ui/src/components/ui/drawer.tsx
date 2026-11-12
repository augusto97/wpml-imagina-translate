import * as React from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Drawer (sheet lateral) implementado sin Radix para mantener el bundle
 * pequeño. Maneja:
 *  - Backdrop con click-to-close.
 *  - Cierre con tecla Escape.
 *  - Bloqueo del scroll del body mientras está abierto.
 *  - Animación slide-in/out con tailwindcss-animate.
 *
 * Uso:
 *   const [open, setOpen] = useState(false);
 *   <Drawer open={open} onOpenChange={setOpen} title="Crear API key">
 *     ...contenido...
 *   </Drawer>
 *
 * Si el día de mañana se necesitan focus traps, multi-instancia o
 * animaciones más finas, sustituir por @radix-ui/react-dialog (es
 * drop-in para esta API mediante el patrón Sheet de shadcn).
 */

interface DrawerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title?: string;
  description?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  /** Anchura del panel; default 'wit-w-full sm:wit-max-w-md'. */
  className?: string;
}

export function Drawer({
  open,
  onOpenChange,
  title,
  description,
  children,
  footer,
  className,
}: DrawerProps) {
  React.useEffect(() => {
    if (!open) return;

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onOpenChange(false);
    };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, [open, onOpenChange]);

  if (!open) return null;

  return (
    <div
      className="wit-fixed wit-inset-0 wit-z-[100000] wit-flex"
      role="dialog"
      aria-modal="true"
      aria-label={title}
    >
      <button
        type="button"
        aria-label="Cerrar"
        onClick={() => onOpenChange(false)}
        className="wit-absolute wit-inset-0 wit-bg-black/40 wit-transition-opacity wit-animate-in wit-fade-in-0"
      />
      <aside
        className={cn(
          'wit-relative wit-ml-auto wit-flex wit-h-full wit-w-full sm:wit-max-w-md wit-flex-col wit-bg-background wit-shadow-lg wit-animate-in wit-slide-in-from-right',
          className,
        )}
      >
        <header className="wit-flex wit-items-start wit-justify-between wit-gap-4 wit-border-b wit-border-border wit-px-6 wit-py-4">
          <div className="wit-min-w-0">
            {title && (
              <h2 className="wit-text-base wit-font-semibold wit-text-foreground">
                {title}
              </h2>
            )}
            {description && (
              <p className="wit-mt-0.5 wit-text-sm wit-text-muted-foreground">
                {description}
              </p>
            )}
          </div>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="wit-rounded-md wit-p-1 wit-text-muted-foreground hover:wit-bg-muted hover:wit-text-foreground"
            aria-label="Cerrar drawer"
          >
            <X className="wit-h-4 wit-w-4" />
          </button>
        </header>

        <div className="wit-flex-1 wit-overflow-y-auto wit-px-6 wit-py-4">
          {children}
        </div>

        {footer && (
          <footer className="wit-flex wit-items-center wit-justify-end wit-gap-2 wit-border-t wit-border-border wit-px-6 wit-py-3">
            {footer}
          </footer>
        )}
      </aside>
    </div>
  );
}
