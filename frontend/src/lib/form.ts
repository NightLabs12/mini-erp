import { get, type FieldErrors, type FieldValues, type Path, type UseFormSetError } from 'react-hook-form'
import type { ZodType } from 'zod'
import { ApiError } from './api'

/**
 * Menjalankan validasi zod dan memetakan issue ke error field react-hook-form.
 *
 * Path bersarang seperti `conversions.0.unit_id` dipakai apa adanya karena
 * react-hook-form mendukung path bertitik pada setError.
 */
export function validateWithZod<T extends FieldValues>(
  schema: ZodType<T>,
  values: T,
  setError: UseFormSetError<T>,
): boolean {
  const result = schema.safeParse(values)

  if (result.success) {
    return true
  }

  for (const issue of result.error.issues) {
    const path = issue.path.join('.')

    setError(path as Path<T>, { type: 'validation', message: issue.message })
  }

  return false
}

/**
 * Memetakan error 422 backend ke field form. Mengembalikan pesan umum bila
 * error tidak memiliki detail field.
 */
export function applyApiError<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
): string | null {
  if (error instanceof ApiError) {
    const entries = Object.entries(error.errors)

    if (entries.length > 0) {
      for (const [field, messages] of entries) {
        setError(field as Path<T>, {
          type: 'server',
          message: messages[0] ?? error.message,
        })
      }

      return null
    }

    return error.message
  }

  return error instanceof Error ? error.message : 'Terjadi kesalahan yang tidak diketahui.'
}

export function fieldError(errors: unknown, path: string): string | undefined {
  const value: unknown = get(errors as FieldErrors, path)

  if (typeof value === 'object' && value !== null && 'message' in value) {
    const message = (value as { message?: unknown }).message

    return typeof message === 'string' ? message : undefined
  }

  return undefined
}
