import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type ComponentType, type ReactNode } from 'react'
import { useForm, type DefaultValues, type FieldValues, type UseFormReturn } from 'react-hook-form'
import type { ZodType } from 'zod'
import { useAuth } from '../../auth/auth-context'
import { api, toErrorMessage, type ApiQuery } from '../../lib/api'
import { applyApiError, validateWithZod } from '../../lib/form'
import type { ApiCollection } from '../../lib/types'
import { ConfirmDialog } from '../ui/ConfirmDialog'
import { DataTable } from '../ui/DataTable'
import type { DataTableColumn } from '../ui/data-table.types'
import { Modal } from '../ui/Modal'
import { useToast } from '../ui/toast-context'

interface MasterCrudPageProps<TRecord extends { id: number }, TForm extends FieldValues> {
  title: string
  description?: string
  resource: string
  permissionPrefix: string
  queryKey: string
  columns: DataTableColumn<TRecord>[]
  schema: ZodType<TForm>
  emptyForm: TForm
  toForm: (record: TRecord) => TForm
  toPayload: (values: TForm) => Record<string, unknown>
  Fields: ComponentType<{ form: UseFormReturn<TForm>; record: TRecord | null }>
  describe: (record: TRecord) => string
  defaultSort?: string
  searchPlaceholder?: string
  perPage?: number
  createLabel?: string
  formTitle?: (record: TRecord | null) => string
  extraQuery?: ApiQuery
  renderRowActions?: (record: TRecord) => ReactNode
}

