import { ChevronLeft, ChevronRight } from 'lucide-react';
import { formatNumber } from '../../utils/format.js';
import { Button } from './Button.jsx';

/** meta: { page, page_size, total, total_pages } from the API. */
export function Pagination({ meta, onPageChange }) {
  if (!meta || meta.total === 0) return null;
  const from = (meta.page - 1) * meta.page_size + 1;
  const to = Math.min(meta.total, meta.page * meta.page_size);
  return (
    <nav className="pagination" aria-label="Halaman">
      <span>
        {formatNumber(from)}–{formatNumber(to)} dari {formatNumber(meta.total)}
      </span>
      <div className="row">
        <Button size="sm" icon variant="secondary" onClick={() => onPageChange(meta.page - 1)} disabled={meta.page <= 1} aria-label="Halaman sebelumnya">
          <ChevronLeft size={16} />
        </Button>
        <span className="num">
          {meta.page} / {meta.total_pages}
        </span>
        <Button
          size="sm"
          icon
          variant="secondary"
          onClick={() => onPageChange(meta.page + 1)}
          disabled={meta.page >= meta.total_pages}
          aria-label="Halaman berikutnya"
        >
          <ChevronRight size={16} />
        </Button>
      </div>
    </nav>
  );
}
