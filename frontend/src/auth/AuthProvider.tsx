import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, ApiError } from '../lib/api'
import { tokenStore } from '../lib/token'
import { AuthContext, type AuthStatus, type AuthUser } from './auth-context'

interface LoginResponse {
  data: {
    token: string
    token_type: string
    expires_at: string
    user: AuthUser
  }
}

interface MeResponse {
  data: AuthUser
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [status, setStatus] = useState<AuthStatus>('loading')

  const reset = useCallback((): void => {
    setUser(null)
    setStatus('unauthenticated')
  }, [])

  const bootstrap = useCallback(async (): Promise<void> => {
    try {
      const response = await api.get<MeResponse>('/auth/me')
      setUser(response.data)
      setStatus('authenticated')
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        tokenStore.clear()
      }

      reset()
    }
  }, [reset])

  useEffect(() => {
    if (tokenStore.get() === null) {
      reset()

      return
    }

    void bootstrap()
  }, [bootstrap, reset])

  useEffect(
    () =>
      tokenStore.subscribe((token) => {
        if (token === null) {
          reset()
        }
      }),
    [reset],
  )

  const login = useCallback(async (email: string, password: string): Promise<void> => {
    const response = await api.post<LoginResponse>('/auth/login', {
      email,
      password,
      device_name: 'web',
    })

    tokenStore.set(response.data.token)
    setUser(response.data.user)
    setStatus('authenticated')
  }, [])

  const logout = useCallback(async (): Promise<void> => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Token mungkin sudah kedaluwarsa; sesi lokal tetap dibersihkan.
    } finally {
      tokenStore.clear()
      reset()
    }
  }, [reset])

  const hasPermission = useCallback(
    (permission: string): boolean => user?.permissions.includes(permission) ?? false,
    [user],
  )

  const hasAnyPermission = useCallback(
    (permissions: string[]): boolean =>
      permissions.some((permission) => user?.permissions.includes(permission) ?? false),
    [user],
  )

  const value = useMemo(
    () => ({ user, status, login, logout, hasPermission, hasAnyPermission }),
    [user, status, login, logout, hasPermission, hasAnyPermission],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
