import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../../lib/api'
import {
  computeLine,
  computeTotals,
  newLine,
  type ItemDetail,
  type LineRow,
} from '../../lib/document'
import { decCompare } from '../../lib/decimal'
import { formatCurrency } from '../../lib/format'
import type { SelectOption } from '../../lib/types'
import { SelectField } from '../ui/SelectField'
import { TextField } from '../ui/TextField'
import { EditableGrid, type GridColumn } from './EditableGrid'
import { ItemLookupModal } from './ItemLookupModal'

export type TransactionMode = 'priced' | 'qty' | 'source-qty' | 'source-priced'

export interface HeaderFieldSpec {
  name: string
  label: string
  type: 'date' | 'select' | 'text' | 'number'
  options?: SelectOption[]
  placeholder?: string
  required?: boolean
  hint?: string
  disabled?: boolean
  className?: string
}

export interface SourceResult {
  rows: LineRow[]
  header?: Record<string, string>
}

export interface SourceSpec {
  field: string
  label: string
  options: SelectOption[]
  onLoad: (
    sourceId: string,
    resolveItem: (itemId: string) => Promise<ItemDetail>,
  ) => Promise<SourceResult>
  hint?: string
}

interface TransactionEditorProps {
  readOnly: boolean
  header: Record<string, string>
  onHeaderChange: (header: Record<string, string>) => void
  fields: HeaderFieldSpec[]
  rows: LineRow[]
  onRowsChange: (rows: LineRow[]) => void
  mode: TransactionMode
  showDiscount?: boolean
  priceEditable?: boolean
  taxEnabled?: boolean
  totalsEnabled?: boolean
  source?: SourceSpec
  stockFor?: (itemId: string, header: Record<string, string>) => Promise<string | null>
  stockWarehouseField?: string
  autoPrice?: (
    itemId: string,
    unitId: string,
    header: Record<string, string>,
  ) => Promise<string | null>
  errors?: Record<string, string[]>
  minRows?: number
}

function errorFor(errors: Record<string, string[]> | undefined, path: string): string | undefined {
  return errors?.[path]?.[0]
}

