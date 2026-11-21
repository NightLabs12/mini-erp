import type { ReactNode } from 'react'

export const inputClassName =
  'block w-full min-h-10 rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-muted/70 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-paper disabled:text-ink-muted'

export interface FormFieldRenderProps {
  id: string
  describedBy?: string
  invalid: boolean
}

interface FormFieldProps {
  id: string
  label: string
  hint?: string
  error?: string
  required?: boolean
  children: ReactNode | ((props: FormFieldRenderProps) => ReactNode)
}

export function FormField({ id, label, hint, error, required = false, children }: FormFieldProps) {
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const describedBy = errorId ?? hintId

  const content =
    typeof children === 'function'
      ? children({ id, describedBy, invalid: error !== undefined })
      : children

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-ink">
        {label}
        {required ? (
          <span aria-hidden="true" className="ml-0.5 text-danger">
            *
          </span>
        ) : null}
      </label>

      {content}

      {hint && !error ? (
        <p id={hintId} className="text-xs text-ink-muted">
          {hint}
        </p>
      ) : null}

      {error ? (
        <p id={errorId} role="alert" className="text-xs font-medium text-danger">
          {error}
        </p>
      ) : null}
    </div>
  )
}
