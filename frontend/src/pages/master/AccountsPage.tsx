import type { UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { CheckboxField } from '../../components/ui/CheckboxField'
import { SelectField } from '../../components/ui/SelectField'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { useAccountOptions } from '../../lib/options'
import { accountSchema, type AccountForm } from '../../lib/validation'

interface AccountRecord {
  id: number
  code: string
  name: string
  type: string
  normal_balance: string
  parent_id: number | null
  level: number
  is_postable: boolean
  is_active: boolean
}

const typeLabels: Record<string, string> = {
  asset: 'Aset',
  liability: 'Kewajiban',
  equity: 'Ekuitas',
  revenue: 'Pendapatan',
  cogs: 'HPP',
  expense: 'Beban',
}

const columns: DataTableColumn<AccountRecord>[] = [
  { id: 'code', header: 'Kode', sortable: true, render: (row) => <span className="font-medium">{row.code}</span> },
  {
    id: 'name',
    header: 'Nama',
    sortable: true,
    render: (row) => (
      <span style={{ paddingLeft: `${Math.max(row.level - 1, 0) * 12}px` }}>{row.name}</span>
    ),
  },
  {
    id: 'type',
    header: 'Tipe',
    sortable: true,
    render: (row) => typeLabels[row.type] ?? row.type,
  },
  {
    id: 'normal_balance',
    header: 'Saldo normal',
    render: (row) => (row.normal_balance === 'D' ? 'Debit' : 'Kredit'),
  },
  {
    id: 'is_postable',
    header: 'Postable',
    sortable: true,
    render: (row) => (row.is_postable ? 'Ya' : 'Tidak'),
  },
  {
    id: 'is_active',
    header: 'Status',
    render: (row) => (row.is_active ? 'Aktif' : 'Nonaktif'),
  },
]

const emptyForm: AccountForm = {
  code: '',
  name: '',
  type: 'asset',
  normal_balance: 'D',
  parent_id: '',
  is_postable: true,
  is_active: true,
}

function AccountFields({ form, record }: { form: UseFormReturn<AccountForm>; record: AccountRecord | null }) {
  const errors = form.formState.errors
  const accounts = useAccountOptions(false)
  const parentOptions = (accounts.data ?? []).filter(
    (option) => record === null || option.value !== String(record.id),
  )

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <TextField
        id="code"
        label="Kode akun"
        required
        autoComplete="off"
        error={fieldError(errors, 'code')}
        hint="Contoh: 1-1400"
        {...form.register('code')}
      />
      <TextField id="name" label="Nama akun" required error={fieldError(errors, 'name')} {...form.register('name')} />

      <SelectField
        id="type"
        label="Tipe akun"
        required
        options={Object.entries(typeLabels).map(([value, label]) => ({ value, label }))}
        error={fieldError(errors, 'type')}
        {...form.register('type')}
      />

      <SelectField
        id="normal_balance"
        label="Saldo normal"
        required
        options={[
          { value: 'D', label: 'Debit' },
          { value: 'C', label: 'Kredit' },
        ]}
        error={fieldError(errors, 'normal_balance')}
        {...form.register('normal_balance')}
      />

      <SelectField
        id="parent_id"
        label="Akun induk"
        placeholder="Tanpa induk (level 1)"
        options={parentOptions}
        error={fieldError(errors, 'parent_id')}
        hint={accounts.isPending ? 'Memuat akun...' : 'Level dihitung otomatis dari akun induk.'}
        {...form.register('parent_id')}
      />

      <div className="flex flex-col justify-center gap-2 sm:pt-6">
        <CheckboxField
          id="is_postable"
          label="Dapat diposting jurnal"
          hint="Akun induk sebaiknya tidak postable."
          {...form.register('is_postable')}
        />
        <CheckboxField id="is_active" label="Akun aktif" {...form.register('is_active')} />
      </div>
    </div>
  )
}

export function AccountsPage() {
  return (
    <MasterCrudPage<AccountRecord, AccountForm>
      title="Chart of Accounts"
      description="Daftar akun bertingkat. Akun induk tidak dapat dipakai jurnal."
      resource="/accounts"
      permissionPrefix="master.account"
      queryKey="accounts"
      columns={columns}
      schema={accountSchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        code: record.code,
        name: record.name,
        type: record.type as AccountForm['type'],
        normal_balance: record.normal_balance === 'C' ? 'C' : 'D',
        parent_id: record.parent_id === null ? '' : String(record.parent_id),
        is_postable: record.is_postable,
        is_active: record.is_active,
      })}
      toPayload={(values) => ({
        code: values.code,
        name: values.name,
        type: values.type,
        normal_balance: values.normal_balance,
        parent_id: values.parent_id === '' ? null : Number(values.parent_id),
        is_postable: values.is_postable,
        is_active: values.is_active,
      })}
      Fields={AccountFields}
      describe={(record) => `Akun ${record.code} - ${record.name}`}
      createLabel="Tambah akun"
      formTitle={(record) => (record === null ? 'Tambah akun' : `Ubah akun ${record.code}`)}
      perPage={50}
    />
  )
}
