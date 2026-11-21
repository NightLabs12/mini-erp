import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/auth-context'
import { FormField, inputClassName } from '../components/ui/FormField'
import { toErrorMessage } from '../lib/api'
import { t } from '../lib/i18n'

interface LoginForm {
  email: string
  password: string
}

const demoAccounts = [
  'admin@demo.test',
  'purchasing@demo.test',
  'sales@demo.test',
  'warehouse@demo.test',
  'finance@demo.test',
  'viewer@demo.test',
]

interface LocationState {
  from?: string
}

export function LoginPage() {
  const { status, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({ defaultValues: { email: '', password: '' } })

  if (status === 'authenticated') {
    return <Navigate to="/dashboard" replace />
  }

  const state = location.state as LocationState | null
  const redirectTo = state?.from ?? '/dashboard'

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)

    try {
      await login(values.email.trim(), values.password)
      navigate(redirectTo, { replace: true })
    } catch (error) {
      setFormError(toErrorMessage(error))
    }
  })

  return (
    <div className="flex min-h-dvh items-center justify-center px-4 py-10">
      <div className="grid w-full max-w-4xl overflow-hidden rounded-lg border border-line bg-surface shadow-sm md:grid-cols-2">
        <div className="hidden flex-col justify-between bg-accent p-8 text-white md:flex">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-white/70">
              {t('app.name')}
            </p>
            <h1 className="mt-2 text-2xl font-semibold">{t('app.company')}</h1>
            <p className="mt-2 text-sm text-white/80">{t('app.tagline')}</p>
          </div>

          <div className="mt-8">
            <p className="text-sm font-semibold">{t('login.demoTitle')}</p>
            <ul className="mt-2 space-y-1 text-sm text-white/80">
              {demoAccounts.map((email) => (
                <li key={email}>{email}</li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-white/70">{t('login.demoHint')}</p>
          </div>
        </div>

        <div className="p-6 sm:p-8">
          <h2 className="text-xl font-semibold tracking-tight">{t('login.title')}</h2>
          <p className="mt-1 text-sm text-ink-muted">{t('login.lead')}</p>

          <form className="mt-6 space-y-4" onSubmit={(event) => void onSubmit(event)} noValidate>
            <FormField id="email" label={t('login.email')} required error={errors.email?.message}>
              {(field) => (
                <input
                  {...register('email', {
                    required: 'Email wajib diisi.',
                    pattern: { value: /\S+@\S+\.\S+/, message: 'Format email tidak valid.' },
                  })}
                  id={field.id}
                  type="email"
                  autoComplete="username"
                  aria-invalid={field.invalid}
                  aria-describedby={field.describedBy}
                  className={inputClassName}
                />
              )}
            </FormField>

            <FormField id="password" label={t('login.password')} required error={errors.password?.message}>
              {(field) => (
                <input
                  {...register('password', { required: 'Password wajib diisi.' })}
                  id={field.id}
                  type="password"
                  autoComplete="current-password"
                  aria-invalid={field.invalid}
                  aria-describedby={field.describedBy}
                  className={inputClassName}
                />
              )}
            </FormField>

            {formError ? (
              <p role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
                {formError}
              </p>
            ) : null}

            <button
              type="submit"
              disabled={isSubmitting}
              className="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-accent px-4 text-sm font-semibold text-white hover:bg-accent/90 disabled:opacity-60"
            >
              {isSubmitting ? t('login.submitting') : t('login.submit')}
            </button>
          </form>

          <p className="mt-4 text-xs text-ink-muted md:hidden">{t('login.demoHint')}</p>
        </div>
      </div>
    </div>
  )
}
