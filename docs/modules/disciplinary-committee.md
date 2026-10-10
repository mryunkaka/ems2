# Disciplinary Committee Module

Dokumen ini menjelaskan alur, struktur database, relasi, dan ruang lingkup awal untuk modul `Disciplinary Committee`.

## Tujuan

Modul ini dipakai untuk:

- mengelola master indikasi pelanggaran
- mencatat disciplinary case per user
- menghitung total point otomatis
- membedakan kasus `tolerable` dan `non_tolerable`
- menghasilkan surat peringatan yang terkait ke case

## Ruang Lingkup Halaman

Modul awal terdiri dari 3 halaman yang saling terhubung:

1. `disciplinary_indications.php`
   Fungsi:
   master data indikasi, point default, kategori toleransi, dan status aktif

2. `disciplinary_cases.php`
   Fungsi:
   membuat case, memilih user terkait, menambahkan satu atau banyak indikasi, menghitung total point otomatis, dan menghasilkan rekomendasi tindakan

3. `disciplinary_warning_letters.php`
   Fungsi:
   membuat surat peringatan dari case yang sudah ada, memantau histori surat, dan menjaga relasi surat ke case

## Alur Bisnis

### 1. Master indikasi

Admin Disciplinary Committee membuat atau mengubah master indikasi:

- nama indikasi
- deskripsi
- point default
- jenis toleransi:
  - `tolerable`
  - `non_tolerable`

Master ini fleksibel. Nama indikasi dan point dapat diubah kapan saja untuk kebutuhan kebijakan baru.

### 2. Input disciplinary case

Saat ada pelanggaran:

- pilih user yang dikenai case
- isi nama case
- isi tanggal kejadian
- pilih satu atau banyak indikasi
- sistem mengambil snapshot nama indikasi, point, dan toleransi saat case dibuat

Snapshot disimpan agar histori lama tidak berubah walaupun master indikasi nantinya diubah.

### 3. Perhitungan otomatis

Setiap case menghitung:

- `total_points`
- `non_tolerable_count`
- `tolerable_count`
- `tolerance_summary`
- `recommended_action`

### 4. Surat peringatan

Jika case perlu tindakan formal:

- pilih case
- sistem mengambil rekomendasi tindakan
- admin membuat surat peringatan
- surat tetap terkait ke case asal

## Aturan Rekomendasi Awal

### Jika ada item `non_tolerable`

- `1 - 34` point: `Written Warning 1`
- `35 - 59` point: `Written Warning 2`
- `60 - 79` point: `Final Warning`
- `80+` point: `Termination Review`

### Jika semua item `tolerable`

- `0 - 19` point: `Coaching`
- `20 - 39` point: `Verbal Warning`
- `40 - 59` point: `Written Warning 1`
- `60 - 79` point: `Written Warning 2`
- `80 - 99` point: `Final Warning`
- `100+` point: `Termination Review`

Aturan ini sengaja dibuat configurable di level kode agar bisa disesuaikan di fase berikutnya.

## Struktur Database

### 1. `disciplinary_indications`

Master indikasi pelanggaran.

Kolom utama:

- `id`
- `code`
- `name`
- `description`
- `default_points`
- `tolerance_type`
- `is_active`
- `created_at`
- `updated_at`

### 2. `disciplinary_cases`

Header case pelanggaran.

Kolom utama:

- `id`
- `case_code`
- `subject_user_id`
- `case_name`
- `case_date`
- `summary`
- `status`
- `total_points`
- `tolerable_count`
- `non_tolerable_count`
- `tolerance_summary`
- `recommended_action`
- `letter_status`
- `created_by`
- `reviewed_by`
- `reviewed_at`
- `created_at`
- `updated_at`

### 3. `disciplinary_case_items`

Detail item indikasi di dalam 1 case.

Kolom utama:

- `id`
- `case_id`
- `indication_id`
- `indication_name_snapshot`
- `points_snapshot`
- `tolerance_type_snapshot`
- `notes`
- `created_at`
- `updated_at`

### 4. `disciplinary_warning_letters`

Surat peringatan yang dibuat dari case.

Kolom utama:

- `id`
- `letter_code`
- `case_id`
- `subject_user_id`
- `letter_type`
- `issued_date`
- `effective_date`
- `title`
- `body_notes`
- `created_by`
- `created_at`
- `updated_at`

## Relasi

- `disciplinary_cases.subject_user_id -> user_rh.id`
- `disciplinary_cases.created_by -> user_rh.id`
- `disciplinary_cases.reviewed_by -> user_rh.id`
- `disciplinary_case_items.case_id -> disciplinary_cases.id`
- `disciplinary_case_items.indication_id -> disciplinary_indications.id`
- `disciplinary_warning_letters.case_id -> disciplinary_cases.id`
- `disciplinary_warning_letters.subject_user_id -> user_rh.id`
- `disciplinary_warning_letters.created_by -> user_rh.id`

## Prinsip Data

- master indikasi boleh berubah
- histori case tidak ikut berubah
- karena itu case item menyimpan snapshot point dan nama indikasi
- surat peringatan selalu mengambil referensi case, bukan langsung dari master indikasi

## Input Yang Disediakan

### Halaman Master Indikasi

- tambah indikasi
- ubah indikasi
- aktif/nonaktif indikasi

### Halaman Cases

- tambah case
- pilih user
- isi nama case
- pilih tanggal kejadian
- pilih banyak indikasi
- isi catatan tiap item
- update status case

### Halaman Surat Peringatan

- pilih case yang akan dijadikan surat
- pilih jenis surat
- isi tanggal terbit
- isi tanggal efektif
- isi catatan isi surat

## Output Yang Dihasilkan

- daftar master indikasi
- daftar case dengan total point otomatis
- daftar surat peringatan
- rekomendasi tindakan disipliner per case

## Catatan Implementasi

- guard akses awal memakai division:
  - `Disciplinary Committee`
  - `Human Capital`
  - `Secretary`
  - `Executive`
- halaman baru akan memakai helper division access agar konsisten dengan sidebar
- halaman yang belum memerlukan approval workflow tambahan disimpan dalam status sederhana dulu
