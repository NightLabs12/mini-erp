import type { UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { CheckboxField } from '../../components/ui/CheckboxField'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { warehouseSchema, type WarehouseForm } from '../../lib/validation'

interface WarehouseRecord {
  id: number
  code: string
  name: string
  address: string | null
  is_active: boolean
}

const columns: DataTableColumn<WarehouseRecord>[] = [
  { id: 'code', header: 'Kode', sortable: true, render: (row) => <span className="font-medium">{row.code}</span> },
  { id: 'name', header: 'Nama', sortable: true, render: (row) => row.name },
  { id: 'address', header: 'Alamat', render: (row) => row.address ?? '—' },
  {
    id: 'is_active',
    header: 'Status',
    sortable: true,
    render: (row) => (row.is_active ? 'Aktif' : 'Nonaktif'),
  },
]

const emptyForm: WarehouseForm = { code: '', name: '', address: '', is_active: true }

function WarehouseFields({ form }: { form: UseFormReturn<WarehouseForm> }) {
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
      <div className="sm:col-span-2">
        <CheckboxField id="is_active" label="Gudang aktif" {...form.register('is_active')} />
      </div>
    </div>
  )
}

export function WarehousesPage() {
  return (
    <MasterCrudPage<WarehouseRecord, WarehouseForm>
      title="Gudang"
      description="Gudang penyimpanan stok beserta status aktifnya."
      resource="/warehouses"
      permissionPrefix="master.warehouse"
      queryKey="warehouses"
      columns={columns}
      schema={warehouseSchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        code: record.code,
        name: record.name,
        address: record.address ?? '',
        is_active: record.is_active,
      })}
      toPayload={(values) => ({
        code: values.code,
        name: values.name,
        address: values.address === '' ? null : values.address,
        is_active: values.is_active,
      })}
      Fields={WarehouseFields}
      describe={(record) => `Gudang ${record.code} - ${record.name}`}
      createLabel="Tambah gudang"
      formTitle={(record) => (record === null ? 'Tambah gudang' : `Ubah gudang ${record.code}`)}
    />
  )
}
