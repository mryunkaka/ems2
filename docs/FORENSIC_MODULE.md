# Forensic Module

Dokumen ini menjadi acuan implementasi awal modul `Forensic` agar struktur bisnis, database, halaman, dan controller dapat dipahami sebelum pekerjaan teknis dilanjutkan.

## Tujuan

Modul `Forensic` dipakai untuk:

- mencatat data pasien private yang ditangani secara forensik
- menyimpan hasil visum dan ringkasan temuan pemeriksaan
- mengarsipkan dokumen forensic yang perlu disimpan terstruktur
- mengelola rekam medis private yang hanya bisa diakses division forensic

Dokumen ini juga berfungsi sebagai referensi progres. Jika pekerjaan berpindah ke model lain, modul dapat dilanjutkan dengan membaca file ini terlebih dahulu.

## Ruang Lingkup Halaman

Tahap awal modul mencakup 3 halaman:

1. `forensic_private_patients.php`
   Fungsi:
   input dan monitoring data pasien private / kasus forensic

2. `forensic_visum_results.php`
   Fungsi:
   input hasil visum, dokter pemeriksa, temuan, dan status hasil

3. `forensic_archive.php`
   Fungsi:
   pencatatan arsip forensic berdasarkan kasus atau hasil visum

Tahap lanjutan modul menambahkan 2 halaman rekam medis private:

4. `forensic_medical_records.php`
   Fungsi:
   input rekam medis private dengan struktur yang sama seperti rekam medis umum

5. `forensic_medical_records_list.php`
   Fungsi:
   daftar rekam medis private yang hanya terlihat oleh division forensic

## Akses Division

Halaman forensic memakai guard division `Forensic`.

Karena helper project saat ini mengizinkan `Specialist Medical Authority` mengakses menu `Forensic`, maka halaman forensic juga dapat dipakai oleh user dari division:

- `Forensic`
- `Specialist Medical Authority`
- `Executive`
- `Secretary`

Implementasi guard tetap cukup memanggil:

- `ems_require_division_access(['Forensic'], '/dashboard/index.php');`

## Alur Bisnis

### 1. Data pasien private

Setiap kasus forensic dimulai dari pencatatan data pasien private atau subjek kasus.

Data minimal:

- kode kasus
- relasi ke rekam medis private
- nama pasien
- nomor rekam medis atau identitas referensi
- jenis kasus
- tanggal kejadian
- lokasi kejadian
- level kerahasiaan
- status kasus

Output tahap ini:

- daftar kasus forensic aktif
- daftar kasus closed / archived
- kasus forensic dapat dihubungkan ke satu rekam medis private

### 1a. Rekam medis private

Modul forensic memakai tabel `medical_records` yang sama dengan rekam medis umum, tetapi ditandai dengan scope khusus.

Prinsipnya:

- tabel tetap sama: `medical_records`
- data forensic private ditandai `visibility_scope = 'forensic_private'`
- halaman umum rekam medis hanya menampilkan `visibility_scope = 'standard'`
- halaman forensic hanya menampilkan `visibility_scope = 'forensic_private'`
- input `No. Rekam Medis` pada kasus forensic memakai autocomplete berdasarkan:
  - `patient_name`
  - `patient_citizen_id`
  - `record_code`

Dengan pendekatan ini:

- struktur rekam medis tidak perlu dipisah ke tabel baru
- akses data private tetap bisa dibatasi per division
- forensic tetap dapat memakai form dan alur rekam medis yang sama

### 2. Hasil visum

Setelah kasus dibuat, user forensic dapat membuat hasil visum yang terhubung ke kasus tersebut.

Data minimal:

- kode visum
- kasus forensic
- tanggal pemeriksaan
- dokter pemeriksa
- pihak peminta
- ringkasan temuan
- kesimpulan
- rekomendasi
- status hasil

Output tahap ini:

- daftar hasil visum per kasus
- riwayat status hasil visum

### 3. Arsip forensic

Arsip dibuat untuk menandai dokumen yang sudah disimpan secara administratif.

Arsip dapat dikaitkan ke:

- kasus forensic
- hasil visum
- atau keduanya

Data minimal:

- kode arsip
- judul arsip
- tipe dokumen
- referensi kasus atau visum
- tanggal retensi
- status arsip
- catatan

Output tahap ini:

