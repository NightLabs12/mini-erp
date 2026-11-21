import type { UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { formatCurrency } from '../../lib/format'
import { customerSchema, type CustomerForm } from '../../lib/validation'

interface CustomerRecord {
  id: number
  code: string
  name: string
  address: string | null
  phone: string | null
  email: string | null
  tax_id: string | null
  payment_term_days: number
  credit_limit: string
}

const columns: DataTableColumn<CustomerRecord>[] = [
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
  {
    id: 'credit_limit',
    header: 'Batas kredit',
    align: 'right',
    sortable: true,
    render: (row) => formatCurrency(row.credit_limit),
  },
]

const emptyForm: CustomerForm = {
  code: '',
  name: '',
  address: '',
  phone: '',
  email: '',
  tax_id: '',
  payment_term_days: '30',
  credit_limit: '0',
}

function CustomerFields({ form }: { form: UseFormReturn<CustomerForm> }) {
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
      <TextField
        id="credit_limit"
        label="Batas kredit"
        error={fieldError(errors, 'credit_limit')}
        hint="0 berarti tanpa batas kredit."
        {...form.register('credit_limit')}
      />
    </div>
  )
}

export function CustomersPage() {
  return (
    <MasterCrudPage<CustomerRecord, CustomerForm>
      title="Customer"
      description="Pelanggan beserta termin pembayaran dan batas kredit."
      resource="/customers"
      permissionPrefix="master.customer"
      queryKey="customers"
      columns={columns}
      schema={customerSchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        code: record.code,
        name: record.name,
        address: record.address ?? '',
        phone: record.phone ?? '',
        email: record.email ?? '',
        tax_id: record.tax_id ?? '',
        payment_term_days: String(record.payment_term_days),
        credit_limit: record.credit_limit,
      })}
      toPayload={(values) => ({
        code: values.code,
        name: values.name,
        address: values.address === '' ? null : values.address,
        phone: values.phone === '' ? null : values.phone,
        email: values.email === '' ? null : values.email,
        tax_id: values.tax_id === '' ? null : values.tax_id,
        payment_term_days: Number(values.payment_term_days),
        credit_limit: values.credit_limit,
      })}
      Fields={CustomerFields}
      describe={(record) => `Customer ${record.code} - ${record.name}`}
      createLabel="Tambah customer"
      formTitle={(record) => (record === null ? 'Tambah customer' : `Ubah customer ${record.code}`)}
    />
  )
}
