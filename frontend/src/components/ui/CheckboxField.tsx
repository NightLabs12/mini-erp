import type { InputHTMLAttributes } from 'react'

interface CheckboxFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  id: string
  label: string
  hint?: string
}

export function CheckboxField({ id, label, hint, className, ...props }: CheckboxFieldProps) {
  return (
    <div className="space-y-1">
      <label htmlFor={id} className="inline-flex items-center gap-2 text-sm font-medium text-ink">
        <input
          id={id}
          type="checkbox"
          className={['size-4 rounded border-line text-accent focus:ring-accent/30', className]
            .filter(Boolean)
            .join(' ')}
          {...props}
        />
        {label}
      </label>

      {hint ? <p className="pl-6 text-xs text-ink-muted">{hint}</p> : null}
    </div>
  )
}
