# Specialist Medical Authority Module

Dokumen ini menjelaskan struktur awal modul `Specialist Medical Authority` agar alur kerja, relasi ke modul jabatan, dan database dapat dipahami sebelum implementasi halaman.

## Tujuan

Modul ini dipakai untuk:

- merekap pelatihan medis lanjutan per tenaga medis
- melakukan penilaian kelayakan naik jabatan dari sisi otoritas medis spesialis
- menerbitkan otorisasi medis spesialis untuk user yang sudah dinyatakan layak

## Ruang Lingkup Halaman

Modul awal mencakup 3 halaman baru:

1. `specialist_training_recap.php`
   Fungsi:
   input dan monitoring riwayat pelatihan medis, sertifikasi, workshop, atau academy lanjutan

2. `specialist_promotion_assessment.php`
   Fungsi:
   menilai pengajuan kenaikan jabatan yang sudah masuk dari modul `Pengajuan Jabatan`

3. `specialist_authorizations.php`
   Fungsi:
   menerbitkan dan memantau otorisasi medis spesialis yang aktif maupun kedaluwarsa

## Hubungan Dengan Modul Yang Sudah Ada

- `pengajuan_jabatan.php`
  dipakai user untuk mengajukan kenaikan jabatan
- `review_pengajuan_jabatan.php`
  dipakai reviewer manajerial untuk approve atau reject pengajuan
- modul `Specialist Medical Authority`
  menambahkan lapisan evaluasi medis dan otorisasi setelah pengajuan tersedia

Dengan demikian, modul ini tidak menggantikan review jabatan yang sudah ada. Modul ini menambahkan evaluasi khusus dari divisi `Specialist Medical Authority`.

## Alur Bisnis

### 1. Rekap pelatihan medis

Setiap pelatihan medis lanjutan dicatat dengan data:

- tenaga medis yang mengikuti
- nama pelatihan
- penyelenggara
- kategori pelatihan
- tanggal mulai dan selesai
- nomor sertifikat bila ada
- status pelatihan
- catatan evaluator

Data ini dipakai sebagai referensi untuk melihat kesiapan medis user saat dinilai untuk kenaikan jabatan atau otorisasi spesialis.

### 2. Penilaian layak naik jabatan

Saat ada data pada `position_promotion_requests`, assessor dari `Specialist Medical Authority` dapat:

- memilih request yang akan dinilai
- memberi skor klinis
- memberi skor pelatihan
- memberi skor kesiapan profesional
- menentukan rekomendasi:
  - `recommended`
  - `follow_up_required`
  - `not_recommended`
- memberi catatan evaluasi

Penilaian ini bersifat medis. Hasilnya disimpan sebagai referensi terpisah dari status approval manajerial.

### 3. Otorisasi medis spesialis

Jika user sudah memenuhi syarat:

- assessor membuat otorisasi medis spesialis
- otorisasi menyimpan:
  - user yang diotorisasi
  - bidang spesialisasi
  - ruang lingkup wewenang
  - tanggal efektif
  - tanggal kedaluwarsa
  - status

Status awal otorisasi:

- `active`
- `expired`
- `revoked`

## Struktur Database

### 1. `specialist_training_records`

Rekap pelatihan medis per user.

Kolom utama:

- `id`
- `training_code`
- `user_id`
- `training_name`
- `provider_name`
- `category`
- `certificate_number`
- `start_date`
- `end_date`
- `status`
- `notes`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

### 2. `specialist_promotion_assessments`

Penilaian medis terhadap request kenaikan jabatan.

Kolom utama:

- `id`
- `assessment_code`
- `promotion_request_id`
- `assessed_user_id`
- `assessor_user_id`
- `clinical_score`
- `training_score`
- `readiness_score`
- `total_score`
- `recommendation`
- `notes`
- `assessed_at`
- `created_at`
- `updated_at`

### 3. `specialist_authorizations`

Otorisasi medis spesialis yang diterbitkan.

Kolom utama:

- `id`
- `authorization_code`
- `user_id`
- `specialty_name`
- `privilege_scope`
- `effective_date`
- `expiry_date`
- `status`
- `assessment_id`
- `approved_by`
- `created_by`
- `updated_by`
- `notes`
- `created_at`
- `updated_at`

## Relasi

- `specialist_training_records.user_id -> user_rh.id`
- `specialist_training_records.created_by -> user_rh.id`
- `specialist_training_records.updated_by -> user_rh.id`
- `specialist_promotion_assessments.promotion_request_id -> position_promotion_requests.id`
- `specialist_promotion_assessments.assessed_user_id -> user_rh.id`
- `specialist_promotion_assessments.assessor_user_id -> user_rh.id`
- `specialist_authorizations.user_id -> user_rh.id`
- `specialist_authorizations.assessment_id -> specialist_promotion_assessments.id`
- `specialist_authorizations.approved_by -> user_rh.id`
- `specialist_authorizations.created_by -> user_rh.id`
- `specialist_authorizations.updated_by -> user_rh.id`

## Status dan Rekomendasi

### Status pelatihan

- `planned`
- `ongoing`
- `completed`
- `expired`

### Rekomendasi assessment

- `recommended`
- `follow_up_required`
- `not_recommended`

### Status otorisasi

- `active`
- `expired`
- `revoked`

## Prinsip Implementasi

- guard akses memakai helper division:
  - `Specialist Medical Authority`
  - `Executive`
  - `Secretary`
- halaman dibuat terpisah per fungsi agar operasional lebih jelas
- data training, assessment, dan authorization dibuat independen tetapi tetap terhubung
- assessment tidak otomatis mengubah status `position_promotion_requests`
- otorisasi dapat dibuat dari assessment yang sudah ada, tetapi tetap boleh menyimpan catatan manual

## Output Yang Dihasilkan

- daftar pelatihan medis per user
- daftar request kenaikan jabatan yang sudah dinilai secara medis
- daftar otorisasi medis spesialis aktif, expired, dan revoked

## SQL Modul

File SQL modul ini dibuat di:

- `docs/sql/04_2026-03-10_specialist_medical_authority_module.sql`

## Catatan Lanjutan

Tahap berikutnya bisa menambahkan:

- upload lampiran sertifikat pelatihan
- reminder kedaluwarsa otorisasi
- sinkronisasi status assessment ke review pengajuan jabatan
- dashboard statistik per spesialisasi