export function TransactionEditor({
  readOnly,
  header,
  onHeaderChange,
  fields,
  rows,
  onRowsChange,
  mode,
  showDiscount = false,
  priceEditable = false,
  taxEnabled = false,
  totalsEnabled = false,
  source,
  stockFor,
  stockWarehouseField,
  autoPrice,
  errors,
  minRows = 1,
}: TransactionEditorProps) {
  const [items, setItems] = useState<Record<string, ItemDetail>>({})
  const [lookupRow, setLookupRow] = useState<number | null>(null)
  const [sourceBusy, setSourceBusy] = useState(false)
  const [sourceError, setSourceError] = useState<string | null>(null)
  const itemsRef = useRef(items)
  itemsRef.current = items

  const resolveItem = useCallback(async (itemId: string): Promise<ItemDetail> => {
    const cached = itemsRef.current[itemId]

    if (cached !== undefined) {
      return cached
    }

    const response = await api.get<{ data: ItemDetail }>(`/items/${itemId}`)
    setItems((current) => ({ ...current, [itemId]: response.data }))

    return response.data
  }, [])

  useEffect(() => {
    for (const row of rows) {
      if (row.item_id !== '' && items[row.item_id] === undefined) {
        void resolveItem(row.item_id)
      }
    }
  }, [rows, items, resolveItem])

  const setHeaderValue = (name: string, value: string): void => {
    onHeaderChange({ ...header, [name]: value })
  }

  const applyRows = (next: LineRow[]): void => {
    onRowsChange(
      next.map((row) => {
        const item = row.item_id === '' ? null : (items[row.item_id] ?? null)

        return computeLine(row, item)
      }),
    )
  }

  const handleGridChange = (next: LineRow[]): void => {
    const changedIndex = next.findIndex(
      (row, index) =>
        row.item_id !== (rows[index]?.item_id ?? '') ||
        row.unit_id !== (rows[index]?.unit_id ?? ''),
    )

    applyRows(next)

    if (changedIndex < 0 || autoPrice === undefined) {
      return
    }

    const changed = next[changedIndex]

    if (changed === undefined || changed.item_id === '' || changed.unit_id === '') {
      return
    }

    void (async () => {
      const price = await autoPrice(changed.item_id, changed.unit_id, header)

      if (price === null) {
        return
      }

      onRowsChange(
        rowsRef.current.map((row, index) =>
          index === changedIndex ? computeLine({ ...row, unit_price: price }, items[row.item_id] ?? null) : row,
        ),
      )
    })()
  }

  const selectItem = async (index: number, itemId: string): Promise<void> => {
    const detail = await resolveItem(itemId)
    const baseUnitId = String(detail.base_unit_id)
    const next = rows.map((row, rowIndex) =>
      rowIndex === index
        ? {
            ...row,
            item_id: itemId,
            item_label: `${detail.sku} - ${detail.name}`,
            unit_id: baseUnitId,
            unit_code: detail.base_unit?.code ?? '',
            unit_price: row.unit_price === '' ? '0' : row.unit_price,
          }
        : row,
    )

    applyRows(next)

    if (autoPrice !== undefined) {
      const price = await autoPrice(itemId, baseUnitId, header)

      if (price !== null) {
        const withPrice = next.map((row, rowIndex) =>
          rowIndex === index ? { ...row, unit_price: price } : row,
        )
        applyRows(withPrice)
      }
    }

    if (stockFor !== undefined) {
      const available = await stockFor(itemId, header)
      applyRows(
        next.map((row, rowIndex) =>
          rowIndex === index ? { ...row, available: available ?? undefined } : row,
        ),
      )
    }
  }

  const loadSource = async (): Promise<void> => {
    if (source === undefined) {
      return
    }

    const sourceId = header[source.field] ?? ''

    if (sourceId === '') {
      setSourceError('Pilih dokumen sumber terlebih dahulu.')
      return
    }

    setSourceBusy(true)
    setSourceError(null)

    try {
      const result = await source.onLoad(sourceId, resolveItem)
      onRowsChange(result.rows)

      if (result.header !== undefined) {
        onHeaderChange({ ...header, ...result.header })
      }
    } catch (error) {
      setSourceError(error instanceof Error ? error.message : 'Gagal memuat baris dokumen sumber.')
    } finally {
      setSourceBusy(false)
    }
  }

  const stockWarehouse = stockWarehouseField === undefined ? '' : (header[stockWarehouseField] ?? '')

  const rowsRef = useRef(rows)
  rowsRef.current = rows
  const headerRef = useRef(header)
  headerRef.current = header
  const lastWarehouse = useRef<string | null>(null)

  useEffect(() => {
    if (stockFor === undefined || stockWarehouseField === undefined) {
      return
    }

    const warehouse = headerRef.current[stockWarehouseField] ?? ''

    if (lastWarehouse.current === warehouse) {
      return
    }

    lastWarehouse.current = warehouse
    let cancelled = false

    void (async () => {
      const updates = await Promise.all(
        rowsRef.current.map(async (row) => {
          if (row.item_id === '') {
            return row
          }

          const available = await stockFor(row.item_id, headerRef.current)

          return { ...row, available: available ?? undefined }
        }),
      )

      if (!cancelled) {
        onRowsChange(updates)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [stockFor, stockWarehouse, stockWarehouseField, onRowsChange])

  const totals = useMemo(
    () => computeTotals(rows, header.tax_rate ?? '0'),
    [rows, header.tax_rate],
  )

  const unitOptionsFor = (row: LineRow): SelectOption[] => {
    const detail = items[row.item_id]

    if (detail === undefined) {
      return row.unit_id === '' ? [] : [{ value: row.unit_id, label: row.unit_code }]
    }

    const options: SelectOption[] = [
      {
        value: String(detail.base_unit_id),
        label: detail.base_unit?.code ?? 'Base',
      },
    ]

    for (const conversion of detail.conversions ?? []) {
      options.push({
        value: String(conversion.unit_id),
        label: `${conversion.unit?.code ?? `#${conversion.unit_id}`} (x${conversion.factor_to_base})`,
      })
    }

    return options
  }

  const showPrice = mode === 'priced' || mode === 'source-priced'
  const columns: GridColumn<LineRow>[] = [
    {
      key: 'item_id',
      header: 'Item',
      type: 'lookup',
      width: '32%',
      placeholder: 'Pilih item',
      editable: true,
      label: (row) => row.item_label,
    },
    {
      key: 'unit_id',
      header: 'Satuan',
      type: 'select',
      width: '13%',
      editable: true,
      optionsFor: unitOptionsFor,
    },
    { key: 'qty', header: 'Qty', type: 'number', width: '10%', align: 'right', step: 'any' },
  ]

  if (showPrice) {
    columns.push({
      key: 'unit_price',
      header: 'Harga',
      type: 'number',
      width: '14%',
      align: 'right',
      step: 'any',
      editable: priceEditable,
    })
  }

  if (showDiscount) {
    columns.push({
      key: 'discount_pct',
      header: 'Diskon %',
      type: 'number',
      width: '9%',
      align: 'right',
      step: 'any',
    })
  }

  columns.push({
    key: 'qty_base',
    header: 'Qty base',
    type: 'readonly',
    width: '10%',
    align: 'right',
  })

  if (stockFor !== undefined) {
    columns.push({
      key: 'available',
      header: 'Tersedia',
      type: 'readonly',
      width: '10%',
      align: 'right',
      render: (row) => {
        const available = row.available

        if (available === undefined) {
          return '—'
        }

        const insufficient = decCompare(row.qty_base, available) > 0

        return (
          <span className={insufficient ? 'font-semibold text-danger' : ''}>
            {available}
          </span>
        )
      },
    })
  }

  if (totalsEnabled) {
    columns.push({
      key: 'line_total',
      header: 'Jumlah',
      type: 'readonly',
      width: '13%',
      align: 'right',
      render: (row) => formatCurrency(row.line_total),
    })
  }

  return (
    <div className="space-y-4">
      <div className="grid gap-3 rounded-md border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-4">
        {fields.map((field) => {
          const value = header[field.name] ?? ''
          const error = errorFor(errors, field.name)

          if (field.type === 'select') {
            return (
              <SelectField
                key={field.name}
                id={field.name}
                label={field.label}
                required={field.required}
                placeholder={field.placeholder ?? 'Pilih'}
                options={field.options ?? []}
                hint={field.hint}
                error={error}
                disabled={readOnly || field.disabled === true}
                value={value}
                onChange={(event) => setHeaderValue(field.name, event.target.value)}
              />
            )
          }

          return (
            <TextField
              key={field.name}
              id={field.name}
              label={field.label}
              type={field.type}
              required={field.required}
              placeholder={field.placeholder}
              hint={field.hint}
              error={error}
              disabled={readOnly || field.disabled === true}
              value={value}
              onChange={(event) => setHeaderValue(field.name, event.target.value)}
            />
          )
        })}
      </div>

      {source !== undefined && !readOnly ? (
        <div className="flex flex-wrap items-end gap-3 rounded-md border border-line bg-surface p-4">
          <SelectField
            id={`source-${source.field}`}
            label={source.label}
            placeholder="Pilih dokumen"
            options={source.options}
            hint={source.hint}
            value={header[source.field] ?? ''}
            onChange={(event) => setHeaderValue(source.field, event.target.value)}
            className="min-w-64"
          />
          <button
            type="button"
            onClick={() => void loadSource()}
            disabled={sourceBusy}
            className="inline-flex min-h-10 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted disabled:opacity-60"
          >
            {sourceBusy ? 'Memuat...' : 'Muat baris'}
          </button>
          {sourceError !== null ? (
            <p role="alert" className="text-sm text-danger">
              {sourceError}
            </p>
          ) : null}
        </div>
      ) : null}

      {errors !== undefined && Object.keys(errors).some((key) => key.startsWith('items.')) ? (
        <ul role="alert" className="list-disc space-y-0.5 rounded-md border border-danger/30 bg-danger-soft px-6 py-2 text-sm text-danger">
          {Object.entries(errors)
            .filter(([key]) => key.startsWith('items.'))
            .flatMap(([key, messages]) =>
              messages.map((message) => <li key={`${key}-${message}`}>{message}</li>),
            )}
        </ul>
      ) : null}

      <EditableGrid
        columns={columns}
        rows={rows}
        onChange={handleGridChange}
        newRow={newLine}
        readOnly={readOnly}
        minRows={minRows}
        onLookup={(index) => setLookupRow(index)}
        footer={
          totalsEnabled ? (
            <div className="flex flex-col items-end gap-1 text-sm">
              <div className="flex w-full max-w-xs justify-between gap-4">
                <span className="text-ink-muted">Subtotal</span>
                <span className="tabular-nums">{formatCurrency(totals.subtotal)}</span>
              </div>
              <div className="flex w-full max-w-xs justify-between gap-4">
                <span className="text-ink-muted">Diskon</span>
                <span className="tabular-nums">{formatCurrency(totals.discount)}</span>
              </div>
              {taxEnabled ? (
                <div className="flex w-full max-w-xs items-center justify-between gap-4">
                  <span className="text-ink-muted">
                    PPN
                    <input
                      type="number"
                      step="any"
                      min="0"
                      max="100"
                      value={header.tax_rate ?? '11'}
                      disabled={readOnly}
                      onChange={(event) => setHeaderValue('tax_rate', event.target.value)}
                      className="ml-2 w-16 rounded border border-line px-1 py-0.5 text-right text-xs tabular-nums"
                      aria-label="Tarif PPN"
                    />
                    %
                  </span>
                  <span className="tabular-nums">{formatCurrency(totals.tax)}</span>
                </div>
              ) : null}
              <div className="flex w-full max-w-xs justify-between gap-4 border-t border-line pt-1 font-semibold">
                <span>Total</span>
                <span className="tabular-nums">{formatCurrency(totals.total)}</span>
              </div>
            </div>
          ) : undefined
        }
      />

      <ItemLookupModal
        open={lookupRow !== null}
        onClose={() => setLookupRow(null)}
        onSelect={(itemId) => {
          if (lookupRow !== null) {
            void selectItem(lookupRow, String(itemId))
          }
        }}
      />
    </div>
  )
}
