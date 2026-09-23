<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/medical_records_api.php';
require_once __DIR__ . '/../config/forensic_private_access.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Detail Rekam Medis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$mode = trim($_GET['mode'] ?? 'standard');
$isForensicPrivate = ($mode === 'forensic_private');
$hasJenisOperasiColumn = ems_column_exists($pdo, 'medical_records', 'jenis_operasi');
$forensicPerms = null;

if ($isForensicPrivate) {
    ems_forensic_private_ensure_tables($pdo);
    $forensicPerms = ems_forensic_private_effective_permissions($pdo, $user);
    if (!$forensicPerms['has_any_access']) {
        $_SESSION['flash_errors'][] = 'Akses Rekam Medis Private ditolak.';
        header('Location: /dashboard/index.php');
        exit;
    }
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
       OR (r.source_provider = 'medical_center' AND r.remote_record_id = ?)
    LIMIT 1
");
$stmt->execute([$id, (string) $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['flash_errors'][] = 'Rekam medis tidak ditemukan.';
    header('Location: ' . ($isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php'));
    exit;
}

$isRemoteMedicalCenterRecord = ems_column_exists($pdo, 'medical_records', 'source_provider')
    && trim((string) ($record['source_provider'] ?? '')) === 'medical_center';
$remotePhotos = $isRemoteMedicalCenterRecord
    ? ems_medical_center_decode_array($record['remote_photos_json'] ?? [])
    : [];
$remoteTeam = $isRemoteMedicalCenterRecord
    ? ems_medical_center_decode_array($record['remote_team_json'] ?? [])
    : [];
$remoteDpjp = $isRemoteMedicalCenterRecord
    ? ems_medical_center_decode_array($record['remote_dpjp_json'] ?? [])
    : [];
$remoteAssistants = $isRemoteMedicalCenterRecord
    ? ems_medical_center_decode_array($record['remote_assistants_json'] ?? [])
    : [];
$remoteMedicalDetails = $isRemoteMedicalCenterRecord
    ? ems_medical_center_decode_array($record['remote_medical_details_json'] ?? [])
    : [];
$remoteSupportingMedications = $isRemoteMedicalCenterRecord
    ? ems_medical_center_list_text($remoteMedicalDetails['obat_obatan'] ?? '')
    : '';
$recordScope = $record['visibility_scope'] ?? 'standard';
$canEditRecord = false;
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

if ($isForensicPrivate && !ems_forensic_private_can_view_row($forensicPerms, $record, $userId)) {
    $_SESSION['flash_errors'][] = 'Anda tidak memiliki izin untuk melihat rekam medis private ini.';
    header('Location: forensic_medical_records_list.php');
    exit;
}

$recordCode = (string)(($record['record_code'] ?? null) ?: ('MR-' . str_pad((string)$record['id'], 6, '0', STR_PAD_LEFT)));
$backUrl = $isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php';
$editUrl = 'rekam_medis_edit.php?id=' . (int)$record['id'] . ($isForensicPrivate ? '&mode=forensic_private' : '');
$assistants = ems_get_medical_record_assistants($pdo, (int) $record['id'], isset($record['assistant_id']) ? (int) $record['assistant_id'] : null);
$supportingImages = ems_get_medical_record_supporting_images($pdo, (int)$record['id'], (string)($record['mri_file_path'] ?? ''));
$canEditRecord = $recordScope === 'forensic_private'
    ? ems_forensic_private_can_edit_row($forensicPerms, $record, $userId)
    : (int) ($record['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
$activityLogs = [];
$canViewForensicHistory = false;

if ($isForensicPrivate) {
    // Log dicatat untuk SEMUA yang melihat (termasuk medis yang cuma dapat
    // grant), tapi log-nya sendiri hanya ditampilkan ke tim Forensic native
    // — sesuai permintaan eksplisit user ("history semua halaman forensic
    // hanya bisa dilihat oleh tim forensic").
    ems_forensic_private_log_action($pdo, (int) $record['id'], 'viewed', $user);
    $canViewForensicHistory = ems_forensic_private_can_view_history($user);
    if ($canViewForensicHistory) {
        $activityLogs = ems_forensic_private_get_logs($pdo, (int) $record['id']);
    }
}

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
if ($isForensicPrivate) {
    $errors = array_values(array_filter(
        $errors,
        static fn (mixed $error): bool => !in_array((string) $error, [
            'Akses halaman ditolak untuk division Anda.',
            'Akses division ditolak.',
        ], true)
    ));
}
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
                        <?= htmlspecialchars((string)$record['patient_name'], ENT_QUOTES, 'UTF-8') ?> &middot;
                        <?= htmlspecialchars((string)($record['patient_gender'] ?: '-'), ENT_QUOTES, 'UTF-8') ?> &middot;
                        <?= htmlspecialchars((string)($record['patient_occupation'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
                <div class="medical-view-hero__actions">
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                        <?= ems_icon('chevron-left', 'h-4 w-4') ?>
                        <span>Kembali</span>
                    </a>
                    <?php if ($canEditRecord): ?>
                        <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-primary action-icon-btn" title="Edit rekam medis" aria-label="Edit rekam medis">
                            <?= ems_icon('document-text', 'h-4 w-4') ?>
                        </a>
                    <?php endif; ?>
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
                <?php if ($hasJenisOperasiColumn && trim((string) ($record['jenis_operasi'] ?? '')) !== ''): ?>
                    <div class="medical-meta-pill">
                        <span class="medical-meta-pill__label">REKAM MEDIS</span>
                        <strong><?= htmlspecialchars((string) $record['jenis_operasi'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endif; ?>
                <div class="medical-meta-pill">
                    <span class="medical-meta-pill__label">Scope</span>
                    <strong><?= htmlspecialchars($isRemoteMedicalCenterRecord ? 'Medical Center · Read-only' : ($recordScope === 'forensic_private' ? 'Forensic Private' : 'Standard'), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <?php if ($isRemoteMedicalCenterRecord && trim((string) ($record['remote_record_id'] ?? '')) !== ''): ?>
                    <div class="medical-meta-pill">
                        <span class="medical-meta-pill__label">ID Remote</span>
                        <a href="<?= htmlspecialchars((string) ($record['remote_record_url'] ?: ems_medical_center_remote_detail_url($record['remote_record_id'])), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="text-primary hover:underline">
                            <?= htmlspecialchars((string) $record['remote_record_id'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string)$message, 'info', 'Detail Rekam Medis') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string)$error, 'error', 'Detail Rekam Medis', 6800) ?>
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
                                <span class="medical-info-item__label">Jenis Kelamin</span>
                                <strong><?= htmlspecialchars((string)($record['patient_gender'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
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

                <?php if ($isRemoteMedicalCenterRecord): ?>
                <div class="card card-section mb-4">
                    <div class="card-header">ANAMNESIS &amp; RIWAYAT KESEHATAN</div>
                    <div class="card-body medical-detail-sections">
                        <?php foreach ([
                            'Anamnesis / Keluhan Utama' => 'remote_anamnesis',
                            'Diagnosis Utama' => 'remote_diagnosis',
                        ] as $label => $column): ?>
                            <div class="medical-detail-block">
                                <span class="medical-side-card__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="medical-detail-value"><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="medical-info-grid medical-info-grid--history">
                            <?php foreach ([
                                'Riwayat Penyakit Dahulu' => 'remote_past_medical_history',
                                'Riwayat Penyakit Keluarga' => 'remote_family_history',
                                'Riwayat Alergi' => 'remote_allergy_history',
                                'Riwayat Pengobatan' => 'remote_medication_history',
                            ] as $label => $column): ?>
                                <div class="medical-info-item">
                                    <span class="medical-info-item__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card card-section mb-4">
                    <div class="card-header">PEMERIKSAAN FISIK &amp; TTV (TANDA VITAL)</div>
                    <div class="card-body">
                        <div class="medical-info-grid medical-info-grid--vitals">
                            <?php foreach ([
                                'Keadaan Umum' => 'remote_general_condition',
                                'Kesadaran / GCS' => 'remote_gcs',
                                'Tekanan Darah' => 'remote_blood_pressure',
                                'Nadi' => 'remote_pulse',
                                'Respirasi (RR)' => 'remote_respiratory_rate',
                                'Suhu Body' => 'remote_temperature',
                                'Saturasi O2' => 'remote_oxygen_saturation',
                                'Golongan Darah' => 'patient_blood_type',
                            ] as $label => $column): ?>
                                <div class="medical-info-item">
                                    <span class="medical-info-item__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card card-section mb-4">
                    <div class="card-header">TINDAKAN / OPERASI</div>
                    <div class="card-body medical-detail-sections">
                        <div class="medical-info-grid medical-info-grid--action">
                            <?php foreach ([
                                'Nama Tindakan' => 'remote_operation_name',
                                'Waktu Mulai' => 'remote_operation_start_at',
                                'Waktu Selesai' => 'remote_operation_end_at',
                            ] as $label => $column): ?>
                                <div class="medical-info-item">
                                    <span class="medical-info-item__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ([
                            'Langkah-Langkah Tindakan' => 'remote_operation_steps',
                            'Hasil Operasi' => 'remote_operation_result',
                        ] as $label => $column): ?>
                            <div class="medical-detail-block">
                                <span class="medical-side-card__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="medical-detail-value"><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card card-section mb-4">
                    <div class="card-header">MANAJEMEN ANESTESI &amp; TABLE SCORE</div>
                    <div class="card-body medical-detail-sections">
                        <div class="medical-info-grid medical-info-grid--anesthesia">
                            <?php foreach ([
                                'Jenis Anestesi' => 'remote_anesthesia_type',
                                'Petugas Anestesi' => 'remote_anesthesia_officer',
                            ] as $label => $column): ?>
                                <div class="medical-info-item">
                                    <span class="medical-info-item__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ([
                            'Obat Pra-Operasi' => 'remote_preop_medications',
                            'Obat Pasca-Operasi (Antidote/Anti Mual/Analgesik)' => 'remote_postop_medications',
                        ] as $label => $column): ?>
                            <div class="medical-detail-block">
                                <span class="medical-side-card__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="medical-detail-value"><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="medical-detail-block">
                            <span class="medical-side-card__label">SCORE PEMULIHAN PASCA ANESTESI (ALDRETE SCORE)</span>
                            <div class="medical-detail-value"><?= nl2br(htmlspecialchars((string) ($record['remote_aldrete'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
                        </div>
                    </div>
                </div>

                <div class="card card-section mb-4">
                    <div class="card-header">PENUNJANG, OBAT &amp; SARAN ANJURAN</div>
                    <div class="card-body medical-detail-sections">
                        <?php foreach ([
                            'Hasil Laboratorium' => 'remote_laboratory_result',
                            'Hasil Radiologi / X-Ray' => 'remote_radiology_result',
                            'Saran dan Anjuran Dokter' => 'remote_postop_advice',
                        ] as $label => $column): ?>
                            <div class="medical-detail-block">
                                <span class="medical-side-card__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="medical-detail-value"><?= nl2br(htmlspecialchars((string) ($record[$column] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="medical-detail-block">
                            <span class="medical-side-card__label">Obat-Obatan Post Operasi</span>
                            <div class="medical-detail-value"><?= nl2br(htmlspecialchars($remoteSupportingMedications, ENT_QUOTES, 'UTF-8')) ?></div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card card-section mb-4">
                    <div class="card-header">Hasil Rekam Medis</div>
                    <div class="card-body">
                        <div class="medical-richtext">
                            <?= (string)$record['medical_result_html'] ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <aside class="medical-view-side">
                <div class="card card-section mb-4">
                    <div class="card-header">Tim Operasi Medis</div>
                    <div class="card-body">
                        <div class="medical-stack">
                            <div class="medical-side-card">
                                <span class="medical-side-card__label">Dokter DPJP</span>
                                <?php if ($isRemoteMedicalCenterRecord): ?>
                                    <strong><?= htmlspecialchars((string) ($remoteDpjp['local_name'] ?? ($remoteDpjp['name'] ?? '-')), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs">Citizen ID: <?= htmlspecialchars((string) ($remoteDpjp['local_citizen_id'] ?? ($remoteDpjp['staff_id'] ?? '-')), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php else: ?>
                                    <strong><?= htmlspecialchars((string)($record['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)($record['doctor_position'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="medical-side-card">
                                <span class="medical-side-card__label">Asisten Operasi</span>
                                <?php if ($isRemoteMedicalCenterRecord && $remoteAssistants !== []): ?>
                                    <?php foreach ($remoteAssistants as $assistantKey => $assistantValue): ?>
                                        <?php if (is_array($assistantValue)) {
                                            $assistantName = ($assistantValue['local_name'] ?? '') ?: ($assistantValue['remote_name'] ?? $assistantValue['name'] ?? $assistantValue['full_name'] ?? $assistantValue['nama'] ?? '-');
                                            $assistantStaffId = $assistantValue['local_citizen_id'] ?? ($assistantValue['staff_id'] ?? '');
                                        } else {
                                            $assistantName = $assistantValue;
                                            $assistantStaffId = '';
                                        } ?>
                                        <div class="medical-assistant-item">
                                            <strong><?= htmlspecialchars((string) $assistantName, ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($assistantStaffId !== ''): ?><div class="meta-text-xs">Staff ID: <?= htmlspecialchars((string) $assistantStaffId, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php elseif ($assistants !== []): ?>
                                    <?php foreach ($assistants as $assistant): ?>
                                        <div class="medical-assistant-item">
                                            <strong><?= htmlspecialchars((string) ($assistant['full_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string) ($assistant['position'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <strong>-</strong>
                                    <div class="meta-text-xs">-</div>
                                <?php endif; ?>
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
                                        <a href="<?= htmlspecialchars(ems_secure_file_url((string)$record['ktp_file_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn-secondary btn-sm">Buka</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($record['ktp_file_path'])): ?>
                                    <img src="<?= htmlspecialchars(ems_secure_file_url((string)$record['ktp_file_path']), ENT_QUOTES, 'UTF-8') ?>" alt="KTP" class="medical-document-card__image">
                                <?php else: ?>
                                    <div class="medical-document-card__empty">Dokumen KTP belum tersedia.</div>
                                <?php endif; ?>
                            </div>

                            <div class="medical-document-card">
                                <div class="medical-document-card__head">
                                    <span class="medical-side-card__label">Foto MRI/CT Scan/USG/Dll</span>
                                </div>
                                <?php if ($isRemoteMedicalCenterRecord && $remotePhotos !== []): ?>
                                    <div class="medical-document-gallery">
                                        <?php foreach ($remotePhotos as $remotePhoto): ?>
                                            <?php $remotePhotoUrl = ems_medical_center_photo_url($remotePhoto); ?>
                                            <?php if ($remotePhotoUrl === '') continue; ?>
                                            <a href="<?= htmlspecialchars($remotePhotoUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="medical-document-gallery__item">
                                                <img src="<?= htmlspecialchars($remotePhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Foto remote Medical Center" class="medical-document-card__image">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($supportingImages !== []): ?>
                                    <div class="medical-document-gallery">
                                        <?php foreach ($supportingImages as $image): ?>
                                            <?php $imagePath = trim((string)($image['file_path'] ?? '')); ?>
                                            <?php if ($imagePath === '') continue; ?>
                                            <a href="<?= htmlspecialchars(ems_secure_file_url($imagePath), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="medical-document-gallery__item">
                                                <img src="<?= htmlspecialchars(ems_secure_file_url($imagePath), ENT_QUOTES, 'UTF-8') ?>" alt="Foto pendukung" class="medical-document-card__image">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="medical-document-card__empty">Foto MRI/CT Scan/USG/Dll belum tersedia.</div>
                                <?php endif; ?>
                            </div>

                            <?php if ($isForensicPrivate): ?>
                            <div class="medical-document-card">
                                <div class="medical-document-card__head">
                                    <span class="medical-side-card__label">Surat Permohonan Visum (DOJ/Instansi Lain)</span>
                                    <?php if (!empty($record['visum_letter_file_path'])): ?>
                                        <a href="<?= htmlspecialchars(ems_secure_file_url((string) $record['visum_letter_file_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn-secondary btn-sm">Buka</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($record['visum_letter_file_path'])): ?>
                                    <img src="<?= htmlspecialchars(ems_secure_file_url((string) $record['visum_letter_file_path']), ENT_QUOTES, 'UTF-8') ?>" alt="Surat Permohonan Visum" class="medical-document-card__image">
                                <?php else: ?>
                                    <div class="medical-document-card__empty">Surat permohonan visum belum diunggah.</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($isForensicPrivate && $canViewForensicHistory): ?>
                <div class="card card-section">
                    <div class="card-header">History / Log Aktivitas (khusus tim Forensic)</div>
                    <div class="card-body">
                        <?php if ($activityLogs === []): ?>
                            <div class="medical-document-card__empty">Belum ada aktivitas tercatat.</div>
                        <?php else: ?>
                            <div class="medical-stack">
                                <?php foreach ($activityLogs as $logRow): ?>
                                    <div class="medical-side-card">
                                        <span class="medical-side-card__label"><?= htmlspecialchars(ems_forensic_private_action_label((string) $logRow['action']), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars((string) ($logRow['actor_name_snapshot'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(date('d M Y H:i', strtotime((string) $logRow['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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
    min-width: 0;
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
    grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
    gap: 1.5rem;
}

.medical-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    align-items: stretch;
}

.medical-info-grid--history {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.medical-info-grid--vitals {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.medical-info-grid--action {
    grid-template-columns: minmax(0, 1.8fr) repeat(2, minmax(0, 0.8fr));
}

.medical-info-grid--anesthesia {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.medical-detail-sections {
    display: grid;
    gap: 1rem;
}

.medical-detail-block {
    border: 1px solid rgba(148, 163, 184, 0.2);
    background: rgba(248, 250, 252, 0.82);
    border-radius: 1rem;
    padding: 1rem 1.1rem;
}

.medical-detail-value {
    color: #1e293b;
    white-space: pre-line;
    line-height: 1.72;
}

.medical-richtext {
    color: #1e293b;
    line-height: 1.72;
}

.medical-richtext h1 {
    margin: 0 0 2rem;
    text-align: center;
    font-size: 2rem;
    font-weight: 800;
}

.medical-richtext h2,
.medical-richtext h3,
.medical-richtext h4 {
    color: #0f172a;
    margin-top: 2.2rem;
    margin-bottom: 0.8rem;
}

.medical-richtext p {
    margin: 0.5rem 0;
}

.medical-richtext p + p {
    margin-top: 0.85rem;
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
    margin: 0.8rem 0 1rem;
    padding-left: 1.25rem;
}

.medical-richtext li + li {
    margin-top: 0.35rem;
}

.medical-stack {
    display: grid;
    gap: 1rem;
}

.medical-assistant-item + .medical-assistant-item {
    margin-top: 0.85rem;
    padding-top: 0.85rem;
    border-top: 1px dashed rgba(148, 163, 184, 0.35);
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

.medical-document-gallery {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.medical-document-gallery__item {
    display: block;
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
