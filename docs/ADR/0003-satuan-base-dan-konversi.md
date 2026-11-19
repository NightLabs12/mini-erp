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

## Konsekuensi

**Positif**

- Laporan stok dan HPP tidak berubah ketika definisi konversi diperbarui, karena dokumen membawa `qty_base` sendiri.
- Satu titik perhitungan (`UnitConversionService`) memudahkan pengujian: 10 Dus = 1.440 Pcs, Rp1.440.000/Dus = Rp10.000/Pcs.
- Frontend dapat menampilkan konversi real-time dengan memakai endpoint dan aturan yang sama.

**Negatif**

- Data qty tersimpan dua kali (`qty` dan `qty_base`). Ruang tambahan kecil, dan konsistensinya dijaga oleh service, bukan oleh trigger.
- Mengubah satuan base item setelah ada transaksi akan membuat data lama tidak konsisten. Karena itu satuan base divalidasi saat item dibuat, dan penghapusan satuan yang dipakai item ditolak `MASTER_IN_USE`. Perubahan satuan base pada item yang sudah bertransaksi belum diizinkan dan akan ditangani bersama modul Inventory.

## Alternatif yang ditolak

- **Hanya menyimpan qty satuan input.** Laporan harus mengalikan ulang dengan konversi terkini, sehingga angka historis bisa berubah diam-diam.
- **Menyimpan semua stok dalam satuan terkecil tetapi mengabaikan konversi di dokumen.** Pengguna harus menghitung sendiri, dan kesalahan input tidak terdeteksi.
- **Memakai float.** Pembulatan biner membuat selisih sen pada total dokumen dan tidak dapat direkonsiliasi.
