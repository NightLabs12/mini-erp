import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ToastContext, type ToastMessage, type ToastTone } from './toast-context'

const DURATION_MS = 5000

const toneStyles: Record<ToastTone, string> = {
  success: 'border-accent/30 bg-accent-soft text-accent',
  error: 'border-danger/30 bg-danger-soft text-danger',
  info: 'border-line bg-surface text-ink',
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [messages, setMessages] = useState<ToastMessage[]>([])
  const counter = useRef(0)
  const timers = useRef(new Set<ReturnType<typeof setTimeout>>())

  useEffect(() => {
    const pending = timers.current

    return () => {
      for (const timer of pending) {
        clearTimeout(timer)
      }

      pending.clear()
    }
  }, [])

  const dismiss = useCallback((id: string): void => {
    setMessages((current) => current.filter((message) => message.id !== id))
  }, [])

  const push = useCallback(
    (message: string, tone: ToastTone = 'info'): void => {
      counter.current += 1
      const id = `${Date.now()}-${counter.current}`

      setMessages((current) => [...current, { id, message, tone }])

      const timer = setTimeout(() => {
        dismiss(id)
        timers.current.delete(timer)
      }, DURATION_MS)

      timers.current.add(timer)
    },
    [dismiss],
  )

  const value = useMemo(() => ({ push }), [push])

  return (
    <ToastContext.Provider value={value}>
      {children}

      <div
        aria-live="polite"
        aria-atomic="false"
        className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:pr-6"
      >
        {messages.map((message) => (
          <div
            key={message.id}
            role={message.tone === 'error' ? 'alert' : 'status'}
            className={[
              'pointer-events-auto flex w-full max-w-sm items-start justify-between gap-3 rounded-md border px-4 py-3 text-sm shadow-lg',
              toneStyles[message.tone],
            ].join(' ')}
          >
            <span>{message.message}</span>
            <button
              type="button"
              onClick={() => dismiss(message.id)}
              className="rounded p-0.5 text-current/70 hover:text-current"
              aria-label="Tutup notifikasi"
            >
              <svg aria-hidden="true" viewBox="0 0 20 20" className="size-4" fill="currentColor">
                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
              </svg>
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}
