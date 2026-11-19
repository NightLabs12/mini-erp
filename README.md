# Mini ERP: PT Contoh Niaga Makmur

Mini ERP untuk perusahaan dagang dan manufaktur kaos fiktif. Dibangun sebagai portofolio end-to-end: pembelian, penjualan, persediaan dengan HPP moving average, dan akuntansi double-entry, dengan data demo yang konsisten dan lolos rekonsiliasi.

> Terinspirasi pengalaman kerja pada sistem ERP nyata. Seluruh kode, nama tabel, dan data di repo ini adalah desain orisinal, bukan salinan sistem perusahaan mana pun.

## Status

**Fase 1 selesai: Core (auth, RBAC, audit trail, penomoran dokumen, settings).**

| Area | Kondisi |
| --- | --- |
| Monorepo | `backend/` Laravel 11 API-only, `frontend/` React 18 + Vite + TypeScript |
| Infra | `docker-compose.yml` (php-fpm, nginx, postgres 16, node), Makefile, GitHub Actions |
| Auth | Sanctum bearer token, login/logout/me, token expiry, rate limit login per email + IP |
| RBAC | 6 role, 172 permission `{modul}.{resource}.{aksi}`, policy + middleware, CRUD user dan assign role |
| Audit trail | Trait `Auditable` (old/new/diff jsonb), `GET /audit-logs`, `GET /users/{id}/audit`, `api_request_logs` untuk request mutasi |
| Penomoran | `DocumentSequenceService` dengan `lockForUpdate` dan unique index, teruji 50 proses paralel |
| Settings | `settings` bertipe dengan nilai default aman, `account_settings` disiapkan untuk Fase 2 |
| Fondasi API | Format error JSON global, CORS terbatas, pagination, filter, sort standar |
| Kualitas | Pint, Larastan level 6, Pest (56 test), ESLint, `tsc` strict |
| Docker | Diuji otomatis lewat job CI `docker-smoke`: build image, tunggu postgres + app + nginx healthy, migrate, cek `/api/v1/health` |

Modul Master, Purchasing, Sales, Inventory, Accounting, dan Manufacturing dikerjakan pada fase berikutnya. Rencana lengkap ada di [`docs/SPEC.md`](docs/SPEC.md); kontrak API di [`docs/API.md`](docs/API.md).

### Cakupan Core

- **Auth**: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` (role + seluruh permission efektif).
- **User management**: CRUD user, assign role, proteksi hapus diri sendiri dan admin terakhir.
- **Audit**: setiap create/update/delete model ber-trait `Auditable` tercatat dengan `old_values`, `new_values`, dan `diff`.
- **Penomoran dokumen**: `{PREFIX}/{YYYY}/{MM}/{0000}` per jenis, tahun, dan bulan.
- **Settings**: `allow_negative_stock`, `tax_rate` (11), `require_no_draft_on_close`, dan lainnya, lengkap dengan default di kode.

## Stack

| Lapisan | Teknologi |
| --- | --- |
| Backend | PHP 8.3, Laravel 11, PostgreSQL 16, Sanctum, spatie/laravel-permission, Scramble, maatwebsite/excel, dompdf, brick/math |
| Frontend | React 18, Vite, TypeScript, Tailwind CSS, TanStack Query, TanStack Table, react-hook-form, zod, React Router, Recharts |
| Infra | Docker Compose, Makefile, GitHub Actions |

## Struktur

```
mini-erp/
  backend/                 Laravel
    app/Domain/            Core, Master, Purchasing, Sales, Inventory, Accounting, Manufacturing
    app/Http/              Controller, FormRequest, Resource
    app/Support/Api/       ListQuery: search, filter, sort, pagination
    routes/api.php
    tests/
  frontend/                React + Vite
    src/app/               router, query client
    src/pages/
    src/lib/               api client, i18n, format
  docker/                  php-fpm, nginx, init database
  docs/                    SPEC.md, ARCHITECTURE.md, API.md, ADR/
  docker-compose.yml
  Makefile
```

## Menjalankan

Ada dua jalur yang setara. Pengembangan harian cukup memakai PHP dan Node di mesin sendiri; Docker dipakai untuk demo sekali jalan dan diverifikasi otomatis oleh CI.

### 1. Native, tanpa Docker

Jalur tercepat untuk mengembangkan dan menjalankan test: tidak perlu build image setiap kali kode berubah.

Prasyarat: PHP 8.3 dengan ekstensi `pdo_pgsql`, `bcmath`, `intl`, `zip`, `gd`, Composer, Node 22, dan PostgreSQL 16.

Siapkan database sekali:

```sql
CREATE ROLE mini_erp LOGIN PASSWORD 'mini_erp_secret';
CREATE DATABASE mini_erp OWNER mini_erp;
CREATE DATABASE mini_erp_test OWNER mini_erp;
```

Backend:

```bash
cd backend
composer install
cp .env.example .env        # Windows: Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve           # http://localhost:8000
```

Frontend, di terminal lain:

```bash
cd frontend
npm install
npm run dev                 # http://localhost:5173
```

Frontend memakai proxy Vite: browser memanggil `/api/v1` dan Vite meneruskan ke `http://localhost:8000`. Ubah target dengan `VITE_API_PROXY_TARGET`.

