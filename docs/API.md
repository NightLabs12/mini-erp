# API Mini ERP

Dokumentasi interaktif tersedia di `/docs/api` (OpenAPI 3.1, dibuat otomatis oleh Scramble dari route dan FormRequest). Berkas JSON ada di `/docs/api.json`.

Semua endpoint memakai prefix `/api/v1`, mengirim dan menerima JSON.

## Format respons

Sukses tunggal:

```json
{ "data": { "id": 1 } }
```

Sukses daftar:

```json
{
  "data": [],
  "meta": { "current_page": 1, "per_page": 15, "last_page": 1, "total": 0, "from": null, "to": null },
  "links": { "first": "...", "prev": null, "next": null, "last": "..." }
}
```

Gagal:

```json
{ "message": "Anda tidak memiliki izin untuk aksi ini.", "code": "FORBIDDEN", "errors": {} }
```

## Autentikasi

Memakai bearer token Sanctum. Masa berlaku token mengikuti `SANCTUM_EXPIRATION` (default 1440 menit).

### POST /auth/login

```bash
curl -X POST http://localhost:8100/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@demo.test","password":"password","device_name":"laptop"}'
```

```json
{
  "data": {
    "token": "1|xxxxxxxx",
    "token_type": "Bearer",
    "expires_at": "2026-11-20T16:55:40+07:00",
    "user": {
      "id": 1,
      "name": "Admin Demo",
      "email": "admin@demo.test",
      "roles": ["admin"],
      "permissions": ["accounting.journal.create", "..."]
    }
  }
}
```

Dibatasi `LOGIN_RATE_LIMIT` percobaan per menit per email + IP. Kredensial salah menghasilkan `401 INVALID_CREDENTIALS`, bukan pesan yang membedakan email terdaftar atau tidak.

### GET /auth/me

Mengembalikan user aktif beserta role dan seluruh permission efektif.

### POST /auth/logout

Mencabut token yang sedang dipakai.

## User management

| Metode | Path | Permission |
| --- | --- | --- |
| GET | `/users` | `core.user.view` |
| POST | `/users` | `core.user.create` |
| GET | `/users/{id}` | `core.user.view` |
| PUT | `/users/{id}` | `core.user.update` |
| DELETE | `/users/{id}` | `core.user.delete` |
| GET | `/users/{id}/audit` | `core.user.view` |

Catatan:

- `roles` berisi nama role yang ada (`admin`, `purchasing`, `sales`, `warehouse`, `finance`, `viewer`).
- `is_active` (default `true`) menandai akun boleh dipakai. Token milik akun nonaktif ditolak `403 USER_INACTIVE`.
- Mengubah password mencabut seluruh token user tersebut, kecuali token yang dipakai pelaku saat mengubah passwordnya sendiri.
- Menonaktifkan atau menghapus user langsung mencabut semua tokennya.
- Pengguna tidak dapat menghapus akunnya sendiri (`403`).
- Role admin tidak dapat dicabut dari admin terakhir (`409 LAST_ADMIN`), dan admin aktif terakhir tidak dapat dinonaktifkan (`409 LAST_ADMIN`).
- Hapus user bersifat soft delete; email bisa dipakai kembali.
- Saat `DEMO_MODE=true`, semua perubahan user ditolak `403 DEMO_MODE_READONLY`.

Parameter daftar: `?search=`, `?filter[role]=sales`, `?filter[email]=`, `?sort=name`, `?page=`, `?per_page=`.

## Audit

| Metode | Path | Permission |
| --- | --- | --- |
| GET | `/audit-logs` | `core.audit.view` |

Filter: `?filter[auditable_type]=user`, `?filter[auditable_id]=7`, `?filter[user_id]=1`, `?filter[event]=updated`, `?filter[created_at][from]=2026-11-01`, `?filter[created_at][to]=2026-11-30`. Urutan default `-id`.

Satu baris berisi `old_values`, `new_values`, dan `diff` (jsonb). Atribut sensitif seperti `password` tidak pernah masuk audit.

Riwayat per resource tersedia di `GET /{resource}/{id}/audit` untuk `users`, `units`, `item-categories`, `items`, `warehouses`, `suppliers`, `customers`, dan `selling-prices`.

## Master data

Semua resource memakai pola yang sama: CRUD RESTful, pagination (`?page`, `?per_page`), `?search=`, `?filter[kolom]=`, `?sort=`, soft delete, dan audit.

| Resource | Path | Permission prefix |
| --- | --- | --- |
| Satuan | `/units` | `master.unit.*` |
| Kategori item | `/item-categories` | `master.item-category.*` |
| Item | `/items` | `master.item.*` |
| Konversi satuan item | `/items/{item}/unit-conversions` | `master.item.view` / `master.item.update` |
| Gudang | `/warehouses` | `master.warehouse.*` |
| Supplier | `/suppliers` | `master.supplier.*` |
| Customer | `/customers` | `master.customer.*` |
| Harga jual | `/selling-prices` | `master.selling-price.*` |

### Item dan konversi satuan

