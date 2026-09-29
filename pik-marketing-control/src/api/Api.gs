/**
 * The client API: the frontend calls google.script.run.api(action, payload, token) and nothing else. `token` is the
 * session token of an email + password sign-in (PasswordAuth.gs); Google-identity sessions send none.
 *
 * Every call runs in a fresh state, identifies the caller (USERS, see Auth.gs), checks the route's permission on the
 * server, runs the handler and returns the standard envelope (Errors.gs). Public routes (the sign-in itself) skip the
 * identity check. A user signed in with a temporary password may only change it (PASSWORD_CHANGE_REQUIRED).
 *   { success: true, data }  |  { success: false, error: { code, message, details } }
 * Unexpected errors are logged server-side and returned as a generic message; AppErrors carry a safe message and, for
 * validation errors, the field errors (details.errors) for the form.
 */

var API_ROUTES_ = null;

function api(action, payload, token) {
  return handleRequest_(function () {
    resetDbCache_();
    resetCurrentUser_();
    if (typeof action !== 'string' || action === '') throw appError_(ERROR_CODE.VALIDATION, 'Aksi API tidak valid.');
    const route = getApiRoute_(action);
    const input = objectInput_(payload, 'Data permintaan');
    if (route.isPublic) return route.handler(input, null);
    const user = requireUser_(typeof token === 'string' && token !== '' ? token : null);
    if (user.mustChangePassword && !route.duringPasswordChange) {
      throw appError_(ERROR_CODE.PASSWORD_CHANGE_REQUIRED, 'Buat password baru untuk mengganti password sementara Anda.');
    }
    if (route.permission) requirePermission_(user, route.permission[0], route.permission[1]);
    return route.handler(input, user);
  });
}

function getApiRoute_(action) {
  if (!API_ROUTES_) API_ROUTES_ = defineApiRoutes_();
  if (!Object.prototype.hasOwnProperty.call(API_ROUTES_, action)) {
    throw appError_(ERROR_CODE.NOT_FOUND, 'Aksi API tidak dikenal: ' + action + '.');
  }
  return API_ROUTES_[action];
}

/**
 * Route table: action → { permission: [module, 'R' | 'RW'] | null, handler(input, user), isPublic, duringPasswordChange }.
 * permission null = any signed-in user (session, pickers); handlers still check finer rules themselves.
 * isPublic = callable without signing in (only the sign-in); duringPasswordChange = allowed before a temporary
 * password is replaced.
 */
