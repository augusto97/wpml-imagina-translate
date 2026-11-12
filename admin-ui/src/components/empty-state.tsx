import type { LucideIcon } from 'lucide-react';

export function EmptyState({ icon: Icon, title, description, action }: {
  icon: LucideIcon;
  title: string;
  description?: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="wit-flex wit-flex-col wit-items-center wit-justify-center wit-gap-2 wit-px-6 wit-py-12 wit-text-center">
      <div className="wit-flex wit-h-10 wit-w-10 wit-items-center wit-justify-center wit-rounded-full wit-bg-slate-100 wit-text-muted-foreground">
        <Icon className="wit-h-5 wit-w-5" />
      </div>
      <p className="wit-text-sm wit-font-medium wit-text-foreground">{title}</p>
      {description && <p className="wit-max-w-sm wit-text-sm wit-text-muted-foreground">{description}</p>}
      {action && <div className="wit-mt-2">{action}</div>}
    </div>
  );
}
