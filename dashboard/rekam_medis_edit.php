<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Edit Rekam Medis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$mode = trim($_GET['mode'] ?? 'standard');
$isForensicPrivate = ($mode === 'forensic_private');

if ($isForensicPrivate) {
    ems_require_division_access(['Forensic'], '/dashboard/index.php');
}

// Get record ID
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash_errors'][] = 'ID rekam medis tidak valid.';
    header('Location: ' . ($isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php'));
    exit;
}

// Get record
$stmt = $pdo->prepare("SELECT * FROM medical_records WHERE id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['flash_errors'][] = 'Rekam medis tidak ditemukan.';
    header('Location: ' . ($isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php'));
    exit;
}

$recordScope = $record['visibility_scope'] ?? 'standard';
if ($recordScope !== 'forensic_private' && (int) ($record['created_by'] ?? 0) !== (int) ($user['id'] ?? 0)) {
    $_SESSION['flash_errors'][] = 'Hanya pembuat rekam medis yang dapat mengedit data ini.';
    header('Location: rekam_medis_view.php?id=' . $id);
    exit;
}
if ($isForensicPrivate && $recordScope !== 'forensic_private') {
    $_SESSION['flash_errors'][] = 'Rekam medis private tidak ditemukan.';
    header('Location: forensic_medical_records_list.php');
    exit;
}

if (!$isForensicPrivate && $recordScope === 'forensic_private') {
    $_SESSION['flash_errors'][] = 'Akses rekam medis private ditolak.';
    header('Location: rekam_medis_list.php');
    exit;
}

$doctorName = '';
if (!empty($record['doctor_id'])) {
    $stmt = $pdo->prepare("SELECT full_name FROM user_rh WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$record['doctor_id']]);
    $doctorName = (string)($stmt->fetchColumn() ?: '');
}

$assistants = ems_get_medical_record_assistants($pdo, (int) $record['id'], isset($record['assistant_id']) ? (int) $record['assistant_id'] : null);
if ($assistants === []) {
    $assistants = [
        ['id' => 0, 'full_name' => '', 'position' => ''],
        ['id' => 0, 'full_name' => '', 'position' => ''],
    ];
}

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title"><?= $isForensicPrivate ? 'Edit Rekam Medis Private' : 'Edit Rekam Medis' ?></h1>
                <p class="page-subtitle">Edit data rekam medis pasien <?= htmlspecialchars($record['patient_name']) ?></p>
            </div>
            <a href="<?= $isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php' ?>" class="btn-secondary">
                <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Kembali
            </a>
        </div>

        <!-- Flash Messages -->
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="rekam_medis_edit_action.php" enctype="multipart/form-data" x-data="medicalForm()">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $record['id'] ?>" />
            <input type="hidden" name="visibility_scope" value="<?= $isForensicPrivate ? 'forensic_private' : 'standard' ?>" />
            <input type="hidden" name="redirect_to" value="<?= $isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php' ?>" />
            <input type="hidden" name="mode" value="<?= $isForensicPrivate ? 'forensic_private' : 'standard' ?>" />
            
            <!-- CARD 1: DATA PASIEN -->
            <div class="card card-section mb-4">
                <div class="card-header">Data Pasien</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Nama -->
                        <div class="form-group">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" name="patient_name" class="form-input" 
                                   value="<?= htmlspecialchars($record['patient_name']) ?>" required />
                        </div>

                        <div class="form-group">
                            <label class="form-label">Citizen ID <span class="text-danger">*</span></label>
                            <input type="text" name="patient_citizen_id" class="form-input" 
                                   value="<?= htmlspecialchars($record['patient_citizen_id'] ?? '') ?>" required />
                        </div>
                        
                        <!-- Pekerjaan -->
                        <div class="form-group">
                            <label class="form-label">Pekerjaan</label>
                            <input type="text" name="patient_occupation" class="form-input" 
                                   value="<?= htmlspecialchars($record['patient_occupation']) ?>" />
                        </div>
                        
                        <!-- Tanggal Lahir -->
                        <div class="form-group">
                            <label class="form-label">Tanggal Lahir <span class="text-danger">*</span></label>
                            <input type="date" name="patient_dob" class="form-input" 
                                   value="<?= htmlspecialchars($record['patient_dob']) ?>" 
                                   max="<?= date('Y-m-d') ?>" required />
                        </div>
                        
                        <!-- No HP -->
                        <div class="form-group">
                            <label class="form-label">No HP</label>
                            <input type="text" name="patient_phone" class="form-input" 
                                   value="<?= htmlspecialchars($record['patient_phone'] ?? '') ?>" />
                        </div>
                        
                        <!-- Jenis Kelamin -->
                        <div class="form-group">
                            <label class="form-label">Jenis Kelamin <span class="text-danger">*</span></label>
                            <select name="patient_gender" class="form-input" required>
                                <option value="">Pilih</option>
                                <option value="Laki-laki" <?= $record['patient_gender'] === 'Laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                                <option value="Perempuan" <?= $record['patient_gender'] === 'Perempuan' ? 'selected' : '' ?>>Perempuan</option>
                            </select>
                        </div>
                        
                        <!-- Alamat -->
                        <div class="form-group">
                            <label class="form-label">Alamat</label>
                            <input type="text" name="patient_address" class="form-input" 
                                   value="<?= htmlspecialchars($record['patient_address']) ?>" />
                        </div>
                        
                        <!-- Status -->
                        <div class="form-group md:col-span-2">
                            <label class="form-label">Status</label>
                            <input type="text" name="patient_status" class="form-input" 
                                   value="<?= htmlspecialchars($record['patient_status'] ?? '') ?>" />
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
                            <?php if ($record['ktp_file_path'] && file_exists(__DIR__ . '/../' . $record['ktp_file_path'])): ?>
                                <div class="mb-2">
                                    <img src="<?= htmlspecialchars(ems_asset($record['ktp_file_path'])) ?>"
                                         alt="KTP" class="max-h-48 rounded border" />
                                    <p class="text-xs text-gray-500 mt-1">File saat ini</p>
                                </div>
                            <?php elseif ($record['ktp_file_path']): ?>
                                <div class="mb-2 p-4 bg-yellow-50 border border-yellow-200 rounded">
                                    <p class="text-sm text-yellow-800">
                                        <strong>File tidak ditemukan:</strong> <?= htmlspecialchars($record['ktp_file_path']) ?>
                                    </p>
                                    <p class="text-xs text-yellow-600 mt-1">Silakan upload file baru</p>
                                </div>
                            <?php endif; ?>
                            <div class="file-upload-wrapper">
                                <input type="file" id="ktp_file" name="ktp_file" 
                                       accept="image/png,image/jpeg" hidden 
                                       @change="previewImage($event, 'ktp_preview')" />
                                <label for="ktp_file" class="file-upload-label">
                                    <div class="preview-container h-48 flex items-center justify-center bg-gray-50 rounded border border-gray-200" 
                                         id="ktp_preview">
                                        <span class="text-gray-400 text-sm">Klik untuk upload file baru</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Upload File Baru</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Kosongkan jika tidak ingin mengganti file</p>
                            </div>
                        </div>
                        
                        <!-- MRI (OPSIONAL) -->
                        <div>
                            <label class="form-label">Foto MRI (Opsional)</label>
                            <?php if ($record['mri_file_path'] && file_exists(__DIR__ . '/../' . $record['mri_file_path'])): ?>
                                <div class="mb-2">
                                    <img src="<?= htmlspecialchars(ems_asset($record['mri_file_path'])) ?>"
                                         alt="MRI" class="max-h-48 rounded border" />
                                    <p class="text-xs text-gray-500 mt-1">File saat ini</p>
                                </div>
                            <?php elseif ($record['mri_file_path']): ?>
                                <div class="mb-2 p-4 bg-yellow-50 border border-yellow-200 rounded">
                                    <p class="text-sm text-yellow-800">
                                        <strong>File tidak ditemukan:</strong> <?= htmlspecialchars($record['mri_file_path']) ?>
                                    </p>
                                    <p class="text-xs text-yellow-600 mt-1">Silakan upload file baru</p>
                                </div>
                            <?php endif; ?>
                            <div class="file-upload-wrapper">
                                <input type="file" id="mri_file" name="mri_file" 
                                       accept="image/png,image/jpeg" hidden 
                                       @change="previewImage($event, 'mri_preview')" />
                                <label for="mri_file" class="file-upload-label">
                                    <div class="preview-container h-48 flex items-center justify-center bg-gray-50 rounded border border-gray-200" 
                                         id="mri_preview">
                                        <span class="text-gray-400 text-sm">Klik untuk upload file baru</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Upload File Baru</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Kosongkan jika tidak ingin mengganti file</p>
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
                        Edit hasil rekam medis di bawah.
                    </p>
                    <div id="editor-container" class="min-h-[500px]"></div>
                    <textarea name="medical_result_html" id="medical_result_html" hidden><?= htmlspecialchars($record['medical_result_html']) ?></textarea>
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
                            <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="doctor" data-autocomplete-required>
                                <input type="text" class="form-input" data-user-autocomplete-input placeholder="Ketik nama dokter..." value="<?= htmlspecialchars($doctorName, ENT_QUOTES, 'UTF-8') ?>" required>
                                <input type="hidden" name="doctor_id" value="<?= (int)$record['doctor_id'] ?>" data-user-autocomplete-hidden>
                                <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Minimal jabatan: Co.Ast ke atas</p>
                        </div>
                        
                        <!-- Jenis Operasi -->
                        <div class="form-group">
                            <label class="form-label">Jenis Operasi <span class="text-danger">*</span></label>
                            <div class="flex gap-4 mt-2">
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" value="minor" 
                                           class="w-4 h-4 text-primary" 
                                           <?= $record['operasi_type'] === 'minor' ? 'checked' : '' ?> />
                                    <span>Minor (Kecil)</span>
                                </label>
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" value="major" 
                                           class="w-4 h-4 text-primary" 
                                           <?= $record['operasi_type'] === 'major' ? 'checked' : '' ?> />
                                    <span>Mayor (Besar)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="form-label">Asisten Operasi <span class="text-danger">*</span></label>
                        <div id="assistants-container">
                            <?php foreach ($assistants as $index => $assistant): ?>
                                <div class="assistant-row grid grid-cols-12 gap-2 mb-2">
                                    <div class="col-span-11">
                                        <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="assistant"<?= $index === 0 ? ' data-autocomplete-required' : '' ?>>
                                            <input
                                                type="text"
                                                class="form-input assistant-select"
                                                data-user-autocomplete-input
                                                placeholder="Ketik nama asisten <?= $index + 1 ?>..."
                                                value="<?= htmlspecialchars((string) ($assistant['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $index === 0 ? 'required' : '' ?>>
                                            <input
                                                type="hidden"
                                                name="assistant_ids[]"
                                                value="<?= (int) ($assistant['id'] ?? 0) ?>"
                                                data-user-autocomplete-hidden>
                                            <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                                        </div>
                                    </div>
                                    <div class="col-span-1 flex items-center">
                                        <?php if ($index === 0): ?>
                                            <span class="text-gray-400 text-sm">#1</span>
                                        <?php else: ?>
                                            <button type="button" onclick="removeAssistant(this)" class="text-red-500 hover:text-red-700" title="Hapus">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addAssistant()" class="btn-secondary btn-sm mt-2">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Tambah Asisten
                        </button>
                        <p class="text-xs text-gray-500 mt-1">Minimal 1 asisten wajib dipilih. Minimal jabatan: Paramedic ke atas.</p>
                    </div>
                </div>
            </div>

            <!-- ACTION BUTTONS -->
            <div class="flex justify-end gap-3 mt-6">
                <a href="<?= $isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php' ?>" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">
                    <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Update Rekam Medis
                </button>
            </div>
        </form>
    </div>
</section>

<!-- Quill.js CDN -->
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<script>
let assistantCount = <?= count($assistants) ?>;

function addAssistant() {
    assistantCount++;
    const container = document.getElementById('assistants-container');
    const row = document.createElement('div');
    row.className = 'assistant-row grid grid-cols-12 gap-2 mb-2';
    row.innerHTML = `
        <div class="col-span-11">
            <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="assistant">
                <input type="text" class="form-input assistant-select" data-user-autocomplete-input placeholder="Ketik nama asisten ${assistantCount}...">
                <input type="hidden" name="assistant_ids[]" value="0" data-user-autocomplete-hidden>
                <div class="ems-suggestion-box" data-user-autocomplete-list></div>
            </div>
        </div>
        <div class="col-span-1 flex items-center">
            <button type="button" onclick="removeAssistant(this)" class="text-red-500 hover:text-red-700" title="Hapus">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </button>
        </div>
    `;
    container.appendChild(row);

    if (window.emsInitUserAutocomplete) {
        window.emsInitUserAutocomplete(row);
    }
}

function removeAssistant(button) {
    const container = document.getElementById('assistants-container');
    const rows = container.querySelectorAll('.assistant-row');
    if (rows.length <= 1) {
        return;
    }

    const row = button.closest('.assistant-row');
    if (row) {
        row.remove();
        assistantCount = container.querySelectorAll('.assistant-row').length;
    }
}

// Global previewImage function for file upload preview
window.previewImage = function(event, previewId) {
    const file = event.target.files[0];
    if (file) {
        if (!file.type.startsWith('image/')) {
            alert('File harus berupa gambar (JPG/PNG)');
            event.target.value = '';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('Ukuran file maksimal 5MB');
            event.target.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            document.getElementById(previewId).innerHTML =
                `<img src="${e.target.result}" class="max-h-full max-w-full rounded object-contain" />`;
        };
        reader.readAsDataURL(file);
    }
};

// Alpine.js component untuk form handling
window.medicalForm = function() {
    return {
        init() {
            console.log('Edit form initialized');
        },

        previewImage(event, previewId) {
            window.previewImage(event, previewId);
        }
    }
};

// Global quill variable
window.quill = null;

// Initialize Quill Editor when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (window.emsInitUserAutocomplete) {
        window.emsInitUserAutocomplete(document);
    }

    window.quill = new Quill('#editor-container', {
        theme: 'snow',
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
    
    // Set existing content
    const existingContent = document.getElementById('medical_result_html').value;
    if (existingContent) {
        window.quill.root.innerHTML = existingContent;
    }

    // Sync content to textarea before form submit
    document.querySelector('form').addEventListener('submit', function(event) {
        const htmlContent = window.quill.root.innerHTML;
        document.getElementById('medical_result_html').value = htmlContent;
        
        if (htmlContent === '<p><br></p>' || htmlContent.trim() === '') {
            alert('Hasil rekam medis wajib diisi!');
            event.preventDefault();
            return false;
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
