import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import { EmptyState, ErrorState, LoadingState } from './States.jsx';

/**
 * Data table with loading, empty and error states, sortable headers and a card list on phones.
 *
 * columns: [{ key, header, render?(row), align?: 'right', sortKey?, className? }]
 * sort: current "field" / "-field"; onSort(nextSort)
 * rowHref(row): makes rows navigable (click anywhere, keyboard via the first cell link)
 * mobileCard(row): content of the phone card (defaults to the first two columns)
 */
export function DataTable({
  columns,
  rows,
  rowKey = 'id',
  loading = false,
  error = null,
  onRetry,
  empty,
  rowHref,
  mobileCard,
  sort,
  onSort,
  footer,
  caption,
}) {
  const navigate = useNavigate();

  if (error && !rows?.length) return <ErrorState error={error} onRetry={onRetry} />;
  if (!rows) return <LoadingState />;
  if (!rows.length && !loading) return <EmptyState {...(empty ?? {})} />;

  const renderCell = (column, row) => (column.render ? column.render(row) : (row[column.key] ?? <span className="muted">–</span>));

  const sortIcon = (column) => {
    if (!column.sortKey) return null;
    if (sort === column.sortKey) return <ArrowUp size={12} aria-hidden="true" />;
    if (sort === `-${column.sortKey}`) return <ArrowDown size={12} aria-hidden="true" />;
    return <ArrowUpDown size={12} aria-hidden="true" style={{ opacity: 0.4 }} />;
  };
  const ariaSort = (column) =>
    sort === column.sortKey ? 'ascending' : sort === `-${column.sortKey}` ? 'descending' : column.sortKey ? 'none' : undefined;
  const nextSort = (column) => (sort === column.sortKey ? `-${column.sortKey}` : column.sortKey);

  return (
    <div className="data-table responsive" style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 160ms' }} aria-busy={loading}>
      <div className="table-wrap">
        <table className="table">
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.key} scope="col" className={column.align === 'right' ? 'num' : ''} aria-sort={ariaSort(column)}>
                  {column.sortKey && onSort ? (
                    <button type="button" onClick={() => onSort(nextSort(column))}>
                      {column.header}
                      {sortIcon(column)}
                    </button>
                  ) : (
                    column.header
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const href = rowHref?.(row);
              return (
                <tr
                  key={row[rowKey]}
                  className={href ? 'clickable' : ''}
                  onClick={
                    href
                      ? (event) => {
                          if (event.target.closest('a, button, input, select, textarea, label')) return;
                          navigate(href);
                        }
                      : undefined
                  }
                >
                  {columns.map((column, index) => (
                    <td key={column.key} className={[column.align === 'right' ? 'num' : '', column.className ?? ''].join(' ')}>
                      {index === 0 && href ? (
                        <Link to={href} className="cell-title">
                          {renderCell(column, row)}
                        </Link>
                      ) : (
                        renderCell(column, row)
                      )}
                    </td>
                  ))}
                </tr>
              );
            })}
          </tbody>
          {footer && <tfoot>{footer}</tfoot>}
        </table>
      </div>
      <div className="mobile-list">
        {rows.map((row) => {
          const href = rowHref?.(row);
          const content = mobileCard ? (
            mobileCard(row)
          ) : (
            <>
              <div className="cell-title">{renderCell(columns[0], row)}</div>
              {columns[1] && <div className="cell-sub">{renderCell(columns[1], row)}</div>}
            </>
          );
          return href ? (
            <Link key={row[rowKey]} to={href} className="mobile-card">
              {content}
            </Link>
          ) : (
            <div key={row[rowKey]} className="mobile-card">
              {content}
            </div>
          );
        })}
      </div>
    </div>
  );
}
