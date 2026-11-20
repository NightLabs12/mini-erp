# ADR 0004: Laba tahun berjalan dihitung dinamis, tanpa jurnal penutup tahunan

- Status: diterima
- Tanggal: 2026-11-20
- Fase: 3 (Akuntansi)

## Konteks

Neraca harus selalu seimbang: Aset = Kewajiban + Ekuitas. Pada akhir tahun, laba tahun berjalan biasanya dipindahkan ke Laba Ditahan lewat jurnal penutup. Proses itu menambah satu langkah manual yang mudah terlupa, menimbulkan jurnal besar di periode Desember, dan membuat laporan bulan berjalan bergantung pada apakah penutupan sudah dijalankan.

Sistem ini menyimpan seluruh jurnal posted tanpa jurnal penutup tahunan. Pertanyaannya: dari mana angka Laba Tahun Berjalan pada Neraca diambil?

## Keputusan

1. **Tidak ada jurnal penutup tahunan.** Akun pendapatan, HPP, dan beban tidak di-nol-kan pada akhir tahun. Saldo akun-akun itu tetap terakumulasi per tahun buku.
2. **Laba tahun berjalan dihitung dinamis** saat laporan dibuat:
   `laba_tahun_berjalan = total_kredit_bersih(pendapatan) - total_debit_bersih(hpp) - total_debit_bersih(beban)`
   untuk seluruh jurnal posted dengan `journal_date` pada tahun berjalan.
3. **Laba Ditahan hanya berisi laba tahun-tahun sebelumnya.** Saldo akun `3-2000` tidak menerima jurnal penutup; ia diisi lewat jurnal manual (misalnya pembagian dividen atau koreksi saldo awal).
4. Akun `3-3000 Laba Tahun Berjalan` disediakan sebagai baris tampilan non-postable. Nilainya **tidak** disimpan di `journal_lines`; laporan menghitungnya dari akun pendapatan/HPP/beban. Karena non-postable, tidak ada pengguna yang bisa memposting langsung ke akun ini.
5. Perhitungan memakai `Decimal` dan parameter binding yang sama dengan laporan lain. Tidak ada float.

## Konsekuensi

**Positif**

- Neraca selalu seimbang tanpa langkah tutup buku manual.
- Laporan bulan berjalan langsung benar; tidak ada periode "menunggu jurnal penutup".
- Tidak ada jurnal besar buatan sistem yang mengaburkan audit trail.
- Rekonsiliasi dan Trial Balance tetap berbasis jurnal posted yang seimbang.

**Negatif**

- Laporan Neraca dan Laba Rugi harus menghitung ulang agregat akun laba setiap kali dibuka. Dengan indeks `(account_id, journal_date, id)` dan volume data ERP kecil, biaya ini dapat diterima.
- Akun pendapatan/beban tidak pernah bersaldo nol di akhir tahun. Pengguna yang terbiasa dengan neraca saldo setelah tutup buku harus memahami bahwa saldo tersebut adalah saldo sejak awal tahun.
- Bila kelak dibutuhkan laporan multi-tahun, query harus membatasi tahun dengan parameter, bukan mengandalkan saldo akun yang sudah ditutup.

## Alternatif yang ditolak

- **Jurnal penutup otomatis setiap 31 Desember.** Menambah kompleksitas periode, membutuhkan jurnal balik, dan membuat koreksi tahun lalu sulit dilakukan setelah periode ditutup.
- **Menyimpan laba tahun berjalan di kolom khusus.** Nilai menjadi duplikat dari jurnal dan dapat berbeda bila ada koreksi; sumber kebenaran ganda.
- **Menutup akun laba ke Laba Ditahan secara manual tanpa panduan.** Mudah terlupa dan menghasilkan Neraca tidak seimbang.
