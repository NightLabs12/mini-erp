import { api } from '../../lib/api'
import { DocumentListPage } from '../../components/document/DocumentListPage'
import { PaymentDocumentPage } from '../../components/document/PaymentDocumentPage'
import { TransactionDocumentPage } from '../../components/document/TransactionDocumentPage'
import type { HeaderFieldSpec, SourceResult } from '../../components/document/TransactionEditor'
import { decDiv, decMul, decRound } from '../../lib/decimal'
import {
  factorFor,
  newLine,
  qtyRemaining,
  unitPriceFromBase,
  type DocumentItem,
  type ItemDetail,
  type LineRow,
} from '../../lib/document'
import { useApprovedDocuments } from '../../lib/document-hooks'
import { formatDate } from '../../lib/format'
import { useAccountOptions, useSupplierOptions, useWarehouseOptions } from '../../lib/options'
import {
  amountColumn,
  docDateColumn,
  docNoColumn,
  listStatuses,
  partnerColumn,
  statusColumn,
  today,
  totalColumn,
  warehouseColumn,
  type DocListRecord,
} from './shared'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import type { SelectOption } from '../../lib/types'

interface PurchaseRecord {
  id: number
  doc_no: string
  doc_date: string
  status: string
  supplier_id: number
  warehouse_id: number
  expected_date?: string | null
  notes?: string | null
  tax_rate?: string
  items?: DocumentItem[]
}

function str(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function idStr(value: unknown): string {
  return value === null || value === undefined ? '' : String(value)
}

function toRows(items: DocumentItem[] | undefined, withPrice: boolean): LineRow[] {
  return (items ?? []).map((item) => {
    const qtyBase = str(item.qty_base, '0')
    const basePrice = str(item.unit_price_base, '0')

    return {
      ...newLine(),
      key: `line-${item.id}`,
      item_id: String(item.item_id),
      item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
      unit_id: String(item.unit_id),
      unit_code: item.unit?.code ?? '',
      qty: str(item.qty, '0'),
      unit_price: withPrice ? str(item.unit_price, '0') : '0',
      discount_pct: str(item.discount_pct, '0'),
      qty_base: qtyBase,
      gross_line: decRound(decMul(qtyBase, basePrice, 6), 2),
      line_total: str(item.line_total, '0'),
      source_line_id: '',
    }
  })
}

function approvedOptions(
  documents: { id: number; doc_no: string; doc_date: string }[] | undefined,
): SelectOption[] {
  return (documents ?? []).map((document) => ({
    value: String(document.id),
    label: `${document.doc_no} (${formatDate(document.doc_date)})`,
  }))
}

function loadPoLines(
  resolveItem: (itemId: string) => Promise<ItemDetail>,
): (sourceId: string) => Promise<SourceResult> {
  return async (sourceId) => {
    const response = await fetchDocument('/purchase-orders', sourceId)
    const rows = await Promise.all(
      (response.items ?? []).map(async (item) => {
        const detail = await resolveItem(String(item.item_id))
        const factor = factorFor(detail, String(item.unit_id))
        const remaining = qtyRemaining(item.qty_base, item.received_qty_base)
        const qty = decDiv(remaining, factor, 4)

        return {
          ...newLine(),
          key: `line-${item.id}`,
          source_line_id: String(item.id),
          item_id: String(item.item_id),
          item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
          unit_id: String(item.unit_id),
          unit_code: item.unit?.code ?? '',
          qty,
          unit_price: '0',
          available: remaining,
          qty_base: remaining,
        }
      }),
    )

    return { rows }
  }
}

async function fetchDocument(resource: string, sourceId: string): Promise<{ items?: DocumentItem[] }> {
  const response = await api.get<{ data: { items?: DocumentItem[] } }>(`${resource}/${sourceId}`)

  return response.data
}

export function PurchaseOrdersListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    partnerColumn('supplier', 'Supplier'),
    statusColumn,
    totalColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Purchase Order"
      description="Pesanan pembelian ke supplier."
      resource="/purchase-orders"
      permissionPrefix="purchase.purchase-order"
      columns={columns}
      detailHref={(record) => `/documents/purchase-orders/${record.id}`}
      createHref="/documents/purchase-orders/new"
      statusFilters={listStatuses}
    />
  )
}

