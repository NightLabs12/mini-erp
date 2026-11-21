import clsx from 'clsx'
import { useEffect, useRef, useState, type ReactNode } from 'react'

export interface GridColumn<Row> {
  key: keyof Row & string
  header: string
  type: 'text' | 'number' | 'select' | 'readonly' | 'lookup'
  width?: string
  align?: 'left' | 'right' | 'center'
  placeholder?: string
  step?: string
  options?: { value: string; label: string }[]
  optionsFor?: (row: Row) => { value: string; label: string }[]
  editable?: boolean
  render?: (row: Row, index: number) => ReactNode
  label?: (row: Row) => string
}

interface EditableGridProps<Row extends { key: string }> {
  columns: GridColumn<Row>[]
  rows: Row[]
  onChange: (rows: Row[]) => void
  newRow: () => Row
  readOnly?: boolean
  addLabel?: string
  emptyMessage?: string
  onLookup?: (rowIndex: number, columnKey: string) => void
  footer?: ReactNode
  minRows?: number
}

const alignClass = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
} as const

export function EditableGrid<Row extends { key: string }>({
  columns,
  rows,
  onChange,
  newRow,
  readOnly = false,
  addLabel = 'Tambah baris',
  emptyMessage = 'Belum ada baris.',
  onLookup,
  footer,
  minRows = 1,
}: EditableGridProps<Row>) {
  const [pendingFocus, setPendingFocus] = useState<{ row: number; column: string } | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (pendingFocus === null) {
      return
    }

    const selector = `[data-cell="${pendingFocus.row}:${pendingFocus.column}"]`
    const element = containerRef.current?.querySelector<HTMLElement>(selector)
    element?.focus()
    setPendingFocus(null)
  }, [pendingFocus, rows])

  const updateCell = (index: number, key: keyof Row & string, value: string): void => {
    const next = rows.map((row, rowIndex) =>
      rowIndex === index ? { ...row, [key]: value } : row,
    )

    onChange(next)
  }

  const addRow = (): void => {
    const next = [...rows, newRow()]
    onChange(next)
    setPendingFocus({ row: next.length - 1, column: columns[0]?.key ?? '' })
  }

  const removeRow = (index: number): void => {
    if (rows.length <= minRows) {
      return
    }

    onChange(rows.filter((_, rowIndex) => rowIndex !== index))
  }

  const focusRelative = (rowIndex: number, columnKey: string, delta: number): void => {
    const target = rowIndex + delta

    if (target < 0) {
      return
    }

    if (target >= rows.length) {
      addRow()

      return
    }

    setPendingFocus({ row: target, column: columnKey })
  }

  return (
    <div className="rounded-md border border-line bg-surface" ref={containerRef}>
      <div className="overflow-x-auto">
        <table className="min-w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-line bg-paper/60">
              <th scope="col" className="w-10 px-2 py-2 text-xs font-semibold text-ink-muted">
                #
              </th>
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  style={column.width === undefined ? undefined : { width: column.width }}
                  className={clsx(
                    'whitespace-nowrap px-2 py-2 text-xs font-semibold uppercase tracking-wide text-ink-muted',
                    alignClass[column.align ?? 'left'],
                  )}
                >
                  {column.header}
                </th>
              ))}
              {readOnly ? null : (
                <th scope="col" className="w-16 px-2 py-2 text-xs font-semibold text-ink-muted">
                  Aksi
                </th>
              )}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length + (readOnly ? 1 : 2)} className="px-3 py-6 text-center text-sm text-ink-muted">
                  {emptyMessage}
                </td>
              </tr>
            ) : (
              rows.map((row, rowIndex) => (
                <tr key={row.key} className="border-b border-line/70 last:border-b-0">
                  <td className="px-2 py-1 text-center text-xs text-ink-muted tabular-nums">{rowIndex + 1}</td>
                  {columns.map((column) => {
                    const cellClass = clsx(
                      'px-1 py-1 align-middle',
                      alignClass[column.align ?? 'left'],
                    )
                    const value = String(row[column.key] ?? '')
                    const editable = readOnly !== true && column.editable !== false && column.type !== 'readonly'

                    if (column.render !== undefined) {
                      return (
                        <td key={column.key} className={cellClass}>
                          {column.render(row, rowIndex)}
                        </td>
                      )
                    }

                    if (column.type === 'lookup') {
                      return (
                        <td key={column.key} className={cellClass}>
                          <button
                            type="button"
                            disabled={!editable}
                            onClick={() => onLookup?.(rowIndex, column.key)}
                            className="flex min-h-9 w-full items-center justify-between gap-2 rounded border border-line bg-surface px-2 text-left text-sm hover:border-ink-muted disabled:bg-paper disabled:text-ink-muted"
                          >
                            <span className="truncate">
                              {column.label?.(row) ?? (value === '' ? column.placeholder ?? 'Pilih...' : value)}
                            </span>
                            <span aria-hidden="true" className="text-xs text-ink-muted">
                              ▾
                            </span>
                          </button>
                        </td>
                      )
                    }

                    if (column.type === 'select') {
                      return (
                        <td key={column.key} className={cellClass}>
                          <select
                            data-cell={`${rowIndex}:${column.key}`}
                            value={value}
                            disabled={!editable}
                            aria-label={column.header}
                            onChange={(event) => updateCell(rowIndex, column.key, event.target.value)}
                            onKeyDown={(event) => {
                              if (event.key === 'ArrowDown') {
                                event.preventDefault()
                                focusRelative(rowIndex, column.key, 1)
                              } else if (event.key === 'ArrowUp') {
                                event.preventDefault()
                                focusRelative(rowIndex, column.key, -1)
                              }
                            }}
                            className="min-h-9 w-full rounded border border-line bg-surface px-1.5 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-paper disabled:text-ink-muted"
                          >
                            <option value="">{column.placeholder ?? 'Pilih'}</option>
                            {(column.optionsFor?.(row) ?? column.options ?? []).map((option) => (
                              <option key={option.value} value={option.value}>
                                {option.label}
                              </option>
                            ))}
                          </select>
                        </td>
                      )
                    }

                    return (
                      <td key={column.key} className={cellClass}>
                        <input
                          data-cell={`${rowIndex}:${column.key}`}
                          type={column.type === 'number' ? 'number' : 'text'}
                          inputMode={column.type === 'number' ? 'decimal' : undefined}
                          step={column.step}
                          value={value}
                          disabled={!editable}
                          readOnly={column.type === 'readonly' || readOnly}
                          aria-label={column.header}
                          placeholder={column.placeholder}
                          onChange={(event) => updateCell(rowIndex, column.key, event.target.value)}
                          onKeyDown={(event) => {
                            if (event.key === 'ArrowDown') {
                              event.preventDefault()
                              focusRelative(rowIndex, column.key, 1)
                            } else if (event.key === 'ArrowUp') {
                              event.preventDefault()
                              focusRelative(rowIndex, column.key, -1)
                            } else if (event.key === 'Enter') {
                              event.preventDefault()
                              focusRelative(rowIndex, column.key, 1)
                            }
                          }}
                          className={clsx(
                            'min-h-9 w-full rounded border border-line bg-surface px-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-paper disabled:text-ink-muted',
                            column.type === 'readonly' ? 'bg-paper text-ink-muted' : '',
                            column.align === 'right' ? 'text-right tabular-nums' : '',
                          )}
                        />
                      </td>
                    )
                  })}
                  {readOnly ? null : (
                    <td className="px-1 py-1 text-center">
                      <button
                        type="button"
                        onClick={() => removeRow(rowIndex)}
                        disabled={rows.length <= minRows}
                        aria-label={`Hapus baris ${rowIndex + 1}`}
                        className="rounded px-2 py-1 text-xs font-medium text-danger hover:bg-danger-soft disabled:opacity-40"
                      >
                        Hapus
                      </button>
                    </td>
                  )}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {readOnly ? null : (
        <div className="border-t border-line px-3 py-2">
          <button
            type="button"
            onClick={addRow}
            className="inline-flex min-h-9 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted"
          >
            {addLabel}
          </button>
        </div>
      )}

      {footer === undefined ? null : <div className="border-t border-line px-3 py-3">{footer}</div>}
    </div>
  )
}
