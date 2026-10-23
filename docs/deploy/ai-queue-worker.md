# Worker Antrean AI

Panggilan model AI berjalan melalui worker antrean. Layar tunggu meminta endpoint worker browser untuk mengambil satu job segera, lalu tetap memantau status. Cron PHP CLI sekali per menit tetap menjadi fallback saat tidak ada layar tunggu aktif atau job dijadwalkan oleh proses latar.

## Penerapan

1. Deploy kode aplikasi dan file `docs/sql/103_2026-10-23_ai_request_queue.sql`. Runner `bin/deploy_migrations.php` memproses migrasi bernomor 103 ke atas; deployment cPanel proyek menjalankan runner sebelum menukar kode. Periksa log deployment dan pastikan migrasi 103 berstatus diterapkan sebelum menyalakan cron worker.
2. Pastikan PHP CLI pada cPanel menggunakan versi PHP yang sama atau lebih baru dari aplikasi dan memiliki ekstensi PDO MySQL serta cURL. Dari Terminal cPanel, periksa `php -v`, `php -m`, dan `command -v php`. Bila cron memakai versi PHP berbeda, gunakan path binari CLI versi aplikasi, misalnya `/opt/cpanel/ea-php83/root/usr/bin/php`.
3. Tambahkan cron berikut. Sesuaikan path PHP jika hasil pemeriksaan cPanel menunjukkan lokasi lain.

```cron
* * * * * /usr/local/bin/php /home/fouf9972/public_html/rh_ems/actions/ai_queue_worker.php >> /home/fouf9972/ai-queue-worker.log 2>&1
```

File log berada di direktori akun, di luar `public_html`. Pastikan akun cPanel dapat menulisnya. Jangan menaruh API key atau isi `.env` pada baris cron.

## Pengaturan dan perilaku

- Migrasi menambahkan batas konkurensi bawaan **1** pekerjaan model dan batas antrean bawaan **50** pekerjaan menunggu.
- Programmer Roxwood dapat memilih konkurensi 1–3 dan antrean 10–200 di **Dashboard → Setting AI**. Untuk hosting dengan CPU/RAM terbatas, biarkan konkurensi di 1.
- Worker mengambil job FIFO dan berjalan maksimal sekitar 50 detik per eksekusi cron. Panggilan model yang sudah dimulai dibiarkan selesai; worker berikutnya menghormati job yang masih berjalan.
- Setiap layar tunggu mengirim POST terautentikasi dan ber-CSRF ke `/ajax/ai_queue_work.php`. Satu request web hanya mengklaim satu job; transaksi claim, lease, dan batas konkurensi database yang sama mencegah pemrosesan ganda. Bila kapasitas penuh atau antrean kosong, endpoint mengembalikan `idle` dan browser mencoba lagi dengan jeda bertahap.
- Pemrosesan dari browser memakai request PHP yang dapat menunggu hingga provider selesai. Batas `max_execution_time` PHP, timeout PHP-FPM, reverse proxy, dan hosting berlaku; endpoint membatasi satu job per request dan meminta batas PHP 120 detik. Bila hosting memutus request lebih cepat, andalkan cron CLI sebagai fallback dan periksa log serta batas waktu hosting.
- Job yang melewati timeout provider ditambah toleransi 30 detik ditandai gagal dan tidak diulang otomatis. Pesan gagal yang tampil kepada pengguna tidak memuat detail provider; rincian diagnostik dicatat ke log PHP.
- AI yang dinonaktifkan menghentikan job tertunda dengan pesan yang jelas. Job aktif/gagal/selesai tidak dihapus saat pembersihan; hasil terminal disimpan tujuh hari.
- Antrean penuh menolak permintaan baru dengan pesan agar dicoba kembali setelah ada slot.

## Pemeriksaan

1. Buka **Setting AI** dan pastikan panel antrean tampil setelah migrasi 103 diterapkan.
2. Kirim satu permintaan AI dari aplikasi. Layar tunggu harus menampilkan posisi/status dan pekerjaan aktif. Setelah selesai, job tercatat `completed`; bila gagal, layar menampilkan pesan aman.
3. Periksa `/home/fouf9972/ai-queue-worker.log` dan log error PHP cPanel bila pekerjaan tidak bergerak. Baris `AI queue: N job diproses.` menunjukkan worker hidup. Jika log tidak berubah, periksa status cron cPanel, path PHP, izin file, dan koneksi database.
4. Jika antrean tertahan, periksa panel antrean untuk job aktif/gagal serta timestamp-nya. Job `running` yang lease-nya habis ditandai gagal oleh worker berikutnya. Jangan menghapus row aktif secara manual.

Pekerjaan yang gagal tidak dicoba ulang otomatis. Pengguna dapat mengirim ulang dari fitur setelah penyebab provider atau server pulih. Cron ini tidak dapat memperbaiki HTTP 500 lain yang bukan berasal dari lonjakan pemanggilan AI; periksa log hosting untuk penyebab tersebut.
