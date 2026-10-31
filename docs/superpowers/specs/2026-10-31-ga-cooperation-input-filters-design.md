# Rancangan Filter Input Kerja Sama GA

## Tujuan
Manager divisi General Affair dapat memfilter daftar input kerja sama berdasarkan rentang tanggal dan instansi, lalu mengekspor hasil dengan filter yang sama.

## Cakupan
- Tambahkan pilihan instansi pada form filter utama di `general_affair_kerjasama_input.php`.
- Letakkan tombol Export Excel di samping Terapkan dan hapus pemilihan instansi melalui modal ekspor.
- Terapkan filter instansi di query daftar dan endpoint ekspor, dengan parameter tanggal dan instansi yang sama.
- Tampilkan tombol ekspor hanya untuk manager ke atas di divisi General Affair dan tegakkan pemeriksaan yang sama pada endpoint ekspor.
- Biarkan daftar input tetap mengikuti batas unit pengguna yang sudah ada.

## Pendekatan
Gunakan filter server-side yang sama di halaman dan endpoint ekspor. Ini menjaga isi Excel konsisten dengan daftar dan pola data yang sudah digunakan. Filter client-side tidak dipilih karena ekspor server-side dapat berbeda dari tampilan tabel.

## Ketentuan
- Pilihan instansi default adalah semua instansi; pilihan instansi tertentu menyaring daftar dan ekspor.
- Filter tanggal menggunakan parameter `range`, `from`, dan `to` yang sudah tersedia.
- Hak akses memakai helper proyek `ems_require_general_affair_manager_access()`.
- Tidak ada perubahan pada alur input atau ekspor kolom Excel.

## Verifikasi
Periksa sintaks PHP dan alur parameter secara statis; tidak menambah atau menjalankan test otomatis.
