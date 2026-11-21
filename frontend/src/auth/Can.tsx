import type { ReactNode } from 'react'
import { useAuth } from './auth-context'

interface CanProps {
  permission: string
  fallback?: ReactNode
  children: ReactNode
}

/**
 * Menampilkan isi hanya bila pengguna memiliki permission tertentu.
 *
 * Ini hanya lapisan tampilan; backend tetap menegakkan otorisasi.
 */
export function Can({ permission, fallback = null, children }: CanProps) {
  const { hasPermission } = useAuth()

  return <>{hasPermission(permission) ? children : fallback}</>
}
