import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/auth-context'
import { api, toErrorMessage } from '../../lib/api'
import type { ApiCollection } from '../../lib/types'
import { DataTable } from '../ui/DataTable'
import type { DataTableColumn } from '../ui/data-table.types'

interface DocumentListPageProps<T extends { id: number }> {
  title: string
  description?: string
  resource: string
  permissionPrefix: string
  columns: DataTableColumn<T>[]
  detailHref: (record: T) => string
  createHref?: string
  createLabel?: string
  defaultSort?: string
  statusFilters?: string[]
  toolbar?: ReactNode
}

export function DocumentListPage<T extends { id: number }>({
  title,
  description,
  resource,
  permissionPrefix,
  columns,
  detailHref,
  createHref,
  createLabel = 'Dokumen baru',
  defaultSort = '-doc_date',
  statusFilters,
  toolbar,
}: DocumentListPageProps<T>) {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [sort, setSort] = useState(defaultSort)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(search)
      setPage(1)
    }, 300)

    return () => clearTimeout(timer)
  }, [search])

  const query = useQuery({
    queryKey: ['documents', resource, { search: debounced, sort, page, status }],
    queryFn: () =>
      api.get<ApiCollection<T>>(resource, {
        search: debounced === '' ? undefined : debounced,
        sort,
        page,
        per_page: 15,
        'filter[status]': status === '' ? undefined : status,
      }),
    placeholderData: keepPreviousData,
  })

  const withDetail: DataTableColumn<T>[] = [
    ...columns,
    {
      id: '__open',
      header: '',
      align: 'right',
      render: (record) => (
        <Link to={detailHref(record)} className="text-xs font-semibold text-accent hover:underline">
          Buka
        </Link>
      ),
    },
  ]

  const meta = query.data?.meta

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {description ? <p className="mt-1 text-sm text-ink-muted">{description}</p> : null}
        </div>

        {createHref !== undefined && hasPermission(`${permissionPrefix}.create`) ? (
          <Link
            to={createHref}
            className="inline-flex min-h-10 items-center rounded-md bg-accent px-4 text-sm font-semibold text-white hover:bg-accent/90"
          >
            {createLabel}
          </Link>
        ) : null}
      </div>

      {statusFilters !== undefined && statusFilters.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {[{ value: '', label: 'Semua' }, ...statusFilters.map((value) => ({ value, label: value }))].map(
            (option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => {
                  setStatus(option.value)
                  setPage(1)
                }}
                className={[
                  'inline-flex min-h-8 items-center rounded-full border px-3 text-xs font-semibold capitalize',
                  status === option.value
                    ? 'border-accent bg-accent-soft text-accent'
                    : 'border-line bg-surface text-ink-muted hover:text-ink',
                ].join(' ')}
              >
                {option.label}
              </button>
            ),
          )}
        </div>
      ) : null}

      {query.isError ? (
        <div role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
          {toErrorMessage(query.error)}
        </div>
      ) : null}

      <DataTable
        columns={withDetail}
        rows={query.data?.data ?? []}
        rowKey={(record) => record.id}
        isLoading={query.isPending}
        searchValue={search}
        onSearchChange={setSearch}
        sort={sort}
        onSortChange={(next) => {
          setSort(next)
          setPage(1)
        }}
        page={meta?.current_page ?? page}
        perPage={meta?.per_page ?? 15}
        total={meta?.total ?? 0}
        onPageChange={setPage}
        toolbar={toolbar}
        searchPlaceholder="Cari nomor dokumen..."
      />
    </section>
  )
}
