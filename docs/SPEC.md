# Spesifikasi Mini ERP

Dokumen ini adalah acuan tunggal pengembangan. Setiap fase mengikuti isi dokumen ini. Bila ada penyimpangan dari aturan domain pada bagian 3, penyimpangan wajib dicatat sebagai ADR di `docs/ADR/`.

## Ringkasan

Mini ERP end-to-end untuk portofolio dengan live demo. Perusahaan fiktif: **PT Contoh Niaga Makmur** (perdagangan dan manufaktur kaos sederhana). Mata uang IDR saja.

Cakupan: Core (RBAC, audit trail, penomoran dokumen, master data), Purchasing, Sales, Inventory dan HPP, Accounting (double-entry, tutup periode, laporan keuangan), serta Mini Manufacturing sebagai opsional.

## 1. Tech stack

| Lapisan | Pilihan |
| --- | --- |
| Backend | PHP 8.3, Laravel 11 (API-only), PostgreSQL 16, Sanctum (token), spatie/laravel-permission, maatwebsite/excel, barryvdh/laravel-dompdf, dedoc/scramble (OpenAPI), brick/math dan bcmath, Pest, Larastan level 6+, Pint |
| Frontend | React 18, Vite, TypeScript, Tailwind CSS, TanStack Query, TanStack Table, react-hook-form dan zod, React Router, Recharts |
| Infra | Docker Compose (php-fpm, nginx, postgres, node), Makefile, GitHub Actions |

Alternatif Blade + Alpine.js boleh dipakai, tetapi keputusan dan alasannya harus ditulis di README. Proyek ini memakai React.

## 2. Struktur repo

```
mini-erp/
  backend/            Laravel
    app/
      Domain/
        Core/          User, Role, AuditTrail, DocumentSequence, Setting
        Master/        Item, Unit, Partner, Warehouse, Category
        Purchasing/
        Sales/
        Inventory/
        Accounting/
        Manufacturing/ (opsional)
      Http/Controllers/Api/V1/...
      Http/Requests/
      Http/Resources/
    database/migrations, seeders, factories
    tests/Feature, tests/Unit
  frontend/           React
  docs/               SPEC.md, ARCHITECTURE.md, ERD.md, ADR/, API.md
  docker/, docker-compose.yml, Makefile, .github/workflows/ci.yml, README.md
```

Aturan arsitektur:

- Controller tipis. Logika bisnis ada di Service atau Action per use-case, misalnya `ApprovePurchaseOrder`, `PostGoodsReceipt`, `DisapproveSalesInvoice`.
- Satu resource, satu controller RESTful. Tidak ada controller global raksasa.
- Semua mutasi stok dan jurnal hanya lewat dua pintu: `StockService` dan `JournalService`. Menulis ke `stock_ledger` atau `journal_lines` dari tempat lain dilarang.
- Semua query memakai Eloquent atau Query Builder dengan parameter binding. Interpolasi variabel ke string SQL dilarang.
- Validasi input wajib lewat FormRequest di setiap endpoint.

## 3. Aturan domain inti

### 3.1 Presisi angka

- Uang: `numeric(18,2)`. Kuantitas: `numeric(18,4)`. Harga dan biaya satuan: `numeric(18,4)`.
- Jangan memakai float. Gunakan brick/math atau bcmath di PHP.
- Pembulatan HALF_UP di level total dokumen untuk pajak. Subtotal baris dibulatkan 2 desimal.

### 3.2 Satuan bertingkat

- Tiap item punya satu base unit (satuan terkecil) dan 0..n satuan lain di `item_unit_conversions` (`unit_id`, `factor_to_base`).
- Contoh: Kaos Polos base Pcs, Pack = 12 Pcs, Dus = 144 Pcs.
- Dokumen menyimpan qty sesuai satuan input dan `qty_base` hasil konversi. Stock ledger dan saldo stok selalu dalam satuan base.
- Harga satuan dokumen mengikuti satuan input. `unit_cost` di ledger selalu per satuan base.