```bash
curl -X POST http://localhost:8100/api/v1/items \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "sku": "KAOS-POLOS",
    "name": "Kaos Polos",
    "category_id": 1,
    "base_unit_id": 1,
    "min_stock": 24,
    "conversions": [
      {"unit_id": 2, "factor_to_base": 12, "is_sales_default": true},
      {"unit_id": 3, "factor_to_base": 144, "is_purchase_default": true}
    ]
  }'
```

Aturan yang ditegakkan:

- `sku` unik di antara item aktif (`WHERE deleted_at IS NULL`); SKU boleh dipakai lagi setelah item dihapus.
- `base_unit_id` harus satuan yang ada.
- Setiap `conversions[].factor_to_base` harus > 0.
- Satu `unit_id` hanya boleh muncul sekali (rule `distinct`), dan tidak boleh sama dengan satuan base.
- Saat update, daftar konversi diselaraskan: unit baru ditambah, unit yang hilang dihapus, faktor yang berubah diperbarui.

Konversi juga bisa dikelola satu per satu lewat `/items/{item}/unit-conversions`.

### Harga jual dan lookup

```bash
curl "http://localhost:8100/api/v1/selling-prices/lookup?item_id=1&unit_id=3&customer_id=2&date=2026-11-20" \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
  "data": {
    "id": 7,
    "item_id": 1,
    "unit_id": 3,
    "customer_id": 2,
    "price": "1440000.0000",
    "unit_price_base": "10000.0000",
    "valid_from": "2026-11-01",
    "valid_to": null
  }
}
```

Urutan prioritas: harga khusus customer yang valid pada tanggal itu, lalu harga umum. Bila beberapa baris valid, `valid_from` terbaru menang. Tanpa harga yang cocok, endpoint mengembalikan `404 SELLING_PRICE_NOT_FOUND`.

### Penghapusan master

Penghapusan ditolak `409 MASTER_IN_USE` bila data masih direferensikan:

| Master | Dicek terhadap |
| --- | --- |
| Satuan | item (base unit), konversi, harga jual |
| Kategori | item |
| Item | harga jual, dokumen pembelian/penjualan, stok |
| Gudang | dokumen dan saldo stok |
| Supplier | dokumen pembelian |
| Customer | dokumen penjualan dan harga khusus |

Tabel dokumen yang belum ada pada fase ini otomatis dilewati, dan ikut diperiksa begitu tabelnya dibuat. Perubahan `base_unit_id` atau `factor_to_base` pada item yang sudah dipakai dokumen ditolak `409 ITEM_IN_USE`.

Presisi satuan ada di `units.decimal_places` (0-4). `UnitConversionService` menolak qty yang melebihi presisi satuan base atau menghasilkan `qty_base` pecahan pada satuan bulat dengan `422 QTY_PRECISION_INVALID`. Contoh: 0,5 PCS ditolak, 0,5 DUS x 144 = 72 PCS diterima.

Rentang `selling_prices` untuk kombinasi item, satuan, dan customer yang sama tidak boleh tumpang tindih (`422 PRICE_PERIOD_OVERLAP`). Batas `valid_from`/`valid_to` inklusif; null berarti terbuka tanpa batas.

## Akuntansi

| Metode | Path | Permission |
| --- | --- | --- |
| GET/POST | `/journals` | `accounting.journal.view` / `accounting.journal.create` |
| GET/PUT/DELETE | `/journals/{id}` | `accounting.journal.view` / `update` / `delete` |
| POST | `/journals/{id}/approve` | `accounting.journal.approve` |
| POST | `/journals/{id}/reverse` | `accounting.journal.reverse` |
| GET | `/journals/{id}/audit` | `accounting.journal.view` |
| GET | `/accounting-periods` | `accounting.period.view` |
| POST | `/accounting-periods/{id}/close` | `accounting.period.close` |
| POST | `/accounting-periods/{id}/reopen` | `accounting.period.reopen` + role admin |
| GET | `/reports/general-ledger` | `report.general-ledger.view` |
| GET | `/reports/trial-balance` | `report.trial-balance.view` |

Jurnal manual dibuat sebagai `draft` tanpa nomor. Approve memvalidasi keseimbangan dan periode open, lalu memberi nomor `JV/YYYY/MM/0000`. Reverse membuat jurnal baru dengan debit/credit ditukar dan menandai jurnal asal `reversed`.

```bash
curl -X POST http://localhost:8100/api/v1/journals \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "journal_date": "2026-11-20",
    "description": "Setoran modal",
    "lines": [
      {"account_id": 4, "debit": "1000000.00"},
      {"account_id": 30, "credit": "1000000.00"}
    ]
  }'
```

Tutup periode memvalidasi: periode sebelumnya closed, seluruh jurnal seimbang, tidak ada draft (bila setting aktif), dan rekonsiliasi. Gagal menghasilkan `409 PERIOD_CLOSE_FAILED` dengan `errors.reasons`. Posting ke periode closed menghasilkan `422 PERIOD_CLOSED`.

