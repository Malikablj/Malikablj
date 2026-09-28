import { MODULE } from '@pik/shared';
import {
  Activity,
  Boxes,
  Building2,
  CalendarClock,
  ChartColumn,
  Database,
  FileText,
  LayoutDashboard,
  Package,
  PackagePlus,
  Settings,
  Target,
  Timer,
  Truck,
  Undo2,
  UserCog,
  Wallet,
} from 'lucide-react';

/** Sidebar navigation. Items whose module the role cannot read are hidden. */
export const NAV_GROUPS = [
  { title: 'Ringkasan', items: [{ to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, module: MODULE.DASHBOARD }] },
  {
    title: 'CRM',
    items: [
      { to: '/customers', label: 'Customer', icon: Building2, module: MODULE.CUSTOMERS },
      { to: '/leads', label: 'Lead', icon: Target, module: MODULE.LEADS },
      { to: '/activities', label: 'Aktivitas', icon: Activity, module: MODULE.ACTIVITIES },
      { to: '/follow-ups', label: 'Follow Up', icon: CalendarClock, module: MODULE.FOLLOW_UPS, badge: 'followUps' },
    ],
  },
  {
    title: 'Operasional',
    items: [
      { to: '/purchase-orders', label: 'Purchase Order', icon: FileText, module: MODULE.PURCHASE_ORDERS },
      { to: '/deliveries', label: 'Pengiriman', icon: Truck, module: MODULE.DELIVERIES },
      { to: '/returns', label: 'Retur', icon: Undo2, module: MODULE.RETURNS },
      { to: '/products', label: 'Produk', icon: Package, module: MODULE.PRODUCTS },
      { to: '/stock', label: 'Stok', icon: Boxes, module: MODULE.STOCK },
      { to: '/lead-times', label: 'Lead Time', icon: Timer, module: MODULE.LEAD_TIME },
      { to: '/inbound-maklon', label: 'Maklon Masuk', icon: PackagePlus, module: MODULE.INBOUND_MAKLON },
    ],
  },
  { title: 'Keuangan', items: [{ to: '/finance', label: 'Invoice & Pembayaran', icon: Wallet, module: MODULE.FINANCE }] },
  { title: 'Analisis', items: [{ to: '/reports', label: 'Laporan', icon: ChartColumn, module: MODULE.REPORTS }] },
  {
    title: 'Pengaturan',
    items: [
      { to: '/settings/users', label: 'Pengguna', icon: UserCog, module: MODULE.USERS },
      { to: '/settings/migration-issues', label: 'Migration Issues', icon: Database, module: MODULE.MIGRATION },
      { to: '/settings', label: 'Akun Saya', icon: Settings, end: true },
    ],
  },
];

/** Priority flows on the phone bottom bar (the fifth slot opens the full menu). */
export const BOTTOM_NAV = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, module: MODULE.DASHBOARD },
  { to: '/customers', label: 'Customer', icon: Building2, module: MODULE.CUSTOMERS },
  { to: '/follow-ups', label: 'Follow Up', icon: CalendarClock, module: MODULE.FOLLOW_UPS, badge: 'followUps' },
  { to: '/leads', label: 'Lead', icon: Target, module: MODULE.LEADS },
];
