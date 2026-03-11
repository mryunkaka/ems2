<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Detail Rekam Medis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$mode = trim($_GET['mode'] ?? 'standard');
$isForensicPrivate = ($mode === 'forensic_private');

if ($isForensicPrivate) {
    ems_require_division_access(['Forensic'], '/dashboard/index.php');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash_errors'][] = 'ID rekam medis tidak valid.';
    header('Location: ' . ($isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php'));
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        r.*,
        doctor.full_name AS doctor_name,
        doctor.position AS doctor_position,
        assistant.full_name AS assistant_name,
        assistant.position AS assistant_position,
        creator.full_name AS created_by_name
    FROM medical_records r
    LEFT JOIN user_rh doctor ON doctor.id = r.doctor_id
    LEFT JOIN user_rh assistant ON assistant.id = r.assistant_id
    LEFT JOIN user_rh creator ON creator.id = r.created_by
    WHERE r.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['flash_errors'][] = 'Rekam medis tidak ditemukan.';
    header('Location: ' . ($isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php'));
    exit;
}

$recordScope = $record['visibility_scope'] ?? 'standard';
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

$recordCode = (string)(($record['record_code'] ?? null) ?: ('MR-' . str_pad((string)$record['id'], 6, '0', STR_PAD_LEFT)));
$backUrl = $isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php';
$editUrl = 'rekam_medis_edit.php?id=' . (int)$record['id'] . ($isForensicPrivate ? '&mode=forensic_private' : '');
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <div class="medical-view-hero card card-section mb-4">
            <div class="medical-view-hero__content">
                <div>
                    <div class="medical-view-kicker"><?= $isForensicPrivate ? 'Forensic Private Record' : 'Medical Record Detail' ?></div>
                    <h1 class="page-title mb-2"><?= htmlspecialchars($recordCode, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="page-subtitle mb-0">
                        <?= htmlspecialchars((string)$record['patient_name'], ENT_QUOTES, 'UTF-8') ?> ·
                        <?= htmlspecialchars((string)($record['patient_gender'] ?: '-'), ENT_QUOTES, 'UTF-8') ?> ·
                        <?= htmlspecialchars((string)($record['patient_occupation'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
                <div class="medical-view-hero__actions">
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                        <?= ems_icon('chevron-left', 'h-4 w-4') ?>
                        <span>Kembali</span>
                    </a>
                    <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">
                        <?= ems_icon('document-text', 'h-4 w-4') ?>
                        <span>Edit</span>
                    </a>
                </div>
            </div>
            <div class="medical-view-meta-grid">
                <div class="medical-meta-pill">
                    <span class="medical-meta-pill__label">Dibuat</span>
                    <strong><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$record['created_at'])), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="medical-meta-pill">
                    <span class="medical-meta-pill__label">Dokter DPJP</span>
                    <strong><?= htmlspecialchars((string)($record['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="medical-meta-pill">
                    <span class="medical-meta-pill__label">Jenis Operasi</span>
                    <strong><?= htmlspecialchars($record['operasi_type'] === 'major' ? 'Mayor' : 'Minor', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="medical-meta-pill">
                    <span class="medical-meta-pill__label">Scope</span>
                    <strong><?= htmlspecialchars($recordScope === 'forensic_private' ? 'Forensic Private' : 'Standard', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="medical-view-layout">
            <div class="medical-view-main">
                <div class="card card-section mb-4">
                    <div class="card-header">Ringkasan Pasien</div>
                    <div class="card-body">
                        <div class="medical-info-grid">
                            <div class="medical-info-item">
                                <span class="medical-info-item__label">Nama Pasien</span>
                                <strong><?= htmlspecialchars((string)$record['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="medical-info-item">
                                <span class="medical-info-item__label">Citizen ID</span>
                                <strong><?= htmlspecialchars((string)($record['patient_citizen_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="medical-info-item">
                                <span class="medical-info-item__label">Tanggal Lahir</span>
                                <strong><?= htmlspecialchars((string)($record['patient_dob'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="medical-info-item">
                                <span class="medical-info-item__label">No HP</span>
                                <strong><?= htmlspecialchars((string)($record['patient_phone'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="medical-info-item">
                                <span class="medical-info-item__label">Alamat</span>
                                <strong><?= htmlspecialchars((string)($record['patient_address'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="medical-info-item">
                                <span class="medical-info-item__label">Status Pasien</span>
                                <strong><?= htmlspecialchars((string)($record['patient_status'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-section mb-4">
                    <div class="card-header">Hasil Rekam Medis</div>
                    <div class="card-body">
                        <div class="medical-richtext">
                            <?= (string)$record['medical_result_html'] ?>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="medical-view-side">
                <div class="card card-section mb-4">
                    <div class="card-header">Tim Medis</div>
                    <div class="card-body">
                        <div class="medical-stack">
                            <div class="medical-side-card">
                                <span class="medical-side-card__label">Dokter DPJP</span>
                                <strong><?= htmlspecialchars((string)($record['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="meta-text-xs"><?= htmlspecialchars((string)($record['doctor_position'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="medical-side-card">
                                <span class="medical-side-card__label">Asisten Operasi</span>
                                <strong><?= htmlspecialchars((string)($record['assistant_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="meta-text-xs"><?= htmlspecialchars((string)($record['assistant_position'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="medical-side-card">
                                <span class="medical-side-card__label">Diinput Oleh</span>
                                <strong><?= htmlspecialchars((string)($record['created_by_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="meta-text-xs">Update terakhir: <?= htmlspecialchars(date('d M Y H:i', strtotime((string)$record['updated_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-section">
                    <div class="card-header">Dokumen Pendukung</div>
                    <div class="card-body">
                        <div class="medical-stack">
                            <div class="medical-document-card">
                                <div class="medical-document-card__head">
                                    <span class="medical-side-card__label">KTP</span>
                                    <?php if (!empty($record['ktp_file_path'])): ?>
                                        <a href="<?= htmlspecialchars(ems_asset((string)$record['ktp_file_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn-secondary btn-sm">Buka</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($record['ktp_file_path'])): ?>
                                    <img src="<?= htmlspecialchars(ems_asset((string)$record['ktp_file_path']), ENT_QUOTES, 'UTF-8') ?>" alt="KTP" class="medical-document-card__image">
                                <?php else: ?>
                                    <div class="medical-document-card__empty">Dokumen KTP belum tersedia.</div>
                                <?php endif; ?>
                            </div>

                            <div class="medical-document-card">
                                <div class="medical-document-card__head">
                                    <span class="medical-side-card__label">Foto MRI</span>
                                    <?php if (!empty($record['mri_file_path'])): ?>
                                        <a href="<?= htmlspecialchars(ems_asset((string)$record['mri_file_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn-secondary btn-sm">Buka</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($record['mri_file_path'])): ?>
                                    <img src="<?= htmlspecialchars(ems_asset((string)$record['mri_file_path']), ENT_QUOTES, 'UTF-8') ?>" alt="MRI" class="medical-document-card__image">
                                <?php else: ?>
                                    <div class="medical-document-card__empty">Foto MRI belum tersedia.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>

<style>
.medical-view-hero {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.14), transparent 28%),
        linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
    border: 1px solid rgba(148, 163, 184, 0.26);
}

.medical-view-hero__content {
    display: flex;
    justify-content: space-between;
    gap: 1.5rem;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}

.medical-view-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.08);
    color: #334155;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 0.9rem;
}

.medical-view-hero__actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.medical-view-meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

.medical-meta-pill,
.medical-side-card,
.medical-document-card,
.medical-info-item {
    border: 1px solid rgba(148, 163, 184, 0.2);
    background: rgba(255, 255, 255, 0.82);
    border-radius: 1rem;
    padding: 1rem 1.1rem;
}

.medical-meta-pill__label,
.medical-info-item__label,
.medical-side-card__label {
    display: block;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
    margin-bottom: 0.45rem;
    font-weight: 700;
}

.medical-view-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 0.95fr);
    gap: 1.25rem;
}

.medical-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.medical-richtext {
    color: #1e293b;
    line-height: 1.72;
}

.medical-richtext h1,
.medical-richtext h2,
.medical-richtext h3,
.medical-richtext h4 {
    color: #0f172a;
    margin-top: 1.4rem;
    margin-bottom: 0.8rem;
}

.medical-richtext table {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
    overflow: hidden;
    border-radius: 0.9rem;
}

.medical-richtext td,
.medical-richtext th {
    border: 1px solid rgba(148, 163, 184, 0.24);
    padding: 0.7rem 0.9rem;
}

.medical-richtext ul,
.medical-richtext ol {
    padding-left: 1.25rem;
}

.medical-stack {
    display: grid;
    gap: 1rem;
}

.medical-document-card__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.9rem;
}

.medical-document-card__image {
    width: 100%;
    max-height: 260px;
    object-fit: cover;
    border-radius: 0.9rem;
    border: 1px solid rgba(148, 163, 184, 0.2);
}

.medical-document-card__empty {
    border: 1px dashed rgba(148, 163, 184, 0.4);
    border-radius: 0.9rem;
    padding: 1rem;
    color: #64748b;
    background: #f8fafc;
    text-align: center;
}

@media (max-width: 1100px) {
    .medical-view-layout,
    .medical-view-meta-grid,
    .medical-info-grid {
        grid-template-columns: 1fr;
    }

    .medical-view-hero__content {
        flex-direction: column;
    }

    .medical-view-hero__actions {
        justify-content: flex-start;
    }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