Laporan menerima `?from=YYYY-MM-DD&to=YYYY-MM-DD`; General Ledger juga menerima `?account_id=`. Default-nya bulan berjalan.

## Purchasing

Alur: Purchase Request -> Purchase Order -> Goods Receipt -> Purchase Invoice -> Supplier Payment (Payment Out). Semua dokumen memakai state machine `draft -> approved -> closed` dan dapat dibatalkan selama aturan SPEC 3.3 terpenuhi.

| Metode | Path | Permission |
| --- | --- | --- |
| GET/POST | `/purchase-requests` | `purchase.purchase-request.view` / `create` |
| GET/PUT/DELETE | `/purchase-requests/{id}` | `view` / `update` / `delete` |
| POST | `/purchase-requests/{id}/approve` | `purchase.purchase-request.approve` |
| POST | `/purchase-requests/{id}/disapprove` | `purchase.purchase-request.disapprove` |
| POST | `/purchase-requests/{id}/close` | `purchase.purchase-request.close` |
| POST | `/purchase-requests/{id}/convert` | `purchase.purchase-request.create` |
| GET/POST | `/purchase-orders` | `purchase.purchase-order.view` / `create` |
| GET/PUT/DELETE | `/purchase-orders/{id}` | `view` / `update` / `delete` |
| POST | `/purchase-orders/{id}/approve` | `purchase.purchase-order.approve` |
| POST | `/purchase-orders/{id}/disapprove` | `purchase.purchase-order.disapprove` |
| POST | `/purchase-orders/{id}/close` | `purchase.purchase-order.close` |
| GET | `/purchase-orders/{id}/print` | `purchase.purchase-order.print` |
| GET/POST | `/goods-receipts` | `purchase.goods-receipt.view` / `create` |
| GET/PUT/DELETE | `/goods-receipts/{id}` | `view` / `update` / `delete` |
| POST | `/goods-receipts/{id}/approve` | `purchase.goods-receipt.approve` |
| POST | `/goods-receipts/{id}/disapprove` | `purchase.goods-receipt.disapprove` |
| GET/POST | `/purchase-invoices` | `purchase.purchase-invoice.view` / `create` |
| GET/PUT/DELETE | `/purchase-invoices/{id}` | `view` / `update` / `delete` |
| POST | `/purchase-invoices/{id}/approve` | `purchase.purchase-invoice.approve` |
| POST | `/purchase-invoices/{id}/disapprove` | `purchase.purchase-invoice.disapprove` |
| GET/POST | `/supplier-payments` | `purchase.supplier-payment.view` / `create` |
| GET/PUT/DELETE | `/supplier-payments/{id}` | `view` / `update` / `delete` |
| POST | `/supplier-payments/{id}/approve` | `purchase.supplier-payment.approve` |
| POST | `/supplier-payments/{id}/disapprove` | `purchase.supplier-payment.disapprove` |
| GET | `/reports/po-outstanding` | `report.po-outstanding.view` |
| GET | `/reports/purchase-summary` | `report.purchase-summary.view` |
| GET | `/reports/ap-outstanding` | `report.ap-outstanding.view` |
| GET | `/reports/ap-aging` | `report.ap-aging.view` |

### Purchase Order

```bash
curl -X POST http://localhost:8100/api/v1/purchase-orders \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "supplier_id": 1,
    "warehouse_id": 1,
    "expected_date": "2026-11-25",
    "items": [
      {"item_id": 1, "unit_id": 3, "qty": "10", "unit_price": "1440000", "discount_pct": "0"}
    ]
  }'
```

- `discount_pct` 0-100, `tax_rate` opsional (default dari setting `tax_rate`).
- Subtotal, diskon, PPN, dan total dibulatkan 2 desimal HALF_UP.
- `unit_price_base` adalah harga bersih per satuan base tanpa pajak; GR memakai nilai ini sebagai biaya.
- PO otomatis `closed` saat seluruh qty base diterima. `POST /purchase-orders/{id}/close` menutup manual.
- `GET /purchase-orders/{id}/print` mengembalikan PDF.

### Goods Receipt

```bash
curl -X POST http://localhost:8100/api/v1/goods-receipts \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "purchase_order_id": 1,
    "items": [
      {"purchase_order_item_id": 1, "qty": "4"}
    ]
  }'
```

- Hanya dari PO `approved`.
- Parsial diperbolehkan. Qty dibatasi sisa PO ditambah `gr_over_receipt_tolerance_pct` (setting, default 0).
- Approve menerima stok lewat `StockService` pada biaya PO bersih per base dan membentuk jurnal `Dr Persediaan per kategori / Cr GR/IR`.
- Approve idempoten. Disapprove membalik stok dan jurnal, serta menolak bila GR sudah ditagih, ada mutasi lanjutan, atau periode closed.

### Purchase Invoice

```bash
curl -X POST http://localhost:8100/api/v1/purchase-invoices \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "supplier_id": 1,
    "items": [
      {"goods_receipt_item_id": 1, "qty": "10", "unit_price": "1440000"}
    ]
  }'
```

