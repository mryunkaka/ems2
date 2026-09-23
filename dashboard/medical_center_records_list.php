<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/medical_records_api.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Rekam Medis Medical Center | Farmasi EMS';
$search = trim((string) ($_GET['search'] ?? ''));
$records = [];
$source = 'local_database';
$syncError = null;
$pagesFetched = 0;
$remoteRecordsReceived = 0;

$where = [
    "r.source_provider = 'medical_center'",
    "r.source_hospital = 'roxwood'",
];
$params = [];
if ($search !== '') {
    $where[] = '(r.remote_record_id LIKE ? OR r.record_code LIKE ? OR r.patient_name LIKE ? OR r.remote_payload_json LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

$stmt = $pdo->prepare(
    'SELECT r.*, r.remote_event_at AS _event_at
     FROM medical_records r
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY r.remote_event_at DESC, r.id DESC'
);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$remoteRecordsReceived = count($records);
foreach ($records as &$record) {
    $record['_event_at'] = ems_medical_center_parse_datetime($record['_event_at'] ?? null);
}
unset($record);

$syncStmt = $pdo->query(
    "SELECT status, error_message, pages_fetched, records_received
     FROM medical_record_api_sync_runs
     WHERE provider = 'medical_center' AND hospital_code = 'roxwood'
     ORDER BY id DESC LIMIT 1"
);
$latestSync = $syncStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (($latestSync['status'] ?? '') === 'failed') {
    $syncError = (string) ($latestSync['error_message'] ?? 'GET Medical Center gagal.');
} elseif (($latestSync['status'] ?? '') === 'needs_review') {
    $syncError = 'Sebagian data Medical Center perlu ditinjau.';
}
$pagesFetched = (int) ($latestSync['pages_fetched'] ?? 0);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function medicalCenterRemoteText(mixed $value, string $fallback = '-'): string
{
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : $fallback;
}

function medicalCenterRemoteHtml(mixed $value, string $fallback = '-'): string
{
    return htmlspecialchars(medicalCenterRemoteText($value, $fallback), ENT_QUOTES, 'UTF-8');
}

function medicalCenterRemotePhotoUrl(mixed $photo): string
{
    return ems_medical_center_photo_url($photo);
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center mb-4 gap-4 flex-wrap">
            <div>
                <h1 class="page-title">Rekam Medis Medical Center</h1>
                <p class="page-subtitle">Tampilan read-only dari Medical Center. Input dilakukan di portal Medical Center.</p>
            </div>
            <span class="remote-readonly-badge">GET read-only</span>
        </div>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string) $message, 'info', 'Rekam Medis') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string) $error, 'error', 'Rekam Medis', 6800) ?>
        <?php endforeach; ?>

        <?php if ($syncError !== null): ?>
            <div class="remote-status remote-status-warning">
                <strong>Medical Center GET sedang bermasalah.</strong>
                <span><?= medicalCenterRemoteHtml($syncError) ?></span>
                <span>Data yang tampil berasal dari cache terakhir jika tersedia.</span>
            </div>
        <?php else: ?>
            <div class="remote-status remote-status-ok">
                <strong>Data mirror lokal Roxwood.</strong>
                <span>Halaman GET terakhir: <?= (int) $pagesFetched ?> · Data tersimpan: <?= count($records) ?></span>
            </div>
        <?php endif; ?>

        <div class="card card-section mb-4">
            <div class="card-body">
                <form method="GET" action="" class="flex gap-2 flex-wrap">
                    <input type="search" name="search" class="form-input flex-1" placeholder="Cari ID, nama pasien, diagnosis, operasi..." value="<?= medicalCenterRemoteHtml($search, '') ?>">
                    <button type="submit" class="btn-primary">Cari</button>
                    <?php if ($search !== ''): ?>
                        <a href="rekam_medis_list.php" class="btn-secondary">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header">Semua Data dari Medical Center</div>
            <div class="card-body">
                <?php if ($records === []): ?>
                    <div class="text-center py-8 text-gray-500">Tidak ada data remote yang sesuai.</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table id="medicalCenterRecordsTable" class="table-custom w-full" data-auto-datatable="true">
                            <thead>
                                <tr>
                                    <th>Tanggal Tindakan</th>
                                    <th>ID Remote</th>
                                    <th>No. Rekam Medis</th>
                                    <th>Nama Pasien</th>
                                    <th>DOB</th>
                                    <th>Jenis Kelamin</th>
                                    <th>Golongan Darah</th>
                                    <th>Lokasi</th>
                                    <th>Operasi</th>
                                    <th>Diagnosis</th>
                                    <th>Anestesi</th>
                                    <th>Status GET</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $index => $record): ?>
                                    <?php
                                    $templateId = 'remote-medical-record-' . $index;
                                    $photos = ems_medical_center_decode_array($record['remote_photos_json'] ?? []);
                                    ?>
                                    <tr>
                                        <td data-order="<?= $record['_event_at']?->getTimestamp() ?? 0 ?>"><?= $record['_event_at'] ? htmlspecialchars($record['_event_at']->format('d/m/Y H:i'), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                                        <td class="font-semibold">
                                            <a href="<?= htmlspecialchars((string) ($record['remote_record_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="text-primary hover:underline">
                                                <?= medicalCenterRemoteHtml($record['remote_record_id'] ?? '') ?>
                                            </a>
                                        </td>
                                        <td class="font-semibold"><?= medicalCenterRemoteHtml($record['record_code'] ?? '') ?></td>
                                        <td class="font-semibold"><?= medicalCenterRemoteHtml($record['patient_name'] ?? '') ?></td>
                                        <td><?= medicalCenterRemoteHtml($record['patient_dob'] ?? '') ?></td>
                                        <td><?= medicalCenterRemoteHtml($record['patient_gender'] ?? '') ?></td>
                                        <td><?= medicalCenterRemoteHtml($record['patient_blood_type'] ?? '') ?></td>
                                        <td><?= medicalCenterRemoteHtml($record['remote_location'] ?? '') ?></td>
                                        <td><?= medicalCenterRemoteHtml($record['remote_operation_name'] ?? '') ?></td>
                                        <td class="remote-truncate"><?= medicalCenterRemoteHtml($record['remote_diagnosis'] ?? '') ?></td>
                                        <td><?= medicalCenterRemoteHtml($record['remote_anesthesia_type'] ?? '') ?></td>
                                        <td><?= medicalCenterRemoteHtml($record['remote_sync_state'] ?? '') ?></td>
                                        <td>
                                            <a href="rekam_medis_view.php?id=<?= (int) $record['id'] ?>" class="btn-secondary btn-sm">Buka</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<style>
.remote-readonly-badge{display:inline-flex;padding:.45rem .8rem;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.remote-status{display:flex;gap:.65rem;flex-wrap:wrap;padding:.85rem 1rem;margin-bottom:1rem;border-radius:.8rem;font-size:.85rem}.remote-status-ok{background:#ecfdf5;color:#166534}.remote-status-warning{background:#fffbeb;color:#92400e}.remote-truncate{max-width:22rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
