# ADR 0003: Satuan base, konversi bertingkat, dan harga per satuan base

- Status: diterima
- Tanggal: 2026-11-19
- Fase: 2 (Master data)

## Konteks

Satu item dijual dan dibeli dalam beberapa satuan: Kaos Polos dalam Pcs, Pack (12 Pcs), dan Dus (144 Pcs). Stok, HPP, dan nilai persediaan harus berada pada satu ukuran yang konsisten, sementara pengguna mengetik qty dan harga pada satuan yang mereka pilih.

Menyimpan qty pada satuan input di dokumen tanpa menyimpan nilai base akan membuat perhitungan stok bergantung pada definisi konversi saat laporan dijalankan. Bila definisi berubah, laporan lama ikut berubah.

## Keputusan

1. Setiap item punya tepat satu satuan base (`items.base_unit_id`), dan konversi lain disimpan di `item_unit_conversions` dengan `factor_to_base`.
2. Dokumen menyimpan **dua** nilai: `qty` pada satuan input dan `qty_base` hasil konversi. Ledger stok dan saldo hanya memakai `qty_base`.
3. `unit_cost` di ledger selalu per satuan base. Harga yang diketik pengguna (per satuan input) dibagi faktor konversi, dibulatkan 4 desimal HALF_UP.
4. Seluruh perhitungan lewat `UnitConversionService` dan `App\Support\Math\Decimal` (bcmath). Float tidak dipakai.
5. Satuan base tidak ditulis ulang di daftar konversi; faktornya selalu 1.
6. Harga jual disimpan per satuan (`selling_prices.unit_id`) dan bisa khusus customer. Lookup mengembalikan `price` (satuan input) sekaligus `unit_price_base` (hasil konversi), sehingga pemanggil tidak perlu menghitung sendiri.
7. Setiap satuan punya `decimal_places` (0-4). Satuan bulat seperti PCS/PACK/DUS/ROLL memakai 0; METER dan KG memakai 3. `toBase()` menolak qty yang melebihi presisi satuan base dan menolak `qty_base` yang tidak dapat dinyatakan pada presisi satuan base (`QTY_PRECISION_INVALID`). Satuan turunan boleh diketik pecahan selama hasilnya pas, misalnya 0,5 DUS x 144 = 72 PCS; 0,5 DUS x 145 = 72,5 PCS ditolak.
8. Konversi balik `unitPriceFromBase()` menghasilkan **harga uang**, sehingga selalu dibulatkan 2 desimal HALF_UP. Contoh: biaya base Rp10.000,005 pada PCS menjadi Rp10.000,01. Pembulatan 4 desimal hanya berlaku untuk qty dan biaya per satuan base, bukan untuk harga yang dikembalikan ke pengguna.

## Konsekuensi

**Positif**

- Laporan stok dan HPP tidak berubah ketika definisi konversi diperbarui, karena dokumen membawa `qty_base` sendiri.
- Satu titik perhitungan (`UnitConversionService`) memudahkan pengujian: 10 Dus = 1.440 Pcs, Rp1.440.000/Dus = Rp10.000/Pcs.
- Frontend dapat menampilkan konversi real-time dengan memakai endpoint dan aturan yang sama.

**Negatif**

- Data qty tersimpan dua kali (`qty` dan `qty_base`). Ruang tambahan kecil, dan konsistensinya dijaga oleh service, bukan oleh trigger.
- Mengubah satuan base atau faktor konversi item setelah ada transaksi akan membuat data lama tidak konsisten. Karena itu `MasterUsageGuard` menolak perubahan tersebut dengan `409 ITEM_IN_USE` begitu item muncul di tabel dokumen. Mekanisme ini membaca `config/master_usage.php`, sehingga aktif otomatis saat tabel dokumen fase berikutnya dibuat tanpa perubahan kode.
- Presisi input satuan turunan tidak dibatasi `decimal_places`-nya sendiri; yang dijaga adalah hasil konversi pada satuan base. Ini disengaja agar pengguna dapat mengetik 0,5 DUS, tetapi tidak dapat menyimpan stok pecahan pada satuan bulat.

## Alternatif yang ditolak

- **Hanya menyimpan qty satuan input.** Laporan harus mengalikan ulang dengan konversi terkini, sehingga angka historis bisa berubah diam-diam.
- **Menyimpan semua stok dalam satuan terkecil tetapi mengabaikan konversi di dokumen.** Pengguna harus menghitung sendiri, dan kesalahan input tidak terdeteksi.
- **Memakai float.** Pembulatan biner membuat selisih sen pada total dokumen dan tidak dapat direkonsiliasi.
