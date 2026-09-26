import {
  BadgeCheck,
  CalendarDays,
  ChartColumn,
  ChartGantt,
  FileText,
  FolderKanban,
  LayoutDashboard,
  Settings,
  Sparkles,
  Workflow,
  type LucideIcon,
} from 'lucide-react';

export interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  children?: Array<{ to: string; label: string }>;
  badge?: 'approvals';
}

/** Sidebar structure from PRD §15 (Desktop Sidebar). */
export const NAV_ITEMS: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  {
    to: '/projects',
    label: 'Project',
    icon: FolderKanban,
    children: [
      { to: '/projects', label: 'Semua Project' },
      { to: '/projects?type=new_mold', label: 'New Mold' },
      { to: '/projects?type=subcont', label: 'Subcont' },
    ],
  },
  { to: '/tracker', label: 'Process Tracker', icon: Workflow },
  { to: '/gantt', label: 'Timeline / Gantt', icon: ChartGantt },
  { to: '/calendar', label: 'Kalender', icon: CalendarDays },
  { to: '/documents', label: 'Dokumen', icon: FileText },
  { to: '/approvals', label: 'Approval', icon: BadgeCheck, badge: 'approvals' },
  { to: '/reports', label: 'Laporan', icon: ChartColumn },
  { to: '/assistant', label: 'AI Assistant', icon: Sparkles },
  { to: '/settings', label: 'Pengaturan', icon: Settings },
];
