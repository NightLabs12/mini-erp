import type { UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { supplierSchema, type SupplierForm } from '../../lib/validation'

interface SupplierRecord {
  id: number
  code: string
  name: string
  address: string | null
  phone: string | null
  email: string | null
  tax_id: string | null
  payment_term_days: number
}

const columns: DataTableColumn<SupplierRecord>[] = [
  { id: 'code', header: 'Kode', sortable: true, render: (row) => <span className="font-medium">{row.code}</span> },
  { id: 'name', header: 'Nama', sortable: true, render: (row) => row.name },
  { id: 'email', header: 'Email', render: (row) => row.email ?? '—' },
  { id: 'phone', header: 'Telepon', render: (row) => row.phone ?? '—' },
  {
    id: 'payment_term_days',
    header: 'Termin',
    align: 'right',
    render: (row) => `${row.payment_term_days} hari`,
  },
]

const emptyForm: SupplierForm = {
  code: '',
  name: '',
  address: '',
  phone: '',
  email: '',
  tax_id: '',
  payment_term_days: '30',
}

function SupplierFields({ form }: { form: UseFormReturn<SupplierForm> }) {
  const errors = form.formState.errors

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <TextField
        id="code"
        label="Kode"
        required
        autoComplete="off"
        error={fieldError(errors, 'code')}
        {...form.register('code')}
      />
      <TextField id="name" label="Nama" required error={fieldError(errors, 'name')} {...form.register('name')} />
      <TextField
        id="address"
        label="Alamat"
        error={fieldError(errors, 'address')}
        className="sm:col-span-2"
        {...form.register('address')}
      />
      <TextField id="phone" label="Telepon" error={fieldError(errors, 'phone')} {...form.register('phone')} />
      <TextField
        id="email"
        label="Email"
        type="email"
        error={fieldError(errors, 'email')}
        {...form.register('email')}
      />
      <TextField id="tax_id" label="NPWP" error={fieldError(errors, 'tax_id')} {...form.register('tax_id')} />
      <TextField
        id="payment_term_days"
        label="Termin pembayaran (hari)"
        type="number"
        min={0}
        max={365}
        required
        error={fieldError(errors, 'payment_term_days')}
        {...form.register('payment_term_days')}
      />
    </div>
  )
}

export function SuppliersPage() {
  return (
    <MasterCrudPage<SupplierRecord, SupplierForm>
      title="Supplier"
      description="Pemasok barang beserta termin pembayaran default."
      resource="/suppliers"
      permissionPrefix="master.supplier"
      queryKey="suppliers"
      columns={columns}
      schema={supplierSchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        code: record.code,
        name: record.name,
        address: record.address ?? '',
        phone: record.phone ?? '',
        email: record.email ?? '',
        tax_id: record.tax_id ?? '',
        payment_term_days: String(record.payment_term_days),
      })}
      toPayload={(values) => ({
        code: values.code,
        name: values.name,
        address: values.address === '' ? null : values.address,
        phone: values.phone === '' ? null : values.phone,
        email: values.email === '' ? null : values.email,
        tax_id: values.tax_id === '' ? null : values.tax_id,
        payment_term_days: Number(values.payment_term_days),
      })}
      Fields={SupplierFields}
      describe={(record) => `Supplier ${record.code} - ${record.name}`}
      createLabel="Tambah supplier"
      formTitle={(record) => (record === null ? 'Tambah supplier' : `Ubah supplier ${record.code}`)}
    />
  )
}
