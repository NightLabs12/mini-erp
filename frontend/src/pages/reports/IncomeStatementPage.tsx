import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api, toErrorMessage } from '../../lib/api'
import { formatCurrency } from '../../lib/format'
import { Skeleton } from '../../components/ui/Skeleton'

interface IncomeLine {
  account_id: number
  code: string
  name: string
  amount: string
}

interface IncomeSection {
  lines: IncomeLine[]
  total: string
}

interface IncomeStatementResponse {
  data: {
    from: string
    to: string
    revenue: IncomeSection
    cogs: IncomeSection
    expense: IncomeSection
    gross_profit: string
    net_profit: string
    comparison: {
      from: string
      to: string
      totals: {
        revenue: string
        cogs: string
        gross_profit: string
        expense: string
        net_profit: string
      }
    }
  }
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function monthStart(): string {
  return `${today().slice(0, 7)}-01`
}

function Section({
  title,
  section,
}: {
  title: string
  section: IncomeSection
}) {
  return (
    <div>
      <h2 className="text-sm font-semibold">{title}</h2>
      {section.lines.length === 0 ? (
        <p className="mt-1 text-sm text-ink-muted">Tidak ada mutasi.</p>
      ) : (
        <ul className="mt-2 space-y-1">
          {section.lines.map((line) => (
            <li key={line.account_id} className="flex justify-between gap-3 border-b border-line/60 py-1 text-sm">
              <span>
                <span className="font-medium">{line.code}</span> {line.name}
              </span>
              <span className="tabular-nums">{formatCurrency(line.amount)}</span>
            </li>
          ))}
        </ul>
      )}
      <div className="mt-2 flex justify-between border-t border-line pt-1 text-sm font-semibold">
        <span>Total {title}</span>
        <span className="tabular-nums">{formatCurrency(section.total)}</span>
      </div>
    </div>
  )
}

export function IncomeStatementPage() {
  const [from, setFrom] = useState(monthStart())
  const [to, setTo] = useState(today())

  const query = useQuery({
    queryKey: ['report', 'income-statement', from, to],
    queryFn: () => api.get<IncomeStatementResponse>('/reports/income-statement', { from, to }),
  })

  return (
    <section className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Laba rugi</h1>
        <p className="mt-1 text-sm text-ink-muted">
          Pendapatan, HPP, beban, dan laba bersih dari jurnal posted.
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded-md border border-line bg-surface p-4">
        <label className="text-sm">
          <span className="mb-1 block font-medium">Dari</span>
          <input
            type="date"
            value={from}
            onChange={(event) => setFrom(event.target.value)}
            className="min-h-10 rounded-md border border-line px-3 text-sm"
          />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Sampai</span>
          <input
            type="date"
            value={to}
            onChange={(event) => setTo(event.target.value)}
            className="min-h-10 rounded-md border border-line px-3 text-sm"
          />
        </label>
      </div>

      {query.isPending ? (
        <Skeleton className="h-64" />
      ) : query.isError ? (
        <p role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
          {toErrorMessage(query.error)}
        </p>
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="space-y-4 rounded-md border border-line bg-surface p-4">
            <Section title="Pendapatan" section={query.data.data.revenue} />
            <Section title="HPP" section={query.data.data.cogs} />
            <Section title="Beban" section={query.data.data.expense} />
          </div>

          <div className="space-y-3 rounded-md border border-line bg-surface p-4">
            <h2 className="text-sm font-semibold">Ringkasan</h2>
            <div className="flex justify-between text-sm">
              <span className="text-ink-muted">Laba kotor</span>
              <span className="font-semibold tabular-nums">
                {formatCurrency(query.data.data.gross_profit)}
              </span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-ink-muted">Laba bersih</span>
              <span className="font-semibold tabular-nums">
                {formatCurrency(query.data.data.net_profit)}
              </span>
            </div>
            <div className="border-t border-line pt-2 text-xs text-ink-muted">
              Pembanding {query.data.data.comparison.from} s/d {query.data.data.comparison.to}: laba kotor{' '}
              {formatCurrency(query.data.data.comparison.totals.gross_profit)}
            </div>
          </div>
        </div>
      )}
    </section>
  )
}
