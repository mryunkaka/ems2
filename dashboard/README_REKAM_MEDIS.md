# Setup Rekam Medis - Quick Guide

## ✅ File yang Sudah Dibuat

Berikut adalah file yang telah dibuat untuk fitur Rekam Medis:

### 1. Controller & View
```
✅ dashboard/rekam_medis.php              - Halaman form input
✅ dashboard/rekam_medis_action.php       - Controller untuk process form
```

### 2. Helper Functions
```
✅ config/helpers.php                     - Updated dengan compressImageSmart() & uploadAndCompressFile()
```

### 3. Storage Directories
```
✅ storage/medical_records/               - Folder utama
✅ storage/medical_records/ktp/           - Folder untuk file KTP
✅ storage/medical_records/mri/           - Folder untuk file MRI
```

### 4. Sidebar Menu
```
✅ partials/sidebar.php                   - Updated dengan menu "Rekam Medis"
```

### 5. Documentation
```
✅ docs/prd-rekam-medis.md                - PRD lengkap
✅ docs/sql/medical_records_migration.sql - SQL migration
✅ docs/IMPLEMENTATION_PLAN_REKAM_MEDIS.md - Step-by-step guide
```

---

## 📋 LANGKAH SELANJUTNYA (WAJIB DILAKUKAN)

### Step 1: Run SQL Migration

Buka phpMyAdmin atau MySQL client Anda, lalu jalankan:

