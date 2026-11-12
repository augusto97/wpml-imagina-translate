import * as React from 'react';
import { X } from 'lucide-react';
import { Button } from './button';

interface DialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  children?: React.ReactNode;
  footer?: React.ReactNode;
}

/** Diálogo modal centrado: Escape y clic fuera cierran. */
export function Dialog({ open, onOpenChange, title, children, footer }: DialogProps) {
  React.useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onOpenChange(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onOpenChange]);

  if (!open) return null;

  return (
    <div className="wit-fixed wit-inset-0 wit-z-[100001] wit-flex wit-items-center wit-justify-center wit-p-4" role="dialog" aria-modal="true" aria-label={title}>
      <div className="wit-absolute wit-inset-0 wit-bg-slate-900/40 wit-animate-in wit-fade-in" onClick={() => onOpenChange(false)} />
      <div className="wit-relative wit-w-full wit-max-w-md wit-rounded-lg wit-border wit-border-border wit-bg-background wit-shadow-xl wit-animate-in wit-fade-in wit-zoom-in-95">
        <div className="wit-flex wit-items-start wit-justify-between wit-gap-4 wit-p-5 wit-pb-2">
          <h2 className="wit-text-base wit-font-semibold wit-text-foreground">{title}</h2>
          <button type="button" aria-label="Cerrar" onClick={() => onOpenChange(false)} className="wit-text-muted-foreground hover:wit-text-foreground">
            <X className="wit-h-4 wit-w-4" />
          </button>
        </div>
        <div className="wit-px-5 wit-pb-5 wit-text-sm wit-text-muted-foreground">{children}</div>
        {footer && <div className="wit-flex wit-justify-end wit-gap-2 wit-border-t wit-border-border wit-bg-slate-50 wit-px-5 wit-py-3 wit-rounded-b-lg">{footer}</div>}
      </div>
    </div>
  );
}

/** Confirmación con dos botones: la acción que no se deshace pregunta antes. */
export function ConfirmDialog({ open, onOpenChange, title, children, confirmLabel, onConfirm, destructive, busy }: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  children?: React.ReactNode;
  confirmLabel: string;
  onConfirm: () => void;
  destructive?: boolean;
  busy?: boolean;
}) {
  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title={title}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>Cancelar</Button>
          <Button variant={destructive ? 'destructive' : 'default'} size="sm" disabled={busy} onClick={onConfirm}>{confirmLabel}</Button>
        </>
      }
    >
      {children}
    </Dialog>
  );
}
