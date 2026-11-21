import { z } from 'zod'

const codePattern = /^[A-Za-z0-9_-]+$/
const skuPattern = /^[A-Za-z0-9._-]+$/

export function requiredText(label: string, max: number) {
  return z
    .string()
    .trim()
    .min(1, `${label} wajib diisi.`)
    .max(max, `${label} maksimal ${max} karakter.`)
}

export function optionalText(label: string, max: number) {
  return z.string().trim().max(max, `${label} maksimal ${max} karakter.`)
}

export function codeField(label: string, max = 20) {
  return z
    .string()
    .trim()
    .min(1, `${label} wajib diisi.`)
    .max(max, `${label} maksimal ${max} karakter.`)
    .regex(codePattern, `${label} hanya boleh berisi huruf, angka, garis bawah, atau tanda hubung.`)
}

export function skuField() {
  return z
    .string()
    .trim()
    .min(1, 'SKU wajib diisi.')
    .max(40, 'SKU maksimal 40 karakter.')
    .regex(skuPattern, 'SKU hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.')
}

export const optionalEmail = z
  .string()
  .trim()
  .max(150, 'Email maksimal 150 karakter.')
  .refine((value) => value === '' || /\S+@\S+\.\S+/.test(value), 'Format email tidak valid.')

export function integerField(label: string, min: number, max: number) {
  return z
    .string()
    .trim()
    .min(1, `${label} wajib diisi.`)
    .refine((value) => /^\d+$/.test(value), `${label} harus bilangan bulat.`)
    .refine(
      (value) => Number(value) >= min && Number(value) <= max,
      `${label} harus antara ${min} dan ${max}.`,
    )
}

export function decimalField(
  label: string,
  options: { min?: number; gt?: number; optional?: boolean } = {},
) {
  const base = z
    .string()
    .trim()
    .refine((value) => options.optional === true || value !== '', `${label} wajib diisi.`)
    .refine(
      (value) => value === '' || /^-?\d+(\.\d+)?$/.test(value),
      `${label} harus berupa angka.`,
    )

  return base
    .refine(
      (value) => value === '' || options.min === undefined || Number(value) >= options.min,
      `${label} minimal ${options.min ?? 0}.`,
    )
    .refine(
      (value) => value === '' || options.gt === undefined || Number(value) > options.gt,
      `${label} harus lebih besar dari ${options.gt ?? 0}.`,
    )
}

export function optionalDate(label: string) {
  return z
    .string()
    .trim()
    .refine(
      (value) => value === '' || /^\d{4}-\d{2}-\d{2}$/.test(value),
      `${label} harus berformat YYYY-MM-DD.`,
    )
}

export const unitSchema = z.object({
  code: codeField('Kode'),
  name: requiredText('Nama', 60),
  decimal_places: integerField('Presisi desimal', 0, 4),
})

export type UnitForm = z.infer<typeof unitSchema>

export const itemCategorySchema = z.object({
  code: codeField('Kode'),
  name: requiredText('Nama', 80),
  item_type: z.enum(['trading', 'raw_material', 'finished_good'], {
    message: 'Jenis item tidak dikenal.',
  }),
  inventory_account_id: z.string(),
  cogs_account_id: z.string(),
  sales_account_id: z.string(),
})

export type ItemCategoryForm = z.infer<typeof itemCategorySchema>

export const itemConversionSchema = z.object({
  unit_id: z.string().min(1, 'Satuan wajib dipilih.'),
  factor_to_base: decimalField('Faktor konversi', { gt: 0 }),
  is_purchase_default: z.boolean(),
  is_sales_default: z.boolean(),
})

export type ItemConversionForm = z.infer<typeof itemConversionSchema>

export const itemSchema = z
  .object({
    sku: skuField(),
    name: requiredText('Nama', 150),
    category_id: z.string().min(1, 'Kategori wajib dipilih.'),
    base_unit_id: z.string().min(1, 'Satuan base wajib dipilih.'),
    min_stock: decimalField('Stok minimum', { min: 0 }),
    is_active: z.boolean(),
    conversions: z.array(itemConversionSchema).max(20, 'Maksimal 20 konversi.'),
  })
  .superRefine((value, ctx) => {
    const seen = new Set<string>()

    value.conversions.forEach((conversion, index) => {
      if (conversion.unit_id === '') {
        return
      }

      if (conversion.unit_id === value.base_unit_id) {
        ctx.addIssue({
          code: 'custom',
          path: ['conversions', index, 'unit_id'],
          message: 'Satuan base tidak perlu ditulis ulang sebagai konversi.',
        })
      }

      if (seen.has(conversion.unit_id)) {
        ctx.addIssue({
          code: 'custom',
          path: ['conversions', index, 'unit_id'],
          message: 'Satu satuan tidak boleh muncul lebih dari sekali.',
        })
      }

      seen.add(conversion.unit_id)
    })
  })

export type ItemForm = z.infer<typeof itemSchema>

export const warehouseSchema = z.object({
  code: codeField('Kode'),
  name: requiredText('Nama', 80),
  address: optionalText('Alamat', 255),
  is_active: z.boolean(),
})

export type WarehouseForm = z.infer<typeof warehouseSchema>

export const supplierSchema = z.object({
  code: codeField('Kode'),
  name: requiredText('Nama', 150),
  address: optionalText('Alamat', 255),
  phone: optionalText('Telepon', 30),
  email: optionalEmail,
  tax_id: optionalText('NPWP', 40),
  payment_term_days: integerField('Termin pembayaran', 0, 365),
})

export type SupplierForm = z.infer<typeof supplierSchema>

export const customerSchema = z.object({
  code: codeField('Kode'),
  name: requiredText('Nama', 150),
  address: optionalText('Alamat', 255),
  phone: optionalText('Telepon', 30),
  email: optionalEmail,
  tax_id: optionalText('NPWP', 40),
  payment_term_days: integerField('Termin pembayaran', 0, 365),
  credit_limit: decimalField('Batas kredit', { min: 0 }),
})

export type CustomerForm = z.infer<typeof customerSchema>

export const sellingPriceSchema = z
  .object({
    item_id: z.string().min(1, 'Item wajib dipilih.'),
    unit_id: z.string().min(1, 'Satuan wajib dipilih.'),
    customer_id: z.string(),
    price: decimalField('Harga jual', { gt: 0 }),
    valid_from: optionalDate('Tanggal mulai'),
    valid_to: optionalDate('Tanggal akhir'),
  })
  .refine(
    (value) => value.valid_from === '' || value.valid_to === '' || value.valid_to >= value.valid_from,
    {
      path: ['valid_to'],
      message: 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
    },
  )

export type SellingPriceForm = z.infer<typeof sellingPriceSchema>

export const accountSchema = z.object({
  code: codeField('Kode', 20),
  name: requiredText('Nama', 120),
  type: z.enum(['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'], {
    message: 'Tipe akun tidak dikenal.',
  }),
  normal_balance: z.enum(['D', 'C'], { message: 'Saldo normal harus D atau C.' }),
  parent_id: z.string(),
  is_postable: z.boolean(),
  is_active: z.boolean(),
})

export type AccountForm = z.infer<typeof accountSchema>
