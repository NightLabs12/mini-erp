import type { UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { SelectField } from '../../components/ui/SelectField'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { formatCurrency } from '../../lib/format'
import { useCustomerOptions, useItemOptions, useUnitOptions } from '../../lib/options'
import { sellingPriceSchema, type SellingPriceForm } from '../../lib/validation'

interface SellingPriceRecord {
  id: number
  item_id: number
  item?: { id: number; sku: string; name: string } | null
  unit_id: number
  unit?: { id: number; code: string; name: string } | null
  customer_id: number | null
  customer?: { id: number; code: string; name: string } | null
  price: string
  valid_from: string | null
  valid_to: string | null
}

const columns: DataTableColumn<SellingPriceRecord>[] = [
  {
    id: 'item',
    header: 'Item',
    render: (row) => (row.item === null || row.item === undefined ? `#${row.item_id}` : `${row.item.sku} - ${row.item.name}`),
  },
  {
    id: 'unit',
    header: 'Satuan',
    render: (row) => row.unit?.code ?? `#${row.unit_id}`,
  },
  {
    id: 'customer',
    header: 'Customer',
    render: (row) => row.customer?.name ?? 'Umum',
  },
  {
    id: 'price',
    header: 'Harga',
    align: 'right',
    sortable: true,
    render: (row) => formatCurrency(row.price),
  },
  {
    id: 'valid_from',
    header: 'Berlaku dari',
    sortable: true,
    render: (row) => row.valid_from ?? '—',
  },
  { id: 'valid_to', header: 'Berlaku sampai', render: (row) => row.valid_to ?? '—' },
]

const emptyForm: SellingPriceForm = {
  item_id: '',
  unit_id: '',
  customer_id: '',
  price: '',
  valid_from: '',
  valid_to: '',
}

function SellingPriceFields({ form }: { form: UseFormReturn<SellingPriceForm> }) {
  const errors = form.formState.errors
  const items = useItemOptions()
  const units = useUnitOptions()
  const customers = useCustomerOptions()

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <SelectField
        id="item_id"
        label="Item"
        required
        placeholder="Pilih item"
        options={items.data ?? []}
        error={fieldError(errors, 'item_id')}
        {...form.register('item_id')}
      />
      <SelectField
        id="unit_id"
        label="Satuan"
        required
        placeholder="Pilih satuan"
        options={units.data ?? []}
        error={fieldError(errors, 'unit_id')}
        {...form.register('unit_id')}
      />
      <SelectField
        id="customer_id"
        label="Customer"
        placeholder="Umum (semua customer)"
        options={customers.data ?? []}
        error={fieldError(errors, 'customer_id')}
        hint="Isi untuk harga khusus customer."
        {...form.register('customer_id')}
      />
      <TextField
        id="price"
        label="Harga jual per satuan"
        required
        error={fieldError(errors, 'price')}
        {...form.register('price')}
      />
      <TextField
        id="valid_from"
        label="Berlaku dari"
        type="date"
        error={fieldError(errors, 'valid_from')}
        {...form.register('valid_from')}
      />
      <TextField
        id="valid_to"
        label="Berlaku sampai"
        type="date"
        error={fieldError(errors, 'valid_to')}
        {...form.register('valid_to')}
      />
    </div>
  )
}

export function SellingPricesPage() {
  return (
    <MasterCrudPage<SellingPriceRecord, SellingPriceForm>
      title="Harga jual"
      description="Harga per item, satuan, dan opsional per customer. Rentang tanggal tidak boleh tumpang tindih."
      resource="/selling-prices"
      permissionPrefix="master.selling-price"
      queryKey="selling-prices"
      columns={columns}
      schema={sellingPriceSchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        item_id: String(record.item_id),
        unit_id: String(record.unit_id),
        customer_id: record.customer_id === null ? '' : String(record.customer_id),
        price: record.price,
        valid_from: record.valid_from ?? '',
        valid_to: record.valid_to ?? '',
      })}
      toPayload={(values) => ({
        item_id: Number(values.item_id),
        unit_id: Number(values.unit_id),
        customer_id: values.customer_id === '' ? null : Number(values.customer_id),
        price: values.price,
        valid_from: values.valid_from === '' ? null : values.valid_from,
        valid_to: values.valid_to === '' ? null : values.valid_to,
      })}
      Fields={SellingPriceFields}
      describe={(record) =>
        `Harga ${record.item?.sku ?? `#${record.item_id}`} (${record.unit?.code ?? `#${record.unit_id}`})`
      }
      createLabel="Tambah harga"
      formTitle={(record) => (record === null ? 'Tambah harga jual' : 'Ubah harga jual')}
      defaultSort="-id"
    />
  )
}