### 3.3 State machine dokumen

Status: `draft -> approved -> closed`, tambahan `cancelled` (hanya dari draft).

- `draft`: boleh diedit atau dihapus (soft delete), belum berdampak ke stok dan jurnal.
- `approved`: berdampak ke stok dan/atau jurnal sesuai jenis dokumen, tidak boleh diedit.
- `closed`: selesai, misalnya PO ditutup manual atau otomatis saat terpenuhi, tidak bisa diubah.
- Disapprove (`approved -> draft`) hanya jika:
  1. periode dokumen masih open;
  2. tidak ada dokumen turunan aktif, misalnya GR yang sudah ditagih tidak boleh disapprove;
  3. pembalikan stok tidak menyebabkan saldo negatif dan tidak ada mutasi stok lanjutan pada item dan gudang tersebut setelah dokumen itu. Aturan sederhana dan aman, dijelaskan di ADR.
- Disapprove membuat entri pembalik di `stock_ledger` dan jurnal, bukan menghapus data lama. Seluruh proses dalam satu `DB::transaction`.
- Approve harus idempoten. Memanggil approve dua kali tidak menggandakan stok atau jurnal. Kembalikan 409 Conflict atau no-op yang jelas.

### 3.4 Penomoran dokumen otomatis

- Tabel `document_sequences` (`doc_type`, `year`, `month`, `last_number`) dengan `lockForUpdate`.
- Format `{PREFIX}/{YYYY}/{MM}/{0000}`. Prefix: PR, PO, GR, PI, PAYOUT, SO, DO, SI, PAYIN, TRF, OPN, ADJ, JV, PRD.
- Nomor dibuat saat dokumen disimpan pertama kali. Unique index mencegah nomor ganda dan race condition. Wajib ada test konkurensi.

### 3.5 Stock ledger dan saldo

- `stock_ledger` append-only. Tidak ada UPDATE atau DELETE, dijaga guard di model dan trigger PostgreSQL.
- Kolom minimal: `id`, `item_id`, `warehouse_id`, `movement_date`, `doc_type`, `doc_id`, `doc_line_id`, `qty_in_base`, `qty_out_base`, `unit_cost`, `total_value`, `balance_qty`, `balance_value`, `avg_cost_after`, `reversal_of_id` (nullable), `created_by`, `created_at`.
- `stock_balances` (`item_id`, `warehouse_id`, `qty_base`, `avg_cost`, `value`) diperbarui dalam transaksi yang sama, dengan unique `(item_id, warehouse_id)`.
- Locking baris `stock_balances` memakai `lockForUpdate` dalam urutan deterministik `item_id ASC, warehouse_id ASC` untuk menghindari deadlock.
- Stok negatif dilarang secara default (`allow_negative_stock=false`). Pesan error harus jelas: item, gudang, stok tersedia, dan jumlah diminta.

### 3.6 HPP moving average

- Penerimaan: `avg_baru = (qty_lama * avg_lama + qty_masuk * biaya_masuk) / (qty_lama + qty_masuk)`.
- Pengeluaran: biaya keluar memakai avg saat ini, avg tidak berubah.
- Avg disimpan 4 desimal. Nilai persediaan = qty * avg dibulatkan 2 desimal di level baris ledger. Selisih pembulatan dikoreksi pada baris terakhir agar `balance_value` konsisten.
- Contoh uji: terima 1.440 pcs @10.000, lalu 1.440 pcs @12.000 menghasilkan avg 11.000. Keluar 720 pcs menghasilkan HPP 7.920.000.

### 3.7 Akuntansi double-entry

