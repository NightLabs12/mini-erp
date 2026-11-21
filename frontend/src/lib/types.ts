export interface PaginationMeta {
  current_page: number
  per_page: number
  last_page: number
  total: number
  from: number | null
  to: number | null
}

export interface PaginationLinks {
  first: string | null
  prev: string | null
  next: string | null
  last: string | null
}

export interface ApiCollection<T> {
  data: T[]
  meta: PaginationMeta
  links: PaginationLinks
}

export interface ApiResource<T> {
  data: T
}

export interface SelectOption {
  value: string
  label: string
}