- Satu invoice dapat menagih beberapa GR dari supplier yang sama.
- `due_date` dihitung dari `doc_date + suppliers.payment_term_days`.
- Approve membentuk jurnal: `Dr GR/IR`, `Dr PPN Masukan`, `Dr/Cr Selisih Harga Pembelian`, `Cr Hutang Usaha`.
- Qty invoice tidak boleh melebihi sisa GR yang belum ditagih.
- Disapprove ditolak bila invoice sudah memiliki pembayaran.

### Supplier Payment

```bash
curl -X POST http://localhost:8100/api/v1/supplier-payments \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "supplier_id": 1,
    "method": "bank",
    "amount": "15984000.00",
    "allocations": [
      {"purchase_invoice_id": 1, "amount": "10000000.00"},
      {"purchase_invoice_id": 2, "amount": "5984000.00"}
    ]
  }'
```

- `method` `cash` atau `bank`; `account_id` opsional, default dari `account_settings` (`cash_default`/`bank_default`).
- Alokasi boleh parsial, tidak boleh melebihi sisa tagihan, dan total alokasi harus sama dengan `amount`.
- Approve membentuk jurnal `Dr Hutang Usaha / Cr Kas atau Bank` dan menaikkan `paid_amount` invoice.
- Disapprove membalik jurnal dan mengembalikan `paid_amount`.

### Laporan purchasing

- **PO Outstanding** (`?from=&to=&supplier_id=`): PO approved yang masih memiliki sisa diterima/ditagih, beserta nilai outstanding.
- **Purchase Summary** (`?from=&to=&supplier_id=`): total invoice approved per supplier (jumlah invoice, subtotal, PPN, total).
- **AP Outstanding** (`?as_of=&supplier_id=`): invoice approved dengan sisa tagihan, due date, dan hari keterlambatan.
- **AP Aging** (`?as_of=&supplier_id=`): bucket current, 1-30, 31-60, 61-90, dan lebih dari 90 hari.

## Sales

Alur: Sales Order -> Delivery Order (Surat Jalan) -> Sales Invoice -> Customer Payment (Payment In). Semua dokumen memakai state machine `draft -> approved -> closed` dan dapat dibatalkan selama aturan SPEC 3.3 terpenuhi.

| Metode | Path | Permission |
| --- | --- | --- |
| GET/POST | `/sales-orders` | `sales.sales-order.view` / `create` |
| GET/PUT/DELETE | `/sales-orders/{id}` | `view` / `update` / `delete` |
| POST | `/sales-orders/{id}/approve` | `sales.sales-order.approve` |
| POST | `/sales-orders/{id}/disapprove` | `sales.sales-order.disapprove` |
| POST | `/sales-orders/{id}/close` | `sales.sales-order.close` |
| GET/POST | `/delivery-orders` | `sales.delivery-order.view` / `create` |
| GET/PUT/DELETE | `/delivery-orders/{id}` | `view` / `update` / `delete` |
| POST | `/delivery-orders/{id}/approve` | `sales.delivery-order.approve` |
| POST | `/delivery-orders/{id}/disapprove` | `sales.delivery-order.disapprove` |
| GET | `/delivery-orders/{id}/print` | `sales.delivery-order.print` |
| GET/POST | `/sales-invoices` | `sales.sales-invoice.view` / `create` |
| GET/PUT/DELETE | `/sales-invoices/{id}` | `view` / `update` / `delete` |
| POST | `/sales-invoices/{id}/approve` | `sales.sales-invoice.approve` |
| POST | `/sales-invoices/{id}/disapprove` | `sales.sales-invoice.disapprove` |
| GET | `/sales-invoices/{id}/print` | `sales.sales-invoice.print` |
| GET/POST | `/customer-payments` | `sales.customer-payment.view` / `create` |
| GET/PUT/DELETE | `/customer-payments/{id}` | `view` / `update` / `delete` |
| POST | `/customer-payments/{id}/approve` | `sales.customer-payment.approve` |
| POST | `/customer-payments/{id}/disapprove` | `sales.customer-payment.disapprove` |
| GET | `/reports/so-outstanding` | `report.so-outstanding.view` |
| GET | `/reports/sales-summary` | `report.sales-summary.view` |
| GET | `/reports/ar-outstanding` | `report.ar-outstanding.view` |
| GET | `/reports/ar-aging` | `report.ar-aging.view` |
| GET | `/reports/income-statement` | `report.income-statement.view` |

### Sales Order

```bash
curl -X POST http://localhost:8100/api/v1/sales-orders \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "customer_id": 1,
    "warehouse_id": 1,
    "items": [
      {"item_id": 1, "unit_id": 3, "qty": "5", "discount_pct": "0"}
    ]
  }'
```