export function MasterCrudPage<TRecord extends { id: number }, TForm extends FieldValues>({
  title,
  description,
  resource,
  permissionPrefix,
  queryKey,
  columns,
  schema,
  emptyForm,
  toForm,
  toPayload,
  Fields,
  describe,
  defaultSort = 'code',
  searchPlaceholder,
  perPage = 15,
  createLabel = 'Tambah',
  formTitle,
  extraQuery,
  renderRowActions,
}: MasterCrudPageProps<TRecord, TForm>) {
  const { hasPermission } = useAuth()
  const toast = useToast()
  const queryClient = useQueryClient()

  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [sort, setSort] = useState(defaultSort)
  const [page, setPage] = useState(1)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<TRecord | null>(null)
  const [deleting, setDeleting] = useState<TRecord | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  const canCreate = hasPermission(`${permissionPrefix}.create`)
  const canUpdate = hasPermission(`${permissionPrefix}.update`)
  const canDelete = hasPermission(`${permissionPrefix}.delete`)

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search)
      setPage(1)
    }, 300)

    return () => clearTimeout(timer)
  }, [search])

  const query = useQuery({
    queryKey: [queryKey, { search: debouncedSearch, sort, page, perPage, extraQuery }],
    queryFn: () =>
      api.get<ApiCollection<TRecord>>(resource, {
        search: debouncedSearch === '' ? undefined : debouncedSearch,
        sort,
        page,
        per_page: perPage,
        ...extraQuery,
      }),
    placeholderData: keepPreviousData,
  })

  const form = useForm<TForm, unknown, TForm>({ defaultValues: emptyForm as DefaultValues<TForm> })

  const invalidate = async (): Promise<void> => {
    await queryClient.invalidateQueries({ queryKey: [queryKey] })
  }

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      editing === null
        ? api.post(resource, payload)
        : api.put(`${resource}/${editing.id}`, payload),
  })

  const deleteMutation = useMutation({
    mutationFn: (record: TRecord) => api.delete(`${resource}/${record.id}`),
  })

  const openCreate = (): void => {
    setEditing(null)
    form.reset(emptyForm)
    setFormError(null)
    setFormOpen(true)
  }

  const openEdit = (record: TRecord): void => {
    setEditing(record)
    form.reset(toForm(record))
    setFormError(null)
    setFormOpen(true)
  }

  const closeForm = (): void => {
    if (saveMutation.isPending) {
      return
    }

    setFormOpen(false)
  }

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    if (!validateWithZod(schema, values, form.setError)) {
      return
    }

    try {
      await saveMutation.mutateAsync(toPayload(values))
      await invalidate()
      setFormOpen(false)
      toast.push(editing === null ? 'Data berhasil ditambahkan.' : 'Data berhasil diperbarui.', 'success')
    } catch (error) {
      const message = applyApiError(error, form.setError)

      setFormError(message ?? toErrorMessage(error))
      toast.push(toErrorMessage(error), 'error')
    }
  })

  const confirmDelete = async (): Promise<void> => {
    if (deleting === null) {
      return
    }

    try {
      await deleteMutation.mutateAsync(deleting)
      await invalidate()
      toast.push('Data berhasil dihapus.', 'success')
      setDeleting(null)
    } catch (error) {
      toast.push(toErrorMessage(error), 'error')
      setDeleting(null)
    }
  }

  const tableColumns: DataTableColumn<TRecord>[] = [...columns]

  if (canUpdate || canDelete || renderRowActions !== undefined) {
    tableColumns.push({
      id: '__actions',
      header: 'Aksi',
      align: 'right',
      render: (record) => (
        <div className="flex items-center justify-end gap-1">
          {renderRowActions?.(record)}
          {canUpdate ? (
            <button
              type="button"
              onClick={() => openEdit(record)}
              className="rounded px-2 py-1 text-xs font-medium text-accent hover:bg-accent-soft"
            >
              Ubah
            </button>
          ) : null}
          {canDelete ? (
            <button
              type="button"
              onClick={() => setDeleting(record)}
              className="rounded px-2 py-1 text-xs font-medium text-danger hover:bg-danger-soft"
            >
              Hapus
            </button>
          ) : null}
        </div>
      ),
    })
  }

  const meta = query.data?.meta

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {description ? <p className="mt-1 text-sm text-ink-muted">{description}</p> : null}
        </div>

        {canCreate ? (
          <button
            type="button"
            onClick={openCreate}
            className="inline-flex min-h-10 items-center rounded-md bg-accent px-4 text-sm font-semibold text-white hover:bg-accent/90"
          >
            {createLabel}
          </button>
        ) : null}
      </div>

      {query.isError ? (
        <div role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
          <p>{toErrorMessage(query.error)}</p>
          <button
            type="button"
            onClick={() => void query.refetch()}
            className="mt-2 inline-flex min-h-8 items-center rounded border border-danger/40 bg-surface px-2 text-xs font-medium"
          >
            Coba lagi
          </button>
        </div>
      ) : null}

      <DataTable
        columns={tableColumns}
        rows={query.data?.data ?? []}
        rowKey={(record) => record.id}
        isLoading={query.isPending}
        searchValue={search}
        onSearchChange={setSearch}
        searchPlaceholder={searchPlaceholder}
        sort={sort}
        onSortChange={(next) => {
          setSort(next)
          setPage(1)
        }}
        page={meta?.current_page ?? page}
        perPage={meta?.per_page ?? perPage}
        total={meta?.total ?? 0}
        onPageChange={setPage}
      />

      <Modal
        open={formOpen}
        title={formTitle?.(editing) ?? (editing === null ? `Tambah ${title}` : `Ubah ${title}`)}
        onClose={closeForm}
        size="lg"
        footer={
          <>
            <button
              type="button"
              onClick={closeForm}
              disabled={saveMutation.isPending}
              className="inline-flex min-h-10 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted disabled:opacity-60"
            >
              Batal
            </button>
            <button
              type="button"
              onClick={() => void submit()}
              disabled={saveMutation.isPending}
              className="inline-flex min-h-10 items-center rounded-md bg-accent px-4 text-sm font-semibold text-white hover:bg-accent/90 disabled:opacity-60"
            >
              {saveMutation.isPending ? 'Menyimpan...' : 'Simpan'}
            </button>
          </>
        }
      >
        <form
          className="space-y-4"
          noValidate
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          {formError ? (
            <p role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
              {formError}
            </p>
          ) : null}

          <Fields form={form} record={editing} />
        </form>
      </Modal>

      <ConfirmDialog
        open={deleting !== null}
        title="Hapus data?"
        description={deleting === null ? undefined : `${describe(deleting)} akan dihapus. Tindakan ini tidak dapat dibatalkan.`}
        confirmLabel="Hapus"
        danger
        busy={deleteMutation.isPending}
        onConfirm={() => void confirmDelete()}
        onCancel={() => setDeleting(null)}
      />
    </section>
  )
}
