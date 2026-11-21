import { api } from '../../lib/api'
import { DocumentListPage } from '../../components/document/DocumentListPage'
import { TransactionDocumentPage } from '../../components/document/TransactionDocumentPage'
import type { HeaderFieldSpec } from '../../components/document/TransactionEditor'
import { newLine, type DocumentItem, type LineRow } from '../../lib/document'
import type { ItemStockResponse } from '../../lib/document-hooks'
import { useWarehouseOptions } from '../../lib/options'
import {
  docDateColumn,
  docNoColumn,
  listStatuses,
  statusColumn,
  today,
  warehouseColumn,
  type DocListRecord,
} from './shared'
import type { DataTableColumn } from '../../components/ui/data-table.types'

function str(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function idStr(value: unknown): string {
  return value === null || value === undefined ? '' : String(value)
}

async function stockForWarehouse(
  itemId: string,
  warehouseId: string,
): Promise<string | null> {
  if (warehouseId === '') {
    return null
  }

  const response = await api.get<ItemStockResponse>(`/items/${itemId}/stock`)
  const balance = response.balances.find((row) => String(row.warehouse_id) === warehouseId)

  return balance?.qty_base ?? '0.0000'
}

export function WarehouseTransfersListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    warehouseColumn('from_warehouse', 'Dari'),
    warehouseColumn('to_warehouse', 'Ke'),
    statusColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Transfer gudang"
      description="Pemindahan stok antar gudang."
      resource="/warehouse-transfers"
      permissionPrefix="inventory.warehouse-transfer"
      columns={columns}
      detailHref={(record) => `/documents/warehouse-transfers/${record.id}`}
      createHref="/documents/warehouse-transfers/new"
      statusFilters={listStatuses}
    />
  )
}

export function WarehouseTransferFormPage() {
  const warehouses = useWarehouseOptions()

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    {
      name: 'from_warehouse_id',
      label: 'Gudang asal',
      type: 'select',
      required: true,
      options: warehouses.data ?? [],
      placeholder: 'Pilih gudang asal',
    },
    {
      name: 'to_warehouse_id',
      label: 'Gudang tujuan',
      type: 'select',
      required: true,
      options: warehouses.data ?? [],
      placeholder: 'Pilih gudang tujuan',
    },
    { name: 'notes', label: 'Catatan', type: 'text' },
  ]

  return (
    <TransactionDocumentPage
      resource="/warehouse-transfers"
      title="Transfer gudang"
      permissionPrefix="inventory.warehouse-transfer"
      mode="qty"
      fields={fields}
      stockWarehouseField="from_warehouse_id"
      stockFor={(itemId, header) => stockForWarehouse(itemId, header.from_warehouse_id ?? '')}
      defaultHeader={{ doc_date: today(), from_warehouse_id: '', to_warehouse_id: '', notes: '' }}
      recordToHeader={(record) => {
        const row = record as unknown as {
          doc_date?: string
          from_warehouse_id?: number
          to_warehouse_id?: number
          notes?: string | null
        }

        return {
          doc_date: str(row.doc_date, today()),
          from_warehouse_id: idStr(row.from_warehouse_id),
          to_warehouse_id: idStr(row.to_warehouse_id),
          notes: str(row.notes),
        }
      }}
      recordToRows={(record) => {
        const row = record as unknown as { items?: DocumentItem[] }

        return (row.items ?? []).map((item) => ({
          ...newLine(),
          key: `line-${item.id}`,
          item_id: String(item.item_id),
          item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
          unit_id: String(item.unit_id),
          unit_code: item.unit?.code ?? '',
          qty: str(item.qty, '0'),
          qty_base: str(item.qty_base, '0'),
        }))
      }}
      buildPayload={(header, rows) => ({
        doc_date: header.doc_date,
        from_warehouse_id: Number(header.from_warehouse_id),
        to_warehouse_id: Number(header.to_warehouse_id),
        notes: header.notes === '' ? null : header.notes,
        items: rows.map((row) => ({
          item_id: Number(row.item_id),
          unit_id: Number(row.unit_id),
          qty: row.qty,
        })),
      })}
    />
  )
}

export function StockOpnamesListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    warehouseColumn('warehouse', 'Gudang'),
    statusColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Stock opname"
      description="Hitung fisik dan penyesuaian stok."
      resource="/stock-opnames"
      permissionPrefix="inventory.stock-opname"
      columns={columns}
      detailHref={(record) => `/documents/stock-opnames/${record.id}`}
      createHref="/documents/stock-opnames/new"
      statusFilters={listStatuses}
    />
  )
}

export function StockOpnameFormPage() {
  const warehouses = useWarehouseOptions()

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    {
      name: 'warehouse_id',
      label: 'Gudang',
      type: 'select',
      required: true,
      options: warehouses.data ?? [],
      placeholder: 'Pilih gudang',
    },
    { name: 'notes', label: 'Catatan', type: 'text' },
  ]

  return (
    <TransactionDocumentPage
      resource="/stock-opnames"
      title="Stock opname"
      permissionPrefix="inventory.stock-opname"
      mode="qty"
      fields={fields}
      stockWarehouseField="warehouse_id"
      stockFor={(itemId, header) => stockForWarehouse(itemId, header.warehouse_id ?? '')}
      defaultHeader={{ doc_date: today(), warehouse_id: '', notes: '' }}
      recordToHeader={(record) => {
        const row = record as unknown as {
          doc_date?: string
          warehouse_id?: number
          notes?: string | null
        }

        return {
          doc_date: str(row.doc_date, today()),
          warehouse_id: idStr(row.warehouse_id),
          notes: str(row.notes),
        }
      }}
      recordToRows={(record) => {
        const row = record as unknown as { items?: DocumentItem[] }

        return (row.items ?? []).map((item) => ({
          ...newLine(),
          key: `line-${item.id}`,
          item_id: String(item.item_id),
          item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
          unit_id: idStr(item.item?.base_unit_id ?? item.unit_id),
          unit_code: item.unit?.code ?? '',
          qty: str(item.counted_qty_base, '0'),
          qty_base: str(item.counted_qty_base, '0'),
        }))
      }}
      buildPayload={(header, rows) => ({
        doc_date: header.doc_date,
        warehouse_id: Number(header.warehouse_id),
        notes: header.notes === '' ? null : header.notes,
        items: rows.map((row: LineRow) => ({
          item_id: Number(row.item_id),
          unit_id: Number(row.unit_id),
          counted_qty: row.qty,
        })),
      })}
    />
  )
}