- `unit_price` opsional. Bila kosong, harga diambil dari `selling_prices` sesuai item, satuan, customer, dan tanggal dokumen.
- Override harga hanya diizinkan dengan permission `sales.sales-order.override-price`. Tanpa permission, harga berbeda ditolak `422 PRICE_OVERRIDE_FORBIDDEN`.
- Credit limit diperiksa sesuai setting `credit_limit_enforcement`: `off` (tanpa cek), `warn` (respons menyertakan `credit_warning`), `block` (`422 CREDIT_LIMIT_EXCEEDED`). Limit `0` dianggap tanpa batas.
- SO otomatis `closed` saat seluruh qty base dikirim; `POST /sales-orders/{id}/close` menutup manual.
- `delivered_qty_base` dan `invoiced_qty_base` per baris menjadi dasar sisa pengiriman/penagihan.

### Delivery Order (Surat Jalan)

```bash
curl -X POST http://localhost:8100/api/v1/delivery-orders \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "sales_order_id": 1,
    "items": [
      {"sales_order_item_id": 1, "qty": "2"}
    ]
  }'
```

- Hanya dari SO approved; parsial diperbolehkan.
- Approve mengeluarkan stok lewat `StockService` pada average cost saat keluar, menyimpan `cogs_unit_cost_base` dan `total_cogs` per baris, lalu membentuk jurnal `Dr HPP / Cr Persediaan`.
- Qty tidak boleh melebihi sisa SO (`422 DO_QTY_EXCEEDS_REMAINING`) atau stok tersedia (`422 STOCK_INSUFFICIENT`).
- Disapprove membalik stok dan jurnal; ditolak bila DO sudah ditagih, ada mutasi lanjutan, atau periode closed.
- `GET /delivery-orders/{id}/print` menghasilkan PDF Surat Jalan.

### Sales Invoice

```bash
curl -X POST http://localhost:8100/api/v1/sales-invoices \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "customer_id": 1,
    "mode": "draft",
    "items": [
      {"delivery_order_item_id": 1, "qty": "5"}
    ]
  }'
```

- Satu invoice dapat menagih beberapa DO dari customer yang sama.
- `due_date` dihitung dari `doc_date + customers.payment_term_days`.
- `mode=draft` dapat diubah; `mode=final` langsung diposting saat dibuat. Approve selalu mengubah mode menjadi `final`.
- Jurnal: `Dr Piutang Usaha`, `Cr Penjualan per kategori item`, `Cr PPN Keluaran`.
- Qty tidak boleh melebihi sisa DO (`422 SI_QTY_EXCEEDS_REMAINING`).
- Disapprove ditolak bila invoice sudah memiliki pembayaran.
- `GET /sales-invoices/{id}/print` menghasilkan PDF Invoice.

### Customer Payment (Payment In)

```bash
curl -X POST http://localhost:8100/api/v1/customer-payments \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "customer_id": 1,
    "method": "bank",
    "amount": "11988000.00",
    "allocations": [
      {"sales_invoice_id": 1, "amount": "8000000.00"},
      {"sales_invoice_id": 2, "amount": "3988000.00"}
    ]
  }'
```

- `method` `cash` atau `bank`; `account_id` opsional, default dari `account_settings` (`cash_default`/`bank_default`).
- Alokasi boleh parsial, tidak boleh melebihi sisa tagihan, dan total alokasi harus sama dengan `amount`.
- Approve membentuk jurnal `Dr Kas atau Bank / Cr Piutang Usaha` dan menaikkan `paid_amount` invoice.
- Disapprove membalik jurnal dan mengembalikan `paid_amount`.

### Laporan sales

- **SO Outstanding** (`?from=&to=&customer_id=`): SO approved yang masih memiliki sisa dikirim/ditagih, beserta nilai outstanding.
- **Sales Summary** (`?from=&to=&customer_id=`): total invoice approved per customer.
- **AR Outstanding** (`?as_of=&customer_id=`): invoice approved dengan sisa tagihan, due date, dan hari keterlambatan.
- **AR Aging** (`?as_of=&customer_id=`): bucket current, 1-30, 31-60, 61-90, dan lebih dari 90 hari.
- **Income Statement** (`?from=&to=`): pendapatan, HPP, laba kotor, beban, dan laba bersih dinamis dari jurnal posted/reversed.

## Laporan dan Dashboard

Semua laporan memakai satu endpoint generik:

```text
GET /reports/{kode}?format=json|xlsx|pdf
```

`format` default `json`. Respons JSON berbentuk `{ "data": ... }`; `xlsx` dan `pdf` mengembalikan berkas unduhan. Hak akses mengikuti `report.{kode}.view`.

| Kode | Parameter utama |
| --- | --- |
| `general-ledger` | `from`, `to`, `account_id` (opsional) |
| `trial-balance` | `from`, `to` |
| `income-statement` | `from`, `to`, `compare_from`, `compare_to` (opsional) |
| `balance-sheet` | `as_of`, `compare_as_of` (opsional) |
| `cash-bank` | `from`, `to` |
| `journal` | `from`, `to`, `source_type`, `status` (opsional) |
| `stock-card` | `item_id` (wajib), `warehouse_id`, `from`, `to` |
| `stock-position` | `from`, `to`, `item_id`, `warehouse_id` |
| `stock-movement` | `from`, `to`, `item_id`, `warehouse_id` |
| `ap-aging` / `ap-outstanding` | `as_of`, `supplier_id` |
| `ar-aging` / `ar-outstanding` | `as_of`, `customer_id` |
| `sales-summary` / `so-outstanding` | `from`, `to`, `customer_id` |
| `purchase-summary` / `po-outstanding` | `from`, `to`, `supplier_id` |
| `reconciliation` | `as_of`, atau `year` + `month` |

