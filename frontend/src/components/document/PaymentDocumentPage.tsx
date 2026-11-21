import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/auth-context'
import { ConfirmDialog } from '../ui/ConfirmDialog'
import { SelectField } from '../ui/SelectField'
import { TextField } from '../ui/TextField'
import { DocumentShell, type DocumentAction } from './DocumentShell'
import { useDocumentActions } from './useDocumentActions'
import { useToast } from '../ui/toast-context'
import { ApiError, api, toErrorMessage } from '../../lib/api'
import { decAdd, decCompare, decSub } from '../../lib/decimal'
import { useDocumentRecord } from '../../lib/document-hooks'
import { formatCurrency } from '../../lib/format'
import type { SelectOption } from '../../lib/types'

export interface PaymentInvoice {
  id: number
  doc_no: string
  total: string
  paid_amount: string
  due_date?: string
}

export interface PaymentAllocationRow {
  key: string
  invoice_id: string
  amount: string
  remaining: string
  doc_no: string
}

export interface PaymentDocumentPageProps {
  resource: string
  title: string
  permissionPrefix: string
  partnerField: 'supplier_id' | 'customer_id'
  partnerLabel: string
  partnerOptions: SelectOption[]
  invoiceResource: string
  invoiceField: 'purchase_invoice_id' | 'sales_invoice_id'
  accountOptions: SelectOption[]
  defaultMethod?: 'cash' | 'bank'
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function newAllocation(): PaymentAllocationRow {
  return {
    key: `alloc-${Math.random().toString(36).slice(2, 10)}`,
    invoice_id: '',
    amount: '',
    remaining: '0.00',
    doc_no: '',
  }
}

export function PaymentDocumentPage({
  resource,
  title,
  permissionPrefix,
  partnerField,
  partnerLabel,
  partnerOptions,
  invoiceResource,
  invoiceField,
  accountOptions,
  defaultMethod = 'bank',
}: PaymentDocumentPageProps) {
  const params = useParams<{ id?: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const { hasPermission } = useAuth()

  const id = params.id === undefined ? null : Number(params.id)
  const isEdit = id !== null && Number.isFinite(id)
  const recordQuery = useDocumentRecord<Record<string, unknown>>(resource, isEdit ? id : null)
  const record = recordQuery.data?.data
  const status = typeof record?.status === 'string' ? record.status : 'draft'
  const readOnly = isEdit && status !== 'draft'

  const [header, setHeader] = useState<Record<string, string>>({
    doc_date: today(),
    [partnerField]: '',
    method: defaultMethod,
    account_id: '',
    notes: '',
  })
  const [rows, setRows] = useState<PaymentAllocationRow[]>([newAllocation()])
  const [invoices, setInvoices] = useState<PaymentInvoice[]>([])
  const [initialized, setInitialized] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (record === undefined || initialized) {
      return
    }

    setHeader({
      doc_date: typeof record.doc_date === 'string' ? record.doc_date : today(),
      [partnerField]: String(record[partnerField] ?? ''),
      method: typeof record.method === 'string' ? record.method : defaultMethod,
      account_id: String(record.account_id ?? ''),
      notes: typeof record.notes === 'string' ? record.notes : '',
    })

    const allocations = Array.isArray(record.allocations) ? record.allocations : []

    setRows(
      allocations.map((allocation) => {
        const row = allocation as {
          id: number
          amount: string
          invoice?: { doc_no?: string; total?: string; paid_amount?: string } | null
        }
        const invoiceId = (allocation as Record<string, unknown>)[invoiceField]

        return {
          key: `alloc-${String(row.id)}`,
          invoice_id: String(invoiceId ?? ''),
          amount: String(row.amount ?? '0'),
          remaining: '0.00',
          doc_no: row.invoice?.doc_no ?? '',
        }
      }),
    )
    setInitialized(true)
  }, [record, initialized, partnerField, defaultMethod, invoiceField])

  const partnerId = header[partnerField] ?? ''

  useEffect(() => {
    if (partnerId === '') {
      setInvoices([])

      return
    }

    let cancelled = false

    const load = async (): Promise<void> => {
      try {
        const response = await api.get<{ data: PaymentInvoice[] }>(invoiceResource, {
          per_page: 100,
          sort: 'due_date',
          'filter[status]': 'approved',
          [`filter[${partnerField}]`]: partnerId,
        })

        if (!cancelled) {
          setInvoices(response.data.filter((invoice) => decCompare(invoice.total, invoice.paid_amount) > 0))
        }
      } catch {
        if (!cancelled) {
          setInvoices([])
        }
      }
    }

    void load()

    return () => {
      cancelled = true
    }
  }, [partnerId, invoiceResource, partnerField])

