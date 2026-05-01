<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

/*
|--------------------------------------------------------------------------
| HARD GUARD
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

/*
|--------------------------------------------------------------------------
| DATA USER SESSION (SISTEM LAMA)
|--------------------------------------------------------------------------
*/
$userSession = $_SESSION['user_rh'] ?? [];
$userId = (int)($userSession['id'] ?? 0);
$currentRole = $userSession['role'] ?? '';

$baseSelectColumns = [
    'full_name',
    'position',
    'batch',
    'kode_nomor_induk_rs',
    'tanggal_masuk',
    'citizen_id',
    'no_hp_ic',
    'jenis_kelamin',
    'file_ktp',
    'file_sim',
    'file_kta',
    'file_skb',
    'sertifikat_heli',
    'sertifikat_operasi',
    'dokumen_lainnya',
];

$optionalSettingAkunColumns = [
    'sertifikat_operasi_plastik',
    'sertifikat_operasi_kecil',
    'sertifikat_operasi_besar',
    'sertifikat_class_co_asst',
    'sertifikat_class_paramedic',
    'tanggal_naik_paramedic',
    'tanggal_naik_co_asst',
    'tanggal_naik_dokter',
    'tanggal_naik_dokter_spesialis',
    'tanggal_join_manager',
];

$selectColumns = $baseSelectColumns;
foreach ($optionalSettingAkunColumns as $optionalColumn) {
    if (ems_column_exists($pdo, 'user_rh', $optionalColumn)) {
        $selectColumns[] = $optionalColumn;
    }
}

$stmt = $pdo->prepare("
    SELECT 
        " . implode(",\n        ", $selectColumns) . "
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC);

$otherDocs = ensureAcademyDocIds(parseAcademyDocs($userDb['dokumen_lainnya'] ?? ''));

function settingAkunNormalizeDocName(?string $name): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string)$name));
    return $value;
}

$otherDocSuggestions = [];
$stmtOtherDocSuggestions = $pdo->query("SELECT dokumen_lainnya FROM user_rh WHERE dokumen_lainnya IS NOT NULL AND dokumen_lainnya <> ''");
$allOtherDocRows = $stmtOtherDocSuggestions ? $stmtOtherDocSuggestions->fetchAll(PDO::FETCH_ASSOC) : [];
foreach ($allOtherDocRows as $otherDocRow) {
    $parsedDocs = ensureAcademyDocIds(parseAcademyDocs($otherDocRow['dokumen_lainnya'] ?? ''));
    foreach ($parsedDocs as $parsedDoc) {
        $docName = settingAkunNormalizeDocName($parsedDoc['name'] ?? '');
        if ($docName === '') {
            continue;
        }

        $lookupKey = strtolower($docName);
        if (!isset($otherDocSuggestions[$lookupKey])) {
            $otherDocSuggestions[$lookupKey] = $docName;
        }
    }
}
natcasesort($otherDocSuggestions);

$citizenId    = $userDb['citizen_id'] ?? '';
$jenisKelamin = $userDb['jenis_kelamin'] ?? '';
$noHpIc = $userDb['no_hp_ic'] ?? '';

$medicName  = $userDb['full_name'] ?? '';
$medicPos   = $userDb['position'] ?? '';
$medicPosNormalized = ems_normalize_position($medicPos);
$currentRoleNormalized = ems_normalize_role($currentRole);
$medicBatch = $userDb['batch'] ?? '';
$nomorInduk = $userDb['kode_nomor_induk_rs'] ?? '';
$tanggalMasuk = $userDb['tanggal_masuk'] ?? '';

$batchLocked = !empty($nomorInduk);
$kodeBatch   = $nomorInduk;

$batchLocked = !empty($nomorInduk);

$pageTitle = 'Setting Akun';

$kodeBatch = $nomorInduk; // tampilkan PERSIS seperti di database

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE (SISTEM EMS)
|--------------------------------------------------------------------------
*/
$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];

