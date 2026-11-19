# ADR 0002: Penomoran dokumen dengan insertOrIgnore, lockForUpdate, dan unique index

- Status: diterima
- Tanggal: 2026-11-19
- Fase: 1 (Core)

## Konteks

Setiap dokumen memakai nomor `{PREFIX}/{YYYY}/{MM}/{0000}` yang harus unik dan berurutan tanpa celah dalam satu periode. Nomor dibuat saat dokumen pertama kali disimpan. Dua request yang datang bersamaan tidak boleh memperoleh nomor yang sama, dan kegagalan salah satu request tidak boleh membuat nomor terpakai ganda.

Tabel `document_sequences` menyimpan `doc_type`, `year`, `month`, `last_number` dengan unique `(doc_type, year, month)`.

## Keputusan

Alur `DocumentSequenceService::next()`:

1. `insertOrIgnore` baris periode bila belum ada. Ini menghindari error unique saat dua proses membuat baris yang sama pada saat bersamaan.
2. `SELECT ... FOR UPDATE` pada baris tersebut, sehingga hanya satu transaksi yang boleh menaikkan `last_number`.
3. `UPDATE last_number = last_number + 1` lalu commit.
4. `DB::transaction($callback, 5)` mengulang transaksi bila PostgreSQL melaporkan deadlock atau serialization failure.

Unique index menjadi jaring pengaman terakhir: bila ada bug yang membuat dua transaksi lolos, database menolak nomor ganda.

## Konsekuensi

**Positif**

- Terbukti pada test konkurensi: 50 proses PHP terpisah meminta nomor bersamaan, hasilnya 50 nomor unik dan berurutan tanpa celah.
- Tidak ada tabel kunci terpisah atau job pembersih.
- Aman untuk retry: transaksi yang gagal di tengah jalan tidak menaikkan nomor.

**Negatif**

- Terjadi serialisasi singkat per periode dokumen; throughput penomoran dibatasi satu transaksi pada satu waktu per `doc_type/year/month`. Untuk ERP dengan volume puluhan dokumen per menit ini tidak menjadi masalah.
- Bila `next()` dipanggil di luar transaksi penyimpanan dokumen, kegagalan setelahnya meninggalkan celah nomor. Karena itu fase dokumen memanggil `next()` di dalam transaksi yang sama dengan penyimpanan dokumen; `DB::transaction` bersarang memakai savepoint, sehingga rollback ikut mengembalikan `last_number`.

## Catatan implementasi

- Prefix divalidasi terhadap daftar tetap (`PR, PO, GR, PI, PAYOUT, SO, DO, SI, PAYIN, TRF, OPN, ADJ, JV, PRD`), sehingga `doc_type` tidak bisa diisi string sembarangan.
- Nomor tidak di-reset otomatis; periode baru menghasilkan baris baru, dan nomor lama tetap dapat ditelusuri.
