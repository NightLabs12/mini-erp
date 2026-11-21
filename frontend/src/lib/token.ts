const STORAGE_KEY = 'minierp.token'

type Listener = (token: string | null) => void

const listeners = new Set<Listener>()

let cached: string | null | undefined

function storage(): Storage | null {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    return window.localStorage
  } catch {
    return null
  }
}

function read(): string | null {
  if (cached !== undefined) {
    return cached
  }

  cached = storage()?.getItem(STORAGE_KEY) ?? null

  return cached
}

function notify(token: string | null): void {
  for (const listener of listeners) {
    listener(token)
  }
}

export const tokenStore = {
  get(): string | null {
    return read()
  },

  set(token: string): void {
    cached = token
    storage()?.setItem(STORAGE_KEY, token)
    notify(token)
  },

  clear(): void {
    cached = null
    storage()?.removeItem(STORAGE_KEY)
    notify(null)
  },

  subscribe(listener: Listener): () => void {
    listeners.add(listener)

    return () => {
      listeners.delete(listener)
    }
  },
}
