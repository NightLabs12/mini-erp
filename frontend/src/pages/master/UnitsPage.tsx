import type { UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { unitSchema, type UnitForm } from '../../lib/validation'

interface UnitRecord {
  id: number
  code: string
  name: string
  decimal_places: number
}

const columns: DataTableColumn<UnitRecord>[] = [
  { id: 'code', header: 'Kode', sortable: true, render: (row) => <span className="font-medium">{row.code}</span> },
  { id: 'name', header: 'Nama', sortable: true, render: (row) => row.name },
  {
    id: 'decimal_places',
    header: 'Presisi',
    align: 'right',
    sortable: true,
    render: (row) => String(row.decimal_places),
  },
]

const emptyForm: UnitForm = { code: '', name: '', decimal_places: '0' }

function UnitFields({ form }: { form: UseFormReturn<UnitForm> }) {
  const errors = form.formState.errors

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <TextField
        id="code"
        label="Kode"
        required
        autoComplete="off"
        error={fieldError(errors, 'code')}
        hint="Huruf, angka, garis bawah, atau tanda hubung."
        {...form.register('code')}
      />
      <TextField
        id="name"
        label="Nama"
        required
        error={fieldError(errors, 'name')}
        {...form.register('name')}
      />
      <TextField
        id="decimal_places"
        label="Presisi desimal"
        type="number"
        min={0}
        max={4}
        required
        error={fieldError(errors, 'decimal_places')}
        hint="0 untuk satuan bulat seperti PCS, hingga 4 untuk satuan pecahan."
        {...form.register('decimal_places')}
      />
    </div>
  )
}

export function UnitsPage() {
  return (
    <MasterCrudPage<UnitRecord, UnitForm>
      title="Satuan"
      description="Satuan pengukuran item, termasuk presisi desimal untuk satuan pecahan."
      resource="/units"
      permissionPrefix="master.unit"
      queryKey="units"
      columns={columns}
      schema={unitSchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        code: record.code,
        name: record.name,
        decimal_places: String(record.decimal_places),
      })}
      toPayload={(values) => ({
        code: values.code,
        name: values.name,
        decimal_places: Number(values.decimal_places),
      })}
      Fields={UnitFields}
      describe={(record) => `Satuan ${record.code} - ${record.name}`}
      createLabel="Tambah satuan"
      formTitle={(record) => (record === null ? 'Tambah satuan' : `Ubah satuan ${record.code}`)}
    />
  )
}
