import { FileText, History, Home, Languages, Settings, Type } from 'lucide-react';
import { config, pageUrl, type PageSlug } from '@/lib/api';
import { cn } from '@/lib/utils';
import { Toaster } from './toaster';

const NAV: { slug: PageSlug; label: string; icon: typeof Home; manage?: boolean; strings?: boolean }[] = [
  { slug: 'dashboard', label: 'Inicio', icon: Home },
  { slug: 'content', label: 'Contenidos', icon: FileText },
  { slug: 'strings', label: 'Cadenas', icon: Type, manage: true, strings: true },
  { slug: 'history', label: 'Historial', icon: History },
  { slug: 'settings', label: 'Ajustes', icon: Settings, manage: true },
];

/**
 * Marco común: marca, navegación entre las secciones y cabecera de la
 * página. Las secciones son páginas de WordPress (como en Imagina
 * Updater); la barra las enlaza para que el plugin se sienta una sola app.
 */
export function AppShell({ page, title, description, actions, children }: {
  page: PageSlug;
  title: string;
  description?: string;
  actions?: React.ReactNode;
  children: React.ReactNode;
}) {
  const cfg = config();
  const items = NAV.filter((n) => (!n.manage || cfg.canManage) && (!n.strings || cfg.features.strings));

  return (
    <div className="wit-app">
      <div className="wit-mx-auto wit-max-w-[1200px]">
        <div className="wit-mb-5 wit-flex wit-flex-wrap wit-items-center wit-justify-between wit-gap-3">
          <div className="wit-flex wit-items-center wit-gap-2.5">
            <div className="wit-flex wit-h-8 wit-w-8 wit-items-center wit-justify-center wit-rounded-lg wit-bg-primary wit-text-primary-foreground">
              <Languages className="wit-h-4 wit-w-4" />
            </div>
            <div className="wit-leading-tight">
              <p className="wit-text-sm wit-font-semibold wit-text-foreground">Imagina Translate</p>
              <p className="wit-text-xs wit-text-muted-foreground">Traducción con IA para WPML · v{cfg.version}</p>
            </div>
          </div>
          <nav aria-label="Secciones" className="wit-flex wit-gap-1 wit-overflow-x-auto wit-rounded-lg wit-border wit-border-border wit-bg-white wit-p-1">
            {items.map((item) => {
              const active = item.slug === page;
              const Icon = item.icon;
              return (
                <a
                  key={item.slug}
                  href={pageUrl(item.slug)}
                  aria-current={active ? 'page' : undefined}
                  className={cn(
                    'wit-flex wit-flex-shrink-0 wit-items-center wit-gap-1.5 wit-whitespace-nowrap wit-rounded-md wit-px-3 wit-py-1.5 wit-text-sm wit-font-medium wit-transition-colors',
                    active ? 'wit-bg-slate-900 wit-text-white hover:wit-text-white' : 'wit-text-muted-foreground hover:wit-bg-slate-100 hover:wit-text-foreground',
                  )}
                >
                  <Icon className="wit-hidden wit-h-4 wit-w-4 wit-flex-shrink-0 sm:wit-block" />
                  {item.label}
                </a>
              );
            })}
          </nav>
        </div>

        <header className="wit-mb-5 wit-flex wit-flex-wrap wit-items-end wit-justify-between wit-gap-3">
          <div>
            <h1 className="wit-text-2xl wit-font-semibold wit-tracking-tight wit-text-foreground">{title}</h1>
            {description && <p className="wit-mt-1 wit-max-w-2xl wit-text-sm wit-text-muted-foreground">{description}</p>}
          </div>
          {actions && <div className="wit-flex wit-flex-wrap wit-items-center wit-gap-2">{actions}</div>}
        </header>

        {children}
      </div>
      <Toaster />
    </div>
  );
}
