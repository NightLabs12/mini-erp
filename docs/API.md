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
| `SERVER_ERROR` | 500 | Kesalahan tak terduga |
