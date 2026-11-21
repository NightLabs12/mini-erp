import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/auth-context'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { TextField } from '../../components/ui/TextField'
import { DocumentListPage } from '../../components/document/DocumentListPage'
import { DocumentShell, type DocumentAction } from '../../components/document/DocumentShell'
import { useDocumentActions } from '../../components/document/useDocumentActions'
import { useToast } from '../../components/ui/toast-context'
import { ApiError, api, toErrorMessage } from '../../lib/api'
import { decCompare, decIsZero, decSub, decSum } from '../../lib/decimal'
import { useDocumentRecord } from '../../lib/document-hooks'
import { formatCurrency, formatDate } from '../../lib/format'
import { useAccountOptions } from '../../lib/options'
import { today } from './shared'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { StatusBadge } from '../../components/ui/StatusBadge'

interface JournalListRecord {
  id: number
  journal_no: string | null
  journal_date: string
  status: string
  description: string | null
  total_debit: string
  total_credit: string
}

interface JournalLineRow {
  key: string
  account_id: string
  debit: string
  credit: string
  description: string
}

function newJournalLine(): JournalLineRow {
  return {
    key: `line-${Math.random().toString(36).slice(2, 10)}`,
    account_id: '',
    debit: '0',
    credit: '0',
    description: '',
  }
}

const journalColumns: DataTableColumn<JournalListRecord>[] = [
  {
    id: 'journal_no',
    header: 'Nomor',
    sortable: true,
    render: (row) => <span className="font-medium">{row.journal_no ?? '—'}</span>,
  },
  {
    id: 'journal_date',
    header: 'Tanggal',
    sortable: true,
    render: (row) => formatDate(row.journal_date),
  },
  { id: 'description', header: 'Keterangan', render: (row) => row.description ?? '—' },
  {
    id: 'total_debit',
    header: 'Debit',
    align: 'right',
    render: (row) => formatCurrency(row.total_debit),
  },
  {
    id: 'total_credit',
    header: 'Kredit',
    align: 'right',
    render: (row) => formatCurrency(row.total_credit),
  },
  {
    id: 'status',
    header: 'Status',
    sortable: true,
    render: (row) => <StatusBadge status={row.status} />,
  },
]

export function JournalsListPage() {
  return (
    <DocumentListPage<JournalListRecord>
      title="Jurnal manual"
      description="Jurnal double-entry manual; approve untuk memposting."
      resource="/journals"
      permissionPrefix="accounting.journal"
      columns={journalColumns}
      detailHref={(record) => `/documents/journals/${record.id}`}
      createHref="/documents/journals/new"
      defaultSort="-journal_date"
      statusFilters={['draft', 'posted', 'reversed']}
    />
  )
}

