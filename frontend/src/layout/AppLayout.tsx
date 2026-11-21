import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/auth-context'
import { useToast } from '../components/ui/toast-context'
import { t } from '../lib/i18n'
import { navigation } from './nav'

function Brand() {
  return (
    <div className="px-2">
      <p className="text-sm font-semibold tracking-tight">{t('app.company')}</p>
      <p className="text-xs text-ink-muted">
        {t('app.name')} · {t('app.tagline')}
      </p>
    </div>
  )
}

function SidebarContent({ onNavigate }: { onNavigate?: () => void }) {
  const { hasPermission } = useAuth()

  return (
    <nav aria-label="Navigasi utama" className="space-y-5">
      {navigation.map((group) => {
        const items = group.items.filter((item) => hasPermission(item.permission))

        if (items.length === 0) {
          return null
        }

        return (
          <div key={group.key}>
            <p className="px-2 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
              {group.label}
            </p>
            <ul className="mt-1.5 space-y-0.5">
              {items.map((item) => (
                <li key={item.key}>
                  <NavLink
                    to={item.to}
                    onClick={onNavigate}
                    className={({ isActive }) =>
                      [
                        'flex min-h-9 items-center rounded-md px-2 text-sm',
                        isActive
                          ? 'bg-accent-soft font-semibold text-accent'
                          : 'text-ink-muted hover:bg-paper hover:text-ink',
                      ].join(' ')
                    }
                  >
                    {item.label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
    </nav>
  )
}

export function AppLayout() {
  const { user, logout } = useAuth()
  const toast = useToast()
  const [menuOpen, setMenuOpen] = useState(false)

  const handleLogout = async (): Promise<void> => {
    await logout()
    toast.push('Anda telah keluar.', 'info')
  }

  return (
    <div className="min-h-dvh lg:flex">
      <aside className="hidden w-64 shrink-0 border-r border-line bg-surface lg:sticky lg:top-0 lg:block lg:h-dvh lg:overflow-y-auto">
        <div className="border-b border-line py-4">
          <Brand />
        </div>
        <div className="px-2 py-4">
          <SidebarContent />
        </div>
      </aside>

      {menuOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div
            className="absolute inset-0 bg-ink/40"
            onClick={() => setMenuOpen(false)}
            aria-hidden="true"
          />
          <div className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col border-r border-line bg-surface shadow-xl">
            <div className="flex items-center justify-between border-b border-line px-3 py-3">
              <Brand />
              <button
                type="button"
                onClick={() => setMenuOpen(false)}
                aria-label={t('nav.closeMenu')}
                className="rounded p-2 text-ink-muted hover:bg-paper hover:text-ink"
              >
                <svg aria-hidden="true" viewBox="0 0 20 20" className="size-4" fill="currentColor">
                  <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                </svg>
              </button>
            </div>
            <div className="flex-1 overflow-y-auto px-2 py-4">
              <SidebarContent onNavigate={() => setMenuOpen(false)} />
            </div>
          </div>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur">
          <div className="flex min-h-14 items-center justify-between gap-3 px-4">
            <button
              type="button"
              onClick={() => setMenuOpen(true)}
              aria-label={t('nav.menu')}
              aria-expanded={menuOpen}
              className="rounded-md border border-line p-2 text-ink-muted hover:text-ink lg:hidden"
            >
              <svg aria-hidden="true" viewBox="0 0 20 20" className="size-5" fill="currentColor">
                <path d="M2.75 5.5h14.5a.75.75 0 0 0 0-1.5H2.75a.75.75 0 0 0 0 1.5Zm0 5h14.5a.75.75 0 0 0 0-1.5H2.75a.75.75 0 0 0 0 1.5Zm0 5h14.5a.75.75 0 0 0 0-1.5H2.75a.75.75 0 0 0 0 1.5Z" />
              </svg>
            </button>

            <div className="min-w-0 flex-1 lg:pl-1">
              <p className="truncate text-sm font-semibold">{user?.name ?? '—'}</p>
              <p className="truncate text-xs text-ink-muted">
                {user?.roles.join(', ') ?? ''} · {user?.email ?? ''}
              </p>
            </div>

            <button
              type="button"
              onClick={() => void handleLogout()}
              className="inline-flex min-h-9 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted"
            >
              {t('nav.logout')}
            </button>
          </div>
        </header>

        <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
          <Outlet />
        </main>

        <footer className="border-t border-line px-4 py-3">
          <p className="mx-auto w-full max-w-6xl text-xs text-ink-muted">{t('footer.phase')}</p>
        </footer>
      </div>
    </div>
  )
}
