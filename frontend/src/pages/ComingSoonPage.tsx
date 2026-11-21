import { Link, useParams } from 'react-router-dom'
import { findNavItem } from '../layout/nav'
import { t } from '../lib/i18n'

export function ComingSoonPage() {
  const { moduleKey } = useParams<{ moduleKey: string }>()
  const item = moduleKey === undefined ? undefined : findNavItem(moduleKey)

  return (
    <section aria-labelledby="placeholder-title" className="max-w-2xl">
      <h1 id="placeholder-title" className="text-2xl font-semibold tracking-tight">
        {item?.label ?? t('placeholder.title')}
      </h1>
      <p className="mt-1 text-sm text-ink-muted">{t('placeholder.lead')}</p>

      <Link
        to="/dashboard"
        className="mt-6 inline-flex min-h-11 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted"
      >
        {t('placeholder.back')}
      </Link>
    </section>
  )
}
