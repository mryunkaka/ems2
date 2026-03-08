# PRD: Rekam Medis Pasien (Medical Records)

**Dokumen Versi:** 1.0  
**Tanggal:** 8 Maret 2026  
**Status:** Planning  
**Author:** AI Assistant

---

## 1. Ringkasan Eksekutif

### 1.1 Latar Belakang
Sistem EMS saat ini membutuhkan modul pencatatan rekam medis pasien yang terstruktur untuk mendokumentasikan:
- Data pasien lengkap dengan verifikasi KTP
- Hasil pemeriksaan medis dalam format HTML rich-text
- Dokumentasi foto MRI (opsional)
- Informasi dokter dan asisten yang menangani
- Klasifikasi operasi (mayor/minor)

### 1.2 Tujuan
Membuat halaman baru "Rekam Medis" yang memungkinkan:
1. Input data pasien lengkap dengan upload KTP (wajib)
2. Upload foto hasil MRI (opsional) dengan kompresi otomatis
3. Input hasil rekam medis menggunakan editor HTML (rich-text)
4. Pemilihan dokter DPJP dan asisten dari user_rh dengan filter jabatan
5. Klasifikasi operasi mayor atau minor
6. UI konsisten dengan desain system EMS existing

---

## 2. Analisis Requirement

### 2.1 Functional Requirements

#### FR-001: Input Data Pasien
| Field | Type | Required | Validation | Default |
|-------|------|----------|------------|---------|
| Nama | Text(100) | Ya | Max 100 chars | - |
| Pekerjaan | Text(50) | Ya | Max 50 chars | Civilian |
| Tanggal Lahir | Date | Ya | Format DD/MM/YYYY | - |
| No HP | Text(20) | Tidak | Max 20 chars | - |
| Jenis Kelamin | Enum | Ya | Laki-laki/Perempuan | - |
| Alamat | Text(255) | Ya | Max 255 chars | INDONESIA |
| Status | Text(50) | Tidak | Max 50 chars | - |

#### FR-002: Upload Dokumen
| Dokumen | Type | Required | Compression | Max Size |
|---------|------|----------|-------------|----------|
| KTP | Image (jpg/png) | Ya | Ya (library existing) | 300KB |
| Foto MRI | Image (jpg/png) | Tidak | Ya (library existing) | 500KB |

#### FR-003: Hasil Rekam Medis (HTML Rich-Text)
Editor harus mendukung:
- ✅ Heading (H1-H6)
- ✅ Bold, Italic, Underline
- ✅ Bullet points & Numbered lists
- ✅ Text alignment
- ✅ Link insertion
- ✅ Table creation
- ✅ Code blocks (jika perlu)

#### FR-004: Pemilihan Dokter
| Role | Source Table | Filter Jabatan | Minimum Level |
|------|-------------|----------------|---------------|
| Dokter DPJP | user_rh | co_asst ke atas | co_asst, general_practitioner, specialist, (Co.Ast), Dokter Umum, Dokter Spesialis |
| Asisten | user_rh | paramedic ke atas | paramedic, co_asst, general_practitioner, specialist |

#### FR-005: Jenis Operasi
| Option | Value | Description |
|--------|-------|-------------|
| Mayor | major | Operasi besar dengan risiko tinggi |
| Minor | minor | Operasi kecil dengan risiko rendah |

### 2.2 Non-Functional Requirements

#### NFR-001: Performance
- Upload file dengan kompresi < 3 detik
- Page load time < 2 detik
- Support concurrent users minimal 10

#### NFR-002: Security
- CSRF protection pada semua form submit
- Session validation
- File type validation (hanya jpg/png)
- Sanitasi HTML input untuk mencegah XSS

#### NFR-003: UI/UX
- Konsisten dengan UI Design System EMS (Tailwind CSS)
- Responsive design (mobile-friendly)
- Form validation real-time
- Loading indicator saat upload
- Preview image sebelum submit

#### NFR-004: Compatibility
- Browser: Chrome, Firefox, Edge (latest 2 versions)
- PHP Version: 8.x
- Database: MariaDB 11.x

---

## 3. Database Design

### 3.1 Tabel Baru: `medical_records`