// Hapus flash error division yang mungkin tersisa dari redirect halaman lain
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

$promotionDateConfigs = [
    'tanggal_naik_paramedic' => 'Tanggal Naik ke Paramedic',
    'tanggal_naik_co_asst' => 'Tanggal Naik ke Co. Asst',
    'tanggal_naik_dokter' => 'Tanggal Naik ke Dokter',
    'tanggal_naik_dokter_spesialis' => 'Tanggal Naik ke Dokter Spesialis',
    'tanggal_join_manager' => 'Tanggal Join Manager',
];

$visiblePromotionDateFields = [];
if ($medicPosNormalized === 'paramedic' && ems_column_exists($pdo, 'user_rh', 'tanggal_naik_paramedic')) {
    $visiblePromotionDateFields[] = 'tanggal_naik_paramedic';
}
if ($medicPosNormalized === 'co_asst' && ems_column_exists($pdo, 'user_rh', 'tanggal_naik_co_asst')) {
    $visiblePromotionDateFields[] = 'tanggal_naik_co_asst';
}
if ($medicPosNormalized === 'general_practitioner' && ems_column_exists($pdo, 'user_rh', 'tanggal_naik_dokter')) {
    $visiblePromotionDateFields[] = 'tanggal_naik_dokter';
}
if ($medicPosNormalized === 'specialist' && ems_column_exists($pdo, 'user_rh', 'tanggal_naik_dokter_spesialis')) {
    $visiblePromotionDateFields[] = 'tanggal_naik_dokter_spesialis';
}
if (ems_is_manager_plus_role($currentRoleNormalized) && ems_column_exists($pdo, 'user_rh', 'tanggal_join_manager')) {
    $visiblePromotionDateFields[] = 'tanggal_join_manager';
}
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-sm">

        <h1 class="page-title flex items-center gap-3"><?= ems_icon('cog-6-tooth', 'h-7 w-7 text-primary') ?> Setting Akun</h1>

        <!-- ===============================
             NOTIFIKASI (SAMA DENGAN REKAP FARMASI)
             =============================== -->
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header">Informasi Akun</div>

            <form method="POST"
                action="setting_akun_action.php"
                class="form"
                enctype="multipart/form-data">

                <!-- ===============================
                IDENTITAS MEDIS
                =============================== -->
                <h3 class="section-form-title">Identitas Medis</h3>

                <div class="row-form-2">
                    <div>
                        <label>Batch <span class="required">*</span></label>
                        <input type="number"
                            name="batch"
                            min="1"
                            max="26"
                            required
                            value="<?= htmlspecialchars($medicBatch) ?>"
                            <?= $batchLocked ? 'disabled class="bg-slate-100 cursor-not-allowed"' : '' ?>>
                        <?php if ($batchLocked): ?>
                            <small class="hint-locked">
                                Batch terkunci karena Kode Medis telah dibuat
                            </small>
                        <?php endif; ?>
                    </div>

                    <?php if ($batchLocked): ?>
                        <input type="hidden" name="batch" value="<?= (int)$medicBatch ?>">
                    <?php endif; ?>

                    <div>
                        <label>Tanggal Masuk <span class="required">*</span></label>
                        <input type="date"
                            name="tanggal_masuk"
                            value="<?= htmlspecialchars($tanggalMasuk) ?>"
                            required>
                    </div>
                </div>

                <!-- ===============================
                DATA PERSONAL
                =============================== -->
                <hr class="section-divider">
                <h3 class="section-form-title">Data Personal</h3>

                <label>Nama Medis <span class="required">*</span></label>
                <input type="text"
                    name="full_name"
                    required
                    placeholder="Masukkan nama lengkap Anda"
                    value="<?= htmlspecialchars($medicName) ?>">

                <!-- BARIS 1 -->
                <div class="row-form-2">
                    <div>
                        <label>Citizen ID <span class="required">*</span></label>
                        <input type="text"
                            id="citizenIdInput"
                            name="citizen_id"
                            required
                            placeholder="RH39IQLC"
                            pattern="[A-Z0-9]+"
                            title="Hanya huruf BESAR dan angka, tanpa spasi"
                            value="<?= htmlspecialchars($citizenId) ?>"
                            class="uppercase">
                        <small class="hint-warning">
                            Format: <strong>HURUF BESAR</strong> atau <strong>kombinasi huruf besar dan angka</strong>, tanpa spasi
                        </small>
                    </div>

                    <div>
                        <label>Jenis Kelamin <span class="required">*</span></label>
                        <select name="jenis_kelamin" required>
                            <option value="">-- Pilih --</option>
                            <option value="Laki-laki" <?= $jenisKelamin === 'Laki-laki' ? 'selected' : '' ?>>
                                Laki-laki
                            </option>
                            <option value="Perempuan" <?= $jenisKelamin === 'Perempuan' ? 'selected' : '' ?>>
                                Perempuan
                            </option>
                        </select>
                    </div>
                </div>

                <!-- BARIS 2 -->
                <div class="row-form-1">
                    <label>No HP IC <span class="required">*</span></label>
                    <input type="number"
                        name="no_hp_ic"
                        required
                        inputmode="numeric"
                        placeholder="Contoh: 544322"
                        value="<?= htmlspecialchars($noHpIc) ?>">
                    <small class="hint-info">
                        Nomor HP yang terdaftar di sistem IC
                    </small>
                </div>

                <!-- ===============================
