import { createContext, useContext } from 'react'

export type ToastTone = 'success' | 'error' | 'info'

export interface ToastMessage {
  id: string
  message: string
  tone: ToastTone
}

export interface ToastContextValue {
  push: (message: string, tone?: ToastTone) => void
}

export const ToastContext = createContext<ToastContextValue | null>(null)

export function useToast(): ToastContextValue {
  const context = useContext(ToastContext)

  if (context === null) {
    throw new Error('useToast harus dipakai di dalam ToastProvider.')
  }

  return context
}
