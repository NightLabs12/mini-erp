import type { UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { SelectField } from '../../components/ui/SelectField'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { useAccountOptions } from '../../lib/options'
import { itemCategorySchema, type ItemCategoryForm } from '../../lib/validation'

interface ItemCategoryRecord {
  id: number
  code: string
  name: string
  item_type: string
  inventory_account_id: number | null
  cogs_account_id: number | null
  sales_account_id: number | null
}

const itemTypeLabels: Record<string, string> = {
  trading: 'Trading',
  raw_material: 'Bahan baku',
  finished_good: 'Barang jadi',
}

const columns: DataTableColumn<ItemCategoryRecord>[] = [
  { id: 'code', header: 'Kode', sortable: true, render: (row) => <span className="font-medium">{row.code}</span> },
  { id: 'name', header: 'Nama', sortable: true, render: (row) => row.name },
  {
    id: 'item_type',
    header: 'Jenis',
    sortable: true,
    render: (row) => itemTypeLabels[row.item_type] ?? row.item_type,
  },
]

const emptyForm: ItemCategoryForm = {
  code: '',
  name: '',
  item_type: 'trading',
  inventory_account_id: '',
  cogs_account_id: '',
  sales_account_id: '',
}

function ItemCategoryFields({ form }: { form: UseFormReturn<ItemCategoryForm> }) {
  const errors = form.formState.errors
  const accounts = useAccountOptions(true)
  const options = accounts.data ?? []

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

      <SelectField
        id="item_type"
        label="Jenis item"
        required
        options={[
          { value: 'trading', label: 'Trading' },
          { value: 'raw_material', label: 'Bahan baku' },
          { value: 'finished_good', label: 'Barang jadi' },
        ]}
        error={fieldError(errors, 'item_type')}
        {...form.register('item_type')}
      />

      <SelectField
        id="inventory_account_id"
        label="Akun persediaan"
        placeholder="Ikuti default jenis item"
        options={options}
        error={fieldError(errors, 'inventory_account_id')}
        hint={accounts.isPending ? 'Memuat akun...' : undefined}
        {...form.register('inventory_account_id')}
      />

      <SelectField
        id="cogs_account_id"
        label="Akun HPP"
        placeholder="Ikuti default"
        options={options}
        error={fieldError(errors, 'cogs_account_id')}
        {...form.register('cogs_account_id')}
      />

      <SelectField
        id="sales_account_id"
        label="Akun penjualan"
        placeholder="Ikuti default"
        options={options}
        error={fieldError(errors, 'sales_account_id')}
        {...form.register('sales_account_id')}
      />
    </div>
  )
}

export function ItemCategoriesPage() {
  return (
    <MasterCrudPage<ItemCategoryRecord, ItemCategoryForm>
      title="Kategori item"
      description="Kategori menentukan jenis persediaan serta akun persediaan, HPP, dan penjualan."
      resource="/item-categories"
      permissionPrefix="master.item-category"
      queryKey="item-categories"
      columns={columns}
      schema={itemCategorySchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        code: record.code,
        name: record.name,
        item_type: (record.item_type as ItemCategoryForm['item_type']) ?? 'trading',
        inventory_account_id: record.inventory_account_id === null ? '' : String(record.inventory_account_id),
        cogs_account_id: record.cogs_account_id === null ? '' : String(record.cogs_account_id),
        sales_account_id: record.sales_account_id === null ? '' : String(record.sales_account_id),
      })}
      toPayload={(values) => ({
        code: values.code,
        name: values.name,
        item_type: values.item_type,
        inventory_account_id: values.inventory_account_id === '' ? null : Number(values.inventory_account_id),
        cogs_account_id: values.cogs_account_id === '' ? null : Number(values.cogs_account_id),
        sales_account_id: values.sales_account_id === '' ? null : Number(values.sales_account_id),
      })}
      Fields={ItemCategoryFields}
      describe={(record) => `Kategori ${record.code} - ${record.name}`}
      createLabel="Tambah kategori"
      formTitle={(record) => (record === null ? 'Tambah kategori' : `Ubah kategori ${record.code}`)}
    />
  )
}