function defineApiRoutes_() {
  const R = ACCESS.READ;
  const W = ACCESS.WRITE;
  const route = function (module, access, handler) {
    return { permission: module ? [module, access] : null, handler: handler, isPublic: false, duringPasswordChange: false };
  };
  const publicRoute = function (handler) {
    return { permission: null, handler: handler, isPublic: true, duringPasswordChange: true };
  };
  const passwordChangeRoute = function (handler) {
    return { permission: null, handler: handler, isPublic: false, duringPasswordChange: true };
  };
  return {
    'auth.login': publicRoute(loginWithPassword_),
    'auth.changePassword': passwordChangeRoute(changeOwnPassword_),

    'session.get': passwordChangeRoute(getSession_),
    'session.login': route(null, null, loginSession_),
    'users.options': route(null, null, listUserOptions_),
    'search.global': route(null, null, globalSearch_),

    'dashboard.summary': route('dashboard', R, getDashboardSummary_),

    'customers.list': route('customers', R, listCustomers_),
    'customers.get': route('customers', R, getCustomer_),
    'customers.options': route('customers', R, customerOptions_),
    'customers.create': route('customers', W, createCustomer_),
    'customers.update': route('customers', W, updateCustomer_),
    'customers.archive': route('customers', W, archiveCustomer_),
    'customers.restore': route('customers', W, restoreCustomer_),

    'contacts.list': route('contacts', R, listContacts_),
    'contacts.options': route('contacts', R, contactOptions_),
    'contacts.create': route('contacts', W, createContact_),
    'contacts.update': route('contacts', W, updateContact_),
    'contacts.setPrimary': route('contacts', W, setPrimaryContact_),
    'contacts.archive': route('contacts', W, archiveContact_),
    'contacts.restore': route('contacts', W, restoreContact_),

    'leads.list': route('leads', R, listLeads_),
    'leads.board': route('leads', R, getLeadBoard_),
    'leads.get': route('leads', R, getLead_),
    'leads.options': route('leads', R, leadOptions_),
    'leads.create': route('leads', W, createLead_),
    'leads.update': route('leads', W, updateLead_),
    'leads.move': route('leads', W, moveLead_),
    'leads.archive': route('leads', W, archiveLead_),
    'leads.restore': route('leads', W, restoreLead_),

    'activities.list': route('activities', R, listActivities_),
    'activities.create': route('activities', W, createActivity_),
    'activities.update': route('activities', W, updateActivity_),
    'activities.archive': route('activities', W, archiveActivity_),
    'activities.restore': route('activities', W, restoreActivity_),

    'followUps.list': route('followUps', R, listFollowUps_),
    'followUps.counts': route('followUps', R, countFollowUps_),
    'followUps.create': route('followUps', W, createFollowUp_),
    'followUps.update': route('followUps', W, updateFollowUp_),
    'followUps.complete': route('followUps', W, completeFollowUp_),
    'followUps.reschedule': route('followUps', W, rescheduleFollowUp_),
    'followUps.cancel': route('followUps', W, cancelFollowUp_),
    'followUps.archive': route('followUps', W, archiveFollowUp_),
    'followUps.restore': route('followUps', W, restoreFollowUp_),

    'products.list': route('products', R, listProducts_),
    'products.get': route('products', R, getProduct_),
    'products.options': route('products', R, productOptions_),
    'products.create': route('products', W, createProduct_),
    'products.update': route('products', W, updateProduct_),
    'products.archive': route('products', W, archiveProduct_),
    'products.restore': route('products', W, restoreProduct_),

    'stock.list': route('stock', R, listStock_),
    'stock.summary': route('stock', R, summarizeStock_),
    'stock.create': route('stock', W, createStock_),
    'stock.update': route('stock', W, updateStock_),
    'stock.archive': route('stock', W, archiveStock_),
    'stock.restore': route('stock', W, restoreStock_),

    'leadTime.list': route('leadTime', R, listLeadTimes_),
    'leadTime.create': route('leadTime', W, createLeadTime_),
    'leadTime.update': route('leadTime', W, updateLeadTime_),
    'leadTime.archive': route('leadTime', W, archiveLeadTime_),
    'leadTime.restore': route('leadTime', W, restoreLeadTime_),

    'purchaseOrders.list': route('purchaseOrders', R, listPurchaseOrders_),
    'purchaseOrders.get': route('purchaseOrders', R, getPurchaseOrder_),
    'purchaseOrders.options': route('purchaseOrders', R, purchaseOrderOptions_),
    'purchaseOrders.lineOptions': route('purchaseOrders', R, poLineOptions_),
    'purchaseOrders.create': route('purchaseOrders', W, createPurchaseOrder_),
    'purchaseOrders.update': route('purchaseOrders', W, updatePurchaseOrder_),
    'purchaseOrders.setStatus': route('purchaseOrders', W, setPurchaseOrderStatus_),
    'purchaseOrders.archive': route('purchaseOrders', W, archivePurchaseOrder_),
    'purchaseOrders.restore': route('purchaseOrders', W, restorePurchaseOrder_),
    'poLines.create': route('purchaseOrders', W, createPoLine_),
    'poLines.update': route('purchaseOrders', W, updatePoLine_),
    'poLines.archive': route('purchaseOrders', W, archivePoLine_),
    'poLines.restore': route('purchaseOrders', W, restorePoLine_),

    'deliveries.list': route('deliveries', R, listDeliveries_),
    'deliveries.create': route('deliveries', W, createDelivery_),
    'deliveries.update': route('deliveries', W, updateDelivery_),
    'deliveries.archive': route('deliveries', W, archiveDelivery_),
    'deliveries.restore': route('deliveries', W, restoreDelivery_),

    'returns.list': route('returns', R, listReturns_),
    'returns.create': route('returns', W, createReturn_),
    'returns.update': route('returns', W, updateReturn_),
    'returns.archive': route('returns', W, archiveReturn_),
    'returns.restore': route('returns', W, restoreReturn_),

    'inbound.list': route('inbound', R, listInbound_),

    'invoices.list': route('finance', R, listInvoices_),
    'invoices.get': route('finance', R, getInvoice_),
    'invoices.create': route('finance', W, createInvoice_),
    'invoices.update': route('finance', W, updateInvoice_),
    'invoices.recordPayment': route('finance', W, recordInvoicePayment_),
    'invoices.archive': route('finance', W, archiveInvoice_),
    'invoices.restore': route('finance', W, restoreInvoice_),

    'reports.customers': route('reports', R, reportCustomers_),
    'reports.leads': route('reports', R, reportLeads_),
    'reports.activities': route('reports', R, reportActivities_),
    'reports.purchaseOrders': route('reports', R, reportPurchaseOrders_),
    'reports.deliveries': route('reports', R, reportDeliveries_),

    'audit.list': route('audit', R, listAuditLog_),
    'audit.history': route(null, null, getRecordHistory_),

    'users.list': route('users', R, listUsers_),
    'users.create': route('users', W, createUser_),
    'users.update': route('users', W, updateUser_),
    'users.archive': route('users', W, archiveUser_),
    'users.restore': route('users', W, restoreUser_),
    'users.setPassword': route('users', W, setUserPassword_),

    'settings.list': route('settings', R, listSettings_),
    'settings.update': route('settings', W, updateSettingValue_),
    'enums.list': route('settings', R, listEnumValues_),
    'enums.create': route('settings', W, createEnumValue_),
    'enums.update': route('settings', W, updateEnumValue_),

    'migrationIssues.list': route('migrationIssues', R, listMigrationIssues_),
    'migrationIssues.resolve': route('migrationIssues', W, resolveMigrationIssue_)
  };
}
