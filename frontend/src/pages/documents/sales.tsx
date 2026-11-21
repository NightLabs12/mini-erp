import { api } from '../../lib/api'
import { useAuth } from '../../auth/auth-context'
import { DocumentListPage } from '../../components/document/DocumentListPage'
import { PaymentDocumentPage } from '../../components/document/PaymentDocumentPage'
import { TransactionDocumentPage } from '../../components/document/TransactionDocumentPage'
import type { HeaderFieldSpec, SourceResult } from '../../components/document/TransactionEditor'
import { decDiv, decMul, decRound } from '../../lib/decimal'
import {
  computeLine,
  factorFor,
  newLine,
  qtyRemaining,
  type DocumentItem,
  type ItemDetail,
  type LineRow,
} from '../../lib/document'
import { useApprovedDocuments, type ItemStockResponse } from '../../lib/document-hooks'
import { formatDate } from '../../lib/format'
import { useAccountOptions, useCustomerOptions, useWarehouseOptions } from '../../lib/options'
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

interface SalesRecord {
  id: number
  doc_date: string
  customer_id: number
  warehouse_id: number
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

function toRows(items: DocumentItem[] | undefined): LineRow[] {
  return (items ?? []).map((item) => {
    const qtyBase = str(item.qty_base, '0')

    return {
      ...newLine(),
      key: `line-${item.id}`,
      item_id: String(item.item_id),
      item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
      unit_id: String(item.unit_id),
      unit_code: item.unit?.code ?? '',
      qty: str(item.qty, '0'),
      unit_price: str(item.unit_price, '0'),
      discount_pct: str(item.discount_pct, '0'),
      qty_base: qtyBase,
      gross_line: decRound(decMul(qtyBase, str(item.unit_price_base, '0'), 6), 2),
      line_total: str(item.line_total, '0'),
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

async function fetchDocument(resource: string, id: string): Promise<Record<string, unknown>> {
  const response = await api.get<{ data: Record<string, unknown> }>(`${resource}/${id}`)

  return response.data
}

export function SalesOrdersListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    partnerColumn('customer', 'Customer'),
    warehouseColumn('warehouse', 'Gudang'),
    statusColumn,
    totalColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Sales Order"
      description="Pesanan penjualan dari customer."
      resource="/sales-orders"
      permissionPrefix="sales.sales-order"
      columns={columns}
      detailHref={(record) => `/documents/sales-orders/${record.id}`}
      createHref="/documents/sales-orders/new"
      statusFilters={listStatuses}
    />
  )
}

export function SalesOrderFormPage() {
  const customers = useCustomerOptions()
  const warehouses = useWarehouseOptions()
  const { hasPermission } = useAuth()

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    {
      name: 'customer_id',
      label: 'Customer',
      type: 'select',
      required: true,
      options: customers.data ?? [],
      placeholder: 'Pilih customer',
    },
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

  const autoPrice = async (
    itemId: string,
    unitId: string,
    header: Record<string, string>,
  ): Promise<string | null> => {
    if ((header.customer_id ?? '') === '') {
      return null
    }

    try {
      const response = await api.get<{ data: { price: string } }>('/selling-prices/lookup', {
        item_id: itemId,
        unit_id: unitId,
        customer_id: header.customer_id,
        date: header.doc_date,
      })

      return response.data.price
    } catch {
      return null
    }
  }

  return (
    <TransactionDocumentPage
      resource="/sales-orders"
      title="Sales Order"
      permissionPrefix="sales.sales-order"
      mode="priced"
      fields={fields}
      showDiscount
      priceEditable={hasPermission('sales.sales-order.override-price')}
      taxEnabled
      totalsEnabled
      autoPrice={autoPrice}
      defaultHeader={{
        doc_date: today(),
        customer_id: '',
        warehouse_id: '',
        notes: '',
        tax_rate: '11',
      }}
      recordToHeader={(record) => {
        const row = record as unknown as SalesRecord

        return {
          doc_date: str(row.doc_date, today()),
          customer_id: idStr(row.customer_id),
          warehouse_id: idStr(row.warehouse_id),
          notes: str(row.notes),
          tax_rate: str(row.tax_rate, '11'),
        }
      }}
      recordToRows={(record) => toRows((record as unknown as SalesRecord).items)}
      buildPayload={(header, rows) => ({
        doc_date: header.doc_date,
        customer_id: Number(header.customer_id),
        warehouse_id: Number(header.warehouse_id),
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
      closable
    />
  )
}

export function DeliveryOrdersListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    partnerColumn('customer', 'Customer'),
    warehouseColumn('warehouse', 'Gudang'),
    statusColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Surat Jalan"
      description="Delivery order dari sales order."
      resource="/delivery-orders"
      permissionPrefix="sales.delivery-order"
      columns={columns}
      detailHref={(record) => `/documents/delivery-orders/${record.id}`}
      createHref="/documents/delivery-orders/new"
      statusFilters={listStatuses}
    />
  )
}

export function DeliveryOrderFormPage() {
  const orders = useApprovedDocuments('/sales-orders')
  const warehouses = useWarehouseOptions()

  const source = {
    field: 'sales_order_id',
    label: 'Sales Order',
    options: approvedOptions(orders.data?.data),
    hint: 'Hanya SO approved yang dapat dikirim.',
    onLoad: async (
      sourceId: string,
      resolveItem: (itemId: string) => Promise<ItemDetail>,
    ): Promise<SourceResult> => {
      const document = await fetchDocument('/sales-orders', sourceId)
      const items = Array.isArray(document.items) ? (document.items as DocumentItem[]) : []
      const rows = await Promise.all(
        items.map(async (item) => {
          const detail = await resolveItem(String(item.item_id))
          const factor = factorFor(detail, String(item.unit_id))
          const remaining = qtyRemaining(item.qty_base, item.delivered_qty_base)
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
            qty_base: remaining,
            available: remaining,
          }
        }),
      )

      const warehouseId = document.warehouse_id

      return {
        rows,
        header: typeof warehouseId === 'number' ? { warehouse_id: String(warehouseId) } : {},
      }
    },
  }

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    {
      name: 'warehouse_id',
      label: 'Gudang',
      type: 'select',
      options: warehouses.data ?? [],
      placeholder: 'Terisi dari sales order',
      disabled: true,
    },
    { name: 'notes', label: 'Catatan', type: 'text' },
  ]

  return (
    <TransactionDocumentPage
      resource="/delivery-orders"
      title="Surat Jalan"
      permissionPrefix="sales.delivery-order"
      mode="source-qty"
      fields={fields}
      source={source}
      print
      stockWarehouseField="warehouse_id"
      stockFor={async (itemId, header) => {
        const warehouseId = header.warehouse_id ?? ''

        if (warehouseId === '') {
          return null
        }

        const response = await api.get<ItemStockResponse>(`/items/${itemId}/stock`)
        const balance = response.balances.find((row) => String(row.warehouse_id) === warehouseId)

        return balance?.qty_base ?? '0.0000'
      }}
      defaultHeader={{ doc_date: today(), sales_order_id: '', warehouse_id: '', notes: '' }}
      recordToHeader={(record) => {
        const row = record as unknown as {
          doc_date?: string
          sales_order_id?: number
          warehouse_id?: number
          notes?: string | null
        }

        return {
          doc_date: str(row.doc_date, today()),
          sales_order_id: idStr(row.sales_order_id),
          warehouse_id: idStr(row.warehouse_id),
          notes: str(row.notes),
        }
      }}
      recordToRows={(record) => {
        const row = record as unknown as { items?: DocumentItem[] }

        return (row.items ?? []).map((item) => ({
          ...newLine(),
          key: `line-${item.id}`,
          source_line_id: idStr(item.sales_order_item_id),
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
        sales_order_id: Number(header.sales_order_id),
        notes: header.notes === '' ? null : header.notes,
        items: rows.map((row) => ({
          sales_order_item_id: Number(row.source_line_id),
          qty: row.qty,
        })),
      })}
    />
  )
}

export function SalesInvoicesListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    partnerColumn('customer', 'Customer'),
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
      title="Sales Invoice"
      description="Tagihan penjualan dari delivery order."
      resource="/sales-invoices"
      permissionPrefix="sales.sales-invoice"
      columns={columns}
      detailHref={(record) => `/documents/sales-invoices/${record.id}`}
      createHref="/documents/sales-invoices/new"
      statusFilters={listStatuses}
    />
  )
}

export function SalesInvoiceFormPage() {
  const customers = useCustomerOptions()
  const deliveries = useApprovedDocuments('/delivery-orders')

  const source = {
    field: 'source_do_id',
    label: 'Delivery Order',
    options: approvedOptions(deliveries.data?.data),
    hint: 'Pilih DO approved; harga diambil dari sales order.',
    onLoad: async (
      sourceId: string,
      resolveItem: (itemId: string) => Promise<ItemDetail>,
    ): Promise<SourceResult> => {
      const delivery = await fetchDocument('/delivery-orders', sourceId)
      const doItems = Array.isArray(delivery.items) ? (delivery.items as DocumentItem[]) : []
      const salesOrderId = delivery.sales_order_id
      const prices = new Map<string, DocumentItem>()

      if (typeof salesOrderId === 'number') {
        const order = await fetchDocument('/sales-orders', String(salesOrderId))
        const soItems = Array.isArray(order.items) ? (order.items as DocumentItem[]) : []

        for (const item of soItems) {
          prices.set(String(item.id), item)
        }
      }

      const rows = await Promise.all(
        doItems.map(async (item) => {
          const detail = await resolveItem(String(item.item_id))
          const factor = factorFor(detail, String(item.unit_id))
          const remaining = qtyRemaining(item.qty_base, item.invoiced_qty_base)
          const qty = decDiv(remaining, factor, 4)
          const soItem = item.sales_order_item_id === null || item.sales_order_item_id === undefined
            ? undefined
            : prices.get(String(item.sales_order_item_id))

          const row: LineRow = {
            ...newLine(),
            key: `line-${item.id}`,
            source_line_id: String(item.id),
            item_id: String(item.item_id),
            item_label: `${item.item?.sku ?? ''} - ${item.item?.name ?? ''}`,
            unit_id: String(item.unit_id),
            unit_code: item.unit?.code ?? '',
            qty,
            unit_price: soItem?.unit_price ?? '0',
            discount_pct: soItem?.discount_pct ?? '0',
            available: remaining,
          }

          return computeLine(row, detail)
        }),
      )

      return { rows }
    },
  }

  const fields: HeaderFieldSpec[] = [
    { name: 'doc_date', label: 'Tanggal', type: 'date', required: true },
    {
      name: 'customer_id',
      label: 'Customer',
      type: 'select',
      required: true,
      options: customers.data ?? [],
      placeholder: 'Pilih customer',
    },
    {
      name: 'mode',
      label: 'Mode',
      type: 'select',
      options: [
        { value: 'draft', label: 'Draft' },
        { value: 'final', label: 'Final (langsung posting)' },
      ],
    },
    { name: 'notes', label: 'Catatan', type: 'text' },
  ]

  return (
    <TransactionDocumentPage
      resource="/sales-invoices"
      title="Sales Invoice"
      permissionPrefix="sales.sales-invoice"
      mode="source-priced"
      fields={fields}
      source={source}
      taxEnabled
      totalsEnabled
      print
      defaultHeader={{
        doc_date: today(),
        customer_id: '',
        source_do_id: '',
        mode: 'draft',
        notes: '',
        tax_rate: '11',
      }}
      recordToHeader={(record) => {
        const row = record as unknown as {
          doc_date?: string
          customer_id?: number
          mode?: string
          notes?: string | null
          tax_rate?: string
        }

        return {
          doc_date: str(row.doc_date, today()),
          customer_id: idStr(row.customer_id),
          source_do_id: '',
          mode: str(row.mode, 'draft'),
          notes: str(row.notes),
          tax_rate: str(row.tax_rate, '11'),
        }
      }}
      recordToRows={(record) => {
        const row = record as unknown as { items?: DocumentItem[] }

        return (row.items ?? []).map((item) => ({
          ...newLine(),
          key: `line-${item.id}`,
          source_line_id: idStr(item.delivery_order_item_id),
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
        customer_id: Number(header.customer_id),
        mode: header.mode === '' ? 'draft' : header.mode,
        tax_rate: header.tax_rate,
        notes: header.notes === '' ? null : header.notes,
        items: rows.map((row) => ({
          delivery_order_item_id: Number(row.source_line_id),
          qty: row.qty,
        })),
      })}
    />
  )
}

export function CustomerPaymentsListPage() {
  const columns: DataTableColumn<DocListRecord>[] = [
    docNoColumn,
    docDateColumn,
    partnerColumn('customer', 'Customer'),
    { id: 'method', header: 'Metode', render: (row) => row.method ?? '—' },
    amountColumn,
    statusColumn,
  ]

  return (
    <DocumentListPage<DocListRecord>
      title="Payment In"
      description="Penerimaan pembayaran dari customer."
      resource="/customer-payments"
      permissionPrefix="sales.customer-payment"
      columns={columns}
      detailHref={(record) => `/documents/customer-payments/${record.id}`}
      createHref="/documents/customer-payments/new"
      statusFilters={listStatuses}
    />
  )
}

export function CustomerPaymentFormPage() {
  const customers = useCustomerOptions()
  const accounts = useAccountOptions(true)

  return (
    <PaymentDocumentPage
      resource="/customer-payments"
      title="Payment In"
      permissionPrefix="sales.customer-payment"
      partnerField="customer_id"
      partnerLabel="Customer"
      partnerOptions={customers.data ?? []}
      invoiceResource="/sales-invoices"
      invoiceField="sales_invoice_id"
      accountOptions={accounts.data ?? []}
    />
  )
}
