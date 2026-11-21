import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/auth-context'
import { Skeleton } from '../components/ui/Skeleton'
import { t } from '../lib/i18n'
import { AppLayout } from './AppLayout'

function FullPageLoading() {
  return (
    <div aria-busy="true" aria-live="polite" className="mx-auto max-w-6xl space-y-4 px-4 py-10">
      <p className="sr-only">{t('common.loading')}</p>
      <Skeleton className="h-8 w-56" />
      <Skeleton className="h-24 w-full" />
      <Skeleton className="h-24 w-full" />
    </div>
  )
}

export function ProtectedLayout() {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'loading') {
    return <FullPageLoading />
  }

  if (status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <AppLayout />
}
