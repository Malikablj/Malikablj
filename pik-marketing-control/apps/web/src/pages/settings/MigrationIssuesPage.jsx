import { MIGRATION_ISSUE_SEVERITY, MIGRATION_ISSUE_STATUS, MODULE, migrationIssueUpdateSchema } from '@pik/shared';
import { Database } from 'lucide-react';
import { useState } from 'react';
import { FormActions, FormError } from '../../components/domain/CrmForms.jsx';
import { Badge, StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card, CardHeader, KpiCard } from '../../components/ui/Card.jsx';
import { Field, Select, Textarea } from '../../components/ui/Field.jsx';
import { DetailList, PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { EmptyState, ErrorState, LoadingState } from '../../components/ui/States.jsx';
import { FilterChips } from '../../components/ui/Tabs.jsx';
import { useAuth, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useForm } from '../../hooks/useForm.js';
import { useListParams } from '../../hooks/useListParams.js';
import { api } from '../../services/api.js';
import { formatDateTime, formatNumber } from '../../utils/format.js';

const ACTION_LABELS = { RESOLVED: 'Tandai selesai', IGNORED: 'Abaikan', OPEN: 'Buka kembali' };

function ResolveForm({ issue, status, onDone, onCancel }) {
  const toast = useToast();
  const form = useForm({ resolution_status: status, resolution_notes: issue.resolution_notes ?? '' });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(migrationIssueUpdateSchema, async (data) => {
      await api.put(`/migration-issues/${issue.id}`, data);
      toast.success(`Issue: ${MIGRATION_ISSUE_STATUS.labels[status]}.`);
      onDone();
    });
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <p className="text-sm" style={{ margin: 0 }}>
        {issue.description}
      </p>
      <Field
        label="Catatan penyelesaian"
        error={form.errors.resolution_notes}
        hint={status === 'IGNORED' ? 'Jelaskan kenapa issue ini dapat diabaikan.' : 'mis. data diperbaiki di aplikasi / di workbook sumber.'}
      >
        <Textarea rows={3} autoFocus {...form.bind('resolution_notes')} />
      </Field>
      <FormActions onCancel={onCancel} submitting={form.submitting} submitLabel={ACTION_LABELS[status]} />
    </form>
  );
}

function IssueItem({ issue, canWrite, onAction }) {
  const location = [issue.entity_type, issue.source_sheet && `sheet ${issue.source_sheet}`, issue.legacy_row && `baris ${issue.legacy_row}`].filter(Boolean).join(' · ');
  return (
    <li className="list-item" style={{ display: 'block' }}>
      <div className="row-between" style={{ alignItems: 'flex-start' }}>
        <div className="stack-sm grow" style={{ minWidth: 0 }}>
          <div className="row" style={{ gap: 6 }}>
            <StatusBadge enumDef={MIGRATION_ISSUE_SEVERITY} value={issue.severity} />
            <Badge>{issue.issue_type}</Badge>
            <span className="cell-sub">{location}</span>
          </div>
          <div className="text-sm">{issue.description}</div>
          {issue.candidate_reference && <div className="cell-sub">Kandidat: {issue.candidate_reference}</div>}
          {issue.source_data && (
            <details>
              <summary className="text-sm muted" style={{ cursor: 'pointer' }}>
                Data sumber
              </summary>
              <pre className="text-xs" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', margin: '8px 0 0', padding: 12, background: 'var(--color-surface-sunken)', borderRadius: 8 }}>
                {JSON.stringify(issue.source_data, null, 2)}
              </pre>
            </details>
          )}
          {issue.resolution_status !== 'OPEN' && (
            <div className="cell-sub">
              {MIGRATION_ISSUE_STATUS.labels[issue.resolution_status]}
              {issue.resolved_by_name && ` oleh ${issue.resolved_by_name}`}
              {issue.resolved_at && ` · ${formatDateTime(issue.resolved_at)}`}
              {issue.resolution_notes && ` · ${issue.resolution_notes}`}
            </div>
          )}
        </div>
        <div className="row" style={{ gap: 4, flexShrink: 0 }}>
          <StatusBadge enumDef={MIGRATION_ISSUE_STATUS} value={issue.resolution_status} />
        </div>
      </div>
      {canWrite && (
        <div className="row" style={{ gap: 6, marginTop: 8 }}>
          {issue.resolution_status === 'OPEN' ? (
            <>
              <Button size="sm" variant="success" onClick={() => onAction(issue, 'RESOLVED')}>
                {ACTION_LABELS.RESOLVED}
              </Button>
              <Button size="sm" onClick={() => onAction(issue, 'IGNORED')}>
                {ACTION_LABELS.IGNORED}
              </Button>
            </>
          ) : (
            <Button size="sm" variant="ghost" onClick={() => onAction(issue, 'OPEN')}>
              {ACTION_LABELS.OPEN}
            </Button>
          )}
        </div>
      )}
    </li>
  );
}

