import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from './ui/button';
import { formatNumber } from '@/lib/format';

export function Pagination({ page, pages, total, onChange, noun = 'elementos' }: {
  page: number;
  pages: number;
  total: number;
  onChange: (page: number) => void;
  noun?: string;
}) {
  if (total === 0) return null;
  return (
    <div className="wit-flex wit-items-center wit-justify-between wit-gap-3 wit-border-t wit-border-border wit-px-3 wit-py-2 wit-text-sm wit-text-muted-foreground">
      <span>{formatNumber(total)} {noun}</span>
      {pages > 1 && (
        <div className="wit-flex wit-items-center wit-gap-2">
          <Button variant="outline" size="icon" className="wit-h-8 wit-w-8" disabled={page <= 1} onClick={() => onChange(page - 1)} aria-label="Página anterior">
            <ChevronLeft className="wit-h-4 wit-w-4" />
          </Button>
          <span className="wit-tabular-nums">{page} / {pages}</span>
          <Button variant="outline" size="icon" className="wit-h-8 wit-w-8" disabled={page >= pages} onClick={() => onChange(page + 1)} aria-label="Página siguiente">
            <ChevronRight className="wit-h-4 wit-w-4" />
          </Button>
        </div>
      )}
    </div>
  );
}