Test dan lint juga berjalan native:

```bash
cd backend && ./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
cd frontend && npm run lint && npm run typecheck && npm run build
```

### 2. Docker, satu perintah

Prasyarat: Docker Desktop dengan Compose v2, serta `make` (Linux, macOS, WSL).

```bash
make demo
```

Perintah ini membangun image, menyalakan empat layanan, menunggu semuanya healthy, menjalankan migrasi, lalu mengisi data demo.

| Layanan | URL |
| --- | --- |
| API | http://localhost:8000/api/v1/health |
| OpenAPI | http://localhost:8000/docs/api |
| Frontend | http://localhost:5173 |
| PostgreSQL | `localhost:5433`, database `mini_erp` |

Port dapat diubah lewat variabel lingkungan, misalnya `APP_PORT=8080 make up`. Kredensial database default: `mini_erp` / `mini_erp_secret`.

Image `app` sudah berisi kode dan `vendor` hasil build. Untuk pengembangan lokal, `docker-compose.override.yml` menambahkan bind mount `./backend` sehingga perubahan kode langsung terpakai tanpa rebuild. Compose memuat berkas itu otomatis selama tidak ada `-f` yang diberikan.

Saat container `app` mulai, entrypoint menyalin `backend/.env.example` menjadi `.env` bila belum ada, menulis ulang koneksi database dari nilai Compose (`DB_HOST=postgres`, dan seterusnya), membuat `APP_KEY` bila kosong, lalu menjalankan `php-fpm`. Jadi tidak perlu menyiapkan `.env` manual untuk Docker.

Perintah lain:

| Perintah | Fungsi |
| --- | --- |
| `make up` | Nyalakan layanan tanpa migrasi |
| `make down` | Hentikan container |
| `make migrate` | Jalankan migrasi |
| `make seed` | Isi data demo |
| `make demo-reset` | Bangun ulang dataset demo dari nol |
| `make test` | Test backend (Pest) di container |
| `make lint` | Pint, Larastan, ESLint, typecheck |
| `make smoke` | Tiru job CI `docker-smoke`: postgres + app + nginx tanpa bind mount, lalu bersihkan |
| `make logs` | Ikuti log semua layanan |
| `make sh` | Shell container app |
| `make psql` | Masuk ke database |

### Verifikasi Docker di CI

Docker tidak dijalankan di mesin pengembangan, jadi kebenaran stack container dijaga oleh job `docker-smoke` di `.github/workflows/ci.yml`. Setiap push dan pull request menjalankan:

1. `docker compose config --quiet` untuk memvalidasi berkas Compose.
2. `docker compose build` untuk membangun image dari `docker/php/Dockerfile`.
3. `docker compose up -d --wait --wait-timeout 180 postgres app nginx` untuk menyalakan ketiga layanan backend dan menunggu semuanya healthy dalam tiga menit.
4. `docker compose exec -T app php artisan migrate --force`.
5. `curl` ke `http://127.0.0.1:8000/api/v1/health`, memastikan HTTP 200 dan `checks.database` bernilai `ok`.
6. `docker compose logs` untuk postgres, app, dan nginx bila ada langkah yang gagal, lalu `docker compose down -v` di akhir.

Job ini sengaja hanya memakai `docker-compose.yml` (tanpa override), sehingga yang diuji adalah image hasil Dockerfile, bukan kode dari bind mount.

Container `node` tidak ikut dinyalakan. Health check API hanya butuh postgres, php-fpm, dan nginx, sementara frontend sudah diverifikasi job `frontend` lewat ESLint, typecheck, dan build produksi. Memisahkan keduanya membuat job ini lebih cepat dan tidak bergantung pada registry npm.

Untuk mereproduksinya di mesin sendiri:

```bash
make smoke
```

## Akun demo

Dibuat oleh `DemoUserSeeder` saat `make demo` atau `php artisan db:seed`.

| Email | Role |
| --- | --- |
| `admin@demo.test` | admin |
| `purchasing@demo.test` | purchasing |
| `sales@demo.test` | sales |
| `warehouse@demo.test` | warehouse |
| `finance@demo.test` | finance |
| `viewer@demo.test` | viewer |

