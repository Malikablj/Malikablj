/** Activity timeline. */
import { ACTIVITY_TYPE } from '@pik/shared';
import {
  Activity,
  CalendarCheck,
  CircleDollarSign,
  FlaskConical,
  Mail,
  MessageCircle,
  MessageSquareWarning,
  Phone,
  Presentation,
  StickyNote,
  Users,
  Warehouse,
} from 'lucide-react';
import { Link } from 'react-router';
import { formatDateTime } from '../../utils/format.js';
import { EmptyState } from '../ui/States.jsx';

const TYPE_ICONS = {
  WHATSAPP: MessageCircle,
  CALL: Phone,
  EMAIL: Mail,
  MEETING: Users,
  VISIT: Warehouse,
  QUOTATION: CircleDollarSign,
  SAMPLE: FlaskConical,
  PRESENTATION: Presentation,
  FOLLOW_UP: CalendarCheck,
  COMPLAINT: MessageSquareWarning,
  NOTE: StickyNote,
  OTHER: Activity,
};

/** items: activity rows · showCustomer: link the customer name (hide on the customer page) */
export function ActivityTimeline({ items, showCustomer = true, onSelect, empty }) {
  if (!items?.length) return empty ?? <EmptyState compact icon={Activity} title="Belum ada aktivitas" />;
  return (
    <ol className="timeline">
      {items.map((activity) => {
        const Icon = TYPE_ICONS[activity.type] ?? Activity;
        return (
          <li key={activity.id} className="timeline-item">
            <span className="timeline-icon" title={ACTIVITY_TYPE.labels[activity.type]}>
              <Icon size={16} aria-hidden="true" />
            </span>
            <div style={{ minWidth: 0 }}>
              <div className="timeline-head">
                {onSelect ? (
                  <button type="button" className="timeline-title" style={{ all: 'unset', cursor: 'pointer', fontWeight: 500 }} onClick={() => onSelect(activity)}>
                    {activity.subject}
                  </button>
                ) : (
                  <span className="timeline-title">{activity.subject}</span>
                )}
                <span className="timeline-meta">{ACTIVITY_TYPE.labels[activity.type]}</span>
              </div>
              <div className="timeline-meta">
                {formatDateTime(activity.activity_at)}
                {showCustomer && (
                  <>
                    {' · '}
                    <Link to={`/customers/${activity.customer_id}`}>{activity.customer_name}</Link>
                  </>
                )}
                {activity.lead_name && (
                  <>
                    {' · '}
                    <Link to={`/leads/${activity.lead_id}`}>{activity.lead_name}</Link>
                  </>
                )}
                {activity.contact_name && ` · ${activity.contact_name}`}
                {activity.owner_name && ` · ${activity.owner_name}`}
              </div>
              {activity.description && <div className="timeline-body pre-wrap">{activity.description}</div>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