- `journals` (header) dan `journal_lines`. Setiap jurnal wajib seimbang: `SUM(debit) = SUM(credit)`. Divalidasi di `JournalService` dan constraint atau trigger database.
- `journal_lines` CHECK: `debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0)`.
- Pemetaan akun dibaca dari `account_settings` (`key -> account_id`), bukan hardcode. Key minimal: `inventory_trading`, `inventory_raw`, `inventory_finished`, `grir`, `accounts_payable`, `accounts_receivable`, `vat_in`, `vat_out`, `sales_revenue`, `sales_return`, `cogs`, `stock_adjustment`, `purchase_price_variance`, `cash_default`, `bank_default`.
- Kategori item menentukan akun persediaan, HPP, dan penjualan, sebagai override terhadap default.
- Setiap jurnal punya `source_type`, `source_id`, `journal_date`, dan `period_id`.
- `accounting_periods` (`year`, `month`, `status` open atau closed). Posting, approve, atau disapprove ke periode closed ditolak.
- Tutup periode memvalidasi: (1) periode sebelumnya sudah closed, (2) semua jurnal periode seimbang, (3) tidak ada dokumen draft bertanggal di periode itu bila setting diaktifkan, (4) rekonsiliasi OK (lihat 3.9). Gagal berarti tampilkan daftar penyebab.
- Reopen periode hanya oleh role admin dan tercatat di audit trail.
- Laba tahun berjalan dihitung dinamis di Neraca tanpa jurnal penutup tahunan. Keputusan dicatat di ADR.

### 3.8 Template jurnal per dokumen

| Dokumen | Jurnal default |
| --- | --- |
| Goods Receipt (approve) | Dr Persediaan per kategori / Cr GR/IR. Nilai = qty_base * biaya, tanpa pajak. |
| Purchase Invoice (approve) | Dr GR/IR sebesar nilai GR yang ditagih, Dr PPN Masukan, Dr/Cr Selisih Harga Pembelian bila harga invoice berbeda dari harga GR / Cr Hutang Usaha sebesar total invoice. |
| Payment Out (approve) | Dr Hutang Usaha / Cr Kas atau Bank. |
| Delivery Order (approve) | Dr HPP / Cr Persediaan. Nilai = qty_base * avg saat keluar, simpan cogs per baris. |
| Sales Invoice (approve) | Dr Piutang Usaha / Cr Penjualan, Cr PPN Keluaran. |
| Incoming Payment (approve) | Dr Kas atau Bank / Cr Piutang Usaha. |
| Sales Return (opsional) | Dr Retur Penjualan, Dr/Cr PPN Keluaran / Cr Piutang, plus Dr Persediaan / Cr HPP pada biaya asli. |
| Purchase Return (opsional) | Kebalikan Goods Receipt plus penyesuaian hutang. |
| Warehouse Transfer | Tanpa jurnal (satu entitas). Stok keluar di gudang asal pada avg asal, masuk di gudang tujuan pada biaya yang sama. |
| Stock Opname (approve) | Selisih minus: Dr Selisih Persediaan / Cr Persediaan. Selisih plus: Dr Persediaan / Cr Selisih Persediaan. Biaya = avg saat ini. |
| Production Result (opsional) | Dr Persediaan Barang Jadi / Cr Persediaan Bahan Baku sebesar biaya komponen riil. Stok komponen keluar, stok barang jadi masuk dengan biaya total komponen / qty bagus. |
| Manual Journal (JV) | Lewat JournalService, wajib seimbang, tidak boleh memakai akun non-postable. |

### 3.9 Rekonsiliasi

- Saldo akun Hutang Usaha di GL = SUM sisa tagihan purchase invoice approved.
- Saldo akun Piutang Usaha di GL = SUM sisa tagihan sales invoice approved.
- Saldo akun Persediaan di GL = SUM `stock_balances.value`.
- Saldo GR/IR di GL = SUM nilai GR yang belum ditagih.
- Tampilkan selisih per baris dengan status OK atau MISMATCH.

### 3.10 Audit trail

