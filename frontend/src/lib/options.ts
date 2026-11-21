import { useQuery } from '@tanstack/react-query'
import { api, type ApiQuery } from './api'
import type { ApiCollection, SelectOption } from './types'

interface NamedRecord {
  id: number
  code?: string
  name: string
  sku?: string
}

async function fetchOptions(path: string, query: ApiQuery): Promise<SelectOption[]> {
  const response = await api.get<ApiCollection<NamedRecord>>(path, query)

  return response.data.map((record) => ({
    value: String(record.id),
    label:
      record.sku !== undefined
        ? `${record.sku} - ${record.name}`
        : record.code !== undefined
          ? `${record.code} - ${record.name}`
          : record.name,
  }))
}

const common = { per_page: 100, sort: 'code' }

export function useUnitOptions() {
  return useQuery({
    queryKey: ['options', 'units'],
    queryFn: () => fetchOptions('/units', common),
    staleTime: 5 * 60_000,
  })
}

export function useItemCategoryOptions() {
  return useQuery({
    queryKey: ['options', 'item-categories'],
    queryFn: () => fetchOptions('/item-categories', common),
    staleTime: 5 * 60_000,
  })
}

export function useItemOptions() {
  return useQuery({
    queryKey: ['options', 'items'],
    queryFn: () => fetchOptions('/items', { per_page: 100, sort: 'sku' }),
    staleTime: 5 * 60_000,
  })
}

export function useCustomerOptions() {
  return useQuery({
    queryKey: ['options', 'customers'],
    queryFn: () => fetchOptions('/customers', common),
    staleTime: 5 * 60_000,
  })
}

export function useAccountOptions(postableOnly = false) {
  return useQuery({
    queryKey: ['options', 'accounts', postableOnly ? 'postable' : 'all'],
    queryFn: () =>
      fetchOptions('/accounts', {
        per_page: 100,
        sort: 'code',
        ...(postableOnly ? { 'filter[is_postable]': 'true' } : {}),
      }),
    staleTime: 5 * 60_000,
  })
}
