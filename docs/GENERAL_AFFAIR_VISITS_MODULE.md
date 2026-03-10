# General Affair Visits Module

Dokumen ini menjelaskan struktur awal modul `General Affair Visits` agar alur bisnis, database, dan implementasi dashboard bisa dipahami dari awal sampai akhir.

## Tujuan

Modul ini dipakai untuk:

- mencatat rencana kunjungan yang dikelola divisi `General Affair`
- menentukan PIC internal untuk setiap kunjungan
- memantau status kunjungan dari penjadwalan sampai selesai
- menyimpan histori kunjungan secara terstruktur agar mudah diaudit

## Aktor

- `General Affair`
  membuat, mengubah, mengonfirmasi, dan menutup data kunjungan
- `Executive`
  dapat melihat dan mengelola karena secara helper memiliki akses lintas division
- `Secretary`
  dapat melihat dan mengelola karena secara helper memiliki akses lintas division

## Ruang Lingkup Halaman

Modul awal terdiri dari 2 file utama:

1. `general_affair_visits.php`
   Fungsi:
   halaman dashboard untuk input kunjungan, melihat ringkasan, dan memantau histori

2. `general_affair_visits_action.php`
   Fungsi:
   memproses tambah, edit, ubah status, dan hapus data kunjungan

## Alur Bisnis

### 1. Pencatatan kunjungan

Saat ada rencana visit:

- admin General Affair membuat data visit
- sistem membuat `visit_code`
- admin mengisi:
  - nama pengunjung atau instansi
  - kontak utama
  - tujuan kunjungan
  - tanggal visit
  - jam mulai dan selesai
  - lokasi pertemuan
  - PIC internal dari `user_rh`
  - catatan tambahan

Status awal visit adalah `scheduled`.

### 2. Konfirmasi visit

Setelah jadwal siap:

- admin dapat mengubah status menjadi `confirmed`
- jika kunjungan dibatalkan, status bisa diubah menjadi `cancelled`

### 3. Kunjungan berlangsung

Saat visit berjalan:

- admin dapat mengubah status menjadi `in_progress`

### 4. Penutupan kunjungan

Setelah visit selesai:

- admin mengubah status menjadi `completed`
- admin dapat menambahkan catatan hasil akhir atau evaluasi singkat

## Status Visit

- `scheduled`
  visit sudah dibuat tetapi belum dikonfirmasi
- `confirmed`
  visit sudah siap dilaksanakan
- `in_progress`
  visit sedang berlangsung
- `completed`
  visit selesai
- `cancelled`
  visit dibatalkan

## Struktur Database

Modul awal memakai 1 tabel utama:

### `general_affair_visits`

Header visit yang dikelola oleh General Affair.

Kolom utama:

- `id`
- `visit_code`
- `visitor_name`
- `institution_name`
- `visitor_phone`
- `visit_purpose`
- `visit_date`
- `start_time`
- `end_time`
- `location`
- `pic_user_id`
- `status`
- `notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

## Relasi

- `general_affair_visits.pic_user_id -> user_rh.id`
- `general_affair_visits.created_by -> user_rh.id`
- `general_affair_visits.updated_by -> user_rh.id`

## Prinsip Data

- satu baris mewakili satu agenda visit
- histori visit tidak dihapus secara otomatis saat user PIC berubah
- PIC tetap direlasikan ke `user_rh` aktif agar dashboard bisa menampilkan nama terbaru
- data kode visit harus unik dan bisa dipakai sebagai referensi operasional

## Input Yang Disediakan

- tambah visit baru
- ubah detail visit
- ubah status visit
- hapus visit jika salah input

## Output Yang Dihasilkan

- ringkasan total visit
- jumlah visit per status
- daftar visit terbaru
- status operasional visit per jadwal

## Aturan Implementasi Awal

- guard akses menggunakan helper division:
  - `General Affair`
  - secara implisit `Executive` dan `Secretary` ikut bisa lewat helper yang sudah ada
- halaman dibuat satu dashboard sederhana terlebih dahulu
- belum ada attachment, approval multi-level, atau notifikasi otomatis
- jika tabel belum dibuat, halaman harus menampilkan pesan bahwa SQL perlu dijalankan

## SQL Modul

File SQL modul ini dibuat di:

- `docs/sql/03_2026-03-11_general_affair_visits_module.sql`

## Catatan Pengembangan Lanjutan

Fase berikutnya bisa menambahkan:

- lampiran visit
- check-in dan check-out tamu
- daftar peserta internal tambahan
- integrasi notulen visit
- export laporan kunjungan