- Tabel `audit_logs`: `user_id`, `auditable_type`, `auditable_id`, `event` (created, updated, deleted, approved, disapproved, closed, period_closed, dan seterusnya), `old_values` (jsonb), `new_values` (jsonb), `diff` (jsonb), `ip`, `user_agent`, `created_at`.
- Trait `Auditable` pada model utama.
- Middleware ringan mencatat request mutasi (method, path, status, durasi) ke `api_request_logs`.

### 3.11 RBAC

- Role: admin, purchasing, sales, warehouse, finance, viewer.
- Permission slug `{modul}.{resource}.{aksi}`, contoh `purchase.po.create`, `purchase.po.approve`, `purchase.po.disapprove`, `sales.invoice.approve`, `accounting.period.close`, `report.gl.view`.
- Aksi standar: view, create, update, delete, approve, disapprove, close, print, export.
- Backend menegakkan lewat Policy atau Gate. Frontend hanya menyembunyikan tombol berdasarkan permission, bukan satu-satunya pengaman.

### 3.12 Keamanan dan kualitas

- Parameter binding di semua query, FormRequest di semua endpoint, rate limiting login, CORS terbatas, password di-hash, token Sanctum dengan expiry, tidak ada secret di repo (hanya `.env.example`).
- Format error konsisten: `{ "message": "...", "code": "STOCK_INSUFFICIENT", "errors": { "field": ["..."] } }` dengan HTTP status benar (401, 403, 404, 409, 422).
- Pagination, sorting, dan filter standar pada semua list endpoint: `?page`, `?per_page`, `?sort`, `?filter[...]`, `?search`.
- Soft delete pada master dan dokumen draft. Unique index parsial `WHERE deleted_at IS NULL` untuk kode unik.
- Standar: Pint, Larastan level 6+, ESLint dan `tsc` strict untuk frontend.

## 4. Skema database

Semua tabel dokumen memiliki kolom umum: `id`, `doc_no` (unique), `doc_date`, `period_id`, `status` (draft, approved, closed, cancelled), `notes`, `created_by`, `approved_by`, `approved_at`, timestamps, `deleted_at`. Detail baris menyimpan `item_id`, `unit_id`, `qty`, `qty_base`, `unit_price` atau `unit_cost`, `discount_pct`, `line_total`.

### Core

`users`, `roles`, `permissions` (spatie), `audit_logs`, `api_request_logs`, `document_sequences`, `settings`, `account_settings`.

### Master

- `units(code, name)`
- `item_categories(code, name, item_type[trading|raw_material|finished_good], inventory_account_id, cogs_account_id, sales_account_id)`
- `items(sku, name, category_id, base_unit_id, min_stock, is_active)`
- `item_unit_conversions(item_id, unit_id, factor_to_base, is_purchase_default, is_sales_default)`
- `warehouses(code, name, address, is_active)`
- `suppliers(code, name, address, phone, email, tax_id, payment_term_days)`
- `customers(code, name, address, phone, email, tax_id, payment_term_days, credit_limit)`
- `selling_prices(item_id, unit_id, customer_id nullable, price, valid_from, valid_to)`

### Purchasing

- `purchase_requests`, `purchase_request_items`
- `purchase_orders(supplier_id, warehouse_id, expected_date, subtotal, discount_amount, tax_rate, tax_amount, total)`, `purchase_order_items` plus `received_qty_base`, `invoiced_qty_base`
- `goods_receipts(po_id nullable, supplier_id, warehouse_id)`, `goods_receipt_items(po_item_id, unit_cost_base)`
- `purchase_invoices(supplier_id, due_date, subtotal, tax_amount, total, paid_amount)`, `purchase_invoice_items(gr_item_id, qty_base, unit_price)`
- `supplier_payments(supplier_id, method, account_id, amount)`, `supplier_payment_allocations(payment_id, purchase_invoice_id, amount)`

### Sales

