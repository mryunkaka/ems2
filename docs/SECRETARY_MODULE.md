# Secretary Module

Dokumen ini menjelaskan struktur modul `Secretary` untuk fitur yang belum dibuat, sehingga implementasi SQL, halaman, dan controller dapat dilanjutkan dengan acuan yang jelas jika pekerjaan berpindah ke model lain.

## Tujuan

Modul `Secretary` dipakai untuk:

- mencatat agenda kunjungan divisi
- mencatat koordinasi internal divisi
- merekap surat rahasia / confidential letter log

Fitur ini melengkapi halaman `surat_menyurat.php` yang sudah ada.

## Ruang Lingkup Halaman

Modul saat ini mencakup 4 halaman baru:

1. `secretary_visit_agenda.php`
   Fungsi:
   pendataan agenda kunjungan divisi, PIC internal, jadwal, dan status kunjungan

2. `secretary_internal_coordination.php`
   Fungsi:
   pencatatan koordinasi internal divisi, topik rapat, host, jadwal, dan tindak lanjut

3. `secretary_confidential_letters.php`
   Fungsi:
   register surat rahasia / confidential letters yang perlu dikendalikan akses dan status distribusinya

4. `secretary_file_registry.php`
   Fungsi:
   register data file divisi seperti proposal, kerja sama, kontrak, laporan, dan arsip file lain agar mudah dicari

## Hubungan Dengan Modul Yang Sudah Ada

- `surat_menyurat.php`
  tetap menjadi modul utama surat masuk, surat keluar, dan notulen umum
- modul `Secretary`
  menambahkan lapisan agenda kunjungan, koordinasi internal, dan surat rahasia yang sifatnya administratif khusus sekretaris
  serta register file divisi untuk pencarian arsip yang lebih rapi

## Akses Division

Halaman sekretaris memakai guard:

- `Secretary`

Karena helper project saat ini memberi akses penuh ke `Executive` dan `Secretary`, halaman ini juga otomatis dapat dipakai oleh:

- `Secretary`
- `Executive`

## Alur Bisnis

### 1. Agenda kunjungan divisi

Setiap kunjungan divisi dicatat dengan data:

- kode agenda
- nama tamu / pihak yang berkunjung
- divisi / instansi asal
- tujuan kunjungan
- tanggal kunjungan
- jam kunjungan
- PIC internal
- lokasi
- status agenda

Output:

- daftar agenda kunjungan mendatang
- histori kunjungan selesai / dibatalkan

### 2. Koordinasi internal divisi

Setiap koordinasi internal dicatat dengan data:

- kode koordinasi
- judul koordinasi
- divisi terkait
- host / penanggung jawab
- tanggal koordinasi
- jam mulai
- status
- ringkasan pembahasan
- tindak lanjut

Output:

- daftar koordinasi aktif
- histori koordinasi dan tindak lanjut

### 3. Rekap surat rahasia

Setiap surat rahasia dicatat dengan data:

- kode register
- nomor referensi surat
- arah surat (`incoming` / `outgoing`)
- subjek
- pengirim atau penerima utama
- level kerahasiaan
- tanggal surat
- status distribusi
- catatan

Output:

- register surat rahasia
- status sealed / distributed / archived

### 4. Data file divisi

Setiap file penting divisi dicatat dengan data:

- kode file
- jenis file (`proposal`, `cooperation`, `contract`, `report`, `other`)
- nomor dokumen / referensi
- judul file
- pihak terkait
- tanggal dokumen
- status file
- kata kunci pencarian
- catatan

Output:

- daftar arsip file divisi
- pencarian file berdasarkan jenis, pihak terkait, nomor dokumen, dan kata kunci

## Struktur Database

### 1. `secretary_visit_agendas`

Kolom utama:

- `id`
- `agenda_code`
- `visitor_name`
- `origin_name`
- `visit_purpose`
- `visit_date`
- `visit_time`
- `location`
- `pic_user_id`
- `status`
- `notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

### 2. `secretary_internal_coordinations`

Kolom utama:

- `id`
- `coordination_code`
- `title`
- `division_scope`
- `host_user_id`
- `coordination_date`
- `start_time`
- `status`
- `summary_notes`
- `follow_up_notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

### 3. `secretary_confidential_letters`

Kolom utama:

- `id`
- `register_code`
- `reference_number`
- `letter_direction`
- `subject`
- `counterparty_name`
- `confidentiality_level`
- `letter_date`
- `status`
- `notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

### 4. `secretary_file_records`

Kolom utama:

- `id`
- `file_code`
- `file_category`
- `reference_number`
- `title`
- `counterparty_name`
- `document_date`
- `status`
- `keywords`
- `description`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

## Relasi

- `secretary_visit_agendas.pic_user_id -> user_rh.id`
- `secretary_visit_agendas.created_by -> user_rh.id`
- `secretary_visit_agendas.updated_by -> user_rh.id`
- `secretary_internal_coordinations.host_user_id -> user_rh.id`
- `secretary_internal_coordinations.created_by -> user_rh.id`
- `secretary_internal_coordinations.updated_by -> user_rh.id`
- `secretary_confidential_letters.created_by -> user_rh.id`
- `secretary_confidential_letters.updated_by -> user_rh.id`
- `secretary_file_records.created_by -> user_rh.id`
- `secretary_file_records.updated_by -> user_rh.id`

## Status

### Status agenda kunjungan

- `scheduled`
- `ongoing`
- `completed`
- `cancelled`

### Status koordinasi internal

- `draft`
- `scheduled`
- `done`
- `cancelled`

### Direction surat rahasia

- `incoming`
- `outgoing`

### Level kerahasiaan

- `confidential`
- `secret`
- `top_secret`

### Status surat rahasia

- `logged`
- `sealed`
- `distributed`
- `archived`

### Jenis file divisi

- `proposal`
- `cooperation`
- `contract`
- `report`
- `other`

### Status file divisi

- `draft`
- `review`
- `active`
- `archived`

## Prinsip Implementasi

- gunakan desain yang sudah tersedia di `assets/design`
- hindari CSS inline untuk layout utama
- gunakan pattern dashboard yang sudah ada:
  - `section.content`
  - `page page-shell`
  - `card`
  - `table-wrapper`
  - `table-custom`
  - `row-form-2`
- controller dipusatkan di:
  - `dashboard/secretary_action.php`
- sidebar `Secretary` diarahkan ke halaman nyata, bukan `#`

## File Modul

- `docs/sql/07_2026-03-11_secretary_module.sql`
- `dashboard/secretary_visit_agenda.php`
- `dashboard/secretary_internal_coordination.php`
- `dashboard/secretary_confidential_letters.php`
- `dashboard/secretary_file_registry.php`
- `dashboard/secretary_action.php`

## Status Progres Implementasi

### Sudah diputuskan

- modul secretary memiliki 4 halaman baru
- SQL terpisah disimpan di `docs/sql`
- desain mengikuti dashboard yang sudah ada

### Belum dibuat saat dokumen ini ditulis

### Sudah dibuat

- file SQL secretary
- halaman dashboard secretary
- action/controller secretary
- penggantian menu sidebar secretary
- register file divisi secretary

### Masih dapat dilanjutkan

- export agenda kunjungan
- daftar peserta koordinasi internal
- upload lampiran surat rahasia
- audit trail distribusi surat rahasia
