import { DELIVERY_STATUS, LEAD_OPEN_STATUSES, LEAD_STATUS, MODULE } from '@pik/shared';
import { Activity, Building2, CalendarClock, CircleAlert, FileText, Package, Target, Truck } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { ActivityTimeline } from '../components/domain/Activities.jsx';
import { FollowUpList } from '../components/domain/FollowUps.jsx';
import { Badge } from '../components/ui/Badge.jsx';
import { Card, CardHeader, KpiCard } from '../components/ui/Card.jsx';
import { PageHeader, Progress } from '../components/ui/Misc.jsx';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States.jsx';
import { Segmented, Tabs } from '../components/ui/Tabs.jsx';
import { useAuth } from '../context/contexts.js';
import { useApi } from '../hooks/useApi.js';
import { formatCurrencyCompact, formatDate, formatDateLong, formatNumber, formatQuantity } from '../utils/format.js';

const DELIVERY_COLORS = {
  DELIVERED: 'var(--color-success)',
  ON_DELIVERY: 'var(--color-violet)',
  SCHEDULED: 'var(--color-info)',
  DELAYED: 'var(--color-danger)',
  CANCELLED: '#aeaeb2',
};

function OutstandingKpi({ outstanding }) {
  const [main, ...others] = outstanding ?? [];
  return (
    <KpiCard
      label="Outstanding Qty"
      icon={Package}
      small={Boolean(main)}
      value={main ? formatQuantity(main.quantity, main.unit === '-' ? '' : main.unit) : '0'}
      note={others.length ? `+ ${others.map((item) => formatQuantity(item.quantity, item.unit)).join(' · ')}` : 'PO Open, On Process, Partial'}
      to="/purchase-orders?has_outstanding=true"
    />
  );
}

function PipelineCard({ pipeline }) {
  const open = pipeline.filter((column) => LEAD_OPEN_STATUSES.includes(column.status));
  const closed = pipeline.filter((column) => !LEAD_OPEN_STATUSES.includes(column.status));
  const max = Math.max(1, ...open.map((column) => column.count));
  return (
    <Card>
      <CardHeader title="Pipeline Lead" subtitle="Jumlah dan estimasi nilai per tahap" actions={<Link to="/leads" className="text-sm">Kanban</Link>} />
      <div className="card-body">
        <div className="bar-list">
          {open.map((column) => (
            <Link key={column.status} to={`/leads?view=list&status=${column.status}`} className="bar-row" style={{ color: 'inherit', textDecoration: 'none' }}>
              <span>{LEAD_STATUS.labels[column.status]}</span>
              <span className="bar-track">
                <span className="bar-fill" style={{ display: 'block', width: `${(column.count / max) * 100}%` }} />
              </span>
              <span className="num text-sm">
                <strong>{column.count}</strong> <span className="muted">· {formatCurrencyCompact(column.total_value)}</span>
              </span>
            </Link>
          ))}
        </div>
        <div className="row" style={{ marginTop: 16, gap: 8 }}>
          {closed.map((column) => (
            <Badge key={column.status} tone={LEAD_STATUS.tones[column.status]}>
              {LEAD_STATUS.labels[column.status]} {column.count}
            </Badge>
          ))}
        </div>
      </div>
    </Card>
  );
}

