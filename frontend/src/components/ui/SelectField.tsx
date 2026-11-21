import type { SelectHTMLAttributes } from 'react'
import type { SelectOption } from '../../lib/types'
import { inputClassName } from './FormField'

interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  id: string
  label: string
  options: SelectOption[]
  error?: string
  hint?: string
  required?: boolean
  placeholder?: string
}

export function SelectField({
  id,
  label,
  options,
  error,
  hint,
  required = false,
  placeholder,
  className,
  ...props
}: SelectFieldProps) {
  const errorId = error ? `${id}-error` : undefined
  const hintId = hint ? `${id}-hint` : undefined

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

      <select
        id={id}
        aria-invalid={error !== undefined}
        aria-describedby={errorId ?? hintId}
        className={[inputClassName, className].filter(Boolean).join(' ')}
        {...props}
      >
        {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>

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
