# ADR 0005: Ledger stok immutable dan pembalikan lewat baris reversal

- Status: diterima
- Tanggal: 2026-11-20
- Fase: 4 (Inventory)

## Konteks

`stock_ledger` adalah sumber kebenaran mutasi stok. Setiap perubahan harus dapat diaudit, dan saldo tidak boleh "hilang" karena edit atau hapus baris. Koreksi dokumen yang sudah approved tetap diperlukan, misalnya salah input qty atau gudang.

Ada dua pilihan umum:

1. **Mutable ledger.** Baris lama diubah atau dihapus saat dokumen dibatalkan.
2. **Append-only ledger.** Koreksi selalu berupa baris baru yang membalik baris lama.

Sistem juga memakai moving average, sehingga pembalikan harus mengembalikan qty dan nilai persediaan ke kondisi sebelum dokumen, tanpa mengubah average cost secara sembarang.

## Keputusan

1. **Ledger append-only.** `stock_ledger` tidak memiliki `updated_at`. Trigger PostgreSQL menolak `UPDATE` dan `DELETE` dengan error `STOCK_LEDGER_IMMUTABLE`.
2. **Pembalikan lewat baris baru.** Baris pembalik menyalin `doc_type`, `doc_id`, dan `doc_line_id`, menukar arah qty, memakai `unit_cost` dan `total_value` asli, serta mengisi `reversal_of_id`. Unique partial index `(reversal_of_id)` memastikan satu baris asal hanya dibalik sekali.
3. **Satu pintu mutasi.** `StockService` adalah satu-satunya penulis `stock_ledger` dan `stock_balances`. Dokumen tidak menulis ledger langsung.
4. **Lock deterministik.** Saldo dikunci `FOR UPDATE` dengan urutan `item_id ASC, warehouse_id ASC`. Dokumen multi-baris memanggil `lockBalances()` sebelum mutasi agar urutan lock sama di semua transaksi. Urutan lock transaksi adalah header dokumen, periode, saldo, lalu sequence jurnal.
5. **Aturan reverse.** `StockService::reverse()` hanya membalik baris aktif, yaitu baris yang belum memiliki reversal dan bukan baris reversal. Sebelum membuat reversal, service memeriksa:
   - periode dokumen masih open (`PeriodGuard` dengan lock);
   - tidak ada mutasi lain pada item dan gudang yang sama dengan `id` lebih besar dari baris aktif terakhir;
   - pembalikan tidak membuat stok negatif, kecuali `allow_negative_stock=true`.
6. **Generasi dokumen.** Approve -> disapprove -> approve -> disapprove didukung. Setelah disapprove, baris asli sudah memiliki reversal. Saat approve ulang, baris baru dibuat. Saat disapprove berikutnya, hanya baris generasi terbaru yang dibalik, sehingga mutasi tidak berlipat.
7. **Moving average berbasis nilai.** Nilai persediaan berjalan adalah jumlah nilai baris bertanda. Average cost disimpan 4 desimal. Pengeluaran memakai average saat itu; saat qty habis, nilai baris terakhir dikoreksi menjadi saldo nilai sebelumnya agar `balance_value` tetap konsisten dengan `SUM(total_value)` ledger. Pengeluaran parsial tidak boleh membuat nilai persediaan negatif.
8. **Stok negatif.** Default ditolak dengan kode `STOCK_INSUFFICIENT` yang menyebut item, gudang, stok tersedia, dan qty diminta. Setting `allow_negative_stock` dapat mengizinkan saldo negatif.

## Konsekuensi

**Positif**

- Riwayat mutasi lengkap; tidak ada data yang hilang karena koreksi.
- Saldo dapat direkonsiliasi dengan `SUM` ledger kapan saja.
- Pembalikan aman terhadap konkurensi: lock saldo dan unique index reversal mencegah double reversal.
- Moving average konsisten dengan nilai persediaan; selisih pembulatan tidak menumpuk.

**Negatif**

- Ledger tumbuh lebih cepat karena setiap koreksi menambah baris.
- Disapprove bisa ditolak jika sudah ada mutasi lanjutan. Ini disengaja agar saldo historis tidak berubah di belakang dokumen lain.
- Laporan harus memperhitungkan baris reversal; Kartu Stok dan Mutasi Stok menampilkannya sebagai mutasi biasa dengan `reversal_of_id`.

## Alternatif yang ditolak

- **UPDATE/DELETE baris ledger.** Menghilangkan jejak audit dan membuat rekonsiliasi historis tidak dapat dipertanggungjawabkan.
- **Menyimpan hanya saldo akhir.** Tidak ada bukti mutasi dan tidak dapat membalik dokumen tertentu.
- **Soft delete pada ledger.** Saldo masih harus dihitung ulang dan tetap membuka celah edit.
- **Membalik dengan mengubah average cost langsung.** Melanggar rumus moving average dan membuat nilai persediaan tidak konsisten dengan ledger.
