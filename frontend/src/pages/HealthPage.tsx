import { useQuery } from '@tanstack/react-query'
import { ApiError, apiFetch } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { t } from '../lib/i18n'

interface HealthResponse {
  status: 'ok' | 'degraded'
  app: string
  version: string
  environment: string
  time: string
  checks: {
    database: string
  }
}

function StatusBadge({ ok }: { ok: boolean }) {
  return (
    <span
      className={[
        'inline-flex items-center gap-1.5 rounded border px-2 py-1 text-xs font-semibold',
        ok
          ? 'border-accent/30 bg-accent-soft text-accent'
          : 'border-danger/30 bg-danger-soft text-danger',
      ].join(' ')}
    >
      <span aria-hidden="true" className={ok ? 'size-1.5 rounded-full bg-accent' : 'size-1.5 rounded-full bg-danger'} />
      {ok ? t('health.ok') : t('health.degraded')}
    </span>
  )
}

function LoadingState() {
  return (
    <div aria-busy="true" aria-live="polite" className="space-y-3 p-5">
      <p className="sr-only">{t('health.loading')}</p>
      <div className="h-4 w-48 rounded bg-line/70 motion-safe:animate-pulse" />
      <div className="h-4 w-64 rounded bg-line/70 motion-safe:animate-pulse" />
      <div className="h-4 w-40 rounded bg-line/70 motion-safe:animate-pulse" />
    </div>
  )
}

function ErrorState({ error, onRetry, isRetrying }: { error: unknown; onRetry: () => void; isRetrying: boolean }) {
  const detail =
    error instanceof ApiError
      ? `${error.message} (${error.code})`
      : error instanceof Error
        ? error.message
        : 'Kesalahan tidak diketahui.'

  return (
    <div className="p-5" role="alert">
      <p className="font-medium text-danger">{t('health.errorTitle')}</p>
      <p className="mt-1 text-sm text-ink-muted">{detail}</p>
      <p className="mt-1 text-sm text-ink-muted">{t('health.errorHint')}</p>
      <button
        type="button"
        onClick={onRetry}
        disabled={isRetrying}
        className="mt-4 inline-flex min-h-11 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted disabled:opacity-60"
      >
        {isRetrying ? t('health.checking') : t('health.retry')}
      </button>
    </div>
  )
}

function HealthSummary({ data }: { data: HealthResponse }) {
  const rows = [
    { label: t('health.api'), value: t('health.ok') },
    { label: t('health.database'), value: data.checks.database === 'ok' ? t('health.ok') : data.checks.database },
    { label: t('health.version'), value: data.version },
    { label: t('health.environment'), value: data.environment },
    { label: t('health.serverTime'), value: formatDateTime(data.time) },
  ]

  return (
    <div className="p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-medium">{data.app}</p>
        <StatusBadge ok={data.status === 'ok'} />
      </div>

      <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
        {rows.map((row) => (
          <div key={row.label} className="flex items-baseline justify-between gap-3 border-b border-line pb-2">
            <dt className="text-sm text-ink-muted">{row.label}</dt>
            <dd className="text-sm font-medium tabular-nums">{row.value}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

export function HealthPage() {
  const query = useQuery({
    queryKey: ['health'],
    queryFn: () => apiFetch<HealthResponse>('/health'),
  })

  return (
    <section aria-labelledby="health-title" className="max-w-2xl">
      <h1 id="health-title" className="text-2xl font-semibold tracking-tight">
        {t('health.title')}
      </h1>
      <p className="mt-1 text-sm text-ink-muted">{t('health.lead')}</p>

      <div className="mt-6 rounded-md border border-line bg-surface">
        {query.isPending ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} isRetrying={query.isFetching} />
        ) : (
          <HealthSummary data={query.data} />
        )}
      </div>
    </section>
  )
}