function OpenPurchaseOrders({ rows }) {
  return (
    <Card>
      <CardHeader title="PO Berjalan" subtitle="Target kirim terdekat" actions={<Link to="/purchase-orders?has_outstanding=true" className="text-sm">Semua PO</Link>} />
      {rows.length ? (
        <ul className="list-plain">
          {rows.map((po) => (
            <li key={po.id} className="list-item" style={{ alignItems: 'center' }}>
              <div className="grow">
                <Link to={`/purchase-orders/${po.id}`} className="cell-title">
                  {po.po_number}
                </Link>
                <div className="cell-sub truncate">{po.customer_name}</div>
              </div>
              <div style={{ width: 110 }}>
                <Progress value={po.ordered_quantity - po.outstanding_quantity} total={po.ordered_quantity} />
                <div className="cell-sub num">sisa {formatNumber(po.outstanding_quantity)}</div>
              </div>
              <div style={{ width: 92, textAlign: 'right' }}>
                {po.is_late ? <Badge tone="danger">Terlambat</Badge> : <span className="text-sm">{formatDate(po.expected_delivery_date)}</span>}
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState compact icon={FileText} title="Tidak ada PO berjalan" />
      )}
    </Card>
  );
}

function DeliveryCard({ deliveries }) {
  const total = deliveries.by_status.reduce((sum, row) => sum + row.count, 0);
  const ordered = DELIVERY_STATUS.values.map((status) => deliveries.by_status.find((row) => row.status === status)).filter(Boolean);
  return (
    <Card>
      <CardHeader title="Pengiriman" subtitle="30 hari terakhir s/d 14 hari ke depan" actions={<Link to="/deliveries" className="text-sm">Semua</Link>} />
      <div className="card-body">
        {total ? (
          <>
            <div className="stacked-bar" role="img" aria-label={ordered.map((row) => `${DELIVERY_STATUS.labels[row.status]} ${row.count}`).join(', ')}>
              {ordered.map((row) => (
                <span key={row.status} style={{ width: `${(row.count / total) * 100}%`, background: DELIVERY_COLORS[row.status] }} />
              ))}
            </div>
            <div className="legend">
              {ordered.map((row) => (
                <span key={row.status}>
                  <i style={{ background: DELIVERY_COLORS[row.status] }} />
                  {DELIVERY_STATUS.labels[row.status]} {row.count}
                </span>
              ))}
            </div>
          </>
        ) : (
          <p className="text-sm muted">Belum ada pengiriman pada periode ini.</p>
        )}
      </div>
      {deliveries.upcoming.length > 0 && (
        <ul className="list-plain" style={{ borderTop: '1px solid var(--color-hairline)' }}>
          {deliveries.upcoming.map((delivery) => (
            <li key={delivery.id} className="list-item">
              <Truck size={16} className="muted" style={{ marginTop: 3 }} aria-hidden="true" />
              <div className="grow">
                <div className="text-sm">
                  <Link to={`/purchase-orders/${delivery.purchase_order_id}`}>{delivery.po_number}</Link> · {delivery.product_name}
                </div>
                <div className="cell-sub">
                  {delivery.customer_name} · {formatQuantity(delivery.quantity, delivery.unit)}
                </div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className={`text-sm ${delivery.is_late ? 'text-danger' : ''}`}>{formatDate(delivery.delivery_date)}</div>
                <Badge tone={DELIVERY_STATUS.tones[delivery.status]}>{DELIVERY_STATUS.labels[delivery.status]}</Badge>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export function DashboardPage() {
  const { user, can } = useAuth();
  const [scope, setScope] = useState(user.role === 'SALES' || user.role === 'MARKETING' ? 'me' : 'all');
  const [followUpTab, setFollowUpTab] = useState('today');
  const { data, error, reload } = useApi('/dashboard/summary', { scope });
  const firstName = user.name.split(' ')[0];

  const header = (
    <PageHeader
      eyebrow={data ? formatDateLong(data.today) : ' '}
      title={`Halo, ${firstName}`}
      actions={
        <Segmented
          label="Cakupan data"
          value={scope}
          onChange={setScope}
          options={[
            { value: 'me', label: 'Data saya' },
            { value: 'all', label: 'Semua' },
          ]}
        />
      }
    />
  );

  if (error && !data) {
    return (
      <>
        {header}
        <Card>
          <ErrorState error={error} onRetry={reload} />
        </Card>
      </>
    );
  }
  if (!data) {
    return (
      <>
        {header}
        <Card>
          <LoadingState rows={6} />
        </Card>
      </>
    );
  }

  const { kpis } = data;
  const followUpItems = data.follow_ups[followUpTab];
  const scopeParam = scope === 'me' ? '&owner=me' : '';

  return (
    <>
      {header}
      <div className="kpi-grid">
        <KpiCard label="Total Customer" icon={Building2} value={formatNumber(kpis.total_customers)} note="Customer aktif" to="/customers" />
        <KpiCard label="Lead Aktif" icon={Target} value={formatNumber(kpis.active_leads)} note={`Pipeline ${formatCurrencyCompact(kpis.pipeline_value)}`} to="/leads" />
        <KpiCard
          label="Follow Up Hari Ini"
          icon={CalendarClock}
          value={formatNumber(kpis.follow_up_today)}
          tone={kpis.follow_up_today ? 'warning' : undefined}
          note={`${formatNumber(kpis.follow_up_upcoming)} dalam 7 hari`}
          to={`/follow-ups?tab=TODAY${scopeParam}`}
        />
        <KpiCard
          label="Follow Up Terlambat"
          icon={CircleAlert}
          value={formatNumber(kpis.follow_up_overdue)}
          tone={kpis.follow_up_overdue ? 'danger' : undefined}
          note={kpis.follow_up_overdue ? 'Perlu ditindaklanjuti' : 'Semua terkendali'}
          to={`/follow-ups?tab=OVERDUE${scopeParam}`}
        />
        <KpiCard
          label="PO Open"
          icon={FileText}
          value={formatNumber(kpis.open_purchase_orders)}
          note={kpis.late_purchase_orders ? `${kpis.late_purchase_orders} lewat target kirim` : 'Tidak ada yang terlambat'}
          tone={kpis.late_purchase_orders ? 'warning' : undefined}
          to="/purchase-orders?status=OPEN,ON_PROCESS,PARTIAL"
        />
        <OutstandingKpi outstanding={kpis.outstanding_quantity} />
      </div>

      <div className="section-grid">
        <div>
          <Card>
            <CardHeader
              title="Follow Up"
              actions={<Link to={`/follow-ups?tab=${followUpTab === 'today' ? 'TODAY' : followUpTab === 'overdue' ? 'OVERDUE' : 'UPCOMING'}${scopeParam}`} className="text-sm">Lihat semua</Link>}
            />
            <div style={{ padding: '0 20px' }}>
              <Tabs
                label="Follow up"
                value={followUpTab}
                onChange={setFollowUpTab}
                tabs={[
                  { id: 'today', label: 'Hari ini', count: data.follow_ups.totals.today },
                  { id: 'overdue', label: 'Terlambat', count: data.follow_ups.totals.overdue, countTone: 'danger' },
                  { id: 'upcoming', label: '7 hari ke depan', count: data.follow_ups.totals.upcoming },
                ]}
              />
            </div>
            <FollowUpList
              items={followUpItems}
              onChanged={reload}
              empty={
                <EmptyState
                  compact
                  icon={CalendarClock}
                  title={followUpTab === 'overdue' ? 'Tidak ada follow up terlambat' : 'Tidak ada follow up'}
                  text={followUpTab === 'today' ? 'Jadwal hari ini kosong.' : undefined}
                />
              }
            />
          </Card>
          {can(MODULE.ACTIVITIES) && (
            <Card>
              <CardHeader title="Aktivitas Terbaru" actions={<Link to="/activities" className="text-sm">Semua aktivitas</Link>} />
              <ActivityTimeline items={data.recent_activities} empty={<EmptyState compact icon={Activity} title="Belum ada aktivitas" />} />
            </Card>
          )}
        </div>
        <div>
          <PipelineCard pipeline={data.pipeline} />
          {can(MODULE.PURCHASE_ORDERS) && <OpenPurchaseOrders rows={data.open_purchase_orders} />}
          {can(MODULE.DELIVERIES) && <DeliveryCard deliveries={data.deliveries} />}
        </div>
      </div>
    </>
  );
}
