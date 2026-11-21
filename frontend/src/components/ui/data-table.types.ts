import type { ReactNode } from 'react'

export interface DataTableColumn<T> {
  id: string
  header: string
  render: (row: T) => ReactNode
  sortable?: boolean
  align?: 'left' | 'right' | 'center'
  className?: string
}
