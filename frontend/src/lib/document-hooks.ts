import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import type { ItemDetail } from './document'
import type { ApiCollection, ApiResource } from './types'

export interface AuditLike {
  id: number
  event: string
  user?: { id: number; name: string; email: string } | null
  diff?: Record<string, { old: unknown; new: unknown }>
  created_at?: string | null
}

export function useDocumentRecord<T>(resource: string, id: number | null) {
  return useQuery({
    queryKey: ['document', resource, id],
    queryFn: () => api.get<ApiResource<T>>(`${resource}/${String(id)}`),
    enabled: id !== null,
  })
}

export function useItemDetail(itemId: string) {
  return useQuery({
    queryKey: ['item-detail', itemId],
    queryFn: () => api.get<ApiResource<ItemDetail>>(`/items/${itemId}`),
    enabled: itemId !== '',
    staleTime: 5 * 60_000,
  })
}

export interface ItemStockResponse {
  item: { id: number; sku: string; name: string; base_unit_id: number }
  total_qty_base: string
  total_value: string
  balances: {
    warehouse_id: number
    warehouse: { id: number; code: string; name: string } | null
    qty_base: string
    avg_cost: string
    value: string
  }[]
}

export function useItemStock(itemId: string) {
  return useQuery({
    queryKey: ['item-stock', itemId],
    queryFn: () => api.get<ItemStockResponse>(`/items/${itemId}/stock`),
    enabled: itemId !== '',
  })
}

export interface SellingPriceLookup {
  id: number
  item_id: number
  unit_id: number
  customer_id: number | null
  price: string
  unit_price_base: string | null
  valid_from: string | null
  valid_to: string | null
}

export function useSellingPriceLookup(
  itemId: string,
  unitId: string,
  customerId: string,
  date: string,
) {
  return useQuery({
    queryKey: ['selling-price-lookup', itemId, unitId, customerId, date],
    queryFn: () =>
      api.get<ApiResource<SellingPriceLookup>>('/selling-prices/lookup', {
        item_id: itemId,
        unit_id: unitId,
        customer_id: customerId === '' ? undefined : customerId,
        date,
      }),
    enabled: itemId !== '' && unitId !== '',
    retry: false,
    staleTime: 60_000,
  })
}

export function useApprovedDocuments(
  resource: string,
  extra: Record<string, string | number | undefined> = {},
  enabled = true,
) {
  return useQuery({
    queryKey: ['approved-documents', resource, extra],
    queryFn: () =>
      api.get<ApiCollection<{ id: number; doc_no: string; doc_date: string }>>(resource, {
        per_page: 100,
        sort: '-doc_date',
        'filter[status]': 'approved',
        ...extra,
      }),
    enabled,
    staleTime: 30_000,
  })
}
