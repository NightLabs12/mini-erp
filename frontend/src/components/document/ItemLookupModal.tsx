import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { api } from '../../lib/api'
import type { ApiCollection } from '../../lib/types'
import { EmptyState } from '../ui/EmptyState'
import { Modal } from '../ui/Modal'
import { Skeleton } from '../ui/Skeleton'

export interface LookupItem {
  id: number
  sku: string
  name: string
  base_unit?: { id: number; code: string; name: string } | null
}

interface ItemLookupModalProps {
  open: boolean
  onClose: () => void
  onSelect: (itemId: number) => void
  title?: string
}

export function ItemLookupModal({ open, onClose, onSelect, title = 'Pilih item' }: ItemLookupModalProps) {
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search), 250)

    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) {
      setSearch('')
      setDebounced('')
    }
  }, [open])

  const query = useQuery({
    queryKey: ['lookup', 'items', debounced],
    queryFn: () =>
      api.get<ApiCollection<LookupItem>>('/items', {
        search: debounced === '' ? undefined : debounced,
        per_page: 25,
        sort: 'sku',
      }),
    enabled: open,
  })

  return (
    <Modal
      open={open}
      title={title}
      description="Cari berdasarkan SKU atau nama item."
      onClose={onClose}
      size="md"
    >
      <label className="relative block">
        <span className="sr-only">Cari item</span>
        <input
          autoFocus
          type="search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Ketik SKU atau nama..."
          className="min-h-10 w-full rounded-md border border-line bg-surface px-3 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
      </label>

      <div className="mt-3 max-h-80 overflow-y-auto rounded-md border border-line">
        {query.isPending ? (
          <div className="space-y-2 p-3">
            <Skeleton className="h-10" />
            <Skeleton className="h-10" />
            <Skeleton className="h-10" />
          </div>
        ) : query.isError ? (
          <p role="alert" className="p-4 text-sm text-danger">
            Gagal memuat item.
          </p>
        ) : query.data.data.length === 0 ? (
          <EmptyState title="Item tidak ditemukan" description="Coba kata kunci lain." />
        ) : (
          <ul className="divide-y divide-line/70">
            {query.data.data.map((item) => (
              <li key={item.id}>
                <button
                  type="button"
                  onClick={() => {
                    onSelect(item.id)
                    onClose()
                  }}
                  className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-paper"
                >
                  <span>
                    <span className="font-medium">{item.sku}</span>
                    <span className="ml-2 text-ink-muted">{item.name}</span>
                  </span>
                  <span className="text-xs text-ink-muted">{item.base_unit?.code ?? ''}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  )
}
