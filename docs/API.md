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
curl -X POST http://localhost:8000/api/v1/auth/login \
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
| `LAST_ADMIN` | 409 | Mencabut atau menonaktifkan admin aktif terakhir |
| `UNKNOWN_DOC_TYPE` | 422 | Prefix dokumen tidak dikenal |
| `SERVER_ERROR` | 500 | Kesalahan tak terduga |