- `sales_orders(customer_id, warehouse_id, ...)`, `sales_order_items` plus `delivered_qty_base`, `invoiced_qty_base`
- `delivery_orders(so_id, customer_id, warehouse_id)`, `delivery_order_items(so_item_id, cogs_unit_cost_base)`
- `sales_invoices(customer_id, due_date, subtotal, tax_amount, total, paid_amount)`, `sales_invoice_items(do_item_id, ...)`
- `customer_payments(customer_id, method, account_id, amount)`, `customer_payment_allocations(payment_id, sales_invoice_id, amount)`

### Inventory

- `stock_ledger` (immutable), `stock_balances`
- `warehouse_transfers(from_warehouse_id, to_warehouse_id)`, `warehouse_transfer_items`
- `stock_opnames(warehouse_id)`, `stock_opname_items(system_qty_base, counted_qty_base, diff_qty_base, unit_cost)`

### Accounting

- `accounts(code, name, type[asset|liability|equity|revenue|cogs|expense], normal_balance[D|C], parent_id, level, is_postable)`
- `accounting_periods(year, month, status, closed_by, closed_at)`
- `journals(journal_no, journal_date, period_id, source_type, source_id, description, status[posted|reversed], reversal_of_id, total_debit, total_credit)`
- `journal_lines(journal_id, account_id, debit, credit, description, partner_type, partner_id)`

### Manufacturing (opsional)

- `boms(finished_item_id, version, is_active)`, `bom_items(component_item_id, qty_per_unit_base, scrap_pct)`
- `production_orders(bom_id, warehouse_id, planned_qty_base, status[draft|released|in_progress|completed])`
- `production_results(production_order_id, good_qty_base, defect_qty_base, downtime_minutes, result_date)`

Indeks wajib: `(item_id, warehouse_id, movement_date)` pada `stock_ledger`, `(account_id, journal_date)` pada `journal_lines`, `(doc_type, doc_id)` pada ledger dan journals, unique parsial pada kode master.

### Chart of Accounts default (seed)

```
1-1100 Kas
1-1200 Bank
1-1300 Piutang Usaha
1-1400 Persediaan Barang Dagang
1-1410 Persediaan Bahan Baku
1-1420 Persediaan Barang Jadi
1-1500 PPN Masukan
2-1100 Hutang Usaha
2-1200 GR/IR (Barang Diterima Belum Ditagih)
2-1300 PPN Keluaran
3-1000 Modal Saham
3-2000 Laba Ditahan
4-1000 Penjualan
4-2000 Retur Penjualan
5-1000 HPP
5-2000 Selisih Persediaan
5-3000 Selisih Harga Pembelian
6-1000 Beban Gaji
6-2000 Beban Sewa
6-3000 Beban Operasional Lain
```

Tambahkan akun induk non-postable sesuai kebutuhan. Total sekitar 40 akun.

## 5. API

Prefix `/api/v1`, JSON, auth Sanctum.

