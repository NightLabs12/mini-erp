import { Link } from 'react-router-dom'
import { t } from '../lib/i18n'

export function NotFoundPage() {
  return (
    <section aria-labelledby="not-found-title">
      <h1 id="not-found-title" className="text-2xl font-semibold tracking-tight">
        {t('notFound.title')}
      </h1>
      <p className="mt-1 text-sm text-ink-muted">{t('notFound.lead')}</p>
      <Link
        to="/"
        className="mt-6 inline-flex min-h-11 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted"
      >
        {t('notFound.back')}
      </Link>
    </section>
  )
}
