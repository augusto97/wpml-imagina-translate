import { cn } from '@/lib/utils';

export interface TabItem<T extends string> {
  value: T;
  label: string;
  count?: number;
  icon?: React.ReactNode;
}

/** Pestañas subrayadas; opcionalmente con recuento (filtros de estado). */
export function Tabs<T extends string>({ items, value, onChange, className }: {
  items: TabItem<T>[];
  value: T;
  onChange: (value: T) => void;
  className?: string;
}) {
  return (
    <div role="tablist" className={cn('wit-flex wit-gap-1 wit-overflow-x-auto wit-border-b wit-border-border', className)}>
      {items.map((item) => {
        const active = item.value === value;
        return (
          <button
            key={item.value}
            role="tab"
            type="button"
            aria-selected={active}
            onClick={() => onChange(item.value)}
            className={cn(
              'wit--mb-px wit-flex wit-items-center wit-gap-1.5 wit-whitespace-nowrap wit-border-b-2 wit-px-3 wit-py-2 wit-text-sm wit-font-medium wit-transition-colors',
              active ? 'wit-border-primary wit-text-foreground' : 'wit-border-transparent wit-text-muted-foreground hover:wit-text-foreground',
            )}
          >
            {item.icon}
            {item.label}
            {item.count !== undefined && (
              <span className={cn('wit-rounded-full wit-px-1.5 wit-text-xs wit-tabular-nums', active ? 'wit-bg-primary/10 wit-text-primary' : 'wit-bg-slate-100 wit-text-muted-foreground')}>
                {item.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
