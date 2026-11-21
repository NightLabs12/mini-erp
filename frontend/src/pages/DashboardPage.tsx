import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Can } from '../auth/Can'
import { DataTable } from '../components/ui/DataTable'
import { EmptyState } from '../components/ui/EmptyState'
import { Skeleton, SkeletonText } from '../components/ui/Skeleton'
import type { DataTableColumn } from '../components/ui/data-table.types'
import { api, toErrorMessage } from '../lib/api'
import { formatCurrency, formatMonth, formatQuantity } from '../lib/format'
import { t } from '../lib/i18n'

interface LowStockItem {
  id: number
  sku: string
  name: string
  min_stock: string
  qty_base: string
  base_unit_code: string
}

interface DashboardSummary {
  period: { from: string; to: string }
  sales_this_month: string
  purchases_this_month: string
  inventory_value: string
  ap_outstanding: string
  ar_outstanding: string
  sales_series: { month: string; total: string }[]
  low_stock_items: LowStockItem[]
  pending_approvals: { total: number; by_resource: Record<string, number> }
}

interface DashboardResponse {
  data: DashboardSummary
}

const lowStockColumns: DataTableColumn<LowStockItem>[] = [
  { id: 'sku', header: 'SKU', render: (row) => <span className="font-medium">{row.sku}</span> },
  { id: 'name', header: 'Item', render: (row) => row.name },
  {
    id: 'qty',
    header: 'Stok',
    align: 'right',
    render: (row) => `${formatQuantity(row.qty_base)} ${row.base_unit_code}`,
  },
  {
    id: 'min',
    header: 'Minimum',
    align: 'right',
    render: (row) => `${formatQuantity(row.min_stock)} ${row.base_unit_code}`,
  },
]

interface MetricCardProps {
  label: string
  value: string
  tone?: 'default' | 'accent' | 'danger'
}

function MetricCard({ label, value, tone = 'default' }: MetricCardProps) {
  const valueClass =
    tone === 'accent' ? 'text-accent' : tone === 'danger' ? 'text-danger' : 'text-ink'

  return (
    <div className="rounded-md border border-line bg-surface p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-ink-muted">{label}</p>
      <p className={`mt-2 text-lg font-semibold tabular-nums ${valueClass}`}>{value}</p>
    </div>
  )
}

function resourceLabel(resource: string): string {
  return resource
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

export function DashboardPage() {
  const query = useQuery({
    queryKey: ['dashboard', 'summary'],
    queryFn: async () => {
      const response = await api.get<DashboardResponse>('/dashboard/summary')

      return response.data
    },
  })

  if (query.isPending) {
    return (
      <section aria-busy="true" aria-live="polite" className="space-y-6">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t('dashboard.title')}</h1>
          <p className="mt-1 text-sm text-ink-muted">{t('dashboard.lead')}</p>
        </div>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          {Array.from({ length: 5 }, (_, index) => (
            <Skeleton key={index} className="h-24" />
          ))}
        </div>
        <Skeleton className="h-72" />
        <SkeletonText lines={4} />
      </section>
    )
  }

  if (query.isError) {
    return (
      <section role="alert" className="rounded-md border border-danger/30 bg-danger-soft p-5">
        <p className="font-semibold text-danger">{t('dashboard.title')}</p>
        <p className="mt-1 text-sm text-danger">{toErrorMessage(query.error)}</p>
        <button
          type="button"
          onClick={() => void query.refetch()}
          className="mt-3 inline-flex min-h-9 items-center rounded-md border border-danger/40 bg-surface px-3 text-sm font-medium text-danger"
        >
          {t('common.retry')}
        </button>
      </section>
    )
  }

  const data = query.data
  const chartData = data.sales_series.map((point) => ({
    month: formatMonth(point.month),
    total: Number.parseFloat(point.total),
  }))
  const hasSales = chartData.some((point) => point.total > 0)
  const pendingEntries = Object.entries(data.pending_approvals.by_resource)

  return (
    <section className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t('dashboard.title')}</h1>
          <p className="mt-1 text-sm text-ink-muted">{t('dashboard.lead')}</p>
        </div>

        <Can permission="report.balance-sheet.view">
          <Link
            to="/reports/income-statement"
            className="inline-flex min-h-9 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted"
          >
            {t('dashboard.reportLink')}
          </Link>
        </Can>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <MetricCard label={t('dashboard.sales')} value={formatCurrency(data.sales_this_month)} tone="accent" />
        <MetricCard label={t('dashboard.purchases')} value={formatCurrency(data.purchases_this_month)} />
        <MetricCard label={t('dashboard.inventory')} value={formatCurrency(data.inventory_value)} />
        <MetricCard label={t('dashboard.ap')} value={formatCurrency(data.ap_outstanding)} tone="danger" />
        <MetricCard label={t('dashboard.ar')} value={formatCurrency(data.ar_outstanding)} />
      </div>

      <div className="rounded-md border border-line bg-surface p-4">
        <h2 className="text-sm font-semibold">{t('dashboard.chart')}</h2>

        {hasSales ? (
          <div className="mt-4 h-64 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <defs>
                  <linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#0d5c4f" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="#0d5c4f" stopOpacity={0.02} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#d8d8d0" vertical={false} />
                <XAxis dataKey="month" tickLine={false} axisLine={false} fontSize={12} />
                <YAxis
                  tickLine={false}
                  axisLine={false}
                  fontSize={12}
                  width={80}
                  tickFormatter={(value: number) => formatCurrency(value)}
                />
                <Tooltip formatter={(value) => formatCurrency(Number(value))} />
                <Area
                  type="monotone"
                  dataKey="total"
                  name="Penjualan"
                  stroke="#0d5c4f"
                  strokeWidth={2}
                  fill="url(#salesFill)"
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        ) : (
          <EmptyState title={t('dashboard.chartEmpty')} />
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <h2 className="mb-2 text-sm font-semibold">{t('dashboard.lowStock')}</h2>
          <DataTable
            columns={lowStockColumns}
            rows={data.low_stock_items}
            rowKey={(row) => row.id}
            page={1}
            perPage={20}
            total={data.low_stock_items.length}
            onPageChange={() => undefined}
            emptyTitle={t('dashboard.lowStockEmpty')}
            emptyDescription={undefined}
          />
        </div>

        <div className="rounded-md border border-line bg-surface p-4">
          <h2 className="text-sm font-semibold">{t('dashboard.pending')}</h2>

          {pendingEntries.length === 0 ? (
            <EmptyState title={t('dashboard.pendingEmpty')} />
          ) : (
            <>
              <p className="mt-3 text-xs text-ink-muted">{t('dashboard.pendingTotal')}</p>
              <p className="text-2xl font-semibold tabular-nums">{data.pending_approvals.total}</p>
              <ul className="mt-3 space-y-2">
                {pendingEntries.map(([resource, count]) => (
                  <li
                    key={resource}
                    className="flex items-center justify-between gap-2 border-b border-line/70 pb-2 text-sm last:border-b-0"
                  >
                    <span className="text-ink-muted">{resourceLabel(resource)}</span>
                    <span className="font-semibold tabular-nums">{count}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      </div>
    </section>
  )
}
