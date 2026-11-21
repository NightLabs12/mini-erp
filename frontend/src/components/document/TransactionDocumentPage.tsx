import { useEffect, useState, type ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/auth-context'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { DocumentShell, type DocumentAction } from '../../components/document/DocumentShell'
import { TransactionEditor, type HeaderFieldSpec, type SourceSpec, type TransactionMode } from '../../components/document/TransactionEditor'
import { useDocumentActions } from '../../components/document/useDocumentActions'
import { useToast } from '../../components/ui/toast-context'
import { ApiError, api, toErrorMessage } from '../../lib/api'
import { useDocumentRecord } from '../../lib/document-hooks'
import type { LineRow } from '../../lib/document'

export interface TransactionDocumentPageProps {
  resource: string
  title: string
  permissionPrefix: string
  mode: TransactionMode
  fields: HeaderFieldSpec[]
  defaultHeader: Record<string, string>
  recordToHeader: (record: Record<string, unknown>) => Record<string, string>
  recordToRows: (record: Record<string, unknown>) => LineRow[]
  buildPayload: (header: Record<string, string>, rows: LineRow[]) => Record<string, unknown>
  showDiscount?: boolean
  priceEditable?: boolean
  taxEnabled?: boolean
  totalsEnabled?: boolean
  source?: SourceSpec
  stockFor?: (itemId: string, header: Record<string, string>) => Promise<string | null>
  stockWarehouseField?: string
  autoPrice?: (
    itemId: string,
    unitId: string,
    header: Record<string, string>,
  ) => Promise<string | null>
  print?: boolean
  closable?: boolean
  extraActions?: DocumentAction[]
  minRows?: number
  readOnlyStatuses?: string[]
  onChanged?: () => void
  children?: ReactNode
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export function TransactionDocumentPage({
  resource,
  title,
  permissionPrefix,
  mode,
  fields,
  defaultHeader,
  recordToHeader,
  recordToRows,
  buildPayload,
  showDiscount = false,
  priceEditable = false,
  taxEnabled = false,
  totalsEnabled = false,
  source,
  stockFor,
  stockWarehouseField,
  autoPrice,
  print = false,
  closable = false,
  extraActions,
  minRows = 1,
  readOnlyStatuses,
  onChanged,
  children,
}: TransactionDocumentPageProps) {
  const params = useParams<{ id?: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const { hasPermission } = useAuth()

  const id = params.id === undefined ? null : Number(params.id)
  const isEdit = id !== null && Number.isFinite(id)
  const recordQuery = useDocumentRecord<Record<string, unknown>>(resource, isEdit ? id : null)

  const [header, setHeader] = useState<Record<string, string>>({
    doc_date: today(),
    ...defaultHeader,
  })
  const [rows, setRows] = useState<LineRow[]>([])
  const [initializedId, setInitializedId] = useState<number | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [submitting, setSubmitting] = useState(false)

  const record = recordQuery.data?.data
  const status = typeof record?.status === 'string' ? record.status : 'draft'
  const readOnly =
    isEdit &&
    (readOnlyStatuses === undefined ? status !== 'draft' : readOnlyStatuses.includes(status))

  useEffect(() => {
    if (record === undefined || initializedId === (id ?? 0)) {
      return
    }

    setHeader({ doc_date: today(), ...recordToHeader(record) })
    setRows(recordToRows(record))
    setInitializedId(id ?? 0)
  }, [record, id, initializedId, recordToHeader, recordToRows])

  const actions = useDocumentActions({
    resource,
    permissionPrefix,
    id: isEdit ? id : null,
    onChanged: () => {
      void recordQuery.refetch()
      onChanged?.()
    },
  })

  const availableActions = isEdit
    ? actions.buildActions(status, { print, closable, extra: extraActions })
    : []
  const canUpdate = !isEdit || hasPermission(`${permissionPrefix}.update`)

  const submit = async (): Promise<void> => {
    setFormError(null)
    setFieldErrors({})

    const errors: Record<string, string[]> = {}

    for (const field of fields) {
      if (field.required === true && (header[field.name] ?? '') === '') {
        errors[field.name] = [`${field.label} wajib diisi.`]
      }
    }

    if (rows.length < minRows) {
      errors.items = [`Minimal ${minRows} baris.`]
    }

    if (source !== undefined && rows.some((row) => row.source_line_id === '')) {
      errors.items = ['Muat baris dari dokumen sumber terlebih dahulu.']
    }

    rows.forEach((row, index) => {
      if (row.item_id === '') {
        errors[`items.${index}.item_id`] = ['Pilih item.']
      }

      if (row.unit_id === '') {
        errors[`items.${index}.unit_id`] = ['Pilih satuan.']
      }

      if (Number(row.qty) <= 0) {
        errors[`items.${index}.qty`] = ['Qty harus lebih besar dari nol.']
      }
    })

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      setFormError('Periksa kembali isian yang ditandai.')

      return
    }

    setSubmitting(true)

    try {
      const payload = buildPayload(header, rows)

      if (isEdit) {
        await api.put(`${resource}/${String(id)}`, payload)
        toast.push('Dokumen berhasil diperbarui.', 'success')
      } else {
        const created = await api.post<{ data: { id: number } }>(resource, payload)
        toast.push('Dokumen berhasil dibuat.', 'success')
        navigate(`/documents/${resource.replace(/^\//, '')}/${String(created.data.id)}`)
      }

      await recordQuery.refetch()
      onChanged?.()
    } catch (error) {
      if (error instanceof ApiError) {
        setFieldErrors(error.errors)
        setFormError(`${error.code}: ${error.message}`)
      } else {
        setFormError(toErrorMessage(error))
      }

      toast.push(toErrorMessage(error), 'error')
    } finally {
      setSubmitting(false)
    }
  }

  const confirmAction = actions.pendingAction

  return (
    <DocumentShell
      title={isEdit ? `${title} ${record?.doc_no ?? ''}`.trim() : `Buat ${title}`}
      docNo={typeof record?.doc_no === 'string' ? record.doc_no : undefined}
      status={isEdit ? status : undefined}
      backTo={`/documents/${resource.replace(/^\//, '')}`}
      actions={availableActions}
      onAction={(key) => {
        const action = availableActions.find((item) => item.key === key)

        if (action === undefined) {
          return
        }

        if (action.confirmMessage !== undefined) {
          actions.setPendingAction(action)
        } else {
          void actions.runAction(key)
        }
      }}
      busyAction={actions.busyAction}
      businessError={actions.businessError}
      auditResource={isEdit ? resource : undefined}
      auditId={isEdit ? id : undefined}
      footer={
        isEdit ? null : (
          <div className="flex flex-wrap items-center justify-end gap-2">
            <button
              type="button"
              onClick={() => navigate(-1)}
              disabled={submitting}
              className="inline-flex min-h-10 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted disabled:opacity-60"
            >
              Batal
            </button>
            <button
              type="button"
              onClick={() => void submit()}
              disabled={submitting}
              className="inline-flex min-h-10 items-center rounded-md bg-accent px-4 text-sm font-semibold text-white hover:bg-accent/90 disabled:opacity-60"
            >
              {submitting ? 'Menyimpan...' : 'Simpan dokumen'}
            </button>
          </div>
        )
      }
    >
      {recordQuery.isError ? (
        <div role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
          {toErrorMessage(recordQuery.error)}
        </div>
      ) : null}

      {formError !== null && !readOnly ? (
        <p role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-2 text-sm text-danger">
          {formError}
        </p>
      ) : null}

      <TransactionEditor
        readOnly={readOnly || submitting}
        header={header}
        onHeaderChange={setHeader}
        fields={fields}
        rows={rows}
        onRowsChange={setRows}
        mode={mode}
        showDiscount={showDiscount}
        priceEditable={priceEditable}
        taxEnabled={taxEnabled}
        totalsEnabled={totalsEnabled}
        source={source}
        stockFor={stockFor}
        stockWarehouseField={stockWarehouseField}
        autoPrice={autoPrice}
        errors={fieldErrors}
        minRows={minRows}
      />

      {children}

      {isEdit && !readOnly && canUpdate ? (
        <div className="flex flex-wrap items-center justify-end gap-2">
          <button
            type="button"
            onClick={() => void submit()}
            disabled={submitting}
            className="inline-flex min-h-10 items-center rounded-md bg-accent px-4 text-sm font-semibold text-white hover:bg-accent/90 disabled:opacity-60"
          >
            {submitting ? 'Menyimpan...' : 'Simpan perubahan'}
          </button>
        </div>
      ) : null}

      <ConfirmDialog
        open={confirmAction !== null}
        title={confirmAction?.label ?? 'Konfirmasi'}
        description={confirmAction?.confirmMessage}
        danger={confirmAction?.tone === 'danger'}
        busy={actions.busyAction !== null}
        onConfirm={() => {
          if (confirmAction !== null) {
            void actions.runAction(confirmAction.key)
          }
        }}
        onCancel={() => actions.setPendingAction(null)}
      />
    </DocumentShell>
  )
}