```sql
-- File: docs/sql/medical_records_migration.sql

CREATE TABLE IF NOT EXISTS `medical_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_name` varchar(100) NOT NULL COMMENT 'Nama pasien',
  `patient_occupation` varchar(50) DEFAULT 'Civilian' COMMENT 'Pekerjaan pasien',
  `patient_dob` date NOT NULL COMMENT 'Tanggal lahir pasien',
  `patient_phone` varchar(20) DEFAULT NULL COMMENT 'Nomor HP pasien',
  `patient_gender` enum('Laki-laki','Perempuan') NOT NULL COMMENT 'Jenis kelamin pasien',
  `patient_address` varchar(255) DEFAULT 'INDONESIA' COMMENT 'Alamat pasien',
  `patient_status` varchar(50) DEFAULT NULL COMMENT 'Status pasien',
  `ktp_file_path` varchar(255) NOT NULL COMMENT 'Path file KTP (wajib)',
  `mri_file_path` varchar(255) DEFAULT NULL COMMENT 'Path file foto MRI (opsional)',
  `medical_result_html` text NOT NULL COMMENT 'HTML rich-text hasil rekam medis',
  `doctor_id` int(11) NOT NULL COMMENT 'DPJP dari user_rh',
  `assistant_id` int(11) DEFAULT NULL COMMENT 'Asisten dari user_rh (opsional)',
  `operasi_type` enum('major','minor') NOT NULL COMMENT 'Jenis operasi: mayor/minor',
  `created_by` int(11) NOT NULL COMMENT 'User yang input data (dari user_rh)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu pembuatan record',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Waktu update terakhir',
  PRIMARY KEY (`id`),
  KEY `idx_patient_name` (`patient_name`),
  KEY `idx_patient_dob` (`patient_dob`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_doctor_id` (`doctor_id`),
  KEY `idx_assistant_id` (`assistant_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_mr_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `user_rh` (`id`) ON DELETE RESTRICT COMMENT 'DPJP tidak bisa dihapus jika ada record',
  CONSTRAINT `fk_mr_assistant` FOREIGN KEY (`assistant_id`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL COMMENT 'Asisten di-set NULL jika dihapus',
  CONSTRAINT `fk_mr_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE COMMENT 'Record ikut terhapus jika creator dihapus'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Rekam Medis Pasien';
```

### Step 2: Verify Table

Jalankan query ini untuk memastikan tabel sudah dibuat:

```sql
DESCRIBE medical_records;
```

### Step 3: Test Halaman

1. Buka browser
2. Akses: `http://localhost/ems2/dashboard/rekam_medis.php`
3. Login jika belum
4. Coba input data rekam medis

---

## 🧪 Testing Checklist

### Form Validation
- [ ] Nama wajib diisi
- [ ] Tanggal lahir wajib diisi
- [ ] Jenis kelamin wajib dipilih
- [ ] KTP wajib upload
- [ ] Dokter DPJP wajib dipilih
- [ ] Hasil rekam medis wajib diisi

### File Upload
- [ ] Upload KTP (JPG/PNG) - berhasil
- [ ] Upload KTP (> 5MB) - error
- [ ] Upload MRI (JPG/PNG) - berhasil
- [ ] Preview file sebelum upload - bekerja
- [ ] File ter-compress otomatis - bekerja

### HTML Editor (Quill.js)
- [ ] Bold text - bekerja
- [ ] Italic text - bekerja
- [ ] Heading (H1-H6) - bekerja
- [ ] Bullet list - bekerja
- [ ] Numbered list - bekerja
- [ ] Link insertion - bekerja
- [ ] Content tersimpan ke database - bekerja

### Database
- [ ] Record inserted successfully
- [ ] All fields saved correctly
- [ ] Foreign keys working (doctor_id, assistant_id, created_by)

### UI/UX
- [ ] Responsive design (mobile) - bekerja
- [ ] Responsive design (tablet) - bekerja
- [ ] Responsive design (desktop) - bekerja
- [ ] Error messages clear - bekerja
- [ ] Success message displayed - bekerja
- [ ] Menu "Rekam Medis" di sidebar - muncul

---

## 🔧 Troubleshooting

### Error: "Table 'medical_records' doesn't exist"

**Solusi:** Run SQL migration di Step 1.

### Error: "Session tidak valid"

**Solusi:** Pastikan sudah login ke aplikasi.

### Error: "Upload KTP wajib dilakukan"

**Solusi:** 
- Pastikan memilih file KTP sebelum submit
- Cek apakah file berupa JPG/PNG
- Cek ukuran file tidak melebihi 5MB

### Error: "Gagal upload KTP"

**Solusi:**
- Pastikan folder `storage/medical_records/ktp` sudah dibuat
- Cek permissions folder (Windows: writable, Linux: chmod 755)

### Quill Editor tidak muncul

**Solusi:**
- Cek koneksi internet (Quill.js dari CDN)
- Buka browser console (F12) untuk lihat error
- Refresh halaman (Ctrl+F5)

### Menu "Rekam Medis" tidak muncul di sidebar

**Solusi:**
- Clear cache browser
- Logout dan login kembali
- Cek file `partials/sidebar.php` sudah di-update

---

## 📝 Cara Penggunaan

### Input Rekam Medis Baru

1. **Buka halaman**
   - Klik menu "Rekam Medis" di sidebar
   - Atau akses: `/dashboard/rekam_medis.php`

2. **Isi Data Pasien**
   - Nama (wajib)
   - Pekerjaan (default: Civilian)
   - Tanggal Lahir (wajib)
   - No HP (opsional)
   - Jenis Kelamin (wajib)
   - Alamat (default: INDONESIA)
   - Status (opsional)

3. **Upload Dokumen**
   - KTP: Klik "Pilih File / Ambil Foto", pilih file JPG/PNG (wajib)
   - MRI: Klik "Pilih File / Ambil Foto", pilih file JPG/PNG (opsional)

4. **Isi Hasil Rekam Medis**
   - Gunakan editor HTML yang sudah disediakan
   - Bisa membuat judul (H1-H6)
   - Bisa membuat teks bold, italic, underline
   - Bisa membuat list (bullet points atau numbered)
   - Bisa menambahkan link
   - Bisa mengatur alignment teks

5. **Pilih Tim Medis**
   - Dokter DPJP: Pilih dari dropdown (minimal Co.Ast)
   - Asisten: Pilih dari dropdown (minimal Paramedic, opsional)

6. **Pilih Jenis Operasi**
   - Minor: Untuk operasi kecil
   - Mayor: Untuk operasi besar

7. **Submit**
   - Klik "Simpan Rekam Medis"
   - Tunggu redirect ke halaman yang sama
   - Lihat pesan sukses

---

## 📊 Struktur Database

### Tabel: `medical_records`

| Kolom | Type | Keterangan |
|-------|------|------------|
| id | int(11) | Primary key, auto increment |
| patient_name | varchar(100) | Nama pasien (wajib) |
| patient_occupation | varchar(50) | Pekerjaan pasien (default: Civilian) |
| patient_dob | date | Tanggal lahir pasien (wajib) |
| patient_phone | varchar(20) | Nomor HP pasien (opsional) |
| patient_gender | enum | Jenis kelamin: Laki-laki/Perempuan (wajib) |
| patient_address | varchar(255) | Alamat pasien (default: INDONESIA) |
| patient_status | varchar(50) | Status pasien (opsional) |
| ktp_file_path | varchar(255) | Path file KTP (wajib) |
| mri_file_path | varchar(255) | Path file MRI (opsional) |
| medical_result_html | text | HTML hasil rekam medis (wajib) |
| doctor_id | int(11) | FK ke user_rh.id (DPJP, wajib) |
| assistant_id | int(11) | FK ke user_rh.id (Asisten, opsional) |
| operasi_type | enum | major/minor (wajib) |
| created_by | int(11) | FK ke user_rh.id (user yang input) |
| created_at | datetime | Waktu pembuatan record |
| updated_at | datetime | Waktu update terakhir |

### Foreign Keys

- `doctor_id` → `user_rh.id` (ON DELETE RESTRICT)
  - Dokter tidak bisa dihapus jika ada record
- `assistant_id` → `user_rh.id` (ON DELETE SET NULL)
  - Asisten di-set NULL jika dihapus
- `created_by` → `user_rh.id` (ON DELETE CASCADE)
  - Record ikut terhapus jika creator dihapus

---

## 🎨 Fitur UI

### Responsive Design
- Mobile: Layout 1 kolom
- Tablet: Layout 2 kolom
- Desktop: Layout 2 kolom dengan lebar maksimal

### File Upload Preview
- Preview image sebelum upload
- Validasi file type (hanya JPG/PNG)
- Validasi file size (max 5MB)
- Auto-compress setelah upload

### HTML Editor (Quill.js)
- Toolbar lengkap
- WYSIWYG (What You See Is What You Get)
- Output HTML
- Placeholder text
- Validasi tidak boleh kosong

### Form Validation
- Client-side validation (HTML5)
- Server-side validation (PHP)
- Real-time feedback
- Error messages yang jelas

---

## 🔐 Security

### CSRF Protection
- Token CSRF di setiap form
- Validasi di controller

### Session Validation
- Cek user login
- Cek user ID valid

### File Upload Security
- Validasi file type (hanya JPG/PNG)
- Validasi file size (max 5MB)
- Sanitasi filename
- Simpan di folder terpisah

### XSS Prevention
- HTML sanitization (strip script tags)
- htmlspecialchars() untuk output
- Prepared statements untuk SQL

---

## 📚 Referensi

- PRD Lengkap: `docs/prd-rekam-medis.md`
- SQL Migration: `docs/sql/medical_records_migration.sql`
- Implementation Plan: `docs/IMPLEMENTATION_PLAN_REKAM_MEDIS.md`
- UI Design System: `docs/ui-design-system.md`

---

## 🆘 Butuh Bantuan?

Jika ada masalah atau pertanyaan:

1. **Cek dokumentasi** di folder `docs/`
2. **Cek error logs** PHP
3. **Cek browser console** (F12)
4. **Cek database** untuk verify data

---

**Happy Coding! 🚀**

Dibuat: 8 Maret 2026
Versi: 1.0
