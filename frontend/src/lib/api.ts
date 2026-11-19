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

const baseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1'

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
      errors:
        'errors' in payload && typeof payload.errors === 'object' && payload.errors !== null
          ? (payload.errors as Record<string, string[]>)
          : {},
    }
  }

  return {
    message: 'Permintaan gagal diproses.',
    code: `HTTP_${status}`,
    errors: {},
  }
}

export async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${baseUrl}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...init?.headers,
    },
  })

  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    throw new ApiError(response.status, toErrorBody(payload, response.status))
  }

  return payload as T
}
