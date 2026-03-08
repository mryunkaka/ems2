# Implementation Plan: Rekam Medis

**Dokumen ini adalah panduan step-by-step untuk mengimplementasikan fitur Rekam Medis.**

Dibuat untuk memudahkan AI lain melanjutkan jika dokumentasi sebelumnya terkena limit.

---

## 📋 Daftar Isi

1. [Overview](#overview)
2. [Pre-requisites](#pre-requisites)
3. [Step 1: Database Setup](#step-1-database-setup)
4. [Step 2: Create Storage Directories](#step-2-create-storage-directories)
5. [Step 3: Helper Function](#step-3-helper-function)
6. [Step 4: Controller](#step-4-controller)
7. [Step 5: View](#step-5-view)
8. [Step 6: Testing](#step-6-testing)
9. [Step 7: Deployment](#step-7-deployment)
10. [Troubleshooting](#troubleshooting)

---

## Overview

### Yang Akan Dibuat

```
ems2/
├── dashboard/
│   ├── rekam_medis.php              ← Halaman form input
│   └── rekam_medis_action.php       ← Controller untuk process form
├── storage/
│   └── medical_records/
│       ├── ktp/                     ← Folder untuk file KTP
│       └── mri/                     ← Folder untuk file MRI
└── docs/
    └── sql/
        └── medical_records_migration.sql  ← SQL migration
```

### Fitur Utama

1. ✅ Input data pasien (Nama, Pekerjaan, Tgl Lahir, No HP, JK, Alamat, Status)
2. ✅ Upload KTP (WAJIB) dengan kompresi otomatis
3. ✅ Upload MRI (OPSIONAL) dengan kompresi otomatis
4. ✅ Editor HTML rich-text untuk hasil rekam medis
5. ✅ Dropdown dokter DPJP (filter: co_asst ke atas)
6. ✅ Dropdown asisten (filter: paramedic ke atas)
7. ✅ Pilihan operasi mayor/minor
8. ✅ UI konsisten dengan design system EMS

---

## Pre-requisites

### Yang Harus Dibaca Sebelumnya

1. **Database Schema**: `hark8423_ems (21).sql` - Tabel `user_rh`
2. **UI Design**: `docs/ui-design-system.md`
3. **Image Compression**: `auth/register_process.php` - function `compressImageSmart()`
4. **Form Pattern**: `dashboard/surat_menyurat.php`
5. **File Upload Pattern**: `dashboard/setting_akun.php`

### Library yang Sudah Terinstall

- ✅ Tailwind CSS 3.4.17
- ✅ Alpine.js 3.15.8
- ✅ DataTables.net 2.3.7
- ✅ PHP GD (built-in)
- ✅ jQuery 4.0.0

### Library yang Perlu Ditambahkan (CDN)

- ✅ Quill.js 1.3.6 (Rich Text Editor)

---

## Step 1: Database Setup

### 1.1 Run Migration SQL

File: `docs/sql/medical_records_migration.sql`

```bash
# Via phpMyAdmin atau CLI MySQL
mysql -u username -p database_name < docs/sql/medical_records_migration.sql
```

### 1.2 Verify Table

```sql
DESCRIBE medical_records;
```

Expected output:
```
+--------------------+--------------+------+-----+-------------------+
| Field              | Type         | Null | Key | Default           |
+--------------------+--------------+------+-----+-------------------+
| id                 | int(11)      | NO   | PRI | NULL              |
| patient_name       | varchar(100) | NO   |     | NULL              |
| patient_occupation | varchar(50)  | YES  |     | Civilian          |
| patient_dob        | date         | NO   |     | NULL              |
| patient_phone      | varchar(20)  | YES  |     | NULL              |
| patient_gender     | enum         | NO   |     | NULL              |
| patient_address    | varchar(255) | YES  |     | INDONESIA         |
| patient_status     | varchar(50)  | YES  |     | NULL              |
| ktp_file_path      | varchar(255) | NO   |     | NULL              |
| mri_file_path      | varchar(255) | YES  |     | NULL              |
| medical_result_html| text         | NO   |     | NULL              |
| doctor_id          | int(11)      | NO   |     | NULL              |
| assistant_id       | int(11)      | YES  |     | NULL              |
| operasi_type       | enum         | NO   |     | NULL              |
| created_by         | int(11)      | NO   |     | NULL              |
| created_at         | datetime     | YES  |     | CURRENT_TIMESTAMP |
| updated_at         | datetime     | YES  |     | CURRENT_TIMESTAMP |
+--------------------+--------------+------+-----+-------------------+
```

---

## Step 2: Create Storage Directories

### 2.1 Create Folders

```bash
cd d:\Project\Web\ems2
mkdir storage\medical_records
mkdir storage\medical_records\ktp
mkdir storage\medical_records\mri
```

### 2.2 Set Permissions (Linux only)

```bash
chmod 755 storage/medical_records
chmod 755 storage/medical_records/ktp
chmod 755 storage/medical_records/mri
```

**Note:** Windows tidak perlu set permissions.

---

## Step 3: Helper Function

### 3.1 Copy Image Compression Function

File: `config/helpers.php` (atau buat baru jika tidak ada)

Tambahkan function ini di bagian bawah file:

```php
/**
 * Compress image smart - reduce file size while maintaining quality
 * 
 * @param string $sourcePath Path to source image
 * @param string $targetPath Path to save compressed image
 * @param int $maxWidth Maximum width (default 1200px)
 * @param int $targetSize Target file size in bytes (default 300KB)
 * @param int $minQuality Minimum quality (default 70)
 * @return bool Success or failure
 */
function compressImageSmart(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1200,
    int $targetSize = 300000,
    int $minQuality = 70
): bool {
    $info = getimagesize($sourcePath);
    if (!$info) return false;

    $mime = $info['mime'];
    if ($mime === 'image/jpeg') {
        $src = imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $src = imagecreatefrompng($sourcePath);
    } else {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    // Resize if width exceeds max
    if ($w > $maxWidth) {
        $ratio = $maxWidth / $w;
        $nw = $maxWidth;
        $nh = (int)($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
    } else {
        $dst = $src;
    }

    // Compress
    if ($mime === 'image/png') {
        imagepng($dst, $targetPath, 7);
    } else {
        for ($q = 90; $q >= $minQuality; $q -= 5) {
            imagejpeg($dst, $targetPath, $q);
            if (filesize($targetPath) <= $targetSize) break;
        }
    }

    imagedestroy($dst);
    return true;
}

/**
 * Upload and compress file helper
 * 
 * @param array $file $_FILES array element
 * @param string $folder Folder name under storage/
 * @param int $maxSize Max file size in bytes
 * @return string|null File path on success, null on failure
 */
function uploadAndCompressFile(array $file, string $folder, int $maxSize = 300000): ?string
{
    // Validate error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png'];
    $info = getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], $allowedTypes, true)) {
        return null;
    }

    // Validate file size
    if ($file['size'] > 5000000) { // 5MB max upload
        return null;
    }

    // Create folder path
    $baseDir = __DIR__ . '/../storage/' . $folder;
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }

    // Generate filename
    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $targetPath = $baseDir . '/' . $filename;

    // Compress and save
    if (compressImageSmart($file['tmp_name'], $targetPath, 1200, $maxSize, 70)) {
        return 'storage/' . $folder . '/' . $filename;
    }

    return null;
}
```

---

## Step 4: Controller

### 4.1 Create Controller File

File: `dashboard/rekam_medis_action.php`

```php
<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

// Validate CSRF token
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

// Get user from session
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: rekam_medis.php');
    exit;
}

try {
    // =====================
    // 1. VALIDATION
    // =====================
    
    $patientName = trim($_POST['patient_name'] ?? '');
    $patientDob = $_POST['patient_dob'] ?? '';
    $patientGender = $_POST['patient_gender'] ?? '';
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $operasiType = $_POST['operasi_type'] ?? 'minor';
    
    // Required fields validation
    if ($patientName === '') {
        throw new Exception('Nama pasien wajib diisi.');
    }
    
    if (empty($patientDob)) {
        throw new Exception('Tanggal lahir pasien wajib diisi.');
    }
    
    if (empty($patientGender)) {
        throw new Exception('Jenis kelamin pasien wajib dipilih.');
    }
    
    if ($doctorId <= 0) {
        throw new Exception('Dokter DPJP wajib dipilih.');
    }
    
    if (!in_array($operasiType, ['major', 'minor'])) {
        throw new Exception('Jenis operasi tidak valid.');
    }
    
    // =====================
    // 2. FILE UPLOAD KTP (WAJIB)
    // =====================
    
    $ktpPath = null;
    if (isset($_FILES['ktp_file']) && $_FILES['ktp_file']['error'] === UPLOAD_ERR_OK) {
        $ktpPath = uploadAndCompressFile($_FILES['ktp_file'], 'medical_records/ktp', 300000);
        if (!$ktpPath) {
            throw new Exception('Gagal upload KTP. Pastikan file berupa gambar JPG/PNG dan ukuran tidak melebihi 5MB.');
        }
    } else {
        throw new Exception('Upload KTP wajib dilakukan.');
    }
    
    // =====================
    // 3. FILE UPLOAD MRI (OPSIONAL)
    // =====================
    
    $mriPath = null;
    if (isset($_FILES['mri_file']) && $_FILES['mri_file']['error'] === UPLOAD_ERR_OK) {
        $mriPath = uploadAndCompressFile($_FILES['mri_file'], 'medical_records/mri', 500000);
        // MRI optional, jadi jika gagal upload tetap lanjut
    }
    
    // =====================
    // 4. HTML SANITIZATION
    // =====================
    
    $medicalResultHtml = $_POST['medical_result_html'] ?? '';
    // Basic XSS prevention - strip script tags
    $medicalResultHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $medicalResultHtml);
    
    // =====================
    // 5. INSERT TO DATABASE
    // =====================
    
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
        trim($_POST['patient_occupation'] ?? 'Civilian'),
        $patientDob,
        trim($_POST['patient_phone'] ?? null),
        $patientGender,
        trim($_POST['patient_address'] ?? 'INDONESIA'),
        trim($_POST['patient_status'] ?? null),
        $ktpPath,
        $mriPath,
        $medicalResultHtml,
        $doctorId,
        (int)($_POST['assistant_id'] ?? null),
        $operasiType,
        $userId
    ]);
    
    // =====================
    // 6. SUCCESS
    // =====================
    
    $_SESSION['flash_messages'][] = 'Rekam medis berhasil disimpan.';
    header('Location: rekam_medis.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
    header('Location: rekam_medis.php');
    exit;
}
```

---

## Step 5: View

### 5.1 Create View File

File: `dashboard/rekam_medis.php`

**CATATAN:** File ini panjang, jadi saya buat dalam beberapa bagian.

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

// Get doctors (DPJP - min co_asst ke atas)
$doctors = $pdo->query("
    SELECT id, full_name, position 
    FROM user_rh 
    WHERE position IN ('co_asst', 'general_practitioner', 'specialist', '(Co.Ast)', 'Dokter Umum', 'Dokter Spesialis')
    AND is_active = 1
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get assistants (min paramedic ke atas)
$assistants = $pdo->query("
    SELECT id, full_name, position 
    FROM user_rh 
    WHERE position IN ('paramedic', 'co_asst', 'general_practitioner', 'specialist')
    AND is_active = 1
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
            
            <!-- CARD 1: DATA PASIEN -->
            <div class="card card-section mb-4">
                <div class="card-header">Data Pasien</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Nama -->
                        <div class="form-group">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" name="patient_name" class="form-input" 
                                   placeholder="Nama lengkap pasien" required />
                        </div>
                        
                        <!-- Pekerjaan -->
                        <div class="form-group">
                            <label class="form-label">Pekerjaan</label>
                            <input type="text" name="patient_occupation" class="form-input" 
                                   value="Civilian" placeholder="Pekerjaan pasien" />
                        </div>
                        
                        <!-- Tanggal Lahir -->
                        <div class="form-group">
                            <label class="form-label">Tanggal Lahir <span class="text-danger">*</span></label>
                            <input type="date" name="patient_dob" class="form-input" required />
                        </div>
                        
                        <!-- No HP -->
                        <div class="form-group">
                            <label class="form-label">No HP</label>
                            <input type="text" name="patient_phone" class="form-input" 
                                   placeholder="Nomor HP pasien" />
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
                            <input type="text" name="patient_address" class="form-input" 
                                   value="INDONESIA" placeholder="Alamat pasien" />
                        </div>
                        
                        <!-- Status -->
                        <div class="form-group md:col-span-2">
                            <label class="form-label">Status</label>
                            <input type="text" name="patient_status" class="form-input" 
                                   placeholder="Status pasien (opsional)" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 2: UPLOAD DOKUMEN -->
            <div class="card card-section mb-4">
                <div class="card-header">Upload Dokumen</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- KTP (WAJIB) -->
                        <div>
                            <label class="form-label">KTP <span class="text-danger">*</span></label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="ktp_file" name="ktp_file" 
                                       accept="image/png,image/jpeg" hidden required 
                                       @change="previewImage($event, 'ktp_preview')" />
                                <label for="ktp_file" class="file-upload-label">
                                    <div class="preview-container h-48 flex items-center justify-center bg-gray-50 rounded border border-gray-200" 
                                         id="ktp_preview">
                                        <span class="text-gray-400 text-sm">Belum ada file</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Pilih File / Ambil Foto</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, Max: 5MB (auto compress)</p>
                            </div>
                        </div>
                        
                        <!-- MRI (OPSIONAL) -->
                        <div>
                            <label class="form-label">Foto MRI (Opsional)</label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="mri_file" name="mri_file" 
                                       accept="image/png,image/jpeg" hidden 
                                       @change="previewImage($event, 'mri_preview')" />
                                <label for="mri_file" class="file-upload-label">
                                    <div class="preview-container h-48 flex items-center justify-center bg-gray-50 rounded border border-gray-200" 
                                         id="mri_preview">
                                        <span class="text-gray-400 text-sm">Belum ada file</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Pilih File / Ambil Foto</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, Max: 5MB (auto compress)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 3: HASIL REKAM MEDIS (HTML EDITOR) -->
            <div class="card card-section mb-4">
                <div class="card-header">Hasil Rekam Medis</div>
                <div class="card-body">
                    <p class="text-sm text-gray-600 mb-2">
                        Gunakan editor di bawah untuk membuat hasil rekam medis dengan format HTML 
                        (bisa membuat judul, poin-poin, bold, italic, dll).
                    </p>
                    <div id="editor-container" class="min-h-[300px]"></div>
                    <textarea name="medical_result_html" id="medical_result_html" hidden></textarea>
                </div>
            </div>

            <!-- CARD 4: TIM MEDIS & OPERASI -->
            <div class="card card-section mb-4">
                <div class="card-header">Tim Medis & Operasi</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Dokter DPJP -->
                        <div class="form-group">
                            <label class="form-label">Dokter DPJP <span class="text-danger">*</span></label>
                            <select name="doctor_id" class="form-input" required>
                                <option value="">Pilih Dokter</option>
                                <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?= $doctor['id'] ?>">
                                        <?= htmlspecialchars($doctor['full_name']) ?> 
                                        (<?= htmlspecialchars($doctor['position']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Minimal jabatan: Co.Ast ke atas</p>
                        </div>
                        
                        <!-- Asisten -->
                        <div class="form-group">
                            <label class="form-label">Asisten</label>
                            <select name="assistant_id" class="form-input">
                                <option value="">Pilih Asisten (Opsional)</option>
                                <?php foreach ($assistants as $assistant): ?>
                                    <option value="<?= $assistant['id'] ?>">
                                        <?= htmlspecialchars($assistant['full_name']) ?> 
                                        (<?= htmlspecialchars($assistant['position']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Minimal jabatan: Paramedic ke atas</p>
                        </div>
                        
                        <!-- Jenis Operasi -->
                        <div class="form-group md:col-span-2">
                            <label class="form-label">Jenis Operasi <span class="text-danger">*</span></label>
                            <div class="flex gap-4">
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" value="minor" checked 
                                           class="w-4 h-4 text-primary" />
                                    <span>Minor (Operasi Kecil)</span>
                                </label>
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" value="major" 
                                           class="w-4 h-4 text-primary" />
                                    <span>Mayor (Operasi Besar)</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACTION BUTTONS -->
            <div class="flex justify-end gap-3 mt-6">
                <a href="index.php" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">
                    <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                    </svg>
                    Simpan Rekam Medis
                </button>
            </div>
        </form>
    </div>
</section>

<!-- Quill.js CDN -->
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<script>
// Alpine.js component untuk form handling
function medicalForm() {
    return {
        init() {
            // Initialize file upload previews
            console.log('Medical form initialized');
        },
        
        previewImage(event, previewId) {
            const file = event.target.files[0];
            if (file) {
                // Validate file type
                if (!file.type.startsWith('image/')) {
                    alert('File harus berupa gambar (JPG/PNG)');
                    event.target.value = '';
                    return;
                }
                
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert('Ukuran file maksimal 5MB');
                    event.target.value = '';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = (e) => {
                    const previewEl = document.getElementById(previewId);
                    previewEl.innerHTML = 
                        `<img src="${e.target.result}" class="max-h-full max-w-full rounded object-contain" />`;
                };
                reader.readAsDataURL(file);
            }
        }
    }
}

// Initialize Quill Editor
var quill = new Quill('#editor-container', {
    theme: 'snow',
    placeholder: 'Tulis hasil rekam medis di sini...',
    modules: {
        toolbar: [
            [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            [{ 'align': [] }],
            [{ 'color': [] }, { 'background': [] }],
            ['link'],
            ['clean']
        ]
    }
});

// Sync content to textarea before form submit
document.querySelector('form').addEventListener('submit', function() {
    const htmlContent = quill.root.innerHTML;
    document.getElementById('medical_result_html').value = htmlContent;
    
    // Validate not empty
    if (htmlContent === '<p><br></p>' || htmlContent.trim() === '') {
        alert('Hasil rekam medis wajib diisi!');
        event.preventDefault();
        return false;
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
```

---

## Step 6: Testing

### 6.1 Functional Testing Checklist

```
[ ] 1. Form validation
    [ ] Nama wajib diisi
    [ ] Tanggal lahir wajib diisi
    [ ] Jenis kelamin wajib dipilih
    [ ] KTP wajib upload
    [ ] Dokter DPJP wajib dipilih
    
[ ] 2. File upload
    [ ] Upload KTP (JPG)
    [ ] Upload KTP (PNG)
    [ ] Upload MRI (opsional)
    [ ] File compression working
    [ ] File size validation (max 5MB)
    [ ] Preview image working
    
[ ] 3. HTML Editor
    [ ] Bold text
    [ ] Italic text
    [ ] Heading (H1-H6)
    [ ] Bullet list
    [ ] Numbered list
    [ ] Link insertion
    [ ] Content saved to database
    
[ ] 4. Dropdown
    [ ] Dokter DPJP filter correct (co_asst+)
    [ ] Asisten filter correct (paramedic+)
    
[ ] 5. Database
    [ ] Record inserted successfully
    [ ] All fields saved correctly
    [ ] Foreign keys working
    
[ ] 6. UI/UX
    [ ] Responsive (mobile, tablet, desktop)
    [ ] Loading states
    [ ] Error messages clear
    [ ] Success message displayed
```

### 6.2 Manual Testing Steps

1. **Test Form Submission**
   ```
   1. Buka halaman: http://localhost/ems2/dashboard/rekam_medis.php
   2. Isi semua field wajib
   3. Upload KTP (file JPG/PNG)
   4. Upload MRI (opsional)
   5. Isi hasil rekam medis di editor
   6. Pilih dokter dan asisten
   7. Klik "Simpan Rekam Medis"
   8. Cek flash message sukses
   9. Cek database: SELECT * FROM medical_records ORDER BY id DESC LIMIT 1;
   ```

2. **Test Validation**
   ```
   1. Kosongkan field nama
   2. Klik submit
   3. Pastikan error muncul: "Nama pasien wajib diisi"
   4. Ulangi untuk field wajib lainnya
   ```

3. **Test File Upload**
   ```
   1. Upload file > 5MB
   2. Pastikan error muncul
   3. Upload file bukan gambar (.txt)
   4. Pastikan error muncul
   ```

---

## Step 7: Deployment

### 7.1 Pre-Deployment Checklist

```
[ ] Backup database
[ ] Test on staging environment
[ ] All tests passed
[ ] Storage directories created
[ ] Permissions set correctly
```

### 7.2 Deployment Steps

1. **Backup Database**
   ```bash
   mysqldump -u username -p database_name > backup_before_medical_records.sql
   ```

2. **Run Migration**
   ```bash
   mysql -u username -p database_name < docs/sql/medical_records_migration.sql
   ```

3. **Create Directories**
   ```bash
   mkdir storage/medical_records
   mkdir storage/medical_records/ktp
   mkdir storage/medical_records/mri
   ```

4. **Deploy Files**
   ```
   Upload files:
   - dashboard/rekam_medis.php
   - dashboard/rekam_medis_action.php
   - config/helpers.php (updated with compression functions)
   ```

5. **Verify Deployment**
   ```
   1. Open: https://yourdomain.com/dashboard/rekam_medis.php
   2. Test submit form
   3. Check error logs
   4. Verify file uploads
   ```

---

## Troubleshooting

### Error: "Session tidak valid"

**Cause:** User not logged in or session expired.

**Solution:**
```php
// Make sure auth_guard.php is included at top of file
require_once __DIR__ . '/../auth/auth_guard.php';
```

### Error: "Upload KTP wajib dilakukan"

**Cause:** File not uploaded or upload error.

**Solution:**
1. Check `php.ini` settings:
   ```ini
   upload_max_filesize = 10M
   post_max_size = 10M
   ```
2. Check form has `enctype="multipart/form-data"`
3. Check file input name matches: `name="ktp_file"`

### Error: "Gagal upload KTP"

**Cause:** Compression function failed or directory not writable.

**Solution:**
```bash
# Check directory permissions
chmod 755 storage/medical_records
chmod 755 storage/medical_records/ktp

# Or on Windows, ensure folder exists and is writable
```

### Error: Table 'medical_records' doesn't exist

**Cause:** Migration not run.

**Solution:**
```bash
mysql -u username -p database_name < docs/sql/medical_records_migration.sql
```

### Error: Quill editor not loading

**Cause:** CDN not accessible or script load order wrong.

**Solution:**
1. Check internet connection
2. Move Quill script to bottom of page (before footer)
3. Check browser console for errors

### Error: CSRF token invalid

**Cause:** Token mismatch or expired.

**Solution:**
```php
// Make sure csrf_field() is inside form
<?= csrf_field() ?>

// And validate in controller
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    exit('Invalid CSRF token');
}
```

---

## Contact & Support

Jika ada pertanyaan atau issue:

1. **Check Documentation:**
   - `docs/prd-rekam-medis.md` - PRD lengkap
   - `docs/ui-design-system.md` - UI guidelines
   - `docs/sql/medical_records_migration.sql` - Database schema

2. **Check Existing Code:**
   - `dashboard/surat_menyurat.php` - Form pattern
   - `auth/register_process.php` - Image compression
   - `dashboard/setting_akun.php` - File upload pattern

3. **Debug Steps:**
   - Check PHP error logs
   - Check browser console
   - Check database queries
   - Check file permissions

---

**END OF IMPLEMENTATION PLAN**

Dibuat untuk memudahkan AI lain melanjutkan implementasi.
Semoga membantu! 🚀
