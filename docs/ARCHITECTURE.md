# Arsitektur Mini ERP

Dokumen ini tumbuh per fase. Fase 0 menetapkan kerangka: bagaimana request masuk, bagaimana respons dibentuk, dan bagaimana lapisan domain akan disusun.

## Diagram komponen

```mermaid
flowchart LR
    Browser["Browser (React 18)"] -->|"JSON + Bearer token"| Nginx[nginx]
    Nginx -->|"FastCGI"| App["php-fpm + Laravel 11"]
    App --> DB[("PostgreSQL 16")]
    App --> Storage["storage/ (PDF, ekspor)"]
    Dev["Vite dev server :5173"] -.->|"proxy /api"| Nginx
```

## Alur satu request

```mermaid
sequenceDiagram
    participant C as Klien
    participant N as nginx
    participant M as Middleware
    participant R as FormRequest
    participant K as Controller
    participant S as Action/Service
    participant D as Database

    C->>N: POST /api/v1/...
    N->>M: FastCGI
    M->>M: ForceJsonResponse, auth, throttle
    M->>R: validasi input
    R-->>C: 422 { message, code, errors } bila gagal
    R->>K: data tervalidasi
    K->>S: panggil use-case
    S->>D: transaksi + lock
    S-->>K: model atau hasil
    K-->>C: 200 { data }
```

Aturan yang berlaku sejak awal:

- Controller tipis, hanya orkestrasi. Logika bisnis di Action atau Service per use-case.
- Validasi selalu lewat FormRequest. Tidak ada `$request->validate()` di controller.
- Kesalahan domain dilempar sebagai `App\Exceptions\ApiException` dengan kode mesin, misalnya `STOCK_INSUFFICIENT`, sehingga klien tidak perlu membaca teks pesan.
- Semua query memakai binding. Nama kolom untuk sort dan filter divalidasi terhadap daftar putih per resource.

## Format respons

| Kasus | Bentuk |
| --- | --- |
| Sukses tunggal | `{ "data": { ... } }` |
| Sukses daftar | `{ "data": [...], "meta": { current_page, per_page, last_page, total, from, to }, "links": { first, prev, next, last } }` |
| Gagal | `{ "message": "...", "code": "SNAKE_CASE", "errors": { "field": ["..."] } }` |

Status yang dipakai: 200, 201, 401, 403, 404, 405, 409, 422, 429, 500.

## List endpoint

`App\Support\Api\ListQuery` menangani `?search=`, `?filter[kolom]=nilai`, `?filter[kolom][operator]=nilai`, `?sort=`, `?page=`, `?per_page=`.

- Operator filter yang didukung: `eq`, `gte`, `lte`, `gt`, `lt`, `from`, `to`, `like`, `in`.
- Kolom yang boleh dipakai didaftarkan per resource. Kolom di luar daftar diabaikan.
- Nama kolom divalidasi dengan pola identifier, nilai dikirim sebagai binding, dan wildcard `%` serta `_` pada pencarian di-escape.
- `per_page` dibatasi maksimum 100.

## Lapisan domain yang akan dibangun

```mermaid
flowchart TB
    subgraph Core
        User --> Audit["Audit trail"]
        Seq["DocumentSequence"]
        Set["Settings + AccountSettings"]
    end
    subgraph Master
        Unit
        Category
        Item
        Warehouse
        Partner["Supplier + Customer"]
    end
    subgraph Purchasing
        PR --> PO --> GR --> PI --> PaymentOut
    end
    subgraph Sales
        SO --> DO --> SI --> PaymentIn
    end
    subgraph Inventory
        Stock["StockService"]
        Ledger["stock_ledger (append-only)"]
        Balances["stock_balances"]
        Stock --> Ledger
        Stock --> Balances
    end
    subgraph Accounting
        Journal["JournalService"]
        JHeader["journals"]
        JLines["journal_lines"]
        Journal --> JHeader
        Journal --> JLines
    end
    GR --> Stock
    DO --> Stock
    GR --> Journal
    DO --> Journal
    PI --> Journal
    SI --> Journal
```

