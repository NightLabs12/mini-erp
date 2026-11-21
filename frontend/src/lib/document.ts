import { decAdd, decDiv, decMul, decRound, decSub, decSum } from './decimal'

export type DocStatus = 'draft' | 'approved' | 'closed' | 'cancelled'

export interface ItemUnit {
  id: number
  code: string
  name: string
}

export interface ItemConversion {
  id?: number
  unit_id: number
  factor_to_base: string
  unit?: ItemUnit | null
}

export interface ItemDetail {
  id: number
  sku: string
  name: string
  base_unit_id: number
  base_unit?: ItemUnit | null
  min_stock?: string
  conversions?: ItemConversion[]
}

export interface LineRow {
  key: string
  item_id: string
  item_label: string
  unit_id: string
  unit_code: string
  qty: string
  unit_price: string
  discount_pct: string
  qty_base: string
  gross_line: string
  line_total: string
  source_line_id: string
  available?: string
}

export interface DocumentItem {
  id: number
  item_id: number
  item?: { id: number; sku: string; name: string; base_unit_id: number } | null
  unit_id: number
  unit?: ItemUnit | null
  line_no: number
  qty?: string
  qty_base?: string
  unit_price?: string
  discount_pct?: string
  unit_price_base?: string
  line_total?: string
  received_qty_base?: string
  invoiced_qty_base?: string
  delivered_qty_base?: string
  unit_cost_base?: string
  total_value?: string
  cogs_unit_cost_base?: string
  total_cogs?: string
  system_qty_base?: string
  counted_qty_base?: string
  diff_qty_base?: string
  purchase_order_item_id?: number | null
  sales_order_item_id?: number | null
  goods_receipt_item_id?: number | null
  delivery_order_item_id?: number | null
}

export interface DocumentRecord {
  id: number
  doc_no: string
  doc_date: string
  status: DocStatus
  notes?: string | null
  approved_at?: string | null
  approved_by?: number | null
  items?: DocumentItem[]
  [key: string]: unknown
}

export function newLine(): LineRow {
  return {
    key: `line-${Math.random().toString(36).slice(2, 10)}`,
    item_id: '',
    item_label: '',
    unit_id: '',
    unit_code: '',
    qty: '1',
    unit_price: '0',
    discount_pct: '0',
    qty_base: '0',
    gross_line: '0.00',
    line_total: '0.00',
    source_line_id: '',
  }
}

export function factorFor(item: ItemDetail | null, unitId: string): string {
  if (item === null || unitId === '') {
    return '1'
  }

  if (String(item.base_unit_id) === unitId) {
    return '1'
  }

  const conversion = (item.conversions ?? []).find(
    (row) => String(row.unit_id) === unitId,
  )

  return conversion?.factor_to_base ?? '1'
}

/**
 * Perhitungan baris mengikuti backend:
 * qty_base = round(qty * faktor, 4)
 * gross = round(qty_base * (harga / faktor), 2)
 * net = round(qty_base * (harga bersih / faktor), 2)
 */
export function computeLine(row: LineRow, item: ItemDetail | null): LineRow {
  const factor = factorFor(item, row.unit_id)
  const qty = row.qty === '' ? '0' : row.qty
  const price = row.unit_price === '' ? '0' : row.unit_price
  const discount = row.discount_pct === '' ? '0' : row.discount_pct

  const qtyBase = decRound(decMul(qty, factor, 6), 4)
  const grossUnitBase = decDiv(price, factor, 4)
  const grossLine = decRound(decMul(qtyBase, grossUnitBase, 6), 2)
  const netUnitPrice = decDiv(decMul(price, decSub('100', discount, 4), 6), '100', 6)
  const netUnitBase = decDiv(netUnitPrice, factor, 4)
  const lineTotal = decRound(decMul(qtyBase, netUnitBase, 6), 2)

  return {
    ...row,
    qty_base: qtyBase,
    gross_line: grossLine,
    line_total: lineTotal,
  }
}

export interface DocumentTotals {
  subtotal: string
  discount: string
  tax: string
  total: string
}

export function computeTotals(rows: LineRow[], taxRate: string): DocumentTotals {
  const subtotal = decSum(rows.map((row) => row.gross_line), 2)
  const discount = decSum(
    rows.map((row) => decSub(row.gross_line, row.line_total, 2)),
    2,
  )
  const taxBase = decSub(subtotal, discount, 2)
  const tax = decRound(decDiv(decMul(taxBase, taxRate === '' ? '0' : taxRate, 6), '100', 6), 2)

  return {
    subtotal,
    discount,
    tax,
    total: decAdd(taxBase, tax, 2),
  }
}

export function unitPriceFromBase(baseCost: string, factor: string): string {
  return decRound(decMul(baseCost, factor, 6), 2)
}

export function qtyRemaining(ordered: string | undefined, used: string | undefined): string {
  return decSub(ordered ?? '0', used ?? '0', 4)
}
