import type { DataTableColumn } from '../../components/ui/data-table.types'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { formatCurrency, formatDate } from '../../lib/format'

export interface DocListRecord {
  id: number
  doc_no: string
  doc_date: string
  status: string
  notes?: string | null
  due_date?: string | null
  total?: string
  supplier?: { id: number; code: string; name: string } | null
  customer?: { id: number; code: string; name: string } | null
  warehouse?: { id: number; code: string; name: string } | null
  from_warehouse?: { id: number; code: string; name: string } | null
  to_warehouse?: { id: number; code: string; name: string } | null
  method?: string
  amount?: string
}

export function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export const docNoColumn: DataTableColumn<DocListRecord> = {
  id: 'doc_no',
  header: 'Nomor',
  sortable: true,
  render: (row) => <span className="font-medium">{row.doc_no}</span>,
}

export const docDateColumn: DataTableColumn<DocListRecord> = {
  id: 'doc_date',
  header: 'Tanggal',
  sortable: true,
  render: (row) => formatDate(row.doc_date),
}

export const statusColumn: DataTableColumn<DocListRecord> = {
  id: 'status',
  header: 'Status',
  sortable: true,
  render: (row) => <StatusBadge status={row.status} />,
}

export function partnerColumn(
  key: 'supplier' | 'customer',
  header: string,
): DataTableColumn<DocListRecord> {
  return {
    id: key,
    header,
    render: (row) => row[key]?.name ?? '—',
  }
}

export function warehouseColumn(
  key: 'warehouse' | 'from_warehouse' | 'to_warehouse',
  header: string,
): DataTableColumn<DocListRecord> {
  return {
    id: key,
    header,
    render: (row) => row[key]?.code ?? '—',
  }
}

export const totalColumn: DataTableColumn<DocListRecord> = {
  id: 'total',
  header: 'Total',
  align: 'right',
  sortable: true,
  render: (row) => (row.total === undefined ? '—' : formatCurrency(row.total)),
}

export const amountColumn: DataTableColumn<DocListRecord> = {
  id: 'amount',
  header: 'Nilai',
  align: 'right',
  sortable: true,
  render: (row) => (row.amount === undefined ? '—' : formatCurrency(row.amount)),
}

export const listStatuses = ['draft', 'approved', 'closed', 'cancelled']
