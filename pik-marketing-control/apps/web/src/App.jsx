import { MODULE } from '@pik/shared';
import { lazy } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';
import { AuthProvider } from './context/AuthProvider.jsx';
import { FeedbackProvider } from './context/FeedbackProvider.jsx';
import { AppLayout } from './layouts/AppLayout.jsx';
import { LoginPage } from './pages/LoginPage.jsx';
import { RequireAuth, RequirePermission } from './routes/guards.jsx';

// Pages are loaded on demand to keep the first load small.
const page = (loader, name) => lazy(() => loader().then((module) => ({ default: module[name] })));
const DashboardPage = page(() => import('./pages/DashboardPage.jsx'), 'DashboardPage');
const CustomersPage = page(() => import('./pages/customers/CustomersPage.jsx'), 'CustomersPage');
const CustomerDetailPage = page(() => import('./pages/customers/CustomerDetailPage.jsx'), 'CustomerDetailPage');
const LeadsPage = page(() => import('./pages/leads/LeadsPage.jsx'), 'LeadsPage');
const LeadDetailPage = page(() => import('./pages/leads/LeadDetailPage.jsx'), 'LeadDetailPage');
const ActivitiesPage = page(() => import('./pages/ActivitiesPage.jsx'), 'ActivitiesPage');
const FollowUpsPage = page(() => import('./pages/FollowUpsPage.jsx'), 'FollowUpsPage');
const PurchaseOrdersPage = page(() => import('./pages/purchase-orders/PurchaseOrdersPage.jsx'), 'PurchaseOrdersPage');
const PurchaseOrderFormPage = page(() => import('./pages/purchase-orders/PurchaseOrderFormPage.jsx'), 'PurchaseOrderFormPage');
const PurchaseOrderDetailPage = page(() => import('./pages/purchase-orders/PurchaseOrderDetailPage.jsx'), 'PurchaseOrderDetailPage');
const DeliveriesPage = page(() => import('./pages/operations/DeliveriesPage.jsx'), 'DeliveriesPage');
const ReturnsPage = page(() => import('./pages/operations/ReturnsPage.jsx'), 'ReturnsPage');
const ProductsPage = page(() => import('./pages/products/ProductsPage.jsx'), 'ProductsPage');
const ProductDetailPage = page(() => import('./pages/products/ProductDetailPage.jsx'), 'ProductDetailPage');
const StockPage = page(() => import('./pages/operations/StockPage.jsx'), 'StockPage');
const LeadTimesPage = page(() => import('./pages/operations/LeadTimesPage.jsx'), 'LeadTimesPage');
const InboundMaklonPage = page(() => import('./pages/operations/InboundMaklonPage.jsx'), 'InboundMaklonPage');
const FinancePage = page(() => import('./pages/FinancePage.jsx'), 'FinancePage');
const ReportsPage = page(() => import('./pages/ReportsPage.jsx'), 'ReportsPage');
const AccountPage = page(() => import('./pages/settings/AccountPage.jsx'), 'AccountPage');
const UsersPage = page(() => import('./pages/settings/UsersPage.jsx'), 'UsersPage');
const MigrationIssuesPage = page(() => import('./pages/settings/MigrationIssuesPage.jsx'), 'MigrationIssuesPage');
const NotFoundPage = page(() => import('./pages/NotFoundPage.jsx'), 'NotFoundPage');

const guarded = (module, element) => <RequirePermission module={module}>{element}</RequirePermission>;

export function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <FeedbackProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route
              element={
                <RequireAuth>
                  <AppLayout />
                </RequireAuth>
              }
            >
              <Route index element={<Navigate to="/dashboard" replace />} />
              <Route path="dashboard" element={guarded(MODULE.DASHBOARD, <DashboardPage />)} />
              <Route path="customers" element={guarded(MODULE.CUSTOMERS, <CustomersPage />)} />
              <Route path="customers/:id" element={guarded(MODULE.CUSTOMERS, <CustomerDetailPage />)} />
              <Route path="leads" element={guarded(MODULE.LEADS, <LeadsPage />)} />
              <Route path="leads/:id" element={guarded(MODULE.LEADS, <LeadDetailPage />)} />
              <Route path="activities" element={guarded(MODULE.ACTIVITIES, <ActivitiesPage />)} />
              <Route path="follow-ups" element={guarded(MODULE.FOLLOW_UPS, <FollowUpsPage />)} />
              <Route path="purchase-orders" element={guarded(MODULE.PURCHASE_ORDERS, <PurchaseOrdersPage />)} />
              <Route path="purchase-orders/new" element={guarded(MODULE.PURCHASE_ORDERS, <PurchaseOrderFormPage />)} />
              <Route path="purchase-orders/:id" element={guarded(MODULE.PURCHASE_ORDERS, <PurchaseOrderDetailPage />)} />
              <Route path="deliveries" element={guarded(MODULE.DELIVERIES, <DeliveriesPage />)} />
              <Route path="returns" element={guarded(MODULE.RETURNS, <ReturnsPage />)} />
              <Route path="products" element={guarded(MODULE.PRODUCTS, <ProductsPage />)} />
              <Route path="products/:id" element={guarded(MODULE.PRODUCTS, <ProductDetailPage />)} />
              <Route path="stock" element={guarded(MODULE.STOCK, <StockPage />)} />
              <Route path="lead-times" element={guarded(MODULE.LEAD_TIME, <LeadTimesPage />)} />
              <Route path="inbound-maklon" element={guarded(MODULE.INBOUND_MAKLON, <InboundMaklonPage />)} />
              <Route path="finance" element={guarded(MODULE.FINANCE, <FinancePage />)} />
              <Route path="reports" element={guarded(MODULE.REPORTS, <ReportsPage />)} />
              <Route path="settings" element={<AccountPage />} />
              <Route path="settings/users" element={guarded(MODULE.USERS, <UsersPage />)} />
              <Route path="settings/migration-issues" element={guarded(MODULE.MIGRATION, <MigrationIssuesPage />)} />
              <Route path="*" element={<NotFoundPage />} />
            </Route>
          </Routes>
        </FeedbackProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}