  const actions = useDocumentActions({
    resource,
    permissionPrefix,
    id: isEdit ? id : null,
    onChanged: () => {
      void recordQuery.refetch()
    },
  })

  const availableActions: DocumentAction[] = isEdit
    ? actions.buildActions(status, { print: false })
    : []
  const canUpdate = !isEdit || hasPermission(`${permissionPrefix}.update`)

  const invoiceRemaining = (invoice: PaymentInvoice): string => decSub(invoice.total, invoice.paid_amount, 2)

  const setRow = (index: number, patch: Partial<PaymentAllocationRow>): void => {
    setRows((current) => current.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)))
  }

  const selectInvoice = (index: number, invoiceId: string): void => {
    const invoice = invoices.find((item) => String(item.id) === invoiceId)

    if (invoice === undefined) {
      setRow(index, { invoice_id: '', doc_no: '', remaining: '0.00' })

      return
    }

    const remaining = invoiceRemaining(invoice)
    setRow(index, {
      invoice_id: invoiceId,
      doc_no: invoice.doc_no,
      remaining,
      amount: remaining,
    })
  }

  const totalAmount = rows.reduce((total, row) => decAdd(total, row.amount === '' ? '0' : row.amount, 2), '0.00')

  const submit = async (): Promise<void> => {
    setError(null)
    setFieldErrors({})

    const errors: Record<string, string[]> = {}

    if (partnerId === '') {
      errors[partnerField] = [`${partnerLabel} wajib dipilih.`]
    }

    if ((header.doc_date ?? '') === '') {
      errors.doc_date = ['Tanggal wajib diisi.']
    }

    const allocations = rows.filter((row) => row.invoice_id !== '')

    if (allocations.length === 0) {
      errors.allocations = ['Tambahkan minimal satu invoice.']
    }

    allocations.forEach((row, index) => {
      if (decCompare(row.amount === '' ? '0' : row.amount, '0') <= 0) {
        errors[`allocations.${index}.amount`] = ['Nominal harus lebih besar dari nol.']
      }

      if (decCompare(row.amount, row.remaining) > 0) {
        errors[`allocations.${index}.amount`] = ['Nominal melebihi sisa tagihan.']
      }
    })

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      setError('Periksa kembali isian alokasi.')

      return
    }

    setSubmitting(true)

    try {
      const payload = {
        doc_date: header.doc_date,
        [partnerField]: Number(partnerId),
        method: header.method,
        account_id: header.account_id === '' ? null : Number(header.account_id),
        amount: totalAmount,
        notes: header.notes === '' ? null : header.notes,
        allocations: allocations.map((row) => ({
          [invoiceField]: Number(row.invoice_id),
          amount: row.amount,
        })),
      }

      if (isEdit) {
        await api.put(`${resource}/${String(id)}`, payload)
        toast.push('Pembayaran diperbarui.', 'success')
      } else {
        const created = await api.post<{ data: { id: number } }>(resource, payload)
        toast.push('Pembayaran dibuat.', 'success')
        navigate(`/documents/${resource.replace(/^\//, '')}/${String(created.data.id)}`)
      }

      await recordQuery.refetch()
    } catch (caught) {
      if (caught instanceof ApiError) {
        setFieldErrors(caught.errors)
        setError(`${caught.code}: ${caught.message}`)
      } else {
        setError(toErrorMessage(caught))
      }

      toast.push(toErrorMessage(caught), 'error')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <DocumentShell
      title={isEdit ? `${title} ${String(record?.doc_no ?? '')}`.trim() : `Buat ${title}`}
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
    >
      {error !== null && !readOnly ? (
        <p role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-2 text-sm text-danger">
          {error}
        </p>
      ) : null}

      <div className="grid gap-3 rounded-md border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
        <TextField
          id="doc_date"
          label="Tanggal"
          type="date"
          required
          disabled={readOnly}
          value={header.doc_date}
          error={fieldErrors.doc_date?.[0]}
          onChange={(event) => setHeader({ ...header, doc_date: event.target.value })}
        />
        <SelectField
          id={partnerField}
          label={partnerLabel}
          required
          placeholder={`Pilih ${partnerLabel.toLowerCase()}`}
          options={partnerOptions}
          disabled={readOnly}
          value={partnerId}
          error={fieldErrors[partnerField]?.[0]}
          onChange={(event) => setHeader({ ...header, [partnerField]: event.target.value })}
        />
        <SelectField
          id="method"
          label="Metode"
          options={[
            { value: 'bank', label: 'Bank' },
            { value: 'cash', label: 'Kas' },
          ]}
          disabled={readOnly}
          value={header.method}
          onChange={(event) => setHeader({ ...header, method: event.target.value })}
        />
        <SelectField
          id="account_id"
          label="Akun kas/bank"
          placeholder="Ikuti default"
          options={accountOptions}
          disabled={readOnly}
          value={header.account_id}
          onChange={(event) => setHeader({ ...header, account_id: event.target.value })}
        />
        <TextField
          id="notes"
          label="Catatan"
          disabled={readOnly}
          value={header.notes}
          onChange={(event) => setHeader({ ...header, notes: event.target.value })}
        />
      </div>

      {Object.keys(fieldErrors).some((key) => key.startsWith('allocations')) ? (
        <ul role="alert" className="list-disc space-y-0.5 rounded-md border border-danger/30 bg-danger-soft px-6 py-2 text-sm text-danger">
          {Object.entries(fieldErrors)
            .filter(([key]) => key.startsWith('allocations'))
            .flatMap(([key, messages]) => messages.map((message) => <li key={`${key}-${message}`}>{message}</li>))}
        </ul>
      ) : null}

      <div className="rounded-md border border-line bg-surface">
        <div className="flex items-center justify-between gap-2 border-b border-line px-3 py-2">
          <div>
            <p className="text-sm font-semibold">Alokasi invoice</p>
            <p className="text-xs text-ink-muted">Total pembayaran dihitung dari alokasi.</p>
          </div>
          {readOnly ? null : (
            <button
              type="button"
              onClick={() => setRows((current) => [...current, newAllocation()])}
              className="inline-flex min-h-9 items-center rounded-md border border-line px-3 text-sm font-medium hover:border-ink-muted"
            >
              Tambah alokasi
            </button>
          )}
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="border-b border-line bg-paper/60 text-xs uppercase tracking-wide text-ink-muted">
                <th className="px-3 py-2 text-left">Invoice</th>
                <th className="px-3 py-2 text-right">Sisa</th>
                <th className="px-3 py-2 text-right">Nominal</th>
                {readOnly ? null : <th className="px-3 py-2 text-center">Aksi</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr key={row.key} className="border-b border-line/70 last:border-b-0">
                  <td className="px-3 py-2">
                    <select
                      aria-label="Invoice"
                      value={row.invoice_id}
                      disabled={readOnly}
                      onChange={(event) => selectInvoice(index, event.target.value)}
                      className="min-h-9 w-full min-w-56 rounded border border-line bg-surface px-2 text-sm"
                    >
                      <option value="">Pilih invoice</option>
                      {invoices.map((invoice) => (
                        <option key={invoice.id} value={String(invoice.id)}>
                          {invoice.doc_no} — sisa {formatCurrency(invoiceRemaining(invoice))}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(row.remaining)}</td>
                  <td className="px-3 py-2 text-right">
                    <input
                      type="number"
                      step="any"
                      min="0"
                      aria-label="Nominal alokasi"
                      value={row.amount}
                      disabled={readOnly}
                      onChange={(event) => setRow(index, { amount: event.target.value })}
                      className="min-h-9 w-40 rounded border border-line px-2 text-right tabular-nums"
                    />
                  </td>
                  {readOnly ? null : (
                    <td className="px-3 py-2 text-center">
                      <button
                        type="button"
                        onClick={() => setRows((current) => current.filter((_, rowIndex) => rowIndex !== index))}
                        disabled={rows.length <= 1}
                        className="rounded px-2 py-1 text-xs font-medium text-danger hover:bg-danger-soft disabled:opacity-40"
                      >
                        Hapus
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex justify-end border-t border-line px-3 py-3 text-sm font-semibold">
          Total pembayaran: <span className="ml-2 tabular-nums">{formatCurrency(totalAmount)}</span>
        </div>
      </div>

      {isEdit && !readOnly && canUpdate ? (
        <div className="flex justify-end">
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

      {isEdit ? null : (
        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={() => navigate(-1)}
            className="inline-flex min-h-10 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted"
          >
            Batal
          </button>
          <button
            type="button"
            onClick={() => void submit()}
            disabled={submitting}
            className="inline-flex min-h-10 items-center rounded-md bg-accent px-4 text-sm font-semibold text-white hover:bg-accent/90 disabled:opacity-60"
          >
            {submitting ? 'Menyimpan...' : 'Simpan pembayaran'}
          </button>
        </div>
      )}

      <ConfirmDialog
        open={actions.pendingAction !== null}
        title={actions.pendingAction?.label ?? 'Konfirmasi'}
        description={actions.pendingAction?.confirmMessage}
        danger={actions.pendingAction?.tone === 'danger'}
        busy={actions.busyAction !== null}
        onConfirm={() => {
          if (actions.pendingAction !== null) {
            void actions.runAction(actions.pendingAction.key)
          }
        }}
        onCancel={() => actions.setPendingAction(null)}
      />
    </DocumentShell>
  )
}
