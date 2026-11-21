import type { InputHTMLAttributes } from 'react'
import { FormField, inputClassName } from './FormField'

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  id: string
  label: string
  error?: string
  hint?: string
  required?: boolean
}

export function TextField({
  id,
  label,
  error,
  hint,
  required = false,
  className,
  ...props
}: TextFieldProps) {
  return (
    <FormField id={id} label={label} error={error} hint={hint} required={required}>
      {(field) => (
        <input
          id={field.id}
          aria-invalid={field.invalid}
          aria-describedby={field.describedBy}
          className={[inputClassName, className].filter(Boolean).join(' ')}
          {...props}
        />
      )}
    </FormField>
  )
}