- daftar arsip forensic
- monitoring dokumen yang masih tersimpan, sealed, atau sudah released

## Struktur Database

### 1. `forensic_private_patients`

Tabel master kasus forensic / pasien private.

Kolom utama:

- `id`
- `case_code`
- `patient_name`
- `medical_record_no`
- `medical_record_id`
- `identity_number`
- `birth_date`
- `gender`
- `case_type`
- `incident_date`
- `incident_location`
- `confidentiality_level`
- `status`
- `notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

### 2. `forensic_visum_results`

Tabel hasil visum yang terhubung ke kasus forensic.

Kolom utama:

- `id`
- `visum_code`
- `private_patient_id`
- `examination_date`
- `doctor_user_id`
- `requesting_party`
- `finding_summary`
- `conclusion_text`
- `recommendation_text`
- `status`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

### 3. `forensic_archives`

Tabel arsip dokumen forensic.

Kolom utama:

- `id`
- `archive_code`
- `private_patient_id`
- `visum_result_id`
- `archive_title`
- `document_type`
- `retention_until`
- `status`
- `notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

## Relasi

- `forensic_private_patients.medical_record_id -> medical_records.id`
- `forensic_private_patients.created_by -> user_rh.id`
- `forensic_private_patients.updated_by -> user_rh.id`
- `forensic_visum_results.private_patient_id -> forensic_private_patients.id`
- `forensic_visum_results.doctor_user_id -> user_rh.id`
- `forensic_visum_results.created_by -> user_rh.id`
- `forensic_visum_results.updated_by -> user_rh.id`
- `forensic_archives.private_patient_id -> forensic_private_patients.id`
- `forensic_archives.visum_result_id -> forensic_visum_results.id`
- `forensic_archives.created_by -> user_rh.id`
- `forensic_archives.updated_by -> user_rh.id`

## Status

### Status kasus forensic

- `draft`
- `active`
- `closed`
- `archived`

### Level kerahasiaan

- `restricted`
- `confidential`
- `sealed`

### Status hasil visum

- `draft`
- `issued`
- `revised`
- `archived`

### Status arsip

- `stored`
- `sealed`
- `released`

## Prinsip Implementasi

- desain halaman harus mengikuti komponen dan utility di `assets/design`
- hindari CSS inline untuk layout utama
- gunakan pattern dashboard yang sudah ada:
  - `section.content`
  - `page page-shell`
  - `card`
  - `table-wrapper`
  - `table-custom`
  - `row-form-2`
- action controller dibuat terpisah di satu file:
  - `dashboard/forensic_action.php`
- sidebar forensic diarahkan ke halaman nyata, bukan `#`
- rekam medis private memakai halaman khusus forensic, tetapi tetap menyimpan data ke tabel `medical_records`
- halaman rekam medis umum wajib mengecualikan data `forensic_private`

## File Modul

Dokumen ini menjadi referensi untuk file berikut:

- `docs/sql/05_2026-03-11_forensic_module.sql`
- `docs/sql/06_2026-03-11_forensic_private_medical_records.sql`
- `dashboard/forensic_private_patients.php`
- `dashboard/forensic_visum_results.php`
- `dashboard/forensic_archive.php`
- `dashboard/forensic_action.php`
- `dashboard/forensic_medical_records.php`
- `dashboard/forensic_medical_records_list.php`

## Status Progres Implementasi

### Sudah diputuskan

- modul forensic memiliki 3 halaman
- database memakai 3 tabel utama
- akses utama memakai division `Forensic`
- desain mengikuti `assets/design`

### Sudah dibuat

- file SQL modul forensic
- halaman dashboard forensic
- controller/action forensic
- menu sidebar forensic ke halaman nyata
- rekam medis private forensic dengan tabel yang sama (`medical_records`) namun scope berbeda
- autocomplete `No. Rekam Medis` pada kasus forensic berbasis nama / citizen ID / record code

### Masih dapat dilanjutkan

- upload file dokumen visum
- export arsip forensic
- filter lanjutan berdasarkan kode kasus, level kerahasiaan, dan dokter pemeriksa

## Catatan Lanjutan

Tahap berikutnya setelah modul dasar jadi bisa menambahkan:

- upload file dokumen visum
- audit log perubahan status kasus
- export arsip forensic
- pencarian sensitif berbasis kode kasus
