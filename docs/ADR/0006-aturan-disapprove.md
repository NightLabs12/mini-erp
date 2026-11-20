# ADR 0006: Aturan disapprove dokumen persediaan

- Status: diterima
- Tanggal: 2026-11-20
- Fase: 4 (Inventory)

## Konteks

Dokumen persediaan mengikuti state machine `draft -> approved -> closed`, dengan `cancelled` hanya dari `draft`. Disapprove (`approved -> draft`) diperlukan untuk mengoreksi dokumen yang sudah berdampak ke stok dan/atau jurnal.

Disapprove tidak boleh menghapus sejarah atau membuat saldo menjadi tidak konsisten. Karena itu, aturan SPEC 3.3(c) harus diterjemahkan menjadi pemeriksaan konkret yang dapat diuji.

## Keputusan

1. **Hanya dari approved.** Disapprove pada draft, cancelled, atau closed ditolak `409 DOCUMENT_NOT_APPROVED`/`DOCUMENT_STATE_INVALID`.
2. **Periode dokumen harus open.** `PeriodGuard::assertOpen()` dipanggil dengan lock sebelum mutasi. Periode closed menghasilkan `422 PERIOD_CLOSED`.
3. **Tidak ada dokumen turunan aktif.** Pada Fase 4, transfer gudang dan stock opname belum memiliki dokumen turunan. Aturan ini ditegakkan oleh action dokumen pemilik turunan pada fase berikutnya (misalnya GR yang sudah ditagih tidak boleh disapprove). `StockService` hanya menegakkan aturan sisi stok; pemanggil bertanggung jawab memeriksa turunan sebelum memanggil reverse.
4. **Tidak ada mutasi stok lanjutan.** Untuk setiap pasangan item dan gudang, `StockService::reverse()` mencari baris ledger aktif dengan `id` lebih besar dari baris aktif terakhir dokumen. Jika ada, disapprove ditolak `409 STOCK_REVERSAL_BLOCKED` dan menyebut baris ledger yang menghalangi.
5. **Pembalikan tidak boleh membuat stok negatif.** Jika stok saat ini lebih kecil dari qty masuk yang akan dibalik dan `allow_negative_stock=false`, reverse ditolak `422 STOCK_INSUFFICIENT`.
6. **Pembalikan stok dan jurnal dalam satu transaksi.**
   - Transfer gudang tidak membentuk jurnal; disapprove hanya membalik stok keluar/masuk.
   - Stock opname membalik stok lewat `StockService` lalu membalik jurnal penyesuaian lewat `JournalService::reverse()`. Jurnal asal tetap ada dengan status `reversed`, dan jurnal pembalik dibuat baru.
   - Jika salah satu langkah gagal, seluruh transaksi rollback.
7. **Idempoten dan aman untuk generasi berulang.** Approve yang dipanggil dua kali tidak menggandakan stok/jurnal. Setelah disapprove, dokumen kembali draft dan dapat diedit lalu di-approve ulang. Reverse hanya membalik baris aktif generasi terbaru, sehingga siklus approve/disapprove berulang tidak menggandakan mutasi.
8. **Lock order seragam.** Header dokumen -> periode -> saldo item/gudang -> sequence jurnal. Urutan ini mencegah deadlock antar dokumen dan antar jurnal.
9. **Audit.** Event `approved` dan `disapproved` dicatat lewat `AuditTrailService`, di samping event `updated` dari trait `Auditable`.

## Konsekuensi

**Positif**

- Disapprove tidak pernah menghapus bukti mutasi; semua koreksi terlihat di ledger dan audit trail.
- Saldo dan jurnal tetap konsisten karena pembalikan memakai nilai asli dan berjalan dalam satu transaksi.
- Aturan dapat diuji: periode closed, mutasi lanjutan, stok tidak cukup, dan disapprove ganda memiliki kode error yang jelas.

**Negatif**

- Disapprove sering ditolak pada data yang sudah memiliki transaksi lanjutan. Pengguna harus membalik dokumen lanjutan lebih dulu, atau membuat dokumen koreksi baru.
- Pemeriksaan dokumen turunan tidak dapat digeneralisasi di `StockService`; setiap modul pemilik turunan harus menambahkan pemeriksaan eksplisit saat modul itu dibuat.
- Siklus approve/disapprove berulang menambah baris ledger. Ini adalah biaya yang diterima demi jejak audit.

## Alternatif yang ditolak

- **Hard delete dokumen approved.** Menghilangkan jejak stok dan jurnal, serta melanggar audit trail.
- **Mengizinkan disapprove meski ada mutasi lanjutan.** Saldo dokumen berikutnya menjadi tidak dapat direproduksi dan rekonsiliasi historis rusak.
- **Membalik jurnal tanpa membalik stok.** Stok dan GL menjadi tidak sinkron.
- **Membalik stok tanpa jurnal.** Persediaan di GL dan `stock_balances` akan berbeda.
