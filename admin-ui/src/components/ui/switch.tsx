import { cn } from '@/lib/utils';

interface SwitchProps {
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
  disabled?: boolean;
  id?: string;
  'aria-label'?: string;
}

/** Interruptor accesible (role=switch), sin dependencias. */
export function Switch({ checked, onCheckedChange, disabled, id, ...rest }: SwitchProps) {
  return (
    <button
      type="button"
      role="switch"
      id={id}
      aria-checked={checked}
      aria-label={rest['aria-label']}
      disabled={disabled}
      onClick={() => onCheckedChange(!checked)}
      className={cn(
        'wit-relative wit-inline-flex wit-h-5 wit-w-9 wit-flex-shrink-0 wit-items-center wit-rounded-full wit-transition-colors focus-visible:wit-outline-none focus-visible:wit-ring-2 focus-visible:wit-ring-ring focus-visible:wit-ring-offset-2 disabled:wit-cursor-not-allowed disabled:wit-opacity-50',
        checked ? 'wit-bg-primary' : 'wit-bg-slate-300',
      )}
    >
      <span
        className={cn(
          'wit-inline-block wit-h-4 wit-w-4 wit-rounded-full wit-bg-white wit-shadow wit-transition-transform',
          checked ? 'wit-translate-x-[18px]' : 'wit-translate-x-0.5',
        )}
      />
    </button>
  );
}
