import { useParams } from 'react-router-dom'
import { NotFoundPage } from '../NotFoundPage'
import { documentRoutes } from './registry'

export function DocumentListRoute() {
  const { docType } = useParams<{ docType: string }>()
  const entry = docType === undefined ? undefined : documentRoutes[docType]

  if (entry === undefined) {
    return <NotFoundPage />
  }

  const List = entry.list

  return <List key={docType} />
}

export function DocumentFormRoute() {
  const { docType, id } = useParams<{ docType: string; id?: string }>()
  const entry = docType === undefined ? undefined : documentRoutes[docType]

  if (entry === undefined) {
    return <NotFoundPage />
  }

  const Form = entry.form

  return <Form key={`${docType}-${id ?? 'new'}`} />
}
