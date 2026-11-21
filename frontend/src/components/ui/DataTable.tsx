import { flexRender, getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table'
import clsx from 'clsx'
import { useMemo, type ReactNode } from 'react'
import { t } from '../../lib/i18n'
import { EmptyState } from './EmptyState'
import { Skeleton } from './Skeleton'
import type { DataTableColumn } from './data-table.types'

interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  isLoading?: boolean
  searchValue?: string
  onSearchChange?: (value: string) => void
  searchPlaceholder?: string
  sort?: string
  onSortChange?: (sort: string) => void
  page: number
  perPage: number
  total: number
  onPageChange: (page: number) => void
  toolbar?: ReactNode
  emptyTitle?: string
  emptyDescription?: string
}

const alignClass = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
} as const

function nextSort(columnId: string, current: string | undefined): string {
  if (current === columnId) {
    return `-${columnId}`
  }

  if (current === `-${columnId}`) {
    return columnId
  }

  return columnId
}

function sortIndicator(columnId: string, current: string | undefined): string {
  if (current === columnId) {
    return '▲'
  }

  if (current === `-${columnId}`) {
    return '▼'
  }

  return ''
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  isLoading = false,
  searchValue,
  onSearchChange,
  searchPlaceholder = t('common.search'),
  sort,
  onSortChange,
  page,
  perPage,
  total,
  onPageChange,
  toolbar,
  emptyTitle = t('common.emptyTitle'),
  emptyDescription = t('common.emptyDescription'),
}: DataTableProps<T>) {
  const tableColumns = useMemo<ColumnDef<T>[]>(
    () =>
      columns.map((column) => ({
        id: column.id,
        header: column.header,
        cell: ({ row }) => column.render(row.original),
      })),
    [columns],
  )

  const table = useReactTable({
    data: rows,
    columns: tableColumns,
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row) => String(rowKey(row)),
  })

  const pageCount = Math.max(1, Math.ceil(total / Math.max(perPage, 1)))
  const firstRow = total === 0 ? 0 : (page - 1) * perPage + 1
  const lastRow = Math.min(page * perPage, total)

  return (
    <div className="rounded-md border border-line bg-surface">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        {onSearchChange ? (
          <label className="relative block w-full max-w-xs">
            <span className="sr-only">{t('common.search')}</span>
            <input
              type="search"
              value={searchValue ?? ''}
              onChange={(event) => onSearchChange(event.target.value)}
              placeholder={searchPlaceholder}
              className="min-h-10 w-full rounded-md border border-line bg-surface pl-9 pr-3 text-sm placeholder:text-ink-muted/70 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            />
            <svg
              aria-hidden="true"
              viewBox="0 0 20 20"
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted"
              fill="currentColor"
            >
              <path
                fillRule="evenodd"
                d="M9 3.5a5.5 5.5 0 1 0 3.4 9.83l3.14 3.14a.75.75 0 1 0 1.06-1.06l-3.14-3.14A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z"
                clipRule="evenodd"
              />
            </svg>
          </label>
        ) : (
          <span />
        )}

        {toolbar ? <div className="flex flex-wrap items-center gap-2">{toolbar}</div> : null}
      </div>

      <div className="overflow-x-auto">
        <table className="min-w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-line bg-paper/60">
              {columns.map((column) => {
                const isSorted = sort === column.id || sort === `-${column.id}`

                return (
                  <th
                    key={column.id}
                    scope="col"
                    className={clsx(
                      'whitespace-nowrap px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-muted',
                      alignClass[column.align ?? 'left'],
                      column.className,
                    )}
                    aria-sort={
                      isSorted ? (sort === column.id ? 'ascending' : 'descending') : 'none'
                    }
                  >
                    {column.sortable && onSortChange ? (
                      <button
                        type="button"
                        onClick={() => onSortChange(nextSort(column.id, sort))}
                        className="inline-flex items-center gap-1 rounded px-1 py-0.5 hover:text-ink"
                      >
                        {column.header}
                        <span aria-hidden="true" className="text-[10px]">
                          {sortIndicator(column.id, sort)}
                        </span>
                      </button>
                    ) : (
                      column.header
                    )}
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              Array.from({ length: Math.min(perPage, 5) }, (_, index) => (
                <tr key={index} className="border-b border-line/70">
                  {columns.map((column) => (
                    <td key={column.id} className="px-4 py-3">
                      <Skeleton className="h-4 w-full max-w-32" />
                    </td>
                  ))}
                </tr>
              ))
            ) : table.getRowModel().rows.length === 0 ? (
              <tr>
                <td colSpan={Math.max(columns.length, 1)}>
                  <EmptyState title={emptyTitle} description={emptyDescription} />
                </td>
              </tr>
            ) : (
              table.getRowModel().rows.map((row) => (
                <tr key={row.id} className="border-b border-line/70 last:border-b-0 hover:bg-paper/50">
                  {row.getVisibleCells().map((cell) => {
                    const column = columns.find((item) => item.id === cell.column.id)

                    return (
                      <td
                        key={cell.id}
                        className={clsx(
                          'px-4 py-3 align-middle',
                          alignClass[column?.align ?? 'left'],
                          column?.className,
                        )}
                      >
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    )
                  })}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm text-ink-muted">
        <p className="tabular-nums">
          {firstRow}–{lastRow} {t('common.of')} {total} {t('common.rows')}
        </p>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => onPageChange(page - 1)}
            disabled={page <= 1 || isLoading}
            className="inline-flex min-h-9 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted disabled:opacity-50"
          >
            {t('common.prev')}
          </button>
          <span className="tabular-nums">
            {t('common.page')} {page} {t('common.of')} {pageCount}
          </span>
          <button
            type="button"
            onClick={() => onPageChange(page + 1)}
            disabled={page >= pageCount || isLoading}
            className="inline-flex min-h-9 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted disabled:opacity-50"
          >
            {t('common.next')}
          </button>
        </div>
      </div>
    </div>
  )
}
