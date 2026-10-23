# Rancangan Pemicu Worker Antrean AI dari Browser

## Tujuan

Mulai memproses antrean segera setelah pengguna membuka halaman tunggu, tanpa menunggu jadwal cron per menit. Cron tetap tersedia sebagai cadangan untuk job yang tidak memiliki halaman browser aktif.

## Perilaku yang diinginkan

- Halaman tunggu JavaScript memanggil endpoint PHP terautentikasi untuk meminta worker mengambil satu job segera.
- Endpoint menggunakan layanan claim, lease, batas konkurensi, handler, dan transisi status yang sudah dipakai worker CLI.
- JavaScript tetap memantau status melalui endpoint status yang ada, dan meminta worker lagi setelah job yang sedang diproses selesai selama antrean masih ada.
- Panggilan pemicu harus aman saat beberapa tab/pengguna memanggilnya bersamaan; batas konkurensi dan FIFO tetap dijaga oleh database.
- Cron per menit tetap menjadi fallback untuk job yang masuk dari proses terjadwal atau saat tidak ada halaman tunggu aktif.

## Bukan tujuan

- Mengirim kredensial/provider API ke browser.
- Mengubah urutan FIFO, kebijakan retry, atau batas konkurensi.
- Menjalankan job melalui endpoint status atau menerima pemanggilan tanpa autentikasi dan perlindungan CSRF.
- Menjanjikan durasi jawaban AI lebih singkat; perubahan ini hanya menghilangkan waktu tunggu menuju pemanggilan worker.

## Pendekatan

1. **Endpoint pemicu worker dari browser (dipilih):** halaman tunggu meminta endpoint PHP untuk menjalankan satu job. Ini memulai job segera dan mempertahankan aturan antrean saat ini. Permintaan web dapat terbuka selama panggilan provider dan tunduk pada batas waktu PHP/FPM/proxy hosting.
2. **Polling status lebih rapat:** sederhana, tetapi tidak memulai pekerjaan; tetap bergantung pada cron per menit dan tidak menyelesaikan jeda awal.

## Rincian implementasi

- Tambahkan endpoint AJAX khusus yang memverifikasi sesi pengguna, CSRF, metode POST, dan memastikan worker berjalan hanya dalam konteks server.
- Ekstrak pemrosesan satu job dari loop CLI menjadi helper bersama. Worker CLI memakainya seperti biasa; endpoint browser memakai helper yang sama dengan batas satu job per request.
- Gunakan transaksi claim dan lease yang sudah ada untuk mencegah job ganda dan menghormati kapasitas global. Jika kapasitas penuh atau antrean kosong, endpoint segera mengembalikan status `idle`.
- Halaman tunggu memicu endpoint saat dibuka dan kembali memicunya ketika status job berubah atau antrean masih menunggu. Batasi satu pemicu aktif per tab dan gunakan jeda bertahap saat terjadi error.
- Endpoint pemicu tidak mengembalikan prompt, hasil provider, maupun kredensial. Hasil hanya dibaca melalui endpoint status milik peminta.
- Pertahankan cron per menit sebagai fallback. Dokumentasi deployment menjelaskan perilaku baru dan batas waktu web hosting.

## Pemeriksaan

- Uji helper satu-job dengan SQLite dan handler palsu tanpa provider eksternal.
- Uji endpoint: autentikasi, CSRF, job kosong, claim tunggal, dan status aktif.
- Jalankan pemeriksaan sintaks PHP/JavaScript serta suite antrean yang relevan.
- Uji pada hosting tetap diperlukan untuk mengetahui batas waktu PHP/FPM/proxy yang berlaku di akun cPanel.