Password diambil dari `DEMO_PASSWORD` (default `password`).

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@demo.test","password":"password"}'
```

Token dari respons dipakai sebagai `Authorization: Bearer <token>`.

## Konfigurasi

Semua variabel ada di `backend/.env.example`.

| Variabel | Fungsi |
| --- | --- |
| `DB_*` | Koneksi PostgreSQL. Compose menimpa host menjadi `postgres`. |
| `SANCTUM_EXPIRATION` | Masa berlaku token dalam menit (default 1440). |
| `CORS_ALLOWED_ORIGINS` | Origin frontend yang diizinkan, dipisah koma. |
| `API_RATE_LIMIT` | Batas request API per menit per user atau IP. |
| `LOGIN_RATE_LIMIT` | Batas percobaan login per menit per email dan IP. |
| `API_DOCS_PUBLIC` | Izinkan `/docs/api` diakses tanpa login (default `true` untuk demo). |
| `DEMO_MODE` | Menonaktifkan perubahan data kritis dan menjadwalkan reset harian. |

## Kontrak API

Sukses tunggal:

```json
{ "data": { "id": 1, "name": "Kaos Polos" } }
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
{ "message": "Stok Kaos Polos di Gudang Utama tidak cukup.", "code": "STOCK_INSUFFICIENT", "errors": {} }
```

List endpoint menerima `?search=`, `?filter[kolom]=nilai`, `?filter[kolom][gte]=nilai`, `?sort=kolom` atau `?sort=-kolom`, `?page=`, dan `?per_page=` (maksimum 100). Hanya kolom yang diizinkan per resource yang dipakai.

Daftar endpoint, contoh `curl`, dan kode error ada di [`docs/API.md`](docs/API.md). Dokumentasi interaktif di `http://localhost:8000/docs/api`.

### Permission

Slug mengikuti `{modul}.{resource}.{aksi}`. Daftar lengkapnya dihasilkan dari `App\Domain\Core\Support\PermissionRegistry` dan di-seed oleh `RolePermissionSeeder` (172 permission).

| Role | Cakupan |
| --- | --- |
| `admin` | Semua permission |
| `purchasing` | Purchase penuh, master read, stok read, laporan pembelian |
| `sales` | Sales penuh, master read, stok read, laporan penjualan |
| `warehouse` | Inventory penuh, master read, GR view/approve/print, laporan stok |
| `finance` | Accounting penuh, invoice dan payment approve, seluruh laporan |
| `viewer` | Hanya aksi `view` di semua modul |

Backend menegakkan lewat Policy (`UserPolicy`, `AuditLogPolicy`) dan middleware `permission:`. Frontend nanti hanya menyembunyikan tombol berdasarkan permission yang sama.

## Test dan kualitas

```bash
make test     # Pest di container, memakai database mini_erp_test
make lint     # Pint --test, Larastan level 6, ESLint, tsc
```

Di Docker, `mini_erp_test` dibuat otomatis oleh `docker/postgres/init`. Untuk pengembangan native, buat sekali dengan `createdb mini_erp_test` atau lewat SQL pada bagian [Menjalankan](#menjalankan).

CI menjalankan Pint, Larastan, Pest (service PostgreSQL 16), ESLint, typecheck, build frontend, dan job `docker-smoke` untuk stack Compose.

## Keputusan awal

- **React, bukan Blade.** Grid editable multi-baris dengan navigasi keyboard lebih terkendali di React, dan kontrak JSON memudahkan pengujian API.
- **Bearer token Sanctum, bukan cookie SPA.** Tidak butuh CSRF dan konfigurasi domain lintas origin untuk demo publik.
- **PostgreSQL di semua lingkungan, termasuk test.** Trigger append-only, constraint CHECK, dan `FOR UPDATE` tidak bisa diuji di SQLite.
- **`ListQuery` terpusat.** Satu perilaku list endpoint untuk seluruh modul, tanpa merakit SQL dari input pengguna.
- **bcmath dan brick/math.** Keduanya tersedia, dengan helper uang dibangun di fase Accounting.
- **Platform Composer dipin ke PHP 8.3.** `config.platform.php = 8.3.0` di `backend/composer.json` membuat `composer.lock` selalu berisi paket yang kompatibel dengan PHP 8.3, meskipun lock dibuat di mesin dengan PHP 8.4. Tanpa pin ini, Symfony 8.x ikut terpasang dan CI (PHP 8.3) gagal saat `composer install`.
- **Image mandiri, bind mount hanya untuk lokal.** `docker/php/Dockerfile` membangun image berisi kode dan `vendor`; `docker-compose.override.yml` menambahkan bind mount untuk hot reload. CI menguji image tanpa override, sehingga Dockerfile benar-benar terbukti bisa jalan sendiri.
- **Pengembangan native sebagai jalur utama.** Siklus ubah-jalan lebih cepat tanpa rebuild image; Docker dipakai untuk demo dan diverifikasi CI.

Catatan keamanan: proyek dipatok ke Laravel 11 sesuai spesifikasi. `composer audit` mencatat advisory `laravel/framework` 11.x yang perbaikannya hanya ada di rilis 12.60+ dan 13.x. Upgrade framework masuk roadmap dan wajib sebelum dipakai produksi.

## Roadmap

Di luar cakupan saat ini:

- Multi-company dan cabang penuh
- Multi-currency dan landed cost
- Batch dan serial number
- MRP penuh, payroll
- Pajak e-Faktur dan integrasi bank
- Approval berjenjang lebih dari satu level
- Upgrade Laravel ke rilis yang masih menerima patch keamanan

## Lisensi

MIT.