Contoh:

```bash
curl "http://localhost:8100/api/v1/reports/income-statement?from=2026-11-01&to=2026-11-30" \
  -H "Authorization: Bearer $TOKEN"

curl -OJ "http://localhost:8100/api/v1/reports/balance-sheet?as_of=2026-11-30&format=pdf" \
  -H "Authorization: Bearer $TOKEN"
```

### Isi laporan

- **Laba Rugi**: `sections.revenue|cogs|expense` dengan `groups` dan `lines`, `gross_profit`, `operating_profit`, `net_profit`, serta `comparison` berisi totals dan variance.
- **Neraca**: `assets`, `liabilities`, `equity`, `totals`, `balanced`, dan `comparison`. Baris `3-3000` bertanda `dynamic=true` adalah laba tahun berjalan.
- **Kas/Bank**: `accounts[].opening_balance`, `lines[].running_balance`, `closing_balance`, dan totals.
- **Jurnal**: `journals[].lines`, totals debit/kredit.
- **Rekonsiliasi**: `checks[]` berisi `check`, `label`, `expected`, `actual`, `difference`, `status` (`OK`, `MISMATCH`, `SKIPPED`), dan `ok`.

### Dashboard

```text
GET /dashboard/summary
```

Permission `core.dashboard.view`. Mengembalikan `sales_this_month`, `purchases_this_month`, `inventory_value`, `ap_outstanding`, `ar_outstanding`, `sales_series` (6 bulan), `low_stock_items`, dan `pending_approvals` (total + per resource).

## Persediaan

| Metode | Path | Permission |
| --- | --- | --- |
| GET | `/stock/balances` | `inventory.stock.view` |
| GET | `/stock/ledger` | `inventory.stock.view` |
| GET | `/items/{id}/stock` | `inventory.stock.view` |
| GET/POST | `/warehouse-transfers` | `inventory.warehouse-transfer.view` / `create` |
| GET/PUT/DELETE | `/warehouse-transfers/{id}` | `view` / `update` / `delete` |
| POST | `/warehouse-transfers/{id}/approve` | `inventory.warehouse-transfer.approve` |
| POST | `/warehouse-transfers/{id}/disapprove` | `inventory.warehouse-transfer.disapprove` |
| GET | `/warehouse-transfers/{id}/audit` | `inventory.warehouse-transfer.view` |
| GET/POST | `/stock-opnames` | `inventory.stock-opname.view` / `create` |
| GET/PUT/DELETE | `/stock-opnames/{id}` | `view` / `update` / `delete` |
| POST | `/stock-opnames/{id}/approve` | `inventory.stock-opname.approve` |
| POST | `/stock-opnames/{id}/disapprove` | `inventory.stock-opname.disapprove` |
| GET | `/stock-opnames/{id}/audit` | `inventory.stock-opname.view` |
| GET | `/reports/stock-card` | `report.stock-card.view` |
| GET | `/reports/stock-position` | `report.stock-position.view` |
| GET | `/reports/stock-movement` | `report.stock-movement.view` |

Saldo dan ledger hanya bisa dibaca. Mutasi stok hanya lahir dari approve dokumen lewat `StockService`; tidak ada endpoint untuk menulis ledger langsung.

### Saldo, ledger, dan stok per item

- `/stock/balances` menerima `?filter[item_id]=`, `?filter[warehouse_id]=`, `?search=` (SKU/nama item atau kode/nama gudang), `?sort=`, `?page=`, `?per_page=`.
- `/stock/ledger` menerima filter `item_id`, `warehouse_id`, `doc_type`, `doc_id`, `movement_date` (termasuk `[from]`/`[to]`), dan `reversal_of_id`.
- `/items/{id}/stock` mengembalikan saldo item di semua gudang, total qty base, dan total nilai.

### Transfer gudang

```bash
curl -X POST http://localhost:8100/api/v1/warehouse-transfers \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "from_warehouse_id": 1,
    "to_warehouse_id": 2,
    "notes": "Pindah stok",
    "items": [
      {"item_id": 1, "unit_id": 3, "qty": "5"}
    ]
  }'
```

Approve mengeluarkan stok dari gudang asal pada average asal dan menerima qty serta nilai yang sama di gudang tujuan. Total nilai persediaan tidak berubah dan **tidak ada jurnal**. Disapprove membalik kedua sisi mutasi.

### Stock opname

```bash
curl -X POST http://localhost:8100/api/v1/stock-opnames \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "doc_date": "2026-11-20",
    "warehouse_id": 1,
    "notes": "Hitung fisik",
    "items": [
      {"item_id": 1, "unit_id": 1, "counted_qty": "2150"}
    ]
  }'
```