DOKUMEN PENDUKUNG
=============================== -->
                <?php
                function renderDocInput($label, $name, $path = null, $required = false)
                {
                ?>
                    <div class="doc-upload-wrapper">
                        <div class="doc-upload-header">
                            <label class="doc-label">
                                <?= htmlspecialchars($label) ?>
                                <?php if ($required): ?>
                                    <span class="required">*</span>
                                <?php endif; ?>
                            </label>

                            <?php if (!empty($path)): ?>
                                <div class="doc-status-badge">
                                    <span class="badge-success-mini">Sudah diunggah</span>
                                    <a href="#"
                                        class="btn-link btn-preview-doc"
                                        data-src="/<?= htmlspecialchars($path) ?>"
                                        data-title="<?= htmlspecialchars($label) ?>">
                                        Lihat dokumen
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="badge-muted-mini">Belum ada</span>
                            <?php endif; ?>
                        </div>

                        <div class="doc-upload-input">
                            <label for="<?= htmlspecialchars($name) ?>" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih file</strong>
                                    <small>PNG atau JPG</small>
                                </span>
                            </label>
                            <input type="file"
                                id="<?= htmlspecialchars($name) ?>"
                                name="<?= htmlspecialchars($name) ?>"
                                accept="image/png,image/jpeg"
                                <?= $required && empty($path) ? 'required' : '' ?>
                                class="sr-only">
                            <div class="file-selected-name" data-for="<?= htmlspecialchars($name) ?>"></div>
                        </div>

                        <?php if (!empty($path)): ?>
                            <small class="doc-hint">Upload ulang akan menggantikan file sebelumnya</small>
                        <?php elseif ($required): ?>
                            <small class="doc-hint">Dokumen ini wajib diunggah.</small>
                        <?php endif; ?>
                    </div>
                <?php
                }

                function renderPromotionDateInput(string $label, string $name, ?string $value = null, bool $required = false): void
                {
                ?>
                    <div>
                        <label>
                            <?= htmlspecialchars($label) ?>
                            <?php if ($required): ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                        </label>
                        <input type="date"
                            name="<?= htmlspecialchars($name) ?>"
                            value="<?= htmlspecialchars((string)$value) ?>"
                            <?= $required ? 'required' : '' ?>>
                    </div>
                <?php
                }
                ?>

                <hr class="section-divider">
                <h3 class="section-form-title">Dokumen Pendukung</h3>
                <p class="text-muted">Unggah dokumen pendukung (PNG / JPG)</p>

                <?php
                renderDocInput('Upload KTP', 'file_ktp', $userDb['file_ktp'], true);
                renderDocInput('Upload SKB', 'file_skb', $userDb['file_skb'], true);
                renderDocInput('Upload SIM', 'file_sim', $userDb['file_sim']);
                renderDocInput('Upload KTA', 'file_kta', $userDb['file_kta'], true);
                renderDocInput('Sertifikat Heli', 'sertifikat_heli', $userDb['sertifikat_heli']);
                renderDocInput('Sertifikat Operasi', 'sertifikat_operasi', $userDb['sertifikat_operasi']);
                if (array_key_exists('sertifikat_operasi_plastik', $userDb)) {
                    renderDocInput('Sertifikat Operasi Plastik', 'sertifikat_operasi_plastik', $userDb['sertifikat_operasi_plastik']);
                }
                if (array_key_exists('sertifikat_operasi_kecil', $userDb)) {
                    renderDocInput('Sertifikat Operasi Kecil', 'sertifikat_operasi_kecil', $userDb['sertifikat_operasi_kecil']);
                }
                if (array_key_exists('sertifikat_operasi_besar', $userDb)) {
                    renderDocInput('Sertifikat Operasi Besar', 'sertifikat_operasi_besar', $userDb['sertifikat_operasi_besar']);
                }
                if (array_key_exists('sertifikat_class_co_asst', $userDb)) {
                    renderDocInput('Sertifikat Class Co. Asst', 'sertifikat_class_co_asst', $userDb['sertifikat_class_co_asst']);
                }
                if (array_key_exists('sertifikat_class_paramedic', $userDb)) {
                    renderDocInput('Sertifikat Class Paramedic', 'sertifikat_class_paramedic', $userDb['sertifikat_class_paramedic']);
                }
                ?>

                <?php if (!empty($visiblePromotionDateFields)): ?>
                    <hr class="section-divider">
                    <h3 class="section-form-title">Riwayat Kenaikan</h3>
                    <p class="text-muted">Kolom tanggal naik hanya tampil sesuai jabatan aktif dan role manager.</p>

                    <div class="row-form-2">
                        <?php foreach ($visiblePromotionDateFields as $promotionField): ?>
                            <?php renderPromotionDateInput($promotionDateConfigs[$promotionField] ?? $promotionField, $promotionField, $userDb[$promotionField] ?? '', true); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="doc-upload-wrapper doc-upload-dashed">
                    <div class="doc-upload-header doc-upload-header-stack">
                        <label class="doc-label">File Lainnya</label>
                        <small class="text-muted doc-upload-meta">
                            Nama dokumen diisi sendiri. Bisa upload banyak file tambahan sesuai kebutuhan.
                        </small>
                    </div>

                    <div id="academyDocsContainer" class="academy-list">
                        <datalist id="academyDocNameSuggestions">
                            <?php foreach ($otherDocSuggestions as $suggestedDocName): ?>
                                <option value="<?= htmlspecialchars($suggestedDocName) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?php if (empty($otherDocs)): ?>
                            <div class="academy-doc-row" data-row="academy">
                                <input type="hidden" name="academy_doc_id[]" value="">

                                <div class="row-form-2 academy-grid">
                                    <div>
                                        <label>Nama File Lainnya</label>
                                        <input type="text"
                                            name="academy_doc_name[]"
                                            list="academyDocNameSuggestions"
                                            autocomplete="off"
                                            placeholder="Contoh: Sertifikat Pelatihan atau Dokumen Pendukung">
                                    </div>
                                    <div>
                                        <label>File</label>
                                        <div class="doc-upload-input doc-upload-input-reset">
                                            <label for="academy_file_new_0" class="file-upload-label">
                                                <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                                <span class="file-text">
                                                    <strong>Pilih file</strong>
                                                    <small>PNG atau JPG</small>
                                                </span>
                                            </label>
                                            <input type="file"
                                                id="academy_file_new_0"
                                                name="academy_doc_file[]"
                                                accept="image/png,image/jpeg"
                                                class="sr-only">
                                            <div class="file-selected-name" data-for="academy_file_new_0"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($otherDocs as $idx => $ad): ?>
                                <div class="academy-doc-row" data-row="academy">
                                    <input type="hidden" name="academy_doc_id[]" value="<?= htmlspecialchars($ad['id'] ?? '') ?>">

                                    <div class="row-form-2 academy-grid">
                                        <div>
                                            <label>Nama File Lainnya</label>
                                            <input type="text"
                                                name="academy_doc_name[]"
                                                list="academyDocNameSuggestions"
                                                autocomplete="off"
                                                value="<?= htmlspecialchars($ad['name'] ?? '') ?>"
                                                placeholder="Contoh: Sertifikat Pelatihan atau Dokumen Pendukung">
                                            <div class="academy-doc-preview">
                                                <span class="badge-success-mini">Sudah diunggah</span>
                                                <a href="#"
                                                    class="btn-link btn-preview-doc"
                                                    data-src="/<?= htmlspecialchars($ad['path'] ?? '') ?>"
                                                    data-title="<?= htmlspecialchars($ad['name'] ?? 'File Lainnya') ?>">
                                                    Lihat dokumen
                                                </a>
                                            </div>
                                        </div>

                                        <div>
                                            <label>Ganti File (opsional)</label>
                                            <div class="doc-upload-input doc-upload-input-reset">
                                                <label for="academy_file_<?= htmlspecialchars($ad['id'] ?? ('idx_' . $idx)) ?>" class="file-upload-label">
                                                    <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                                    <span class="file-text">
                                                        <strong>Pilih file</strong>
                                                        <small>PNG atau JPG</small>
                                                    </span>
                                                </label>
                                                <input type="file"
                                                    id="academy_file_<?= htmlspecialchars($ad['id'] ?? ('idx_' . $idx)) ?>"
                                                    name="academy_doc_file[]"
                                                    accept="image/png,image/jpeg"
                                                    class="sr-only">
                                                <div class="file-selected-name" data-for="academy_file_<?= htmlspecialchars($ad['id'] ?? ('idx_' . $idx)) ?>"></div>
                                            </div>
                                            <small class="doc-hint">Upload ulang akan menggantikan file sebelumnya</small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="action-row-end">
                        <button type="button" id="btnAddAcademyDoc" class="btn-secondary button-compact">
                            <?= ems_icon('plus', 'h-4 w-4') ?> Tambah File Lainnya
                        </button>
                    </div>
                </div>

                <!-- (sisanya tetap sama sampai bagian PIN) -->

                <!-- ===============================
                KEAMANAN AKUN
                =============================== -->
                <hr class="section-divider">
                <h3 class="section-form-title">Keamanan Akun</h3>

                <div class="info-box">
                    <span class="info-icon"><?= ems_icon('exclamation-triangle', 'h-5 w-5') ?></span>
                    <span>Kosongkan semua field PIN jika tidak ingin mengubah password</span>
                </div>

                <label>PIN Lama <small>(opsional)</small></label>
                <input type="password"
                    id="oldPinInput"
                    name="old_pin"
                    inputmode="numeric"
                    pattern="[0-9]{4}"
                    maxlength="4"
                    placeholder="****">

                <div class="row-form-2">
                    <div>
                        <label>PIN Baru <small>(opsional)</small></label>
                        <input type="password"
                            id="newPinInput"
                            name="new_pin"
                            inputmode="numeric"
                            pattern="[0-9]{4}"
                            maxlength="4"
                            placeholder="****">
                    </div>

                    <div>
                        <label>Konfirmasi PIN Baru <small>(opsional)</small></label>
                        <input type="password"
                            id="confirmPinInput"
                            name="confirm_pin"
                            inputmode="numeric"
                            pattern="[0-9]{4}"
                            maxlength="4"
                            placeholder="****">
                    </div>
                </div>

                <!-- ===============================
                SUBMIT
                =============================== -->
                <div class="form-submit-wrapper">
                    <button type="submit" class="btn-primary btn-submit">
                        <?= ems_icon('check-circle', 'h-5 w-5') ?>
                        <span>Simpan Perubahan</span>
                    </button>
                </div>

            </form>
        </div>

    </div>
    <script>
        // Show selected filename (delegated: support input dinamis)
        document.addEventListener('change', function(e) {
            const input = e.target;
            if (!input || input.tagName !== 'INPUT' || input.type !== 'file') return;

            const nameDisplay = document.querySelector('.file-selected-name[data-for="' + input.id + '"]');
            if (!nameDisplay) return;

            if (input.files && input.files.length > 0) {
                const fileName = input.files[0].name;
                const fileSize = (input.files[0].size / 1024).toFixed(1);
                nameDisplay.innerHTML = `
	                    <span class="selected-file-info">
	                        <strong>${fileName}</strong>
	                        <small>${fileSize} KB</small>
	                    </span>
	                `;
                nameDisplay.style.display = 'flex';
            } else {
                nameDisplay.style.display = 'none';
            }
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('btnAddAcademyDoc');
            const container = document.getElementById('academyDocsContainer');
            if (!btn || !container) return;

            let newIndex = 1;

            btn.addEventListener('click', function() {
                const id = 'academy_file_new_' + newIndex++;
                const row = document.createElement('div');
                row.className = 'academy-doc-row';
                row.setAttribute('data-row', 'academy');
                row.innerHTML = `
	                    <input type="hidden" name="academy_doc_id[]" value="">
	                    <div class="row-form-2 academy-grid">
	                        <div>
	                            <label>Nama File Lainnya</label>
	                            <input type="text" name="academy_doc_name[]" list="academyDocNameSuggestions" autocomplete="off" placeholder="Contoh: Sertifikat Pelatihan atau Dokumen Pendukung">
	                        </div>
	                        <div>
	                            <label>File</label>
	                            <div class="doc-upload-input doc-upload-input-reset">
	                                <label for="${id}" class="file-upload-label">
	                                    <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
	                                    <span class="file-text">
	                                        <strong>Pilih file</strong>
	                                        <small>PNG atau JPG</small>
	                                    </span>
	                                </label>
                                <input type="file" id="${id}" name="academy_doc_file[]" accept="image/png,image/jpeg" class="sr-only">
	                                <div class="file-selected-name" data-for="${id}"></div>
	                            </div>
	                        </div>
	                    </div>
	                `;

                container.appendChild(row);
            });
        });
    </script>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            document.querySelectorAll(
                '.alert-info, .alert-warning, .alert-error'
            ).forEach(function(el) {
                el.style.transition = 'opacity 0.5s ease';
                el.style.opacity = '0';

                setTimeout(function() {
                    if (el.parentNode) {
                        el.parentNode.removeChild(el);
                    }
                }, 600);
            });
        }, 5000); // 5 detik
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const citizenIdInput = document.getElementById('citizenIdInput');

        if (citizenIdInput) {
            // Auto uppercase saat mengetik
            citizenIdInput.addEventListener('input', function(e) {
                let value = e.target.value;

                // Hapus spasi dan karakter selain huruf & angka
                value = value.replace(/[^A-Z0-9]/gi, '');

                // Convert ke uppercase
                e.target.value = value.toUpperCase();
            });

            // Validasi sebelum submit
            citizenIdInput.closest('form').addEventListener('submit', function(e) {
                const value = citizenIdInput.value.trim();

                // Validasi: tidak boleh kosong
                if (value === '') {
                    e.preventDefault();
                    alert('Citizen ID wajib diisi');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: tidak boleh ada spasi
                if (/\s/.test(value)) {
                    e.preventDefault();
                    alert('Citizen ID tidak boleh mengandung spasi');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: harus ada minimal 1 huruf
                if (!/[A-Z]/i.test(value)) {
                    e.preventDefault();
                    alert('Citizen ID harus mengandung minimal 1 huruf');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: panjang minimal 6 karakter
                if (value.length < 6) {
                    e.preventDefault();
                    alert('Citizen ID minimal 6 karakter');
                    citizenIdInput.focus();
                    return false;
                }

                // Validasi: tidak boleh sama dengan nama lengkap
                const fullNameInput = document.querySelector('input[name="full_name"]');
                if (fullNameInput) {
                    const fullName = fullNameInput.value.trim().toUpperCase();
                    const cleanedFullName = fullName.replace(/\s+/g, '');

                    if (value.toUpperCase() === cleanedFullName) {
                        e.preventDefault();
                        alert('Citizen ID tidak boleh sama dengan Nama Medis.\n\nContoh Citizen ID yang benar: RH39IQLC');
                        citizenIdInput.focus();
                        return false;
                    }
                }

                if (/^[0-9]+$/.test(value)) {
                    e.preventDefault();
                    alert('Citizen ID tidak boleh hanya angka saja.\n\nGunakan huruf BESAR atau kombinasi huruf BESAR dan angka.');
                    citizenIdInput.focus();
                    return false;
                }
            });
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form[action="setting_akun_action.php"]');
        const oldPinInput = document.getElementById('oldPinInput');
        const newPinInput = document.getElementById('newPinInput');
        const confirmPinInput = document.getElementById('confirmPinInput');

        if (form && oldPinInput && newPinInput && confirmPinInput) {
            form.addEventListener('submit', function(e) {
                const oldPin = oldPinInput.value.trim();
                const newPin = newPinInput.value.trim();
                const confirmPin = confirmPinInput.value.trim();

                // Jika salah satu field PIN diisi, semua harus diisi
                const anyPinFilled = oldPin !== '' || newPin !== '' || confirmPin !== '';

                if (anyPinFilled) {
                    // Validasi: semua field PIN harus diisi
                    if (oldPin === '') {
                        e.preventDefault();
                        alert('PIN Lama wajib diisi jika ingin mengganti PIN');
                        oldPinInput.focus();
                        return false;
                    }

                    if (newPin === '') {
                        e.preventDefault();
                        alert('PIN Baru wajib diisi jika ingin mengganti PIN');
                        newPinInput.focus();
                        return false;
                    }

                    if (confirmPin === '') {
                        e.preventDefault();
                        alert('Konfirmasi PIN wajib diisi jika ingin mengganti PIN');
                        confirmPinInput.focus();
                        return false;
                    }

                    // Validasi: PIN harus 4 digit
                    if (oldPin.length !== 4 || !/^\d{4}$/.test(oldPin)) {
                        e.preventDefault();
                        alert('PIN Lama harus 4 digit angka');
                        oldPinInput.focus();
                        return false;
                    }

                    if (newPin.length !== 4 || !/^\d{4}$/.test(newPin)) {
                        e.preventDefault();
                        alert('PIN Baru harus 4 digit angka');
                        newPinInput.focus();
                        return false;
                    }

                    // Validasi: PIN baru dan konfirmasi harus sama
                    if (newPin !== confirmPin) {
                        e.preventDefault();
                        alert('PIN Baru dan Konfirmasi PIN tidak sama');
                        confirmPinInput.focus();
                        return false;
                    }

                    // Validasi: PIN baru tidak boleh sama dengan PIN lama
                    if (oldPin === newPin) {
                        e.preventDefault();
                        alert('PIN Baru tidak boleh sama dengan PIN Lama');
                        newPinInput.focus();
                        return false;
                    }
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
