import { ROLE } from '@pik/shared';
import { Bell, CalendarClock, CircleAlert, LogOut, Menu, Search, Settings, Truck, X } from 'lucide-react';
import { Suspense, useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router';
import { Avatar } from '../components/ui/Misc.jsx';
import { Modal } from '../components/ui/Modal.jsx';
import { PageLoading } from '../components/ui/States.jsx';
import { useAuth } from '../context/contexts.js';
import { useApi } from '../hooks/useApi.js';
import { formatDate } from '../utils/format.js';
import { GlobalSearch } from './GlobalSearch.jsx';
import { BOTTOM_NAV, NAV_GROUPS } from './navigation.js';

const NOTIFICATION_REFRESH_MS = 3 * 60 * 1000;
const NOTIFICATION_ICONS = { FOLLOW_UP_OVERDUE: CalendarClock, FOLLOW_UP_TODAY: CalendarClock, PO_LATE: Truck, MIGRATION_ERRORS: CircleAlert };

function useOutsideClose(open, setOpen) {
  const ref = useRef(null);
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (event) => {
      if (!ref.current?.contains(event.target)) setOpen(false);
    };
    const onKey = (event) => event.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, setOpen]);
  return ref;
}

function Notifications({ notifications }) {
  const [open, setOpen] = useState(false);
  const ref = useOutsideClose(open, setOpen);
  const count = notifications?.count ?? 0;
  return (
    <div className="popover-anchor" ref={ref}>
      <button
        type="button"
        className="btn btn-ghost btn-icon"
        aria-label={`Notifikasi (${count})`}
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        style={{ position: 'relative' }}
      >
        <Bell size={19} />
        {count > 0 && (
          <span className="nav-count" style={{ position: 'absolute', top: 2, right: 2, height: 17, minWidth: 17, fontSize: 10 }}>
            {count > 99 ? '99+' : count}
          </span>
        )}
      </button>
      {open && (
        <div className="popover" role="dialog" aria-label="Notifikasi">
          <div className="menu-section-title">Perlu perhatian</div>
          {!count && <div className="state compact">Tidak ada yang perlu ditindaklanjuti.</div>}
          {notifications?.items.map((item, index) => {
            const Icon = NOTIFICATION_ICONS[item.type] ?? Bell;
            return (
              <Link key={index} to={item.url} className="menu-item" onClick={() => setOpen(false)}>
                <Icon size={16} className={item.severity === 'danger' ? 'text-danger' : 'text-warning'} style={{ marginTop: 3 }} aria-hidden="true" />
                <span className="stack-sm" style={{ gap: 0 }}>
                  <span>{item.title}</span>
                  <span className="cell-sub">{[item.subtitle, item.date && formatDate(item.date)].filter(Boolean).join(' · ')}</span>
                </span>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}

function UserMenu() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const ref = useOutsideClose(open, setOpen);
  return (
    <div className="popover-anchor" ref={ref}>
      <button type="button" className="btn btn-ghost" style={{ padding: '0 6px' }} aria-label="Menu akun" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
        <Avatar name={user.name} />
      </button>
      {open && (
        <div className="popover" style={{ width: 260 }}>
          <div style={{ padding: '8px 12px 12px' }}>
            <div style={{ fontWeight: 600 }}>{user.name}</div>
            <div className="cell-sub">{user.email}</div>
            <div className="cell-sub">{ROLE.labels[user.role]}</div>
          </div>
          <Link to="/settings" className="menu-item" onClick={() => setOpen(false)}>
            <Settings size={16} aria-hidden="true" /> Akun Saya
          </Link>
          <button type="button" className="menu-item" onClick={logout}>
            <LogOut size={16} aria-hidden="true" /> Keluar
          </button>
        </div>
      )}
    </div>
  );
}

/** Application shell: sidebar navigation (drawer on phones), header, content and bottom navigation. */
export function AppLayout() {
  const { user, can, logout } = useAuth();
  const location = useLocation();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [tick, setTick] = useState(0);
  // Re-checked on every navigation and periodically.
  const { data: notifications } = useApi('/notifications', undefined, { refreshKey: `${location.pathname}#${tick}` });
  const followUpBadge = notifications?.items.filter((item) => item.type.startsWith('FOLLOW_UP')).length ?? 0;

  useEffect(() => {
    const timer = setInterval(() => setTick((value) => value + 1), NOTIFICATION_REFRESH_MS);
    return () => clearInterval(timer);
  }, []);

  // Close the drawer after navigating.
  const [lastPath, setLastPath] = useState(location.pathname);
  if (lastPath !== location.pathname) {
    setLastPath(location.pathname);
    setDrawerOpen(false);
  }

  const badgeFor = (item) => (item.badge === 'followUps' && followUpBadge > 0 ? followUpBadge : null);
  const visible = (item) => !item.module || can(item.module);

  return (
    <div className="app-shell">
      <a href="#main-content" className="sr-only">
        Lewati ke konten
      </a>
      {drawerOpen && <div className="sidebar-backdrop" onClick={() => setDrawerOpen(false)} aria-hidden="true" />}
      <aside className={`sidebar ${drawerOpen ? 'open' : ''}`} aria-label="Navigasi utama">
        <div className="row-between">
          <Link to="/dashboard" className="brand">
            <span className="brand-mark">PIK</span>
            <span className="brand-name">
              <strong>Marketing Control</strong>
              <span>PT Permata Indo Kemas</span>
            </span>
          </Link>
          {drawerOpen && (
            <button type="button" className="btn btn-ghost btn-icon btn-sm" onClick={() => setDrawerOpen(false)} aria-label="Tutup menu">
              <X size={18} />
            </button>
          )}
        </div>
        <nav>
          {NAV_GROUPS.map((group) => {
            const items = group.items.filter(visible);
            if (!items.length) return null;
            return (
              <div key={group.title} className="nav-group">
                <div className="nav-group-title">{group.title}</div>
                {items.map((item) => {
                  const Icon = item.icon;
                  const badge = badgeFor(item);
                  return (
                    <NavLink key={item.to} to={item.to} end={item.end} className="nav-link">
                      <Icon size={17} aria-hidden="true" />
                      {item.label}
                      {badge && (
                        <span className="nav-count" aria-label={`${badge} perlu tindak lanjut`}>
                          {badge}
                        </span>
                      )}
                    </NavLink>
                  );
                })}
              </div>
            );
          })}
        </nav>
        <div className="sidebar-footer">
          <div className="row" style={{ padding: '0 8px', flexWrap: 'nowrap' }}>
            <Avatar name={user.name} />
            <div className="grow">
              <div className="truncate" style={{ fontSize: 13, fontWeight: 600 }}>
                {user.name}
              </div>
              <div className="cell-sub">{ROLE.labels[user.role]}</div>
            </div>
            <button type="button" className="btn btn-ghost btn-icon btn-sm" onClick={logout} aria-label="Keluar" title="Keluar">
              <LogOut size={16} />
            </button>
          </div>
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <button type="button" className="btn btn-ghost btn-icon mobile-only" onClick={() => setDrawerOpen(true)} aria-label="Buka menu">
            <Menu size={20} />
          </button>
          <Link to="/dashboard" className="brand mobile-only" style={{ padding: 0 }}>
            <span className="brand-mark" style={{ width: 30, height: 30, fontSize: 11 }}>
              PIK
            </span>
          </Link>
          <div className="desktop-only grow topbar-search">
            <GlobalSearch />
          </div>
          <div className="grow mobile-only" />
          <button type="button" className="btn btn-ghost btn-icon mobile-only" onClick={() => setSearchOpen(true)} aria-label="Cari">
            <Search size={19} />
          </button>
          <Notifications notifications={notifications} />
          <div className="desktop-only">
            <UserMenu />
          </div>
        </header>

        <main id="main-content" className="content">
          <Suspense fallback={<PageLoading />}>
            <Outlet />
          </Suspense>
        </main>
      </div>

      <nav className="bottom-nav" aria-label="Navigasi cepat">
        {BOTTOM_NAV.filter(visible).map((item) => {
          const Icon = item.icon;
          const badge = badgeFor(item);
          return (
            <NavLink key={item.to} to={item.to}>
              <Icon size={21} aria-hidden="true" />
              {item.label}
              {badge && <span className="badge-dot">{badge}</span>}
            </NavLink>
          );
        })}
        <button type="button" onClick={() => setDrawerOpen(true)}>
          <Menu size={21} aria-hidden="true" />
          Menu
        </button>
      </nav>

      <Modal open={searchOpen} onClose={() => setSearchOpen(false)} title="Cari">
        <GlobalSearch autoFocus onNavigate={() => setSearchOpen(false)} />
        <div style={{ height: 280 }} />
      </Modal>
    </div>
  );
}
