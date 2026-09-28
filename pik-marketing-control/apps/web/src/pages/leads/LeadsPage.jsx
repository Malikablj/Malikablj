import { LEAD_STATUS, MODULE, PRIORITY } from '@pik/shared';
import { Kanban, List, Plus, Target } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { LeadForm } from '../../components/domain/CrmForms.jsx';
import { LostReasonForm } from '../../components/domain/LeadStatusDialog.jsx';
import { OwnerFilter } from '../../components/domain/pickers.jsx';
import { PriorityDot, StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Avatar, PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { ErrorState, LoadingState } from '../../components/ui/States.jsx';
import { FilterChips, Segmented } from '../../components/ui/Tabs.jsx';
import { useAuth, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useListParams } from '../../hooks/useListParams.js';
import { useMediaQuery } from '../../hooks/useMediaQuery.js';
import { api } from '../../services/api.js';
import { formatCurrency, formatCurrencyCompact, formatDate, formatNumber } from '../../utils/format.js';

function LeadCard({ lead, draggable, onDragStart, onDragEnd, dragging }) {
  return (
    <article
      className={`kanban-card ${dragging ? 'dragging' : ''}`}
      draggable={draggable}
      onDragStart={(event) => {
        event.dataTransfer.setData('text/plain', lead.id);
        event.dataTransfer.effectAllowed = 'move';
        onDragStart(lead);
      }}
      onDragEnd={onDragEnd}
    >
      <Link to={`/leads/${lead.id}`}>{lead.name}</Link>
      <span className="muted truncate">{lead.customer_name}</span>
      <div className="row-between">
        <span className="num" style={{ fontWeight: 600 }}>
          {lead.estimated_value ? formatCurrencyCompact(lead.estimated_value) : <span className="muted">–</span>}
        </span>
        <span className="row" style={{ gap: 6 }}>
          <PriorityDot priority={lead.priority} />
          {lead.expected_closing_date && <span className="cell-sub">{formatDate(lead.expected_closing_date)}</span>}
          {lead.owner_name && <Avatar name={lead.owner_name} size="sm" />}
        </span>
      </div>
    </article>
  );
}

function Board({ filters, canWrite }) {
  const toast = useToast();
  const { data, error, reload, setData } = useApi('/leads/board', filters);
  const [dragged, setDragged] = useState(null);
  const [dropTarget, setDropTarget] = useState(null);
  const [lostLead, setLostLead] = useState(null);

  const moveLocally = (lead, status) =>
    setData((columns) =>
      columns.map((column) => {
        if (column.status === lead.status) {
          return { ...column, count: column.count - 1, total_value: column.total_value - (lead.estimated_value ?? 0), leads: column.leads.filter((l) => l.id !== lead.id) };
        }
        if (column.status === status) {
          return { ...column, count: column.count + 1, total_value: column.total_value + (lead.estimated_value ?? 0), leads: [{ ...lead, status }, ...column.leads] };
        }
        return column;
      }),
    );

  const drop = async (status) => {
    setDropTarget(null);
    const lead = dragged;
    setDragged(null);
    if (!lead || lead.status === status) return;
    if (status === 'LOST') {
      setLostLead(lead);
      return;
    }
    moveLocally(lead, status);
    try {
      await api.patch(`/leads/${lead.id}/status`, { status });
      toast.success(`${lead.name} → ${LEAD_STATUS.labels[status]}`);
    } catch (err) {
      toast.error(err.message);
      reload();
    }
  };

  if (error && !data) return <ErrorState error={error} onRetry={reload} />;
  if (!data) return <LoadingState rows={6} />;

  return (
    <>
      <div className="kanban" aria-label="Pipeline lead">
        {data.map((column) => (
          <section
            key={column.status}
            className={`kanban-column ${dropTarget === column.status ? 'drop-target' : ''}`}
            aria-label={`${LEAD_STATUS.labels[column.status]}: ${column.count} lead`}
            onDragOver={(event) => {
              if (!dragged) return;
              event.preventDefault();
              setDropTarget(column.status);
            }}
            onDragLeave={() => setDropTarget((current) => (current === column.status ? null : current))}
            onDrop={(event) => {
              event.preventDefault();
              drop(column.status);
            }}
          >
            <header className="kanban-column-header">
              <h3>
                <StatusBadge enumDef={LEAD_STATUS} value={column.status} />
                <span className="muted num" style={{ fontWeight: 500 }}>
                  {column.count}
                </span>
              </h3>
              <span className="cell-sub num">{formatCurrencyCompact(column.total_value)}</span>
            </header>
            {column.leads.map((lead) => (
              <LeadCard
                key={lead.id}
                lead={lead}
                draggable={canWrite}
                dragging={dragged?.id === lead.id}
                onDragStart={setDragged}
                onDragEnd={() => {
                  setDragged(null);
                  setDropTarget(null);
                }}
              />
            ))}
            {column.count > column.leads.length && (
              <Link to={`/leads?view=list&status=${column.status}`} className="text-sm" style={{ padding: '4px 8px' }}>
                +{formatNumber(column.count - column.leads.length)} lainnya
              </Link>
            )}
            {!column.leads.length && <p className="cell-sub" style={{ padding: '8px 4px' }}>Kosong</p>}
          </section>
        ))}
      </div>
      <Modal open={Boolean(lostLead)} onClose={() => setLostLead(null)} title={`Tandai Lost: ${lostLead?.name ?? ''}`} size="narrow">
        {lostLead && (
          <LostReasonForm
            lead={lostLead}
            onCancel={() => setLostLead(null)}
            onDone={() => {
              setLostLead(null);
              reload();
            }}
          />
        )}
      </Modal>
    </>
  );
}

function LeadList({ filters, params, setParams }) {
  const { data, meta, error, loading, reload } = useApi('/leads', { ...filters, sort: params.sort ?? '-updated_at', page: params.page, page_size: 25 });
  return (
    <Card>
      <DataTable
        caption="Daftar lead"
        rows={data}
        loading={loading}
        error={error}
        onRetry={reload}
        sort={params.sort ?? '-updated_at'}
        onSort={(sort) => setParams({ sort })}
        rowHref={(row) => `/leads/${row.id}`}
        empty={{ icon: Target, title: 'Tidak ada lead', text: 'Ubah filter atau tambahkan lead baru.' }}
        columns={[
          {
            key: 'name',
            header: 'Lead',
            sortKey: 'name',
            render: (row) => (
              <>
                {row.name}
                <div className="cell-sub">{row.customer_name}</div>
              </>
            ),
          },
          { key: 'status', header: 'Status', sortKey: 'status', render: (row) => <StatusBadge enumDef={LEAD_STATUS} value={row.status} /> },
          {
            key: 'priority',
            header: 'Prioritas',
            render: (row) =>
              row.priority ? (
                <span className="row" style={{ gap: 6 }}>
                  <PriorityDot priority={row.priority} />
                  {PRIORITY.labels[row.priority]}
                </span>
              ) : (
                '–'
              ),
          },
          { key: 'estimated_value', header: 'Estimasi', sortKey: 'estimated_value', align: 'right', render: (row) => formatCurrency(row.estimated_value) },
          { key: 'expected_closing_date', header: 'Closing', sortKey: 'expected_closing_date', render: (row) => formatDate(row.expected_closing_date) },
          { key: 'owner_name', header: 'PIC' },
          { key: 'next_follow_up_date', header: 'Follow up', render: (row) => formatDate(row.next_follow_up_date) },
        ]}
        mobileCard={(row) => (
          <div className="stack-sm">
            <div className="row-between">
              <span className="cell-title">{row.name}</span>
              <StatusBadge enumDef={LEAD_STATUS} value={row.status} />
            </div>
            <div className="cell-sub">
              {[row.customer_name, row.estimated_value ? formatCurrencyCompact(row.estimated_value) : null, row.owner_name].filter(Boolean).join(' · ')}
            </div>
          </div>
        )}
      />
      <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
    </Card>
  );
}

export function LeadsPage() {
  const { can, user } = useAuth();
  const navigate = useNavigate();
  const isPhone = useMediaQuery('(max-width: 760px)');
  const [creating, setCreating] = useState(false);
  const [params, setParams] = useListParams({}, ['status', 'priority']);
  const view = params.view ?? (isPhone ? 'list' : 'board');
  const owner = params.owner ?? '';
  const filters = {
    q: params.q,
    priority: params.priority,
    owner_user_id: owner === 'me' ? user.id : owner || undefined,
    ...(view === 'list' ? { status: params.status } : {}),
  };
  const canWrite = can(MODULE.LEADS, 'write');

  return (
    <>
      <PageHeader
        title="Lead"
        eyebrow="Pipeline penjualan"
        actions={
          <>
            <Segmented
              label="Tampilan"
              value={view}
              onChange={(next) => setParams({ view: next })}
              options={[
                { value: 'board', label: 'Kanban', icon: Kanban },
                { value: 'list', label: 'Daftar', icon: List },
              ]}
            />
            {canWrite && (
              <Button variant="primary" onClick={() => setCreating(true)}>
                <Plus size={16} aria-hidden="true" /> Tambah Lead
              </Button>
            )}
          </>
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari lead atau customer…" />
        <OwnerFilter value={owner} onChange={(next) => setParams({ owner: next })} />
        <FilterChips label="Prioritas" options={PRIORITY.options} value={params.priority} onChange={(priority) => setParams({ priority })} />
      </div>
      {view === 'list' && (
        <div className="filter-bar">
          <FilterChips label="Status" options={LEAD_STATUS.options} value={params.status} onChange={(status) => setParams({ status })} />
        </div>
      )}
      {view === 'board' ? <Board filters={filters} canWrite={canWrite} /> : <LeadList filters={filters} params={params} setParams={setParams} />}
      {view === 'board' && canWrite && <p className="cell-sub" style={{ marginTop: 8 }}>Seret kartu ke kolom lain untuk memindahkan tahap lead.</p>}
      <Modal open={creating} onClose={() => setCreating(false)} title="Tambah lead" size="wide">
        {creating && <LeadForm onCancel={() => setCreating(false)} onSaved={(lead) => navigate(`/leads/${lead.id}`)} />}
      </Modal>
    </>
  );
}