Dua pintu mutasi yang dijaga:

- `StockService` satu-satunya penulis `stock_ledger` dan `stock_balances`. Ledger append-only, saldo diperbarui dengan moving average dan lock `FOR UPDATE` berurutan `item_id, warehouse_id`.
- `JournalService` satu-satunya penulis `journals` dan `journal_lines`. Jurnal wajib seimbang, periode harus open, dan akun harus postable.

## Modul Core (Fase 1)

```mermaid
flowchart TB
    subgraph Auth
        L["POST /auth/login"] --> T["Sanctum token (expiry dari SANCTUM_EXPIRATION)"]
        T --> M["GET /auth/me"]
    end
    subgraph RBAC
        R["RolePermissionSeeder"] --> P["PermissionRegistry: 172 slug"]
        P --> G["Policy + middleware permission:"]
    end
    subgraph Audit
        E["Model event: created/updated/deleted"] --> S["AuditTrailService"]
        S --> AL[("audit_logs jsonb")]
        D["Aksi domain: approve/close"] --> S
        MW["LogApiRequest (terminate)"] --> RL[("api_request_logs")]
    end
    subgraph Numbering
        N["DocumentSequenceService.next()"] --> DS[("document_sequences")]
    end
```

### Auth

- Login memakai `Hash::check`, bukan `Auth::attempt`, karena API tidak memakai session. Kredensial salah selalu menghasilkan `401 INVALID_CREDENTIALS` dengan pesan sama, sehingga tidak membocorkan email terdaftar.
- Status nonaktif hanya diungkap setelah password terbukti benar (`403 USER_INACTIVE`), jadi penyerang tanpa password tidak dapat memetakan akun.
- Token dibuat dengan `expiresAt` eksplisit; `SANCTUM_EXPIRATION` (menit) menjadi satu-satunya sumber kebenaran.
- `/auth/me` mengembalikan role dan permission efektif, dipakai frontend untuk menyusun menu.
- Logout menghapus token yang sedang dipakai, bukan seluruh token pengguna.

#### Siklus hidup token

| Kejadian | Efek |
| --- | --- |
| Password diubah oleh pemilik akun | Seluruh token lain dicabut, token yang dipakai tetap berlaku |
| Password diubah admin untuk user lain | Seluruh token user tersebut dicabut |
| Akun dinonaktifkan (`is_active=false`) | Seluruh token dicabut; token yang beredar ditolak `403 USER_INACTIVE` |
| Akun di-soft-delete | Seluruh token dicabut; token yang beredar ditolak `401` karena Sanctum tidak dapat me-resolve user terhapus |

Middleware `EnsureUserIsActive` dipasang setelah `auth:sanctum`, sehingga pemeriksaan status berjalan setelah token valid. Urutan ini penting: pengguna tanpa token tetap menerima `401`, bukan `403`.

### RBAC

- `PermissionRegistry` adalah sumber tunggal: modul, resource, aksi, pola per role. Seeder, test, dan (nanti) menu frontend membaca daftar yang sama.
- Role disusun dari pola wildcard (`purchase.*.*`, `*.view`) lalu diekspansi menjadi slug konkret. Menambah resource cukup mengubah satu array.
- Otorisasi di backend memakai Policy per model (`UserPolicy`, `AuditLogPolicy`) dan middleware `permission:` untuk endpoint yang tidak punya model.
- Admin tidak memakai `Gate::before` yang membuka semua aksi; ia memegang seluruh permission lewat seeder. Alasannya: `Gate::before` juga melewati aturan proteksi seperti "tidak boleh menghapus akun sendiri".

### Audit trail

