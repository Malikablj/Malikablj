import { LogOut, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { NavLink, useLocation } from 'react-router-dom';
import { ROLE_LABEL } from '@/config/labels';
import { useApprovals } from '@/hooks/queries';
import { useAuth, useUser } from '@/hooks/useAuth';
import { cn } from '@/lib/utils';
import { Avatar } from '../ui/Misc';
import { NAV_ITEMS } from './navigation';

export function BrandMark({ compact }: { compact?: boolean }) {
  return (
    <div className="flex items-center gap-2.5">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-ink text-[11px] font-bold tracking-tight text-surface">NPD</span>
      {!compact && (
        <span className="min-w-0 leading-tight">
          <span className="block text-[13px] font-semibold tracking-[-0.01em] text-ink">NPD Project Control</span>
          <span className="block text-[11px] text-ink-3">New Mold & Subcont</span>
        </span>
      )}
    </div>
  );
}

function usePendingApprovals(): number {
  const { data } = useApprovals();
  return data?.filter((a) => a.status === 'pending' && a.canDecide).length ?? 0;
}

export function SidebarNav({ collapsed, onNavigate }: { collapsed?: boolean; onNavigate?: () => void }) {
  const location = useLocation();
  const pending = usePendingApprovals();
  const search = new URLSearchParams(location.search);
  const typeParam = search.get('type');

  return (
    <nav aria-label="Navigasi utama" className="flex flex-col gap-0.5">
      {NAV_ITEMS.map((item) => {
        const Icon = item.icon;
        const sectionActive = item.to === '/' ? location.pathname === '/' : location.pathname.startsWith(item.to);
        return (
          <div key={item.to}>
            <NavLink
              to={item.to}
              end={item.to === '/'}
              onClick={onNavigate}
              title={collapsed ? item.label : undefined}
              className={cn(
                'group flex h-9 items-center gap-3 rounded-[9px] px-2.5 text-[14px] transition-colors max-lg:h-11',
                sectionActive ? 'bg-accent-soft font-medium text-accent-ink' : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
                collapsed && 'justify-center px-0',
              )}
            >
              <Icon className="size-[18px] shrink-0" strokeWidth={1.75} aria-hidden="true" />
              {!collapsed && <span className="flex-1 truncate">{item.label}</span>}
              {!collapsed && item.badge === 'approvals' && pending > 0 && (
                <span className="tabular rounded-full bg-tone-yellow-soft px-1.5 text-[11px] font-semibold text-tone-yellow" aria-label={`${pending} approval menunggu keputusan Anda`}>
                  {pending}
                </span>
              )}
              {collapsed && item.badge === 'approvals' && pending > 0 && <span className="sr-only">{pending} approval pending</span>}
            </NavLink>
            {item.children && !collapsed && sectionActive && (
              <div className="mt-0.5 mb-1 ml-[22px] flex flex-col border-l border-line pl-3">
                {item.children.map((c) => {
                  const cType = new URLSearchParams(c.to.split('?')[1] ?? '').get('type');
                  const active = location.pathname === '/projects' && (cType ?? null) === (typeParam ?? null);
                  return (
                    <NavLink
                      key={c.to}
                      to={c.to}
                      onClick={onNavigate}
                      aria-current={active ? 'page' : undefined}
                      className={cn(
                        'flex h-8 items-center rounded-[7px] px-2 text-[13px] transition-colors max-lg:h-10',
                        active ? 'font-medium text-ink' : 'text-ink-3 hover:text-ink',
                      )}
                    >
                      {c.label}
                    </NavLink>
                  );
                })}
              </div>
            )}
          </div>
        );
      })}
    </nav>
  );
}

export function UserCard({ collapsed }: { collapsed?: boolean }) {
  const user = useUser();
  const { logout } = useAuth();
  return (
    <div className={cn('flex items-center gap-2.5', collapsed && 'flex-col')}>
      <Avatar name={user.name} />
      {!collapsed && (
        <div className="min-w-0 flex-1 leading-tight">
          <p className="truncate text-[13px] font-medium text-ink">{user.name}</p>
          <p className="truncate text-[12px] text-ink-3">{ROLE_LABEL[user.role]}</p>
        </div>
      )}
      <button
        type="button"
        onClick={logout}
        aria-label="Keluar"
        title="Keluar"
        className="flex size-8 items-center justify-center rounded-lg text-ink-3 hover:bg-surface-2 hover:text-ink max-lg:size-11"
      >
        <LogOut className="size-4" />
      </button>
    </div>
  );
}

export function Sidebar({ collapsed, onToggle }: { collapsed: boolean; onToggle: () => void }) {
  return (
    <aside
      className={cn(
        'fixed inset-y-0 left-0 z-30 hidden flex-col border-r border-line bg-surface transition-[width] duration-200 md:flex',
        collapsed ? 'w-[72px]' : 'w-[248px]',
      )}
    >
      <div className={cn('flex h-16 items-center justify-between px-4', collapsed && 'justify-center px-0')}>
        <BrandMark compact={collapsed} />
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto px-3 pb-3 scrollbar-thin">
        <SidebarNav collapsed={collapsed} />
      </div>
      <div className="border-t border-line p-3">
        <UserCard collapsed={collapsed} />
        <button
          type="button"
          onClick={onToggle}
          aria-label={collapsed ? 'Lebarkan sidebar' : 'Ciutkan sidebar'}
          className={cn('mt-2 flex h-8 w-full items-center gap-2 rounded-lg px-2 text-[12px] text-ink-3 hover:bg-surface-2 hover:text-ink', collapsed && 'justify-center px-0')}
        >
          {collapsed ? <PanelLeftOpen className="size-4" /> : <PanelLeftClose className="size-4" />}
          {!collapsed && 'Ciutkan'}
        </button>
      </div>
    </aside>
  );
}
