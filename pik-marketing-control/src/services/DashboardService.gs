/**
 * Dashboard: KPIs and work lists computed from the database on every request (no stored or placeholder figures).
 *
 * scope 'mine' limits the personal figures (leads, follow-ups, activities) to the signed-in user's records;
 * company-wide figures (customers, purchase orders, outstanding) are always for the whole company.
 */

const DASHBOARD_LIST_LIMIT = 8;

function getDashboardSummary_(input, user) {
  const params = objectInput_(input);
  const scope = params.scope === 'mine' ? 'mine' : 'all';
  const today = todayIso_();
  const isMine = function (record) { return scope === 'all' || record.owner_user_id === user.id; };
  const active = function (record) { return record.is_active !== false; };

  const customers = loadTable_('CUSTOMERS').records.filter(active);
  const leads = loadTable_('LEADS').records.filter(active);
  const openLeads = leads.filter(function (lead) { return isOpenLead_(lead) && isMine(lead); });
  const followUps = loadTable_('FOLLOW_UP').records.filter(function (f) { return isOpenFollowUp_(f) && isMine(f); })
    .map(function (f) { return Object.assign(withNames_(f), { due_state: followUpDueState_(f, today) }); });
  const openPos = loadTable_('PURCHASE_ORDERS').records.filter(isOpenPurchaseOrder_).map(purchaseOrderWithSummary_);

  const pipeline = (getEnumState_().byName.LEAD_STATUS || { items: [] }).items.filter(function (item) { return item.is_active === true; })
    .map(function (item) {
      const inStatus = leads.filter(function (lead) { return lead.status === item.enum_value && isMine(lead); });
      return {
        status: item.enum_value, label: item.label, count: inStatus.length,
        value: roundMoney_(inStatus.reduce(function (sum, lead) { return sum + (lead.estimated_value || 0); }, 0))
      };
    });

  const byDateTime = function (a, b) {
    return compareForSort_(a.follow_up_date + ' ' + (a.follow_up_time || '99:99'), b.follow_up_date + ' ' + (b.follow_up_time || '99:99'));
  };
  const summary = {
    scope: scope,
    today: today,
    kpis: {
      totalCustomers: customers.length,
      activeCustomers: customers.filter(function (c) { return c.status === 'ACTIVE'; }).length,
      activeLeads: openLeads.length,
      openLeadValue: roundMoney_(openLeads.reduce(function (sum, lead) { return sum + (lead.estimated_value || 0); }, 0)),
      followUpToday: followUps.filter(function (f) { return f.due_state === 'TODAY'; }).length,
      followUpOverdue: followUps.filter(function (f) { return f.due_state === 'OVERDUE'; }).length,
      openPurchaseOrders: openPos.length,
      outstandingQuantity: roundQty_(openPos.reduce(function (sum, po) { return sum + po.outstanding_quantity; }, 0))
    },
    leadPipeline: pipeline,
    followUpsToday: followUps.filter(function (f) { return f.due_state === 'TODAY'; }).sort(byDateTime).slice(0, DASHBOARD_LIST_LIMIT),
    followUpsOverdue: followUps.filter(function (f) { return f.due_state === 'OVERDUE'; }).sort(byDateTime).slice(0, DASHBOARD_LIST_LIMIT),
    recentActivities: loadTable_('ACTIVITIES').records.filter(function (a) { return active(a) && isMine(a); })
      .sort(function (a, b) { return compareForSort_(b.activity_at, a.activity_at); }).slice(0, DASHBOARD_LIST_LIMIT).map(withNames_),
    openPurchaseOrders: openPos.slice().sort(function (a, b) {
      return (b.outstanding_quantity - a.outstanding_quantity) || compareForSort_(b.po_date, a.po_date);
    }).slice(0, DASHBOARD_LIST_LIMIT),
    finance: null,
    generatedAt: nowIso_()
  };
  if (can_(user, 'finance', ACCESS.READ)) {
    const invoices = loadTable_('INVOICES_PAYMENTS').records.filter(active);
    summary.finance = {
      receivable: roundMoney_(invoices.reduce(function (sum, invoice) { return sum + invoiceOutstanding_(invoice); }, 0)),
      unpaidInvoices: invoices.filter(function (invoice) { return invoiceOutstanding_(invoice) > 0; }).length,
      overdueInvoices: invoices.filter(function (invoice) { return invoiceIsOverdue_(invoice, today); }).length
    };
  }
  return summary;
}
