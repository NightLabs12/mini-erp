import { useFieldArray, type UseFormReturn } from 'react-hook-form'
import { MasterCrudPage } from '../../components/master/MasterCrudPage'
import { CheckboxField } from '../../components/ui/CheckboxField'
import { SelectField } from '../../components/ui/SelectField'
import { TextField } from '../../components/ui/TextField'
import type { DataTableColumn } from '../../components/ui/data-table.types'
import { fieldError } from '../../lib/form'
import { formatQuantity } from '../../lib/format'
import { useItemCategoryOptions, useUnitOptions } from '../../lib/options'
import { itemSchema, type ItemForm } from '../../lib/validation'

interface ItemConversionRecord {
  id: number
  unit_id: number
  unit?: { id: number; code: string; name: string } | null
  factor_to_base: string
  is_purchase_default: boolean
  is_sales_default: boolean
}

interface ItemRecord {
  id: number
  sku: string
  name: string
  category_id: number
  category?: { id: number; code: string; name: string } | null
  base_unit_id: number
  base_unit?: { id: number; code: string; name: string } | null
  min_stock: string
  is_active: boolean
  conversions: ItemConversionRecord[]
}

const columns: DataTableColumn<ItemRecord>[] = [
  { id: 'sku', header: 'SKU', sortable: true, render: (row) => <span className="font-medium">{row.sku}</span> },
  { id: 'name', header: 'Nama', sortable: true, render: (row) => row.name },
  { id: 'category', header: 'Kategori', render: (row) => row.category?.code ?? '—' },
  { id: 'base_unit', header: 'Satuan base', render: (row) => row.base_unit?.code ?? '—' },
  {
    id: 'min_stock',
    header: 'Stok minimum',
    align: 'right',
    sortable: true,
    render: (row) => formatQuantity(row.min_stock),
  },
  {
    id: 'conversions',
    header: 'Konversi',
    align: 'right',
    render: (row) => String(row.conversions.length),
  },
  {
    id: 'is_active',
    header: 'Status',
    render: (row) => (row.is_active ? 'Aktif' : 'Nonaktif'),
  },
]

const emptyForm: ItemForm = {
  sku: '',
  name: '',
  category_id: '',
  base_unit_id: '',
  min_stock: '0',
  is_active: true,
  conversions: [],
}