function LastRun({ run }) {
  if (!run) {
    return (
      <Card>
        <CardHeader title="Migrasi terakhir" />
        <div className="card-body">
          <p className="text-sm" style={{ marginTop: 0 }}>
            Belum ada migrasi yang dijalankan. Workbook sumber diproses dari server dengan perintah:
          </p>
          <pre className="text-xs" style={{ margin: 0, padding: 12, background: 'var(--color-surface-sunken)', borderRadius: 8, whiteSpace: 'pre-wrap' }}>
            npm run migrate:profile{'\n'}npm run migrate:dry{'\n'}npm run migrate{'\n'}npm run migrate:verify
          </pre>
        </div>
      </Card>
    );
  }
  const entities = run.summary?.entities ?? [];
  return (
    <Card>
      <CardHeader title="Migrasi terakhir" subtitle={formatDateTime(run.finished_at)} />
      <div className="card-body">
        <DetailList
          items={[
            { label: 'File sumber', value: run.source_file },
            { label: 'Versi mapping', value: run.mapping_version },
            { label: 'Hasil', value: run.summary?.result },
          ]}
        />
      </div>
      {entities.length > 0 && (
        <div className="table-wrap">
          <table className="table">
            <caption className="sr-only">Hasil per entitas</caption>
            <thead>
              <tr>
                <th scope="col">Entitas</th>
                <th scope="col" className="num">
                  Baru
                </th>
                <th scope="col" className="num">
                  Diperbarui
                </th>
                <th scope="col" className="num">
                  Tidak diimpor
                </th>
                <th scope="col" className="num">
                  Error
                </th>
              </tr>
            </thead>
            <tbody>
              {entities.map((entity) => (
                <tr key={entity.entity}>
                  <td>
                    {entity.entity}
                    <div className="cell-sub">{entity.sheet}</div>
                  </td>
                  <td className="num">{formatNumber(entity.inserted)}</td>
                  <td className="num">{formatNumber(entity.updated)}</td>
                  <td className="num">{formatNumber(entity.not_imported)}</td>
                  <td className={`num ${entity.errors ? 'text-danger' : ''}`}>{formatNumber(entity.errors)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

export function MigrationIssuesPage() {
  const { can } = useAuth();
  const [params, setParams] = useListParams({ resolution_status: 'OPEN' }, ['resolution_status', 'severity']);
  const [action, setAction] = useState(null);
  const summary = useApi('/migration-issues/summary');
  // The status filter defaults to OPEN; clearing every chip is stored as "ALL" so it does not fall back to the default.
  const statusFilter = params.resolution_status.filter((status) => status !== 'ALL');
  const { data, meta, error, loading, reload } = useApi('/migration-issues', {
    q: params.q,
    resolution_status: statusFilter,
    severity: params.severity,
    entity_type: params.entity_type,
    issue_type: params.issue_type,
    page: params.page,
    page_size: 25,
  });
  const canWrite = can(MODULE.MIGRATION, 'write');
  const close = () => setAction(null);
  const done = () => {
    close();
    reload();
    summary.reload();
  };

  const counts = summary.data?.counts ?? [];
  const count = (status, severity) =>
    counts.filter((row) => row.resolution_status === status && (!severity || row.severity === severity)).reduce((sum, row) => sum + row.count, 0);
  const openByType = summary.data?.open_by_type ?? [];
  const entityTypes = [...new Set(openByType.map((row) => row.entity_type))].sort();
  const issueTypes = [...new Set(openByType.map((row) => row.issue_type))].sort();

  return (
    <>
      <PageHeader title="Migration Issues" eyebrow="Kualitas data migrasi" />
      {summary.error && !summary.data ? (
        <Card>
          <ErrorState error={summary.error} onRetry={summary.reload} />
        </Card>
      ) : !summary.data ? (
        <LoadingState rows={3} />
      ) : (
        <div className="kpi-grid">
          <KpiCard label="Error terbuka" value={formatNumber(count('OPEN', 'ERROR'))} tone={count('OPEN', 'ERROR') ? 'danger' : undefined} note="Data tidak diimpor / perlu keputusan" />
          <KpiCard label="Peringatan terbuka" value={formatNumber(count('OPEN', 'WARNING'))} tone={count('OPEN', 'WARNING') ? 'warning' : undefined} note="Diimpor dengan catatan" />
          <KpiCard label="Info terbuka" value={formatNumber(count('OPEN', 'INFO'))} />
          <KpiCard label="Selesai" value={formatNumber(count('RESOLVED'))} />
          <KpiCard label="Diabaikan" value={formatNumber(count('IGNORED'))} />
        </div>
      )}
      <div className="section-grid">
        <div>
          <div className="filter-bar">
            <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari deskripsi atau data sumber…" />
            {entityTypes.length > 0 && (
              <Select
                aria-label="Entitas"
                placeholder="Semua entitas"
                options={entityTypes.map((value) => ({ value, label: value }))}
                value={params.entity_type ?? ''}
                onChange={(event) => setParams({ entity_type: event.target.value })}
              />
            )}
            {issueTypes.length > 0 && (
              <Select
                aria-label="Jenis issue"
                placeholder="Semua jenis"
                options={issueTypes.map((value) => ({ value, label: value }))}
                value={params.issue_type ?? ''}
                onChange={(event) => setParams({ issue_type: event.target.value })}
              />
            )}
          </div>
          <div className="filter-bar">
            <FilterChips
              label="Status"
              options={MIGRATION_ISSUE_STATUS.options}
              value={statusFilter}
              onChange={(value) => setParams({ resolution_status: value.length ? value : ['ALL'] })}
            />
            <FilterChips label="Tingkat" options={MIGRATION_ISSUE_SEVERITY.options} value={params.severity} onChange={(value) => setParams({ severity: value })} />
          </div>
          <Card>
            {error && !data ? (
              <ErrorState error={error} onRetry={reload} />
            ) : !data ? (
              <LoadingState rows={5} />
            ) : !data.length ? (
              <EmptyState icon={Database} title="Tidak ada issue" text="Tidak ada issue migrasi untuk filter ini." />
            ) : (
              <ul className="list-plain" style={{ opacity: loading ? 0.6 : 1 }} aria-busy={loading}>
                {data.map((issue) => (
                  <IssueItem key={issue.id} issue={issue} canWrite={canWrite} onAction={(item, status) => setAction({ issue: item, status })} />
                ))}
              </ul>
            )}
            <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
          </Card>
        </div>
        <div>
          {summary.data && <LastRun run={summary.data.last_run} />}
          {openByType.length > 0 && (
            <Card>
              <CardHeader title="Issue terbuka per jenis" />
              <ul className="list-plain">
                {openByType.map((row) => (
                  <li key={`${row.issue_type}-${row.entity_type}`} className="list-item" style={{ alignItems: 'center' }}>
                    <button
                      type="button"
                      className="grow text-sm"
                      style={{ all: 'unset', cursor: 'pointer', flex: 1 }}
                      onClick={() => setParams({ issue_type: row.issue_type, entity_type: row.entity_type, resolution_status: ['OPEN'] })}
                    >
                      {row.issue_type}
                      <div className="cell-sub">{row.entity_type}</div>
                    </button>
                    <strong className="num">{formatNumber(row.count)}</strong>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </div>
      </div>
      <Modal open={Boolean(action)} onClose={close} title={action ? ACTION_LABELS[action.status] : ''}>
        {action && <ResolveForm issue={action.issue} status={action.status} onCancel={close} onDone={done} />}
      </Modal>
    </>
  );
}