- Trait `Auditable` memasang hook `created`, `updated`, `deleted` dan menulis lewat `AuditTrailService`.
- `old_values`, `new_values`, dan `diff` disimpan sebagai jsonb; atribut sensitif (`password`, `remember_token`) dikecualikan di model.
- `updated` hanya tercatat bila ada atribut yang benar-benar berubah, sehingga audit tidak dipenuhi baris kosong.
- Event domain (`approved`, `disapproved`, `closed`, `period_closed`) akan memanggil `AuditTrailService::record()` langsung dari Action, karena bukan siklus hidup Eloquent.
- Pelaku diambil dari `request()->user()` lebih dulu, lalu `Auth::id()`. `Auth::id()` saja tidak cukup karena guard default adalah `web`, sedangkan API memakai `sanctum`.
- `LogApiRequest` memakai `terminate()` agar penulisan log terjadi setelah respons dikirim dan tidak memperlambat pengguna. Yang disimpan hanya method, path (tanpa query string), status, durasi, IP, dan user agent. Body dan header tidak pernah disimpan, sehingga password dan bearer token tidak masuk database log.

### Mode demo

- `BlockDemoWrites` (`demo.readonly`) memblokir POST/PUT/PATCH/DELETE pada path `users`, `roles`, `settings`, dan `account-settings` ketika `DEMO_MODE=true`, dengan `403 DEMO_MODE_READONLY`.
- Middleware dipasang setelah `auth:sanctum` dan `active`, sehingga permintaan tanpa token tetap `401` dan urutan kesalahan tidak menyesatkan.
- Transaksi bisnis (PO, GR, invoice, dan seterusnya) sengaja tidak diblokir agar pengunjung demo dapat mencoba alur lengkap.
- `demo:reset` adalah perintah artisan, jadi tidak melewati middleware HTTP dan tetap bisa memulihkan data.
- `DEMO_MODE` wajib `true` pada deploy publik; lihat README.

### Penomoran dokumen

Lihat [ADR 0002](ADR/0002-document-numbering-locking.md). Ringkas: `insertOrIgnore` untuk membuat baris periode, `lockForUpdate` untuk menaikkan nomor, unique index sebagai jaring pengaman, dan retry transaksi saat deadlock.

## Modul Master (Fase 2)

```mermaid
flowchart LR
    Unit -->|base_unit| Item
    Unit -->|konversi| Conv["item_unit_conversions"]
    Item --> Conv
    Category["item_categories"] --> Item
    Item --> Price["selling_prices"]
    Customer --> Price
    Unit --> Price
```

Delapan resource CRUD memakai pola seragam: FormRequest, Policy per model, API Resource, soft delete, dan audit.

### Konversi satuan

- `UnitConversionService` adalah satu-satunya tempat mengubah qty satuan input menjadi `qty_base`, dan sebaliknya menghitung biaya per satuan base. Lihat [ADR 0003](ADR/0003-satuan-base-dan-konversi.md).
- Semua aritmetika memakai `App\Support\Math\Decimal` (bcmath, pembulatan HALF_UP). Tidak ada float.
- Konversi yang tidak terdefinisi menghasilkan `422 UNIT_NOT_CONVERTIBLE`, bukan diam-diam memakai faktor 1.

### Harga jual

- `SellingPriceResolver` memilih baris dengan urutan: harga khusus customer dulu (`customer_id IS NOT NULL`), lalu `valid_from` terbaru, lalu `id` terbesar.
- `valid_from`/`valid_to` null berarti tanpa batas. Tanggal lookup default hari ini, dan bisa dikirim eksplisit lewat `?date=`.
- Endpoint lookup mengembalikan `unit_price_base` hasil `UnitConversionService`, sehingga frontend tidak menghitung ulang.
- `SellingPricePeriodGuard` menolak rentang tumpang tindih untuk kombinasi (item, satuan, customer) yang sama dengan `422 PRICE_PERIOD_OVERLAP`. Batas tanggal inklusif. Constraint `EXCLUDE USING gist` pada `daterange` menjadi jaring pengaman untuk request paralel.
- `unitPriceFromBase()` selalu membulatkan 2 desimal HALF_UP karena hasilnya harga uang, bukan qty.

### Proteksi penghapusan