```sql
CREATE TABLE `medical_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_name` varchar(100) NOT NULL,
  `patient_occupation` varchar(50) DEFAULT 'Civilian',
  `patient_dob` date NOT NULL,
  `patient_phone` varchar(20) DEFAULT NULL,
  `patient_gender` enum('Laki-laki','Perempuan') NOT NULL,
  `patient_address` varchar(255) DEFAULT 'INDONESIA',
  `patient_status` varchar(50) DEFAULT NULL,
  `ktp_file_path` varchar(255) NOT NULL,
  `mri_file_path` varchar(255) DEFAULT NULL,
  `medical_result_html` text NOT NULL COMMENT 'HTML rich-text hasil rekam medis',
  `doctor_id` int(11) NOT NULL COMMENT 'DPJP dari user_rh',
  `assistant_id` int(11) DEFAULT NULL COMMENT 'Asisten dari user_rh',
  `operasi_type` enum('major','minor') NOT NULL COMMENT 'mayor/minor',
  `created_by` int(11) NOT NULL COMMENT 'User yang input data',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient_name` (`patient_name`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_doctor_id` (`doctor_id`),
  KEY `idx_assistant_id` (`assistant_id`),
  CONSTRAINT `fk_mr_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `user_rh` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_mr_assistant` FOREIGN KEY (`assistant_id`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 3.2 Migration SQL

File: `docs/sql/medical_records_migration.sql`

```sql
-- ============================================
-- MEDICAL RECORDS TABLE MIGRATION
-- ============================================
-- Author: EMS Development Team
-- Date: 2026-03-08
-- Description: Create medical_records table for patient medical records management

-- Step 1: Create table
CREATE TABLE IF NOT EXISTS `medical_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_name` varchar(100) NOT NULL,
  `patient_occupation` varchar(50) DEFAULT 'Civilian',
  `patient_dob` date NOT NULL,
  `patient_phone` varchar(20) DEFAULT NULL,
  `patient_gender` enum('Laki-laki','Perempuan') NOT NULL,
  `patient_address` varchar(255) DEFAULT 'INDONESIA',
  `patient_status` varchar(50) DEFAULT NULL,
  `ktp_file_path` varchar(255) NOT NULL,
  `mri_file_path` varchar(255) DEFAULT NULL,
  `medical_result_html` text NOT NULL COMMENT 'HTML rich-text hasil rekam medis',
  `doctor_id` int(11) NOT NULL COMMENT 'DPJP dari user_rh',
  `assistant_id` int(11) DEFAULT NULL COMMENT 'Asisten dari user_rh',
  `operasi_type` enum('major','minor') NOT NULL COMMENT 'mayor/minor',
  `created_by` int(11) NOT NULL COMMENT 'User yang input data',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient_name` (`patient_name`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_doctor_id` (`doctor_id`),
  KEY `idx_assistant_id` (`assistant_id`),
  CONSTRAINT `fk_mr_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `user_rh` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_mr_assistant` FOREIGN KEY (`assistant_id`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Step 2: Insert sample data (optional for testing)
-- INSERT INTO `medical_records` (...) VALUES (...);
```

---

## 4. UI/UX Design

### 4.1 Layout Structure

```
┌─────────────────────────────────────────────────────────────┐
│  HEADER (existing)                                          │
├──────────┬──────────────────────────────────────────────────┤
│ SIDEBAR  │  CONTENT AREA                                    │
│          │  ┌────────────────────────────────────────────┐  │
│ [Menu]   │  │ Page Title: Rekam Medis                    │  │
│          │  │ Subtitle: Pencatatan rekam medis pasien    │  │
│          │  └────────────────────────────────────────────┘  │
│          │                                                  │
│          │  ┌────────────────────────────────────────────┐  │
│          │  │ CARD: Data Pasien                          │  │
│          │  │ ┌──────────────────────────────────────┐   │  │
│          │  │ │ Form Fields (Grid 2 columns)         │   │  │
│          │  │ │ - Nama, Pekerjaan                    │   │  │
│          │  │ │ - Tanggal Lahir, No HP               │   │  │
│          │  │ │ - Jenis Kelamin, Alamat              │   │  │
│          │  │ │ - Status                             │   │  │
│          │  │ └──────────────────────────────────────┘   │  │
│          │  └────────────────────────────────────────────┘  │
│          │                                                  │
│          │  ┌────────────────────────────────────────────┐  │
│          │  │ CARD: Upload Dokumen                       │  │
│          │  │ ┌──────────────────┐ ┌──────────────────┐  │  │
│          │  │ │ KTP (Wajib)      │ │ Foto MRI (Ops)   │  │  │
│          │  │ │ [Preview]        │ │ [Preview]        │  │  │
│          │  │ │ [Upload Button]  │ │ [Upload Button]  │  │  │
│          │  │ └──────────────────┘ └──────────────────┘  │  │
│          │  └────────────────────────────────────────────┘  │
│          │                                                  │
│          │  ┌────────────────────────────────────────────┐  │
│          │  │ CARD: Hasil Rekam Medis                    │  │
│          │  │ ┌──────────────────────────────────────┐   │  │
│          │  │ │ [Rich Text Editor Toolbar]           │   │  │
│          │  │ │ ┌────────────────────────────────┐   │   │  │
│          │  │ │ │ Editor Content Area            │   │   │  │
│          │  │ │ │ (HTML WYSIWYG)                 │   │   │  │
│          │  │ │ └────────────────────────────────┘   │   │  │
│          │  │ └──────────────────────────────────────┘   │  │
│          │  └────────────────────────────────────────────┘  │
│          │                                                  │
│          │  ┌────────────────────────────────────────────┐  │
│          │  │ CARD: Tim Medis & Operasi                  │  │
│          │  │ - Dokter DPJP (Dropdown)                   │  │
│          │  │ - Asisten (Dropdown)                       │  │
│          │  │ - Jenis Operasi (Radio: Mayor/Minor)       │  │
│          │  └────────────────────────────────────────────┘  │
│          │                                                  │
│          │  ┌────────────────────────────────────────────┐  │
│          │  │ ACTION BUTTONS                             │  │
│          │  │ [Cancel]                    [Save Record]  │  │
│          │  └────────────────────────────────────────────┘  │
└──────────┴──────────────────────────────────────────────────┘
```

### 4.2 Component Specifications

#### Card Component (Existing)
```html
<div class="card card-section">
    <div class="card-header">Title</div>
    <div class="card-body">Content</div>
</div>
```

#### Form Input (Existing)
```html
<div class="form-group">
    <label class="form-label">Label</label>
    <input type="text" class="form-input" />
</div>
```

#### File Upload (Existing Pattern)
```html
<div class="file-upload-wrapper">
    <input type="file" id="ktp_file" accept="image/png,image/jpeg" hidden />
    <label for="ktp_file" class="file-upload-label">
        <div class="preview-container"></div>
        <span>Pilih File / Ambil Foto</span>
    </label>
</div>
```

---

## 5. Technical Implementation

### 5.1 File Structure

```
ems2/
├── dashboard/
│   ├── rekam_medis.php              # Main page (view + form)
│   └── rekam_medis_action.php       # Controller (process form)
├── ajax/
│   └── get_medical_records.php      # API endpoint for data table
├── storage/
│   └── medical_records/
│       ├── ktp/                     # KTP files
│       └── mri/                     # MRI files
└── docs/
    └── sql/
        └── medical_records_migration.sql
```

### 5.2 Technology Stack

| Component | Technology | Version |
|-----------|-----------|---------|
| Frontend Framework | Vanilla JS + Alpine.js | 3.15.8 |
| CSS Framework | Tailwind CSS | 3.4.17 |
| Rich Text Editor | Quill.js | Latest (CDN) |
| Image Compression | PHP GD | Built-in |
| DataTables | DataTables.net | 2.3.7 |
| Icons | Heroicons | SVG |

### 5.3 Image Compression Function (Reuse Existing)

File: `auth/register_process.php` (existing)
```php
function compressImageSmart(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1200,
    int $targetSize = 300000,
    int $minQuality = 70
): bool {
    // ... existing implementation
}
```

**Action:** Copy function to `config/helpers.php` untuk reusability.

### 5.4 Rich Text Editor Configuration

**Option 1: Quill.js (Recommended)**
- Lightweight (~50KB)
- Easy integration
- HTML output support
- Custom toolbar

**Option 2: TinyMCE**
- Feature-rich
- Larger bundle size
- Cloud dependency (optional)

**Decision:** Use Quill.js untuk konsistensi dengan lightweight approach.

---

## 6. Implementation Plan

### Phase 1: Database Setup (Day 1)
- [ ] Create migration SQL file
- [ ] Run migration on development database
- [ ] Create storage directories
- [ ] Test foreign key constraints

### Phase 2: Backend Controller (Day 2)
- [ ] Create `rekam_medis_action.php`
- [ ] Implement CSRF protection
- [ ] Implement form validation
- [ ] Implement file upload with compression
- [ ] Implement database insert
- [ ] Implement error handling
- [ ] Implement success redirect

### Phase 3: Frontend UI (Day 3-4)
- [ ] Create `rekam_medis.php`
- [ ] Implement form layout with Tailwind
- [ ] Integrate Quill.js editor
- [ ] Implement file upload preview
- [ ] Implement doctor/assistant dropdown (AJAX load)
- [ ] Implement form validation (client-side)
- [ ] Add loading states
- [ ] Test responsive design

### Phase 4: Testing & QA (Day 5)
- [ ] Unit test controller functions
- [ ] Integration test form submission
- [ ] Test file upload (various sizes)
- [ ] Test HTML sanitization
- [ ] Test responsive UI
- [ ] Cross-browser testing
- [ ] Performance testing

### Phase 5: Documentation & Deployment (Day 6)
- [ ] Update user documentation
- [ ] Create deployment checklist
- [ ] Backup database
- [ ] Deploy to production
- [ ] Monitor error logs
- [ ] User training (if needed)

---

## 7. Code Templates

### 7.1 Controller Template

File: `dashboard/rekam_medis_action.php`

```php
<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: rekam_medis.php');
    exit;
}

try {
    // Validation
    $patientName = trim($_POST['patient_name'] ?? '');
    // ... validate all fields
    
    if ($patientName === '') {
        throw new Exception('Nama pasien wajib diisi.');
    }
    
    // File Upload KTP (Wajib)
    $ktpPath = null;
    if (isset($_FILES['ktp_file']) && $_FILES['ktp_file']['error'] === UPLOAD_ERR_OK) {
        $ktpPath = uploadAndCompressFile($_FILES['ktp_file'], 'medical_records/ktp');
    } else {
        throw new Exception('Upload KTP wajib dilakukan.');
    }
    
    // File Upload MRI (Opsional)
    $mriPath = null;
    if (isset($_FILES['mri_file']) && $_FILES['mri_file']['error'] === UPLOAD_ERR_OK) {
        $mriPath = uploadAndCompressFile($_FILES['mri_file'], 'medical_records/mri', 500000);
    }
    
    // Insert to database
    $stmt = $pdo->prepare("
        INSERT INTO medical_records 
        (patient_name, patient_occupation, patient_dob, patient_phone, 
         patient_gender, patient_address, patient_status, ktp_file_path, 
         mri_file_path, medical_result_html, doctor_id, assistant_id, 
         operasi_type, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $patientName,
        $_POST['patient_occupation'] ?? 'Civilian',
        $_POST['patient_dob'] ?? null,
        $_POST['patient_phone'] ?? null,
        $_POST['patient_gender'] ?? null,
        $_POST['patient_address'] ?? 'INDONESIA',
        $_POST['patient_status'] ?? null,
        $ktpPath,
        $mriPath,
        $_POST['medical_result_html'] ?? '',
        $_POST['doctor_id'] ?? null,
        $_POST['assistant_id'] ?? null,
        $_POST['operasi_type'] ?? 'minor',
        $userId
    ]);
    
    $_SESSION['flash_messages'][] = 'Rekam medis berhasil disimpan.';
    header('Location: rekam_medis.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
    header('Location: rekam_medis.php');
    exit;
}
```

### 7.2 View Template

File: `dashboard/rekam_medis.php`

```php
<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Rekam Medis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];

// Get doctors (DPJP - min co_asst)
$doctors = $pdo->query("
    SELECT id, full_name, position 
    FROM user_rh 
    WHERE position IN ('co_asst', 'general_practitioner', 'specialist', '(Co.Ast)', 'Dokter Umum', 'Dokter Spesialis')
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get assistants (min paramedic)
$assistants = $pdo->query("
    SELECT id, full_name, position 
    FROM user_rh 
    WHERE position IN ('paramedic', 'co_asst', 'general_practitioner', 'specialist')
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Rekam Medis</h1>
        <p class="page-subtitle">Pencatatan rekam medis pasien</p>

        <!-- Flash Messages -->
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="rekam_medis_action.php" enctype="multipart/form-data" x-data="medicalForm()">
            <?= csrf_field() ?>
            
            <!-- Data Pasien -->
            <div class="card card-section">
                <div class="card-header">Data Pasien</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Nama -->
                    <div class="form-group">
                        <label class="form-label">Nama <span class="text-danger">*</span></label>
                        <input type="text" name="patient_name" class="form-input" required />
                    </div>
                    
                    <!-- Pekerjaan -->
                    <div class="form-group">
                        <label class="form-label">Pekerjaan</label>
                        <input type="text" name="patient_occupation" class="form-input" value="Civilian" />
                    </div>
                    
                    <!-- Tanggal Lahir -->
                    <div class="form-group">
                        <label class="form-label">Tanggal Lahir <span class="text-danger">*</span></label>
                        <input type="date" name="patient_dob" class="form-input" required />
                    </div>
                    
                    <!-- No HP -->
                    <div class="form-group">
                        <label class="form-label">No HP</label>
                        <input type="text" name="patient_phone" class="form-input" />
                    </div>
                    
                    <!-- Jenis Kelamin -->
                    <div class="form-group">
                        <label class="form-label">Jenis Kelamin <span class="text-danger">*</span></label>
                        <select name="patient_gender" class="form-input" required>
                            <option value="">Pilih</option>
                            <option value="Laki-laki">Laki-laki</option>
                            <option value="Perempuan">Perempuan</option>
                        </select>
                    </div>
                    
                    <!-- Alamat -->
                    <div class="form-group">
                        <label class="form-label">Alamat</label>
                        <input type="text" name="patient_address" class="form-input" value="INDONESIA" />
                    </div>
                    
                    <!-- Status -->
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <input type="text" name="patient_status" class="form-input" />
                    </div>
                </div>
            </div>

            <!-- Upload Dokumen -->
            <div class="card card-section">
                <div class="card-header">Upload Dokumen</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- KTP -->
                    <div>
                        <label class="form-label">KTP <span class="text-danger">*</span></label>
                        <div class="file-upload-wrapper">
                            <input type="file" id="ktp_file" name="ktp_file" accept="image/png,image/jpeg" hidden required />
                            <label for="ktp_file" class="file-upload-label">
                                <div class="preview-container" id="ktp_preview"></div>
                                <span>Pilih File / Ambil Foto</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- MRI -->
                    <div>
                        <label class="form-label">Foto MRI (Opsional)</label>
                        <div class="file-upload-wrapper">
                            <input type="file" id="mri_file" name="mri_file" accept="image/png,image/jpeg" hidden />
                            <label for="mri_file" class="file-upload-label">
                                <div class="preview-container" id="mri_preview"></div>
                                <span>Pilih File / Ambil Foto</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hasil Rekam Medis -->
            <div class="card card-section">
                <div class="card-header">Hasil Rekam Medis</div>
                <div id="editor-container" class="min-h-[300px]"></div>
                <textarea name="medical_result_html" id="medical_result_html" hidden></textarea>
            </div>

            <!-- Tim Medis & Operasi -->
            <div class="card card-section">
                <div class="card-header">Tim Medis & Operasi</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Dokter DPJP -->
                    <div class="form-group">
                        <label class="form-label">Dokter DPJP <span class="text-danger">*</span></label>
                        <select name="doctor_id" class="form-input" required>
                            <option value="">Pilih Dokter</option>
                            <?php foreach ($doctors as $doctor): ?>
                                <option value="<?= $doctor['id'] ?>">
                                    <?= htmlspecialchars($doctor['full_name']) ?> (<?= htmlspecialchars($doctor['position']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Asisten -->
                    <div class="form-group">
                        <label class="form-label">Asisten</label>
                        <select name="assistant_id" class="form-input">
                            <option value="">Pilih Asisten (Opsional)</option>
                            <?php foreach ($assistants as $assistant): ?>
                                <option value="<?= $assistant['id'] ?>">
                                    <?= htmlspecialchars($assistant['full_name']) ?> (<?= htmlspecialchars($assistant['position']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Jenis Operasi -->
                    <div class="form-group md:col-span-2">
                        <label class="form-label">Jenis Operasi <span class="text-danger">*</span></label>
                        <div class="flex gap-4">
                            <label class="radio-label">
                                <input type="radio" name="operasi_type" value="minor" checked />
                                <span>Minor (Kecil)</span>
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="operasi_type" value="major" />
                                <span>Mayor (Besar)</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-end gap-3 mt-6">
                <a href="index.php" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">Simpan Rekam Medis</button>
            </div>
        </form>
    </div>
</section>

<!-- Quill.js CDN -->
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<script>
// File upload preview
function medicalForm() {
    return {
        init() {
            // KTP preview
            document.getElementById('ktp_file').addEventListener('change', (e) => {
                this.previewImage(e, 'ktp_preview');
            });
            
            // MRI preview
            document.getElementById('mri_file').addEventListener('change', (e) => {
                this.previewImage(e, 'mri_preview');
            });
        },
        
        previewImage(event, previewId) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    document.getElementById(previewId).innerHTML = 
                        `<img src="${e.target.result}" class="max-h-48 rounded" />`;
                };
                reader.readAsDataURL(file);
            }
        }
    }
}

// Quill Editor
var quill = new Quill('#editor-container', {
    theme: 'snow',
    modules: {
        toolbar: [
            [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            [{ 'align': [] }],
            ['link'],
            ['clean']
        ]
    }
});

// Sync content to textarea before submit
document.querySelector('form').addEventListener('submit', function() {
    document.getElementById('medical_result_html').value = quill.root.innerHTML;
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
```

---

## 8. Testing Checklist

### 8.1 Functional Testing
- [ ] Form validation (required fields)
- [ ] File upload KTP (wajib)
- [ ] File upload MRI (opsional)
- [ ] File compression working
- [ ] HTML content saved correctly
- [ ] Doctor/assistant selection working
- [ ] Operasi type selection working
- [ ] Database insert successful
- [ ] Success message displayed
- [ ] Error handling working

### 8.2 UI Testing
- [ ] Responsive design (mobile, tablet, desktop)
- [ ] Form layout consistent
- [ ] File preview working
- [ ] Editor toolbar functional
- [ ] Loading states visible
- [ ] Error messages clear

### 8.3 Security Testing
- [ ] CSRF token validation
- [ ] Session validation
- [ ] File type validation
- [ ] HTML sanitization (XSS prevention)
- [ ] SQL injection prevention
- [ ] File size limits enforced

---

## 9. Deployment Checklist

- [ ] Backup database
- [ ] Run migration SQL
- [ ] Create storage directories
- [ ] Set directory permissions (755)
- [ ] Deploy PHP files
- [ ] Clear cache
- [ ] Test on staging
- [ ] Test on production
- [ ] Monitor error logs
- [ ] Update documentation

---

## 10. Appendix

### 10.1 References
- UI Design System: `docs/ui-design-system.md`
- Database Schema: `hark8423_ems (21).sql`
- Image Compression: `auth/register_process.php`
- Form Pattern: `dashboard/surat_menyurat.php`
- File Upload Pattern: `dashboard/setting_akun.php`

### 10.2 Glossary
| Term | Definition |
|------|------------|
| DPJP | Dokter Penanggung Jawab Pasien |
| Co.Ast | Co-Assistant (Dokter Muda) |
| HTML Rich-Text | Text dengan formatting HTML |
| CSRF | Cross-Site Request Forgery |

---

**END OF DOCUMENT**
