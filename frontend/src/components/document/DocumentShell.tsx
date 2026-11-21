import { useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/auth-context'
import { api } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import type { ApiCollection } from '../../lib/types'
import { Skeleton } from '../ui/Skeleton'
import { StatusBadge } from '../ui/StatusBadge'

export interface DocumentAction {
  key: string
  label: string
  permission: string
  tone?: 'primary' | 'danger' | 'neutral'
  confirmMessage?: string
  disabled?: boolean
}

export interface BusinessError {
  code: string
  message: string
  errors: Record<string, string[]>
}

interface AuditEntry {
  id: number
  event: string
  user?: { id: number; name: string; email: string } | null
  diff?: Record<string, { old: unknown; new: unknown }>
  new_values?: Record<string, unknown>
  created_at?: string | null
}

interface DocumentShellProps {
  title: string
  docNo?: string | null
  status?: string | null
  backTo?: string
  actions?: DocumentAction[]
  onAction?: (key: string) => void
  busyAction?: string | null
  businessError?: BusinessError | null
  auditResource?: string
  auditId?: number | null
  header?: ReactNode
  children: ReactNode
  footer?: ReactNode
}

const toneClass = {
  primary: 'bg-accent text-white hover:bg-accent/90',
  danger: 'border border-danger/40 bg-surface text-danger hover:bg-danger-soft',
  neutral: 'border border-line bg-surface text-ink hover:border-ink-muted',
} as const

function AuditPanel({ resource, id }: { resource: string; id: number }) {
  const [open, setOpen] = useState(false)

  const query = useQuery({
    queryKey: ['audit', resource, id],
    queryFn: () => api.get<ApiCollection<AuditEntry>>(`${resource}/${id}/audit`, { per_page: 50 }),
    enabled: open,
  })

  return (
    <div className="rounded-md border border-line bg-surface">
      <button
        type="button"
        onClick={() => setOpen((current) => !current)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-2 px-4 py-3 text-sm font-semibold"
      >
        Riwayat audit
        <span aria-hidden="true" className="text-xs text-ink-muted">
          {open ? '▲' : '▼'}
        </span>
      </button>

      {open ? (
        <div className="border-t border-line px-4 py-3">
          {query.isPending ? (
            <div className="space-y-2">
              <Skeleton className="h-4 w-2/3" />
              <Skeleton className="h-4 w-1/2" />
            </div>
          ) : query.isError ? (
            <p role="alert" className="text-sm text-danger">
              Gagal memuat riwayat audit.
            </p>
          ) : query.data.data.length === 0 ? (
            <p className="text-sm text-ink-muted">Belum ada riwayat.</p>
          ) : (
            <ol className="space-y-3">
              {query.data.data.map((entry) => (
                <li key={entry.id} className="border-b border-line/70 pb-2 last:border-b-0 last:pb-0">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm font-medium">{entry.event}</span>
                    <span className="text-xs text-ink-muted">
                      {entry.created_at ? formatDateTime(entry.created_at) : ''}
                    </span>
                  </div>
                  <p className="text-xs text-ink-muted">{entry.user?.name ?? 'Sistem'}</p>
                  {entry.diff && Object.keys(entry.diff).length > 0 ? (
                    <ul className="mt-1 space-y-0.5 text-xs text-ink-muted">
                      {Object.entries(entry.diff).map(([field, change]) => (
                        <li key={field}>
                          <span className="font-medium">{field}</span>: {String(change.old ?? '—')} →{' '}
                          {String(change.new ?? '—')}
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </li>
              ))}
            </ol>
          )}
        </div>
      ) : null}
    </div>
  )
}

export function DocumentShell({
  title,
  docNo,
  status,
  backTo,
  actions = [],
  onAction,
  busyAction,
  businessError,
  auditResource,
  auditId,
  header,
  children,
  footer,
}: DocumentShellProps) {
  const { hasPermission } = useAuth()

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          {backTo !== undefined ? (
            <Link to={backTo} className="text-xs font-medium text-accent hover:underline">
              ← Kembali ke daftar
            </Link>
          ) : null}
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            {status !== undefined && status !== null ? <StatusBadge status={status} /> : null}
          </div>
          {docNo !== undefined && docNo !== null ? (
            <p className="mt-1 text-sm text-ink-muted">{docNo}</p>
          ) : null}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {actions
            .filter((action) => hasPermission(action.permission))
            .map((action) => (
              <button
                key={action.key}
                type="button"
                disabled={busyAction !== null || action.disabled === true}
                onClick={() => onAction?.(action.key)}
                className={[
                  'inline-flex min-h-10 items-center rounded-md px-3 text-sm font-semibold disabled:opacity-50',
                  toneClass[action.tone ?? 'neutral'],
                ].join(' ')}
              >
                {busyAction === action.key ? 'Memproses...' : action.label}
              </button>
            ))}
        </div>
      </div>

      {businessError ? (
        <div role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-3">
          <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-danger">
            <span className="rounded bg-danger px-1.5 py-0.5 text-xs font-mono text-white">
              {businessError.code}
            </span>
            {businessError.message}
          </p>
          {Object.keys(businessError.errors).length > 0 ? (
            <ul className="mt-2 list-disc space-y-0.5 pl-5 text-sm text-danger">
              {Object.entries(businessError.errors).flatMap(([field, messages]) =>
                messages.map((message) => (
                  <li key={`${field}-${message}`}>
                    <span className="font-medium">{field}</span>: {message}
                  </li>
                )),
              )}
            </ul>
          ) : null}
        </div>
      ) : null}

      {header}

      {children}

      {footer}

      {auditResource !== undefined && auditId !== undefined && auditId !== null ? (
        <AuditPanel resource={auditResource} id={auditId} />
      ) : null}
    </section>
  )
}
