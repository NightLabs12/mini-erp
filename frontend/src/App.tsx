import { NavLink, Outlet } from 'react-router-dom'
import { t } from './lib/i18n'

export function App() {
  return (
    <div className="flex min-h-dvh flex-col">
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex w-full max-w-4xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div>
            <p className="text-sm font-semibold tracking-tight">{t('app.company')}</p>
            <p className="text-xs text-ink-muted">
              {t('app.name')} · {t('app.tagline')}
            </p>
          </div>
          <nav aria-label="Navigasi utama">
            <NavLink
              to="/"
              end
              className={({ isActive }) =>
                [
                  'inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium',
                  isActive ? 'bg-accent-soft text-accent' : 'text-ink-muted hover:text-ink',
                ].join(' ')
              }
            >
              {t('nav.status')}
            </NavLink>
          </nav>
        </div>
      </header>

      <main className="mx-auto w-full max-w-4xl flex-1 px-4 py-8">
        <Outlet />
      </main>

      <footer className="border-t border-line px-4 py-4">
        <p className="mx-auto w-full max-w-4xl text-xs text-ink-muted">{t('footer.phase')}</p>
      </footer>
    </div>
  )
}