- `MasterUsageGuard` membaca `config/master_usage.php`: daftar pasangan tabel dan kolom per model, plus `document_tables`.
- Tabel yang belum ada dilewati (`Schema::hasTable`), sehingga modul Purchasing dan Sales cukup menambah entri konfigurasi tanpa mengubah guard.
- Penghapusan yang masih dipakai menghasilkan `409 MASTER_IN_USE` beserta daftar tabel di `errors.references`.
- Perubahan `items.base_unit_id` atau `factor_to_base` pada item yang sudah dipakai tabel dokumen ditolak `409 ITEM_IN_USE`. Mekanisme aktif otomatis begitu tabel dokumen dibuat; test placeholder menandai tabel yang belum ada secara eksplisit.

### Audit dan morph map

- Seluruh master memakai trait `Auditable`, sehingga create/update/delete tercatat lengkap dengan `diff`.
- `Relation::enforceMorphMap` mencantumkan setiap model master. Tanpa entri morph map, audit gagal saat model baru pertama kali disimpan; ini sudah teruji lewat seeder.
- `GET /{resource}/{id}/audit` tersedia untuk semua master lewat trait `InteractsWithAuditTrail`.

### Settings

- Nilai default didefinisikan di `SettingService::DEFAULTS`, bukan hanya di seeder, sehingga instalasi baru dan test berperilaku sama.
- Akses bertipe: `bool()`, `int()`, `decimal()`, `get()` dengan fallback.
- `account_settings` diisi `AccountingSeeder` dengan 15 key akun (persediaan per jenis, GR/IR, AP/AR, PPN, penjualan, HPP, kas/bank, dan variance).

## Modul Akuntansi (Fase 3)

```mermaid
flowchart LR
    Doc["Dokumen / jurnal manual"] --> JS["JournalService"]
    JS --> PG["PeriodGuard"]
    JS --> DS["DocumentSequenceService (JV)"]
    JS --> J["journals"]
    J --> JL["journal_lines"]
    JL --> A["accounts"]
    AS["account_settings"] --> AM["AccountMapper"]
    AM --> Doc
    JL --> GL["GeneralLedgerService"]
    JL --> TB["TrialBalanceService"]
```

### Skema dan integritas

- `accounts` bertingkat lewat `parent_id`/`level`; akun induk `is_postable=false` tidak boleh dipakai jurnal.
- `journal_lines` memiliki CHECK `debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0)`.
- Constraint trigger deferred memastikan jurnal posted/reversed seimbang di database. Draft manual boleh tidak seimbang agar pengguna dapat menyimpan pekerjaan, tetapi approve tetap ditolak `422 JOURNAL_NOT_BALANCED`.
- FK `item_categories.*_account_id` dan `account_settings.account_id` ditambahkan setelah tabel `accounts` ada, dengan `restrictOnDelete`.
- Semua model baru (`Account`, `AccountingPeriod`, `Journal`, `JournalLine`) terdaftar di morph map sejak awal.

### Alur jurnal

- `JournalService::post()` membuat jurnal posted, nomor `JV/YYYY/MM/0000`, baris, dan audit dalam satu `DB::transaction`.
- Jurnal manual dibuat draft tanpa nomor. Nomor diberikan saat approve agar mengikuti tanggal final.
- `reverse()` membuat jurnal baru dengan debit/credit ditukar, lalu menandai jurnal asal `reversed`. Kedua jurnal tetap ada.
- `PeriodGuard::assertOpen()` dipakai semua operasi posting. Periode closed menghasilkan `422 PERIOD_CLOSED`.
- Tutup periode memvalidasi periode sebelumnya, keseimbangan seluruh jurnal, dokumen draft, dan rekonsiliasi. Gagal mengembalikan daftar penyebab di `errors.reasons`.
- Reopen hanya untuk role admin dan tercatat sebagai `period_reopened`.

### Laporan

- General Ledger memakai CTE `opening`/`period` dan window function `SUM(...) OVER (PARTITION BY account_id ORDER BY journal_date, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)`.
- Trial Balance menghitung saldo awal, mutasi, dan saldo akhir; total debit/kredit harus sama.
- Semua parameter laporan memakai binding; tidak ada nilai tanggal atau id yang dirangkai ke SQL.
- Laba tahun berjalan dihitung dinamis di laporan, tanpa jurnal penutup tahunan. Lihat [ADR 0004](ADR/0004-laba-tahun-berjalan-dinamis.md).

