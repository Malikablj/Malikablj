import { ChartColumn, Download } from 'lucide-react';
import { CustomerSelect, OwnerFilter } from '../components/domain/pickers.jsx';
import { Card, StatStrip } from '../components/ui/Card.jsx';
import { DataTable } from '../components/ui/DataTable.jsx';
import { Select } from '../components/ui/Field.jsx';
import { DateRange, PageHeader, SearchBar } from '../components/ui/Misc.jsx';
import { Pagination } from '../components/ui/Pagination.jsx';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States.jsx';
import { FilterChips } from '../components/ui/Tabs.jsx';
import { useAuth } from '../context/contexts.js';
import { useApi } from '../hooks/useApi.js';
import { useListParams } from '../hooks/useListParams.js';
import { apiUrl } from '../services/api.js';
import { formatCurrency, formatDate, formatDateTime, formatNumber, formatTime } from '../utils/format.js';

/** Formats a report cell by the column type the API declares. */
function formatValue(column, value) {
  if (value === null || value === undefined || value === '') return <span className="muted">–</span>;
  if (column.labels) return column.labels[value] ?? value;
  switch (column.type) {
    case 'money':
      return formatCurrency(value);
    case 'number':
      return formatNumber(value);
    case 'date':
      return formatDate(value);
    case 'time':
      return formatTime(value);
    case 'timestamp':
      return formatDateTime(value);
    default:
      return String(value);
  }
}

const isNumeric = (column) => column.type === 'money' || column.type === 'number';

function ReportView({ report, params, setParams }) {
  const { user } = useAuth();
  const owner = params.owner ?? '';
  const filters = {
    q: params.q,
    from: report.filters.date ? params.from : undefined,
    to: report.filters.date ? params.to : undefined,
    customer_id: report.filters.customer ? params.customer_id : undefined,
    owner_user_id: report.filters.owner ? (owner === 'me' ? user.id : owner || undefined) : undefined,
    status: params.status,
  };
  const { data, meta, error, loading, reload } = useApi(`/reports/${report.type}`, { ...filters, page: params.page, page_size: 50 });
  const csvUrl = apiUrl(`/reports/${report.type}`, { ...filters, format: 'csv' });

  return (
    <>
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari…" />
        {report.filters.date && <DateRange from={params.from} to={params.to} onChange={setParams} label={report.filters.date} />}
        {report.filters.owner && <OwnerFilter value={owner} onChange={(next) => setParams({ owner: next })} />}
        {report.filters.customer && (
          <div style={{ minWidth: 240, flex: '0 1 300px' }}>
            <CustomerSelect
              aria-label="Filter customer"
              placeholder="Semua customer"
              value={params.customer_id ?? ''}
              selectedLabel={params.customer_name}
              onChange={(id, row) => setParams({ customer_id: id, customer_name: row?.name })}
            />
          </div>
        )}
      </div>
      <div className="filter-bar">
        <FilterChips label={report.filters.status.label} options={report.filters.status.options} value={params.status} onChange={(status) => setParams({ status })} />
      </div>
      {meta?.totals && (
        <StatStrip
          items={report.total_columns.map((column) => ({
            label: column.header,
            value: column.type === 'money' ? formatCurrency(meta.totals[column.key]) : formatNumber(meta.totals[column.key]),
          }))}
        />
      )}
      <Card>
        <div className="card-header">
          <div className="grow">
            <h2>{report.title}</h2>
            <div className="card-subtitle">Data langsung dari database; sama dengan yang tampil di layar modul.</div>
          </div>
          <a className="btn btn-secondary btn-sm" href={csvUrl} download>
            <Download size={15} aria-hidden="true" /> Export CSV
          </a>
        </div>
        <DataTable
          caption={report.title}
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          empty={{ icon: ChartColumn, title: 'Tidak ada data untuk filter ini' }}
          columns={report.columns.map((column) => ({
            key: column.key,
            header: column.header,
            align: isNumeric(column) ? 'right' : undefined,
            render: (row) => formatValue(column, row[column.key]),
          }))}
          mobileCard={(row) => (
            <dl className="dl" style={{ margin: 0 }}>
              {report.columns.slice(0, 6).map((column) => (
                <div key={column.key} style={{ display: 'contents' }}>
                  <dt>{column.header}</dt>
                  <dd>{formatValue(column, row[column.key])}</dd>
                </div>
              ))}
            </dl>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <p className="cell-sub" style={{ marginTop: 8 }}>
        Export CSV berisi semua baris sesuai filter (maksimal 20.000 baris), dengan pemisah koma dan UTF-8 agar dapat dibuka di Excel.
      </p>
    </>
  );
}

export function ReportsPage() {
  const { data: catalog, error, reload } = useApi('/reports');
  const [params, setParams] = useListParams({}, ['status']);

  const header = <PageHeader title="Laporan" eyebrow="Analisis dan export" />;
  if (error && !catalog) {
    return (
      <>
        {header}
        <Card>
          <ErrorState error={error} onRetry={reload} />
        </Card>
      </>
    );
  }
  if (!catalog) {
    return (
      <>
        {header}
        <LoadingState rows={6} />
      </>
    );
  }
  if (!catalog.length) {
    return (
      <>
        {header}
        <Card>
          <EmptyState icon={ChartColumn} title="Tidak ada laporan yang dapat Anda akses" />
        </Card>
      </>
    );
  }

  const report = catalog.find((item) => item.type === params.report) ?? catalog[0];
  return (
    <>
      <PageHeader
        title="Laporan"
        eyebrow="Analisis dan export"
        actions={
          <Select
            aria-label="Pilih laporan"
            options={catalog.map((item) => ({ value: item.type, label: item.title }))}
            value={report.type}
            onChange={(event) =>
              // Filters differ per report, so switching starts from a clean filter set.
              setParams({ report: event.target.value, q: '', from: '', to: '', owner: '', customer_id: '', customer_name: '', status: [] })
            }
            style={{ width: 'auto' }}
          />
        }
      />
      <ReportView key={report.type} report={report} params={params} setParams={setParams} />
    </>
  );
}
