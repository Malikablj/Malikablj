import { Menu as MenuIcon, Search, X } from 'lucide-react';
import { type ReactNode, Suspense, useEffect, useRef, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { useLocalPref } from '@/hooks/useUtils';
import { cn } from '@/lib/utils';
import { IconButton } from '../ui/Button';
import { PageSkeleton } from '../ui/Feedback';
import { GlobalSearch } from './GlobalSearch';
import { NotificationBell } from './NotificationPanel';
import { BrandMark, Sidebar, SidebarNav, UserCard } from './Sidebar';

function MobileDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (open && !el.open) el.showModal();
    if (!open && el.open) el.close();
  }, [open]);
  return (
    <dialog
      ref={ref}
      aria-label="Menu navigasi"
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      onClick={(e) => e.target === ref.current && onClose()}
      className="m-0 h-[100dvh] max-h-none w-[300px] max-w-[86vw] animate-slide-left bg-surface p-0 text-ink shadow-pop"
    >
      <div className="flex h-full flex-col">
        <div className="flex h-16 items-center justify-between px-4 pt-[env(safe-area-inset-top)]">
          <BrandMark />
          <IconButton label="Tutup menu" onClick={onClose}>
            <X className="size-5" />
          </IconButton>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto px-3 pb-3">
          <SidebarNav onNavigate={onClose} />
        </div>
        <div className="border-t border-line p-3 pb-[max(12px,env(safe-area-inset-bottom))]">
          <UserCard />
        </div>
      </div>
    </dialog>
  );
}

export function AppShell({ children }: { children?: ReactNode }) {
  const [collapsed, setCollapsed] = useLocalPref('sidebar-collapsed', typeof window !== 'undefined' && window.innerWidth < 1024);
  const [drawer, setDrawer] = useState(false);
  const [mobileSearch, setMobileSearch] = useState(false);
  const location = useLocation();
  const mainRef = useRef<HTMLElement>(null);

  useEffect(() => {
    setDrawer(false);
    setMobileSearch(false);
    window.scrollTo({ top: 0 });
  }, [location.pathname]);

  return (
    <div className="min-h-dvh">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[70] focus:rounded-lg focus:bg-surface focus:px-3 focus:py-2 focus:shadow-pop">
        Lewati ke konten
      </a>
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed(!collapsed)} />
      <MobileDrawer open={drawer} onClose={() => setDrawer(false)} />
      <div className={cn('transition-[padding] duration-200', collapsed ? 'md:pl-[72px]' : 'md:pl-[248px]')}>
        <header className="no-print sticky top-0 z-20 border-b border-line bg-surface/85 backdrop-blur-xl pt-[env(safe-area-inset-top)]">
          <div className="mx-auto flex h-14 max-w-[1440px] items-center gap-2 px-4 md:h-16 md:px-6 lg:px-8">
            <IconButton label="Buka menu" className="md:hidden -ml-2" onClick={() => setDrawer(true)}>
              <MenuIcon className="size-5" />
            </IconButton>
            <div className="md:hidden">
              <BrandMark compact />
            </div>
            {mobileSearch ? (
              <GlobalSearch className="flex-1" autoFocus onDone={() => setMobileSearch(false)} />
            ) : (
              <GlobalSearch className="hidden max-w-md flex-1 md:block" />
            )}
            <div className="ml-auto flex items-center gap-1">
              <IconButton label={mobileSearch ? 'Tutup pencarian' : 'Cari project'} className="md:hidden" onClick={() => setMobileSearch((s) => !s)}>
                {mobileSearch ? <X className="size-5" /> : <Search className="size-[18px]" />}
              </IconButton>
              <NotificationBell />
            </div>
          </div>
        </header>
        <main id="main" ref={mainRef} tabIndex={-1} className="mx-auto max-w-[1440px] px-4 pt-5 pb-28 outline-none md:px-6 md:pt-7 md:pb-12 lg:px-8">
          <Suspense fallback={<PageSkeleton />}>{children ?? <Outlet />}</Suspense>
        </main>
      </div>
    </div>
  );
}
