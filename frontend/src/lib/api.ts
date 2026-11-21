import { tokenStore } from './token'

export interface ApiErrorBody {
  message: string
  code: string
  errors: Record<string, string[]>
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly errors: Record<string, string[]>

  constructor(status: number, body: ApiErrorBody) {
    super(body.message)
    this.name = 'ApiError'
    this.status = status
    this.code = body.code
    this.errors = body.errors
  }
}

export interface ApiQuery {
  [key: string]: string | number | boolean | null | undefined
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: ApiQuery
  signal?: AbortSignal
}

const baseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1'

function normalizeErrors(value: unknown): Record<string, string[]> {
  if (typeof value !== 'object' || value === null) {
    return {}
  }

  const errors: Record<string, string[]> = {}

  for (const [key, messages] of Object.entries(value)) {
    if (Array.isArray(messages)) {
      errors[key] = messages.filter((message): message is string => typeof message === 'string')
    } else if (typeof messages === 'string') {
      errors[key] = [messages]
    }
  }

  return errors
}

function toErrorBody(payload: unknown, status: number): ApiErrorBody {
  if (
    typeof payload === 'object' &&
    payload !== null &&
    'message' in payload &&
    typeof payload.message === 'string'
  ) {
    return {
      message: payload.message,
      code: 'code' in payload && typeof payload.code === 'string' ? payload.code : `HTTP_${status}`,
      errors: 'errors' in payload ? normalizeErrors(payload.errors) : {},
    }
  }

  return {
    message: 'Permintaan gagal diproses.',
    code: `HTTP_${status}`,
    errors: {},
  }
}

function buildUrl(path: string, query?: ApiQuery): string {
  const url = `${baseUrl}${path}`

  if (!query) {
    return url
  }

  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') {
      continue
    }

    params.set(key, String(value))
  }

  const serialized = params.toString()

  return serialized === '' ? url : `${url}?${serialized}`
}

function parseJson(text: string): unknown {
  try {
    return JSON.parse(text) as unknown
  } catch {
    return null
  }
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = tokenStore.get()

  if (token !== null) {
    headers.Authorization = `Bearer ${token}`
  }

  let body: string | undefined

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }

  const response = await fetch(buildUrl(path, options.query), {
    method: options.method ?? 'GET',
    headers,
    body,
    signal: options.signal,
  })

  if (response.status === 204) {
    return undefined as T
  }

  const text = await response.text()
  const payload = text === '' ? null : parseJson(text)

  if (!response.ok) {
    const errorBody = toErrorBody(payload, response.status)

    // Token kedaluwarsa atau dicabut: bersihkan agar AuthProvider
    // mengarahkan pengguna kembali ke halaman login.
    if (response.status === 401 && !path.startsWith('/auth/login')) {
      tokenStore.clear()
    }

    throw new ApiError(response.status, errorBody)
  }

  return payload as T
}

export const api = {
  get<T>(path: string, query?: ApiQuery, signal?: AbortSignal): Promise<T> {
    return apiRequest<T>(path, { query, signal })
  },

  post<T>(path: string, body?: unknown): Promise<T> {
    return apiRequest<T>(path, { method: 'POST', body })
  },

  put<T>(path: string, body?: unknown): Promise<T> {
    return apiRequest<T>(path, { method: 'PUT', body })
  },

  patch<T>(path: string, body?: unknown): Promise<T> {
    return apiRequest<T>(path, { method: 'PATCH', body })
  },

  delete<T>(path: string): Promise<T> {
    return apiRequest<T>(path, { method: 'DELETE' })
  },
}

/**
 * Mengunduh berkas (PDF/Excel) dengan header Authorization, lalu memicu
 * unduhan di browser. `window.open` biasa tidak membawa bearer token.
 */
export async function downloadFile(path: string, fallbackName: string): Promise<void> {
  const headers: Record<string, string> = {}
  const token = tokenStore.get()

  if (token !== null) {
    headers.Authorization = `Bearer ${token}`
  }

  const response = await fetch(`${baseUrl}${path}`, { headers })

  if (!response.ok) {
    const text = await response.text()
    throw new ApiError(response.status, toErrorBody(text === '' ? null : parseJson(text), response.status))
  }

  const blob = await response.blob()
  const disposition = response.headers.get('content-disposition') ?? ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const filename = match?.[1] ?? fallbackName
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

export function toErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message
  }

  if (error instanceof Error) {
    return error.message
  }

  return 'Terjadi kesalahan yang tidak diketahui.'
}