function ItemFields({ form }: { form: UseFormReturn<ItemForm> }) {
  const errors = form.formState.errors
  const categories = useItemCategoryOptions()
  const units = useUnitOptions()
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'conversions' })
  const baseUnitId = form.watch('base_unit_id')
  const conversionErrors = errors.conversions

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="sku"
          label="SKU"
          required
          autoComplete="off"
          error={fieldError(errors, 'sku')}
          {...form.register('sku')}
        />
        <TextField id="name" label="Nama" required error={fieldError(errors, 'name')} {...form.register('name')} />

        <SelectField
          id="category_id"
          label="Kategori"
          required
          placeholder="Pilih kategori"
          options={categories.data ?? []}
          error={fieldError(errors, 'category_id')}
          {...form.register('category_id')}
        />

        <SelectField
          id="base_unit_id"
          label="Satuan base"
          required
          placeholder="Pilih satuan"
          options={units.data ?? []}
          error={fieldError(errors, 'base_unit_id')}
          hint="Satuan terkecil; semua stok disimpan pada satuan ini."
          {...form.register('base_unit_id')}
        />

        <TextField
          id="min_stock"
          label="Stok minimum"
          error={fieldError(errors, 'min_stock')}
          {...form.register('min_stock')}
        />

        <div className="flex items-end pb-2">
          <CheckboxField id="is_active" label="Item aktif" {...form.register('is_active')} />
        </div>
      </div>

      <div className="rounded-md border border-line">
        <div className="flex items-center justify-between gap-2 border-b border-line px-3 py-2">
          <div>
            <p className="text-sm font-semibold">Konversi satuan</p>
            <p className="text-xs text-ink-muted">Contoh: 1 Dus = 144 Pcs.</p>
          </div>
          <button
            type="button"
            onClick={() => append({ unit_id: '', factor_to_base: '', is_purchase_default: false, is_sales_default: false })}
            className="inline-flex min-h-9 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted"
          >
            Tambah konversi
          </button>
        </div>

        {fields.length === 0 ? (
          <p className="px-3 py-4 text-sm text-ink-muted">Belum ada konversi tambahan.</p>
        ) : (
          <div className="space-y-3 px-3 py-3">
            {fields.map((field, index) => {
              const unitError = fieldError(conversionErrors, `${index}.unit_id`)
              const factorError = fieldError(conversionErrors, `${index}.factor_to_base`)

              return (
                <div
                  key={field.id}
                  className="grid gap-3 rounded-md border border-line/70 p-3 sm:grid-cols-[1fr_1fr_auto]"
                >
                  <SelectField
                    id={`conversions.${index}.unit_id`}
                    label="Satuan"
                    placeholder="Pilih satuan"
                    options={(units.data ?? []).filter((option) => option.value !== baseUnitId)}
                    error={unitError}
                    {...form.register(`conversions.${index}.unit_id`)}
                  />

                  <TextField
                    id={`conversions.${index}.factor_to_base`}
                    label="Faktor ke satuan base"
                    type="number"
                    step="any"
                    min="0"
                    error={factorError}
                    {...form.register(`conversions.${index}.factor_to_base`)}
                  />

                  <div className="flex flex-col justify-center gap-2">
                    <CheckboxField
                      id={`conversions.${index}.is_purchase_default`}
                      label="Default beli"
                      {...form.register(`conversions.${index}.is_purchase_default`)}
                    />
                    <CheckboxField
                      id={`conversions.${index}.is_sales_default`}
                      label="Default jual"
                      {...form.register(`conversions.${index}.is_sales_default`)}
                    />
                    <button
                      type="button"
                      onClick={() => remove(index)}
                      className="self-start rounded px-2 py-1 text-xs font-medium text-danger hover:bg-danger-soft"
                    >
                      Hapus
                    </button>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>
    </div>
  )
}

export function ItemsPage() {
  return (
    <MasterCrudPage<ItemRecord, ItemForm>
      title="Item"
      description="Item beserta satuan base dan konversi bertingkat."
      resource="/items"
      permissionPrefix="master.item"
      queryKey="items"
      columns={columns}
      schema={itemSchema}
      emptyForm={emptyForm}
      toForm={(record) => ({
        sku: record.sku,
        name: record.name,
        category_id: String(record.category_id),
        base_unit_id: String(record.base_unit_id),
        min_stock: record.min_stock,
        is_active: record.is_active,
        conversions: record.conversions.map((conversion) => ({
          unit_id: String(conversion.unit_id),
          factor_to_base: conversion.factor_to_base,
          is_purchase_default: conversion.is_purchase_default,
          is_sales_default: conversion.is_sales_default,
        })),
      })}
      toPayload={(values) => ({
        sku: values.sku,
        name: values.name,
        category_id: Number(values.category_id),
        base_unit_id: Number(values.base_unit_id),
        min_stock: values.min_stock,
        is_active: values.is_active,
        conversions: values.conversions.map((conversion) => ({
          unit_id: Number(conversion.unit_id),
          factor_to_base: conversion.factor_to_base,
          is_purchase_default: conversion.is_purchase_default,
          is_sales_default: conversion.is_sales_default,
        })),
      })}
      Fields={ItemFields}
      describe={(record) => `Item ${record.sku} - ${record.name}`}
      createLabel="Tambah item"
      formTitle={(record) => (record === null ? 'Tambah item' : `Ubah item ${record.sku}`)}
      defaultSort="sku"
    />
  )
}