- **Auth**: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` (termasuk permission).
- **Master**: CRUD `/units`, `/item-categories`, `/items`, `/warehouses`, `/suppliers`, `/customers`, `/selling-prices`, `/accounts`; `GET /items/{id}/stock`.
- **Purchase**: CRUD `/purchase-requests`, `/purchase-orders`, `/goods-receipts`, `/purchase-invoices`, `/supplier-payments`; `POST /{resource}/{id}/approve`, `/disapprove`, `/close` sesuai jenis.
- **Sales**: CRUD `/sales-orders`, `/delivery-orders`, `/sales-invoices`, `/customer-payments` plus aksi approve, disapprove, close; `GET /selling-prices/lookup?item_id&customer_id&date&unit_id`.
- **Inventory**: CRUD `/warehouse-transfers`, `/stock-opnames` plus aksi; `GET /stock/balances`, `GET /stock/ledger`.
- **Accounting**: CRUD `/journals` (manual), `POST /journals/{id}/reverse`; `/accounting-periods` (list, `POST /{id}/close`, `POST /{id}/reopen`).
- **Reports**: `GET /reports/{kode}?...&format=json|xlsx|pdf`. Kode: `general-ledger`, `trial-balance`, `income-statement`, `balance-sheet`, `cash-bank`, `journal`, `stock-card`, `stock-position`, `stock-movement`, `ap-aging`, `ar-aging`, `ap-outstanding`, `ar-outstanding`, `sales-summary`, `purchase-summary`, `so-outstanding`, `po-outstanding`, `reconciliation`.
- **Dashboard**: `GET /dashboard/summary`.
- **Print**: `GET /{resource}/{id}/print` (PDF: PO, Surat Jalan, Sales Invoice, Payment).
- Dokumentasi OpenAPI otomatis di `/docs/api`.

## 6. Laporan

- **General Ledger**: saldo awal, transaksi, saldo akhir per akun. Gunakan CTE `begin_balance` dan `transactions` serta window function `SUM(...) OVER (PARTITION BY account_id ORDER BY journal_date, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)`. Filter periode atau tanggal dan akun.
- **Trial Balance**: saldo awal, mutasi debit dan kredit, saldo akhir per akun. Total debit = total kredit.
- **Income Statement**: Pendapatan, HPP, Laba Kotor, Beban, Laba Bersih. Bisa per bulan dan komparatif.
- **Balance Sheet**: Aset = Kewajiban + Ekuitas, termasuk laba tahun berjalan dinamis. Komparatif dua periode.
- **Cash/Bank Report**: mutasi harian kas dan bank dengan saldo berjalan.
- **Stock Card**: per item dan gudang, saldo berjalan qty dan nilai, avg cost.
- **Stock Position dan Movement**: posisi per tanggal, mutasi awal, masuk, keluar, akhir dalam periode.
- **AP/AR Aging**: bucket current, 1-30, 31-60, 61-90, lebih dari 90 hari.
- **Reconciliation**: lihat 3.9.

Semua laporan: filter, pagination bila besar, ekspor Excel (maatwebsite) dan PDF (dompdf). Query berat memakai index yang tepat. Tulis EXPLAIN ANALYZE singkat untuk dua laporan terberat di `docs/ARCHITECTURE.md`.

## 7. Frontend

- Layout: sidebar menu pohon berdasarkan permission, topbar (user, logout), breadcrumb.
- Halaman: Login, Dashboard (kartu penjualan bulan ini, pembelian bulan ini, nilai persediaan, hutang dan piutang beredar, grafik penjualan 6 bulan, daftar stok di bawah minimum, dokumen menunggu approval).
- Master: tabel dengan search, sort, pagination, form modal atau halaman.
- Dokumen transaksi (PO, GR, PI, SO, DO, SI, Payment, Transfer, Opname): form master-detail dengan grid editable.
  - Tambah dan hapus baris, navigasi keyboard (Tab, Enter, Arrow), lookup item lewat modal atau autocomplete.
  - Pilih satuan, tampilkan konversi ke base unit secara real-time.
  - Kalkulasi otomatis subtotal, diskon, PPN, dan total, sinkron dengan aturan pembulatan backend.
  - Lookup harga jual otomatis dari `selling_prices`.
  - Cek stok tersedia real-time saat input DO atau Transfer.
  - Badge status (draft, approved, closed), tombol Approve, Disapprove, Close, Print sesuai status dan permission.
  - Mode read-only otomatis setelah approved. Riwayat audit dokumen ditampilkan.
- Laporan: halaman filter, tabel, tombol ekspor Excel dan PDF.
- Akuntansi: daftar jurnal, form jurnal manual dengan validasi seimbang real-time, halaman Tutup Periode berisi checklist validasi dan hasil rekonsiliasi.
- UX: loading skeleton, empty state, toast error dari `message` API, konfirmasi aksi berbahaya, responsif minimal tablet, aksesibilitas dasar (label, fokus).
- Bahasa UI Indonesia, struktur i18n siap menambah Inggris.

## 8. Data dummy (seeder)

- Seeder memakai Service layer, bukan insert mentah, agar `stock_ledger` dan jurnal konsisten dan lolos rekonsiliasi.
- Isi: 2 gudang (Utama, Cabang), 6 kategori, sekitar 25 item (kaos polos, kain katun, benang, label, kemasan) dengan konversi Pcs/Pack/Dus atau Meter/Roll, 6 supplier, 6 customer, sekitar 40 akun COA, saldo awal lewat jurnal manual, dan 3 bulan transaksi (PO, GR, PI, Payment Out, SO, DO, SI, Payment In, 1 Transfer, 1 Opname) pada periode lalu yang sudah closed plus bulan berjalan yang open.
- User demo dengan password dari `.env`, ditulis di README: `admin@demo.test`, `purchasing@demo.test`, `sales@demo.test`, `warehouse@demo.test`, `finance@demo.test`, `viewer@demo.test`.
- Artisan command `demo:reset` mengembalikan data ke kondisi awal dan dijadwalkan harian lewat Laravel Scheduler. Mode demo (`DEMO_MODE=true`) menonaktifkan perubahan user, role, setting kritis, dan unggahan file.

## 9. Pengujian

Unit dan feature test minimal, memakai PostgreSQL:

1. Konversi satuan: 10 Dus = 1.440 Pcs, pembulatan `qty_base` benar.
2. Moving average: 1.440 @10.000 lalu 1.440 @12.000 menghasilkan avg 11.000 dan nilai 31.680.000. Keluar 720 menghasilkan HPP 7.920.000.
3. Stok negatif ditolak dengan kode `STOCK_INSUFFICIENT`.
4. Approve GR membuat ledger dan jurnal seimbang. Approve dua kali tidak menggandakan (idempoten).
5. Disapprove GR membuat entri pembalik. Ditolak bila sudah ditagih, ada mutasi lanjutan, atau periode closed.
6. Approve PI: GR/IR terbebas, PPN Masukan benar, selisih harga masuk akun variance.
7. Payment Out dan In: alokasi parsial ke beberapa invoice, sisa tagihan benar, tidak boleh melebihi sisa.
8. DO: HPP dari avg saat keluar. Sales Invoice: piutang dan PPN keluaran benar.
9. Transfer gudang: nilai total persediaan tetap, tanpa jurnal.
10. Stock opname: selisih minus dan plus menghasilkan jurnal yang benar.
11. Setiap jurnal di seluruh test seimbang. Buat helper `assertJournalBalanced`.
12. Tutup periode gagal bila ada jurnal tidak seimbang atau rekonsiliasi MISMATCH. Sukses bila valid. Posting ke periode closed ditolak.
13. Neraca seimbang: Aset = Kewajiban + Ekuitas. Trial Balance debit = kredit.
14. Penomoran dokumen: 50 request paralel menghasilkan nomor unik berurutan.
15. RBAC: user tanpa permission mendapat 403 pada approve, disapprove, dan period close.
16. Test konkurensi: dua DO bersamaan pada stok terbatas, salah satu gagal dengan benar tanpa stok negatif.
17. Audit trail merekam old, new, dan diff saat update dan approve.

Target coverage service layer inti (Stock, Journal, Purchasing, Sales, Accounting) minimal 80 persen.

## 10. Skenario acceptance end-to-end

Item: Kaos Polos, base Pcs, Pack = 12 Pcs, Dus = 144 Pcs. PPN 11 persen. Gudang Utama. Periode open.

1. PO-1: 10 Dus @ Rp1.440.000/Dus (Rp10.000/pcs). GR-1 10 Dus menghasilkan stok 1.440 pcs senilai 14.400.000. Jurnal GR: Dr Persediaan 14.400.000 / Cr GR/IR 14.400.000.
2. PI-1 untuk GR-1: Dr GR/IR 14.400.000, Dr PPN Masukan 1.584.000 / Cr Hutang 15.984.000.
3. Payment Out 15.984.000 via Bank: Dr Hutang / Cr Bank. Hutang menjadi 0.
4. PO-2: 10 Dus @ Rp1.728.000/Dus (Rp12.000/pcs). GR-2 menghasilkan total stok 2.880 pcs, nilai 31.680.000, avg 11.000.
5. SO-1: 5 Dus (720 pcs) @ Rp15.000/pcs. DO-1 menghasilkan HPP 7.920.000. Jurnal DO: Dr HPP 7.920.000 / Cr Persediaan 7.920.000. Stok 2.160 pcs, nilai 23.760.000, avg 11.000.
6. SI-1: subtotal 10.800.000, PPN 1.188.000, total 11.988.000. Jurnal: Dr Piutang 11.988.000 / Cr Penjualan 10.800.000 / Cr PPN Keluaran 1.188.000.
7. Payment In 11.988.000 via Bank: Dr Bank / Cr Piutang. Piutang menjadi 0.
8. Laba kotor = 10.800.000 - 7.920.000 = 2.880.000. Income Statement harus menunjukkan angka ini.
9. Opname: fisik 2.150 pcs (selisih -10 pcs). Jurnal: Dr Selisih Persediaan 110.000 / Cr Persediaan 110.000.
10. Rekonsiliasi: Persediaan GL = nilai `stock_balances`, AP = 0, AR = 0, GR/IR = 0. Semua OK.
11. Disapprove GR-1 harus ditolak karena sudah ditagih dan ada mutasi lanjutan. Approve DO dengan qty melebihi stok ditolak.
12. Tutup periode sukses. Approve dokumen bertanggal periode itu ditolak.

## 11. Dokumentasi dan DevOps

- **README**: ringkasan, fitur, screenshot atau GIF, arsitektur singkat, cara menjalankan (`make demo` atau `docker compose up`), akun demo, cara menjalankan test, tautan live demo, catatan "terinspirasi pengalaman kerja, bukan sistem asli".
- **docs/ARCHITECTURE.md**: diagram arsitektur Mermaid, alur data dokumen ke stok dan jurnal, keputusan desain, batasan.
- **docs/ERD.md**: ERD Mermaid per modul.
- **docs/ADR/**: minimal 5 ADR (moving average, ledger immutable dan reversal, laba tahun berjalan dinamis, aturan disapprove, REST per resource versus endpoint generik).
- **docs/API.md** atau OpenAPI otomatis plus koleksi Postman.
- **GitHub Actions**: Pint, Larastan, test backend dengan service PostgreSQL, lint, typecheck, dan build frontend.
- **Makefile**: `up`, `down`, `migrate`, `seed`, `test`, `lint`, `demo-reset`.
- Docker satu perintah: `make demo` menyalakan semua layanan, migrate, seed, lalu menampilkan URL dan akun demo.

## 12. Batasan cakupan

Di luar cakupan: multi-company atau cabang penuh, multi-currency, landed cost, batch dan serial number, MRP penuh, payroll, pajak e-Faktur, integrasi bank, approval berjenjang lebih dari satu level. Semua dicatat sebagai roadmap di README.

## 13. Cara kerja

- Kerjakan per fase sesuai instruksi pengguna.
- Setelah tiap fase: jalankan test dan lint, lalu laporkan (a) file yang dibuat atau diubah, (b) cara menjalankan, (c) hasil test, (d) asumsi dan keputusan, (e) hal yang belum selesai.
- Bila spesifikasi ambigu, pilih opsi paling sederhana yang aman, catat sebagai asumsi, dan lanjut. Jangan berhenti bertanya kecuali benar-benar menghalangi.
- Jangan menambah fitur di luar cakupan. Jangan menyederhanakan aturan domain di bagian 3 tanpa ADR.
- Tulis test bersamaan dengan fitur, bukan setelahnya.
