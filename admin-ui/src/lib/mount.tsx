import { StrictMode, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { createQueryClient } from '@/lib/query-client';
import '@/styles/globals.css';

/** Mount a screen on the `#wit-<slug>` element the PHP printed. */
export function mount(slug: string, node: ReactNode): void {
  const container = document.getElementById(`wit-${slug}`);
  if (!container) return;
  const client = createQueryClient();
  createRoot(container).render(
    <StrictMode>
      <QueryClientProvider client={client}>{node}</QueryClientProvider>
    </StrictMode>,
  );
}