Saat approve, sistem membaca saldo terkini dan menghitung ulang selisih. Selisih minus menghasilkan `Dr Selisih Persediaan / Cr Persediaan`; selisih plus menghasilkan `Dr Persediaan / Cr Selisih Persediaan`. Jurnal dibuat lewat `JournalService` dan wajib seimbang. Jika seluruh selisih nol, tidak ada mutasi dan tidak ada jurnal. Disapprove membalik stok dan jurnal penyesuaian.

### Laporan persediaan

- **Kartu Stok**: `?item_id=` wajib, `?warehouse_id=` opsional, `?from=` dan `?to=` default bulan berjalan. Mengembalikan saldo awal, baris mutasi dengan saldo berjalan, average berjalan, dan saldo akhir per gudang.
- **Posisi Stok**: `?from=`, `?to=`, opsional `?item_id=` dan `?warehouse_id=`. Mengembalikan saldo awal, masuk, keluar, dan akhir per item dan gudang.
- **Mutasi Stok**: filter yang sama; mengembalikan baris ledger dan total qty/nilai masuk-keluar.

Semua nilai qty memakai 4 desimal dan nilai uang 2 desimal. Baris pembalik ikut tampil dengan `reversal_of_id`.

## Kode error

| Kode | HTTP | Kapan |
| --- | --- | --- |
| `VALIDATION_ERROR` | 422 | Payload tidak lolos FormRequest |
| `UNAUTHENTICATED` | 401 | Token tidak ada, salah, atau kedaluwarsa |
| `INVALID_CREDENTIALS` | 401 | Email atau password salah |
| `USER_INACTIVE` | 403 | Akun dinonaktifkan (login dengan password benar, atau token masih dipakai) |
| `FORBIDDEN` | 403 | Token valid tetapi permission kurang |
| `NOT_FOUND` | 404 | Resource tidak ada |
| `RATE_LIMITED` | 429 | Melewati batas request |
| `DEMO_MODE_READONLY` | 403 | Perubahan data kritis saat `DEMO_MODE=true` |
| `MASTER_IN_USE` | 409 | Master masih direferensikan data lain |
| `ITEM_IN_USE` | 409 | Satuan base/faktor item diubah padahal sudah dipakai dokumen |
| `UNIT_NOT_CONVERTIBLE` | 422 | Satuan tidak terdefinisi untuk item |
| `QTY_PRECISION_INVALID` | 422 | Qty melebihi presisi satuan atau qty_base pecahan pada satuan bulat |
| `CONVERSION_NOT_FOUND` | 404 | Konversi bukan milik item tersebut |
| `SELLING_PRICE_NOT_FOUND` | 404 | Tidak ada harga jual yang berlaku |
| `PRICE_PERIOD_OVERLAP` | 422 | Rentang harga tumpang tindih |
| `JOURNAL_NOT_BALANCED` | 422 | Debit dan credit jurnal tidak sama |
| `JOURNAL_LINE_INVALID` | 422 | Baris jurnal tidak valid (debit/credit ganda, negatif, nol) |
| `ACCOUNT_NOT_POSTABLE` | 422 | Akun induk/nonaktif dipakai jurnal |
| `ACCOUNT_MAPPING_MISSING` | 422 | Key account_settings belum punya akun |
| `JOURNAL_NOT_DRAFT` | 409 | Aksi draft dilakukan pada jurnal posted/reversed |
| `JOURNAL_NOT_REVERSIBLE` | 409 | Reverse dilakukan pada jurnal yang bukan posted |
| `PERIOD_NOT_FOUND` | 422 | Belum ada baris periode untuk tanggal tersebut |
| `PERIOD_CLOSED` | 422 | Posting ke periode closed |
| `PERIOD_CLOSE_FAILED` | 409 | Validasi tutup periode gagal |
| `PERIOD_ALREADY_CLOSED` / `PERIOD_ALREADY_OPEN` | 409 | Status periode sudah sesuai aksi |
| `PERIOD_LATER_CLOSED` | 409 | Reopen periode yang diikuti periode closed |
| `LAST_ADMIN` | 409 | Mencabut atau menonaktifkan admin aktif terakhir |
| `UNKNOWN_DOC_TYPE` | 422 | Prefix dokumen tidak dikenal |
| `STOCK_INSUFFICIENT` | 422 | Qty keluar melebihi saldo dan stok negatif tidak diizinkan |
| `STOCK_REVERSAL_BLOCKED` | 409 | Reverse/disapprove ditolak karena ada mutasi lanjutan |
| `STOCK_ALREADY_REVERSED` | 409 | Seluruh mutasi aktif dokumen sudah dibalik |
| `STOCK_NOT_FOUND` | 404 | Dokumen tidak memiliki mutasi stok |
| `STOCK_DOC_REF_INVALID` | 422 | Reverse tanpa `doc_id` |
| `STOCK_QTY_INVALID` | 422 | Qty stok nol/negatif pada mutasi |
| `STOCK_COST_INVALID` | 422 | Biaya atau nilai persediaan negatif |
| `SAME_WAREHOUSE_TRANSFER` | 422 | Gudang asal dan tujuan sama |
| `DOCUMENT_NOT_DRAFT` | 409 | Aksi draft dilakukan pada dokumen non-draft |
| `DOCUMENT_NOT_APPROVED` | 409 | Disapprove dilakukan pada dokumen yang belum approved |
| `DOCUMENT_STATE_INVALID` | 409 | Aksi tidak diizinkan dari status dokumen saat ini |
| `DOCUMENT_EMPTY` | 422 | Dokumen tidak memiliki baris item |
| `DOCUMENT_HAS_CHILDREN` | 409 | Disapprove ditolak karena ada dokumen turunan aktif |
| `DOCUMENT_HAS_PAYMENTS` | 409 | Disapprove invoice yang sudah dibayar |
| `PURCHASE_ORDER_NOT_APPROVED` | 409 | GR dibuat dari PO yang belum approved/closed |
| `GR_QTY_EXCEEDS_REMAINING` | 422 | Qty GR melebihi sisa PO dan toleransi |
| `PI_QTY_EXCEEDS_REMAINING` | 422 | Qty invoice melebihi sisa GR |
| `GOODS_RECEIPT_NOT_APPROVED` | 409 | Invoice menagih GR yang belum approved |
| `PURCHASE_INVOICE_SUPPLIER_MISMATCH` | 422 | Supplier invoice berbeda dari supplier GR |
| `PAYMENT_ALLOCATION_INVALID` | 422 | Alokasi tidak valid |
| `PAYMENT_ALLOCATION_REQUIRED` | 422 | Pembayaran tanpa alokasi |
| `PAYMENT_ALLOCATION_DUPLICATE` | 422 | Invoice dialokasikan lebih dari sekali |
| `PAYMENT_AMOUNT_MISMATCH` | 422 | Amount tidak sama dengan total alokasi |
| `PAYMENT_EXCEEDS_REMAINING` | 422 | Alokasi melebihi sisa tagihan |
| `PAYMENT_INVOICE_NOT_FOUND` | 422 | Invoice alokasi tidak ditemukan |
| `PAYMENT_INVOICE_NOT_APPROVED` | 409 | Invoice alokasi belum approved |
| `PAYMENT_SUPPLIER_MISMATCH` | 422 | Invoice bukan milik supplier pembayaran |
| `PAYMENT_METHOD_INVALID` | 422 | Metode bukan cash/bank |
| `SUPPLIER_REQUIRED` | 422 | Konversi PR tanpa supplier |
| `PURCHASE_PRICE_INVALID` | 422 | Harga satuan negatif |
| `PURCHASE_DISCOUNT_INVALID` | 422 | Diskon di luar 0-100 |
| `TAX_RATE_INVALID` | 422 | Tarif PPN di luar 0-100 |
| `GOODS_RECEIPT_LINE_INVALID` | 422 | Baris GR tidak terhubung ke PO |
| `PURCHASE_INVOICE_LINE_INVALID` | 422 | Baris invoice tidak terhubung ke GR |
| `SELLING_PRICE_NOT_FOUND` | 422 | Item belum punya harga jual dan harga tidak diisi |
| `PRICE_OVERRIDE_FORBIDDEN` | 422 | Harga berbeda tanpa permission override |
| `SALES_PRICE_INVALID` | 422 | Harga jual negatif |
| `SALES_DISCOUNT_INVALID` | 422 | Diskon di luar 0-100 |
| `CREDIT_LIMIT_EXCEEDED` | 422 | Credit limit terlampaui pada mode block |
| `SALES_ORDER_NOT_APPROVED` | 409 | DO dibuat dari SO yang belum approved/closed |
| `DO_QTY_EXCEEDS_REMAINING` | 422 | Qty DO melebihi sisa SO |
| `DELIVERY_ORDER_NOT_APPROVED` | 409 | Invoice menagih DO yang belum approved |
| `DELIVERY_ORDER_LINE_INVALID` | 422 | Baris DO tidak terhubung ke SO |
| `SI_QTY_EXCEEDS_REMAINING` | 422 | Qty invoice melebihi sisa DO |
| `SALES_INVOICE_MODE_INVALID` | 422 | Mode invoice bukan draft/final |
| `SALES_INVOICE_LINE_INVALID` | 422 | Baris invoice tidak terhubung ke DO |
| `SALES_INVOICE_CUSTOMER_MISMATCH` | 422 | Customer invoice berbeda dari customer DO |
| `PAYMENT_CUSTOMER_MISMATCH` | 422 | Invoice bukan milik customer pembayaran |
| `REPORT_NOT_FOUND` | 404 | Kode laporan tidak dikenal |
| `REPORT_PARAMETER_REQUIRED` | 422 | Parameter laporan wajib belum diisi (mis. `item_id`) |
| `SERVER_ERROR` | 500 | Kesalahan tak terduga |
