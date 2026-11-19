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

## Keamanan

- Sanctum bearer token dengan masa berlaku dari `SANCTUM_EXPIRATION`.
- Rate limit `api` per user atau IP, rate limit `login` per email dan IP.
- CORS dibatasi ke origin yang didaftarkan di `CORS_ALLOWED_ORIGINS`.
- Respons error tidak pernah membocorkan stack trace. Detail internal hanya masuk log.
- `.env` tidak masuk repo, hanya `.env.example`.

## Container

```mermaid
flowchart LR
    subgraph Compose["docker compose (mini-erp)"]
        PG["postgres:16-alpine<br/>volume pgdata"]
        APP["app: php:8.3-fpm-alpine<br/>kode + vendor dari Dockerfile"]
        NG["nginx:1.27-alpine<br/>public/ + default.conf"]
        ND["node:22-alpine<br/>Vite dev server"]
    end
    NG -->|"fastcgi app:9000"| APP
    APP -->|"pgsql"| PG
    ND -.->|"proxy /api"| NG
```

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

Konsekuensi yang perlu diketahui: `make smoke` dan job CI tidak lagi memvalidasi integrasi Vite di dalam Compose. Perubahan pada `frontend/docker-entrypoint.sh` atau konfigurasi proxy hanya teruji saat `make demo` dijalankan manual.

## Kualitas

- Pint dengan `declare(strict_types=1)` dan urutan import.
- Larastan level 6 pada `app`, `routes`, dan `database`.
- Pest dengan `RefreshDatabase`, memakai database PostgreSQL terpisah.
- ESLint dan `tsc` strict untuk frontend.

## Batasan fase 0

- Belum ada endpoint domain, login, maupun seeder data.
- ERD dan ADR ditulis saat modul terkait mulai diimplementasikan, agar isinya sesuai kode yang ada.
- Docker tidak tersedia di mesin pengembangan ini, sehingga stack Compose tidak dijalankan manual di sana. Kebenarannya dijaga oleh job `docker-smoke`; berkas Compose dan workflow sudah divalidasi sintaksnya secara lokal.