export function PurchaseOrderFormPage() {
  const suppliers = useSupplierOptions()
  const warehouses = useWarehouseOptions()

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    {
      name: 'supplier_id',
      label: 'Supplier',
      type: 'select',
      required: true,
      options: suppliers.data ?? [],
      placeholder: 'Pilih supplier',
    },
    {
      name: 'warehouse_id',
      label: 'Gudang',
      type: 'select',
      required: true,
      options: warehouses.data ?? [],
      placeholder: 'Pilih gudang',
    },
    { name: 'expected_date', label: 'Perkiraan datang', type: 'date' },
    { name: 'notes', label: 'Catatan', type: 'text' },
  ]

  return (
    <TransactionDocumentPage
      resource="/purchase-orders"
      title="Purchase Order"
      permissionPrefix="purchase.purchase-order"
      mode="priced"
      fields={fields}
      showDiscount
      priceEditable
      taxEnabled
      totalsEnabled
      defaultHeader={{
        doc_date: today(),
        supplier_id: '',
        warehouse_id: '',
        expected_date: '',
        notes: '',
        tax_rate: '11',
      }}
      recordToHeader={(record) => {
        const row = record as unknown as PurchaseRecord

        return {
          doc_date: str(row.doc_date, today()),
          supplier_id: idStr(row.supplier_id),
          warehouse_id: idStr(row.warehouse_id),
          expected_date: str(row.expected_date),
          notes: str(row.notes),
          tax_rate: str(row.tax_rate, '11'),
        }
      }}
      recordToRows={(record) => toRows((record as unknown as PurchaseRecord).items, true)}
      buildPayload={(header, rows) => ({
        doc_date: header.doc_date,
        supplier_id: Number(header.supplier_id),
        warehouse_id: Number(header.warehouse_id),
        expected_date: header.expected_date === '' ? null : header.expected_date,
        tax_rate: header.tax_rate,
        notes: header.notes === '' ? null : header.notes,
        items: rows.map((row) => ({
          item_id: Number(row.item_id),
          unit_id: Number(row.unit_id),
          qty: row.qty,
          unit_price: row.unit_price,
          discount_pct: row.discount_pct,
        })),
      })}
      print
      closable
    />
  )
}

export function GoodsReceiptsListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    warehouseColumn('warehouse', 'Gudang'),
    statusColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Goods Receipt"
      description="Penerimaan barang dari purchase order."
      resource="/goods-receipts"
      permissionPrefix="purchase.goods-receipt"
      columns={columns}
      detailHref={(record) => `/documents/goods-receipts/${record.id}`}
      createHref="/documents/goods-receipts/new"
      statusFilters={listStatuses}
    />
  )
}

export function GoodsReceiptFormPage() {
  const orders = useApprovedDocuments('/purchase-orders')

  const source = {
    field: 'purchase_order_id',
    label: 'Purchase Order',
    options: approvedOptions(orders.data?.data),
    hint: 'Hanya PO approved yang dapat diterima.',
    onLoad: async (sourceId: string, resolveItem: (itemId: string) => Promise<ItemDetail>) =>
      loadPoLines(resolveItem)(sourceId),
  }

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    { name: 'notes', label: 'Catatan', type: 'text' },
  ]

  return (
    <TransactionDocumentPage
      resource="/goods-receipts"
      title="Goods Receipt"
      permissionPrefix="purchase.goods-receipt"
      mode="source-qty"
      fields={fields}
      source={source}
      defaultHeader={{ doc_date: today(), purchase_order_id: '', notes: '' }}
      recordToHeader={(record) => {
        const row = record as unknown as { doc_date?: string; purchase_order_id?: number; notes?: string | null }

        return {
          doc_date: str(row.doc_date, today()),
          purchase_order_id: idStr(row.purchase_order_id),
          notes: str(row.notes),
        }
      }}
      recordToRows={(record) => {
        const row = record as unknown as { items?: DocumentItem[] }

        return (row.items ?? []).map((item) => ({
          ...newLine(),
          key: `line-${item.id}`,
          source_line_id: idStr(item.purchase_order_item_id),
          item_id: String(item.item_id),
          item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
          unit_id: String(item.unit_id),
          unit_code: item.unit?.code ?? '',
          qty: str(item.qty, '0'),
          qty_base: str(item.qty_base, '0'),
          available: str(item.qty_base, '0'),
        }))
      }}
      buildPayload={(header, rows) => ({
        doc_date: header.doc_date,
        purchase_order_id: Number(header.purchase_order_id),
        notes: header.notes === '' ? null : header.notes,
        items: rows.map((row) => ({
          purchase_order_item_id: Number(row.source_line_id),
          qty: row.qty,
        })),
      })}
    />
  )
}

export function PurchaseInvoicesListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    partnerColumn('supplier', 'Supplier'),
    {
      id: 'due_date',
      header: 'Jatuh tempo',
      sortable: true,
      render: (row) => (row.due_date === undefined || row.due_date === null ? '—' : formatDate(row.due_date)),
    },
    statusColumn,
    totalColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Purchase Invoice"
      description="Tagihan supplier dari goods receipt."
      resource="/purchase-invoices"
      permissionPrefix="purchase.purchase-invoice"
      columns={columns}
      detailHref={(record) => `/documents/purchase-invoices/${record.id}`}
      createHref="/documents/purchase-invoices/new"
      statusFilters={listStatuses}
    />
  )
}