### EXPLAIN ANALYZE General Ledger

Dataset uji: 5.000 jurnal posted, 10.000 baris, satu akun kas, rentang Desember 2026, PostgreSQL 16 lokal.

```
WindowAgg  (actual time=4.684..4.915 rows=338 loops=1)
  Buffers: shared hit=305
  ->  Sort  (Sort Key: jl.journal_date, jl.id)  (actual time=4.675..4.686 rows=338)
        ->  Nested Loop Left Join  (actual time=3.627..4.568 rows=338)
              ->  Index Scan using accounts_pkey on accounts a  (actual time=0.009..0.009 rows=1)
              ->  Bitmap Heap Scan on journal_lines jl  (actual time=0.027..0.075 rows=338)
                    Recheck Cond: ((account_id = '4') AND (journal_date >= '2026-12-01') AND (journal_date <= '2026-12-31'))
                    ->  Bitmap Index Scan on journal_lines_account_date_idx  (actual time=0.023..0.023 rows=338)
                          Index Cond: ((account_id = '4') AND (journal_date >= '2026-12-01') AND (journal_date <= '2026-12-31'))
Planning Time: 4.675 ms
Execution Time: 5.196 ms
```

Catatan: CTE `opening` memilih 46% baris (sebelum Desember), sehingga planner memakai seq scan + hash join; itu pilihan yang benar untuk selektivitas tersebut. Slice periode (338 baris) memakai `journal_lines_account_date_idx`. Untuk akun dengan riwayat panjang dan rentang sempit, index yang sama dipakai langsung.

## Keamanan

- Sanctum bearer token dengan masa berlaku dari `SANCTUM_EXPIRATION`.
- Rate limit `api` per user atau IP, rate limit `login` per email dan IP.
- CORS dibatasi ke origin yang didaftarkan di `CORS_ALLOWED_ORIGINS`.
- Respons error tidak pernah membocorkan stack trace. Detail internal hanya masuk log.
- `.env` tidak masuk repo, hanya `.env.example`.
- Dokumen OpenAPI terbuka secara default untuk demo (`API_DOCS_PUBLIC=true`); matikan bila instance memakai data nyata.

## Container

```mermaid
flowchart LR
    subgraph Compose["docker compose (minierp-dev)"]
        PG["postgres:16-alpine<br/>volume pgdata"]
        APP["app: php:8.3-fpm-alpine<br/>kode + vendor dari Dockerfile"]
        NG["nginx:1.27-alpine<br/>public/ + default.conf"]
        ND["node:22-alpine<br/>Vite dev server"]
    end
    NG -->|"fastcgi app:9000"| APP
    APP -->|"pgsql"| PG
    ND -.->|"proxy /api"| NG
```

### Isolasi VPS dan batas sumber daya

Proyek Compose memakai nama `minierp-dev`. Semua target Makefile menjalankan `docker compose -p minierp-dev`, sehingga container, network, dan volume proyek lain (Coolify, Minecraft, database aplikasi lain) tidak tersentuh. Semua port dipublikasikan ke loopback dan dapat diubah lewat `.env` root:

| Layanan | Port host default | Variabel |
| --- | --- | --- |
| API/nginx | `127.0.0.1:8100` | `APP_PORT` |
| PostgreSQL | `127.0.0.1:5433` | `POSTGRES_PORT` |
| Vite | `127.0.0.1:5173` | `FRONTEND_PORT` |

Batas memori dan CPU dipasang di Compose: postgres `768m`/`0.5` CPU, app `768m`/`1.0` CPU, nginx `128m`, node `768m`. Total batas sekitar 2,4 GB, di bawah pagu 3 GB. Layanan `node` berada di profil `frontend`; `make demo` dan `make smoke` hanya menyalakan postgres, app, dan nginx. Nyalakan Vite secara eksplisit dengan `make fe-up`.