export function JournalFormPage() {
  const params = useParams<{ id?: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const { hasPermission } = useAuth()
  const accounts = useAccountOptions(true)

  const id = params.id === undefined ? null : Number(params.id)
  const isEdit = id !== null && Number.isFinite(id)
  const recordQuery = useDocumentRecord<Record<string, unknown>>('/journals', isEdit ? id : null)
  const record = recordQuery.data?.data
  const status = typeof record?.status === 'string' ? record.status : 'draft'
  const readOnly = isEdit && status !== 'draft'

  const [header, setHeader] = useState({ journal_date: today(), description: '' })
  const [rows, setRows] = useState<JournalLineRow[]>([newJournalLine(), newJournalLine()])
  const [initialized, setInitialized] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (record === undefined || initialized) {
      return
    }

    setHeader({
      journal_date: typeof record.journal_date === 'string' ? record.journal_date : today(),
      description: typeof record.description === 'string' ? record.description : '',
    })

    const lines = Array.isArray(record.lines) ? record.lines : []

    setRows(
      lines.map((line) => {
        const row = line as {
          id: number
          account_id: number
          debit: string
          credit: string
          description?: string | null
        }

        return {
          key: `line-${row.id}`,
          account_id: String(row.account_id),
          debit: String(row.debit ?? '0'),
          credit: String(row.credit ?? '0'),
          description: row.description ?? '',
        }
      }),
    )
    setInitialized(true)
  }, [record, initialized])

  const actions = useDocumentActions({
    resource: '/journals',
    permissionPrefix: 'accounting.journal',
    id: isEdit ? id : null,
    onChanged: () => {
      void recordQuery.refetch()
    },
  })

  const availableActions: DocumentAction[] = isEdit ? actions.buildActions(status) : []
  const canUpdate = !isEdit || hasPermission('accounting.journal.update')

  const totalDebit = decSum(rows.map((row) => row.debit === '' ? '0' : row.debit), 2)
  const totalCredit = decSum(rows.map((row) => row.credit === '' ? '0' : row.credit), 2)
  const balanced = decCompare(totalDebit, totalCredit) === 0 && !decIsZero(totalDebit)

  const setRow = (index: number, patch: Partial<JournalLineRow>): void => {
    setRows((current) => current.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)))
  }

  const submit = async (): Promise<void> => {
    setError(null)
    setFieldErrors({})

    const errors: Record<string, string[]> = {}

    if (header.journal_date === '') {
      errors.journal_date = ['Tanggal wajib diisi.']
    }

    if (rows.length < 2) {
      errors.lines = ['Jurnal minimal dua baris.']
    }

    rows.forEach((row, index) => {
      if (row.account_id === '') {
        errors[`lines.${index}.account_id`] = ['Pilih akun.']
      }

      const hasDebit = !decIsZero(row.debit)
      const hasCredit = !decIsZero(row.credit)

      if (hasDebit === hasCredit) {
        errors[`lines.${index}.debit`] = ['Isi tepat satu debit atau kredit.']
      }
    })

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      setError('Periksa kembali baris jurnal.')

      return
    }

    setSubmitting(true)

    try {
      const payload = {
        journal_date: header.journal_date,
        description: header.description === '' ? null : header.description,
        lines: rows.map((row) => ({
          account_id: Number(row.account_id),
          debit: row.debit === '' ? '0' : row.debit,
          credit: row.credit === '' ? '0' : row.credit,
          description: row.description === '' ? null : row.description,
        })),
      }

      if (isEdit) {
        await api.put(`/journals/${String(id)}`, payload)
        toast.push('Jurnal diperbarui.', 'success')
      } else {
        const created = await api.post<{ data: { id: number } }>('/journals', payload)
        toast.push('Jurnal dibuat sebagai draft.', 'success')
        navigate(`/documents/journals/${String(created.data.id)}`)
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

  const accountOptions = accounts.data ?? []

  return (
    <DocumentShell
      title={isEdit ? `Jurnal ${String(record?.journal_no ?? '')}`.trim() : 'Buat jurnal manual'}
      docNo={typeof record?.journal_no === 'string' ? record.journal_no : undefined}
      status={isEdit ? status : undefined}
      backTo="/documents/journals"
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
      auditResource={isEdit ? '/journals' : undefined}
      auditId={isEdit ? id : undefined}
    >
      {error !== null && !readOnly ? (
        <p role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-2 text-sm text-danger">
          {error}
        </p>
      ) : null}

      <div className="grid gap-3 rounded-md border border-line bg-surface p-4 sm:grid-cols-2">
        <TextField
          id="journal_date"
          label="Tanggal"
          type="date"
          required
          disabled={readOnly}
          value={header.journal_date}
          error={fieldErrors.journal_date?.[0]}
          onChange={(event) => setHeader({ ...header, journal_date: event.target.value })}
        />
        <TextField
          id="description"
          label="Keterangan"
          disabled={readOnly}
          value={header.description}
          onChange={(event) => setHeader({ ...header, description: event.target.value })}
        />
      </div>

      <div className="rounded-md border border-line bg-surface">
        <div className="flex items-center justify-between gap-2 border-b border-line px-3 py-2">
          <div>
            <p className="text-sm font-semibold">Baris jurnal</p>
            <p className="text-xs text-ink-muted">Debit dan kredit harus seimbang.</p>
          </div>
          <span
            className={[
              'inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold',
              balanced
                ? 'border-accent/30 bg-accent-soft text-accent'
                : 'border-danger/30 bg-danger-soft text-danger',
            ].join(' ')}
          >
            {balanced ? 'Seimbang' : 'Belum seimbang'}
          </span>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="border-b border-line bg-paper/60 text-xs uppercase tracking-wide text-ink-muted">
                <th className="px-3 py-2 text-left">Akun</th>
                <th className="px-3 py-2 text-left">Keterangan</th>
                <th className="px-3 py-2 text-right">Debit</th>
                <th className="px-3 py-2 text-right">Kredit</th>
                {readOnly ? null : <th className="px-3 py-2 text-center">Aksi</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr key={row.key} className="border-b border-line/70 last:border-b-0">
                  <td className="px-3 py-2">
                    <select
                      aria-label={`Akun baris ${index + 1}`}
                      value={row.account_id}
                      disabled={readOnly}
                      onChange={(event) => setRow(index, { account_id: event.target.value })}
                      className="min-h-9 w-full min-w-56 rounded border border-line bg-surface px-2 text-sm"
                    >
                      <option value="">Pilih akun</option>
                      {accountOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                    {fieldErrors[`lines.${index}.account_id`]?.[0] !== undefined ? (
                      <p className="mt-1 text-xs text-danger">
                        {fieldErrors[`lines.${index}.account_id`]?.[0]}
                      </p>
                    ) : null}
                  </td>
                  <td className="px-3 py-2">
                    <input
                      aria-label={`Keterangan baris ${index + 1}`}
                      value={row.description}
                      disabled={readOnly}
                      onChange={(event) => setRow(index, { description: event.target.value })}
                      className="min-h-9 w-full rounded border border-line px-2 text-sm"
                    />
                  </td>
                  <td className="px-3 py-2 text-right">
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      aria-label={`Debit baris ${index + 1}`}
                      value={row.debit}
                      disabled={readOnly}
                      onChange={(event) => setRow(index, { debit: event.target.value, credit: '0' })}
                      className="min-h-9 w-32 rounded border border-line px-2 text-right tabular-nums"
                    />
                  </td>
                  <td className="px-3 py-2 text-right">
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      aria-label={`Kredit baris ${index + 1}`}
                      value={row.credit}
                      disabled={readOnly}
                      onChange={(event) => setRow(index, { credit: event.target.value, debit: '0' })}
                      className="min-h-9 w-32 rounded border border-line px-2 text-right tabular-nums"
                    />
                  </td>
                  {readOnly ? null : (
                    <td className="px-3 py-2 text-center">
                      <button
                        type="button"
                        onClick={() => setRows((current) => current.filter((_, rowIndex) => rowIndex !== index))}
                        disabled={rows.length <= 2}
                        className="rounded px-2 py-1 text-xs font-medium text-danger hover:bg-danger-soft disabled:opacity-40"
                      >
                        Hapus
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t border-line font-semibold">
                <td className="px-3 py-2" colSpan={2}>
                  Total
                </td>
                <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(totalDebit)}</td>
                <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(totalCredit)}</td>
                {readOnly ? null : <td />}
              </tr>
              <tr>
                <td className="px-3 py-2 text-right text-xs text-ink-muted" colSpan={4}>
                  Selisih: {formatCurrency(decSub(totalDebit, totalCredit, 2))}
                </td>
                {readOnly ? null : <td />}
              </tr>
            </tfoot>
          </table>
        </div>

        {readOnly ? null : (
          <div className="border-t border-line px-3 py-2">
            <button
              type="button"
              onClick={() => setRows((current) => [...current, newJournalLine()])}
              className="inline-flex min-h-9 items-center rounded-md border border-line px-3 text-sm font-medium hover:border-ink-muted"
            >
              Tambah baris
            </button>
          </div>
        )}
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
            {submitting ? 'Menyimpan...' : 'Simpan draft'}
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