export function PurchaseInvoiceFormPage() {
  const suppliers = useSupplierOptions()
  const receipts = useApprovedDocuments('/goods-receipts')

  const source = {
    field: 'source_gr_id',
    label: 'Goods Receipt',
    options: approvedOptions(receipts.data?.data),
    hint: 'Pilih GR approved; baris dimuat otomatis.',
    onLoad: async (sourceId: string, resolveItem: (itemId: string) => Promise<ItemDetail>) => {
      const response = await fetchDocument('/goods-receipts', sourceId)
      const rows = await Promise.all(
        (response.items ?? []).map(async (item) => {
          const detail = await resolveItem(String(item.item_id))
          const factor = factorFor(detail, String(item.unit_id))
          const remaining = qtyRemaining(item.qty_base, item.invoiced_qty_base)
          const qty = decDiv(remaining, factor, 4)
          const unitPrice = unitPriceFromBase(str(item.unit_cost_base, '0'), factor)
          const qtyBase = remaining
          const basePrice = str(item.unit_cost_base, '0')

          return {
            ...newLine(),
            key: `line-${item.id}`,
            source_line_id: String(item.id),
            item_id: String(item.item_id),
            item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
            unit_id: String(item.unit_id),
            unit_code: item.unit?.code ?? '',
            qty,
            unit_price: unitPrice,
            qty_base: qtyBase,
            gross_line: decRound(decMul(qtyBase, basePrice, 6), 2),
            line_total: decRound(decMul(qtyBase, basePrice, 6), 2),
            available: remaining,
          }
        }),
      )

      return { rows }
    },
  }

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    {
      name: 'supplier_id',
      label: 'Supplier',
      type: 'select',
      required: true,
      options: suppliers.data ?? [],
      placeholder: 'Pilih supplier',
    },
    { name: 'notes', label: 'Catatan', type: 'text' },
  ]

  return (
    <TransactionDocumentPage
      resource="/purchase-invoices"
      title="Purchase Invoice"
      permissionPrefix="purchase.purchase-invoice"
      mode="source-priced"
      fields={fields}
      source={source}
      priceEditable
      taxEnabled
      totalsEnabled
      defaultHeader={{ doc_date: today(), supplier_id: '', source_gr_id: '', notes: '', tax_rate: '11' }}
      recordToHeader={(record) => {
        const row = record as unknown as {
          doc_date?: string
          supplier_id?: number
          notes?: string | null
          tax_rate?: string
        }

        return {
          doc_date: str(row.doc_date, today()),
          supplier_id: idStr(row.supplier_id),
          source_gr_id: '',
          notes: str(row.notes),
          tax_rate: str(row.tax_rate, '11'),
        }
      }}
      recordToRows={(record) => {
        const row = record as unknown as { items?: DocumentItem[] }

        return (row.items ?? []).map((item) => ({
          ...newLine(),
          key: `line-${item.id}`,
          source_line_id: idStr(item.goods_receipt_item_id),
          item_id: String(item.item_id),
          item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
          unit_id: String(item.unit_id),
          unit_code: item.unit?.code ?? '',
          qty: str(item.qty, '0'),
          unit_price: str(item.unit_price, '0'),
          qty_base: str(item.qty_base, '0'),
          gross_line: decRound(decMul(str(item.qty_base, '0'), str(item.unit_price_base, '0'), 6), 2),
          line_total: str(item.total_value, '0'),
        }))
      }}
      buildPayload={(header, rows) => ({
        doc_date: header.doc_date,
        supplier_id: Number(header.supplier_id),
        tax_rate: header.tax_rate,
        notes: header.notes === '' ? null : header.notes,
        items: rows.map((row) => ({
          goods_receipt_item_id: Number(row.source_line_id),
          qty: row.qty,
          unit_price: row.unit_price,
        })),
      })}
    />
  )
}

export function SupplierPaymentsListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    partnerColumn('supplier', 'Supplier'),
    { id: 'method', header: 'Metode', render: (row) => row.method ?? '—' },
    amountColumn,
    statusColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Payment Out"
      description="Pembayaran ke supplier dengan alokasi invoice."
      resource="/supplier-payments"
      permissionPrefix="purchase.supplier-payment"
      columns={columns}
      detailHref={(record) => `/documents/supplier-payments/${record.id}`}
      createHref="/documents/supplier-payments/new"
      statusFilters={listStatuses}
    />
  )
}

export function SupplierPaymentFormPage() {
  const suppliers = useSupplierOptions()
  const accounts = useAccountOptions(true)

  return (
    <PaymentDocumentPage
      resource="/supplier-payments"
      title="Payment Out"
      permissionPrefix="purchase.supplier-payment"
      partnerField="supplier_id"
      partnerLabel="Supplier"
      partnerOptions={suppliers.data ?? []}
      invoiceResource="/purchase-invoices"
      invoiceField="purchase_invoice_id"
      accountOptions={accounts.data ?? []}
    />
  )
}