Dua cara image dipakai:

| Konteks | Berkas Compose | Kode aplikasi |
| --- | --- | --- |
| Pengembangan lokal | `docker-compose.yml` + `docker-compose.override.yml` (otomatis) | Bind mount `./backend`, `vendor` dipasang entrypoint bila belum ada |
| CI dan demo | `docker-compose.yml` saja | Hasil `COPY` di Dockerfile, tanpa bind mount |

Dockerfile memakai tiga tahap: `base` (php:8.3-fpm-alpine dengan ekstensi `pdo_pgsql`, `bcmath`, `intl`, `zip`, `gd`, `opcache`), `vendor` (Composer memasang dependensi di atas `base` yang sama), dan runtime yang menyalin kode serta vendor. Karena `vendor` memakai `base`, Composer memverifikasi requirement terhadap platform yang identik dengan runtime, bukan image Composer yang PHP-nya berbeda. Entrypoint menyiapkan `.env`, menulis koneksi database dari environment Compose, menghasilkan `APP_KEY`, dan menyesuaikan kepemilikan `storage` serta `bootstrap/cache` untuk user `www-data`. Karena image sudah membawa vendor, container di CI siap tanpa `composer install` saat runtime.

Koneksi database ditulis eksplisit ke `.env` oleh entrypoint, bukan hanya mengandalkan variabel lingkungan container. Alasannya: perilaku `clear_env` pada php-fpm dan urutan prioritas dotenv berbeda antar setup, dan salah satu kombinasi bisa membuat worker menyambung ke `127.0.0.1` alih-alih service `postgres`.

`.dockerignore` mengecualikan `backend/vendor`, `**/.env`, dan `frontend`, sehingga konteks build kecil dan tidak pernah membawa rahasia dari mesin pengembang. `.gitattributes` memaksa LF pada `*.sh`, `Dockerfile`, dan `Makefile` agar checkout di Windows tidak merusak `#!/bin/sh`.

## Verifikasi Docker

Job `docker-smoke` di CI menjalankan urutan yang sama dengan `make smoke`:

1. `docker compose config --quiet`.
2. `docker compose build`.
3. `docker compose up -d --wait --wait-timeout 180 postgres app nginx` (tiga menit untuk ketiga layanan backend healthy).
4. `docker compose exec -T app php artisan migrate --force`.
5. `curl` ke `/api/v1/health`, wajib HTTP 200 dan `checks.database = ok` (divalidasi `jq`).
6. `docker compose logs postgres app nginx` bila gagal, lalu `docker compose down -v --remove-orphans`.

Karena job ini tidak memuat `docker-compose.override.yml`, image yang diuji adalah image yang benar-benar dipakai untuk demo.

Layanan `node` sengaja tidak dinyalakan. Endpoint health hanya bergantung pada postgres, php-fpm, dan nginx; frontend sudah diuji job `frontend` (ESLint, typecheck, build). Akibatnya job smoke tidak menarik image Node, tidak menjalankan `npm ci`, dan tidak bergantung pada registry npm.

Konsekuensi yang perlu diketahui: `make smoke` dan job CI tidak memvalidasi integrasi Vite di dalam Compose. Perubahan pada `frontend/docker-entrypoint.sh` atau konfigurasi proxy diuji terpisah lewat `make fe-up`/`make fe-build`.

## Kualitas

- Pint dengan `declare(strict_types=1)` dan urutan import.
- Larastan level 6 pada `app`, `routes`, dan `database`.
- Pest dengan `RefreshDatabase`, memakai database PostgreSQL terpisah.
- ESLint dan `tsc` strict untuk frontend.

## Batasan fase 0

- Belum ada endpoint domain, login, maupun seeder data.
- ERD dan ADR ditulis saat modul terkait mulai diimplementasikan, agar isinya sesuai kode yang ada.
- Stack Compose dijalankan di VPS bersama dengan batas sumber daya dan port loopback; kebenarannya juga dijaga oleh job `docker-smoke`.
