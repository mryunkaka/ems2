<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/dispatcher.php';
if (!isset($_GET['range'])) {
    $_GET['range'] = 'today';
}
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Dispatcher';

ems_dispatcher_ensure_tables($pdo);

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// Halaman ini bisa diakses semua user login (untuk melihat papan status),
// jadi flash error guard division yang tersisa dari redirect halaman lain diabaikan.
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
$canManage = ems_is_manager_plus_role($userRole);
$canHardDelete = ems_is_director_role($userRole);
$unitCode = ems_effective_unit($pdo, $user);
$csrfToken = generateCsrfToken();

$statusOptions = ems_dispatcher_status_options();

// ===============================
// DAFTAR MEDIS (division Medis, aktif, unit sama)
// ===============================
$stmtMedics = $pdo->prepare("
    SELECT id, full_name, position
    FROM user_rh
    WHERE is_active = 1
      AND division = 'Medis'
      AND COALESCE(unit_code, 'roxwood') = ?
    ORDER BY full_name ASC
");
$stmtMedics->execute([$unitCode]);
$allMedics = $stmtMedics->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// ASSIGNMENT AKTIF + ANGGOTA
// ===============================
$stmtActive = $pdo->prepare("
    SELECT da.*,
           GROUP_CONCAT(dam.medic_user_id ORDER BY dam.id SEPARATOR ',') AS member_ids,
           GROUP_CONCAT(dam.medic_name_snapshot ORDER BY dam.id SEPARATOR ', ') AS member_names
    FROM dispatcher_assignments da
    JOIN dispatcher_assignment_members dam ON dam.assignment_id = da.id
    WHERE da.status = 'active' AND da.unit_code = ?
    GROUP BY da.id
    ORDER BY da.started_at DESC
");
$stmtActive->execute([$unitCode]);
$activeAssignments = $stmtActive->fetchAll(PDO::FETCH_ASSOC);

$medicAssignmentMap = [];
foreach ($activeAssignments as $assignment) {
    $memberIds = array_filter(array_map('intval', explode(',', (string)($assignment['member_ids'] ?? ''))));
    foreach ($memberIds as $mid) {
        $medicAssignmentMap[$mid] = $assignment;
    }
}

// ===============================
// RIWAYAT (cleared) SESUAI RANGE TANGGAL
// ===============================
$stmtHistory = $pdo->prepare("
    SELECT da.*,
           GROUP_CONCAT(dam.medic_name_snapshot ORDER BY dam.id SEPARATOR ', ') AS member_names
    FROM dispatcher_assignments da
    JOIN dispatcher_assignment_members dam ON dam.assignment_id = da.id
    WHERE da.status = 'cleared' AND da.unit_code = ?
      AND da.started_at BETWEEN ? AND ?
    GROUP BY da.id
    ORDER BY da.cleared_at DESC
    LIMIT 300
");
$stmtHistory->execute([$unitCode, $rangeStart, $rangeEnd]);
$historyAssignments = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// RINGKASAN
// ===============================
$totalMedics = count($allMedics);
$totalBertugas = count($medicAssignmentMap);
$totalTersedia = max(0, $totalMedics - $totalBertugas);
$totalResponLapangan = 0;
foreach ($activeAssignments as $a) {
    if (($a['status_code'] ?? '') === 'respon_lapangan') {
        $totalResponLapangan++;
    }
}

function dispatcherFormatInfo(array $assignment): string
{
    $parts = [];
    $coordinate = trim((string)($assignment['coordinate'] ?? ''));
    $location = trim((string)($assignment['location_name'] ?? ''));
    if ($coordinate !== '') {
        $parts[] = 'Koordinat: ' . htmlspecialchars($coordinate) . ($location !== '' ? ' (' . htmlspecialchars($location) . ')' : '');
    } elseif ($location !== '') {
        $parts[] = 'Lokasi: ' . htmlspecialchars($location);
    }

    $koordinasi = trim((string)($assignment['koordinasi_note'] ?? ''));
    if ($koordinasi !== '') {
        $parts[] = 'Koordinasi: ' . htmlspecialchars($koordinasi);
    }

    $note = trim((string)($assignment['note'] ?? ''));
    if ($note !== '') {
        $parts[] = 'Catatan: ' . htmlspecialchars($note);
    }

    return $parts === [] ? '-' : implode('<br>', $parts);
}

function dispatcherSecondsSince(string $datetime): int
{
    $start = strtotime($datetime);
    return $start ? max(0, time() - $start) : 0;
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">

        <h1 class="page-title">Dispatcher</h1>
        <p class="page-subtitle">Papan koordinasi status &amp; respon lapangan seluruh medis unit <?= htmlspecialchars(ems_unit_hospital_name($unitCode)) ?>.</p>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string)$message, 'success', 'Dispatcher') ?>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <?= ems_render_toast_script((string)$warning, 'warning', 'Dispatcher') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string)$error, 'error', 'Dispatcher', 6800) ?>
        <?php endforeach; ?>

        <div class="dispatcher-summary-grid">
            <div class="card card-section dispatcher-summary-card">
                <div class="meta-text-xs">Total Medis Terdaftar</div>
                <div class="dispatcher-summary-value"><?= $totalMedics ?></div>
            </div>
            <div class="card card-section dispatcher-summary-card">
                <div class="meta-text-xs">Tersedia</div>
                <div class="dispatcher-summary-value text-success"><?= $totalTersedia ?></div>
            </div>
            <div class="card card-section dispatcher-summary-card">
                <div class="meta-text-xs">Sedang Bertugas</div>
                <div class="dispatcher-summary-value"><?= $totalBertugas ?></div>
            </div>
            <div class="card card-section dispatcher-summary-card">
                <div class="meta-text-xs">Respon Lapangan Aktif</div>
                <div class="dispatcher-summary-value text-danger"><?= $totalResponLapangan ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header-actions card-section">
                <div class="card-header-actions-title">Papan Status Medis</div>
                <?php if ($canManage): ?>
                    <div class="card-header-actions-right">
                        <button type="button" id="btnOpenAssignModal" class="btn-success">
                            <?= ems_icon('megaphone', 'h-4 w-4') ?><span>Atur Status Baru</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="table-wrapper">
                <table id="dispatcherBoardTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Status</th>
                            <th>Rekan Bertugas</th>
                            <th>Info</th>
                            <th>Sejak</th>
                            <th>Durasi</th>
                            <?php if ($canManage): ?><th>Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allMedics as $medic): ?>
                            <?php
                            $medicId = (int)$medic['id'];
                            $assignment = $medicAssignmentMap[$medicId] ?? null;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($medic['full_name']) ?></td>
                                <td><?= htmlspecialchars(ems_position_label($medic['position'] ?? '-')) ?></td>
                                <?php if ($assignment): ?>
                                    <?php
                                    $statusLabel = ems_dispatcher_status_label((string)$assignment['status_code'], $assignment['status_label_custom'] ?? null);
                                    $badgeClass = ems_dispatcher_status_badge_class((string)$assignment['status_code']);
                                    $memberNames = array_filter(array_map('trim', explode(',', (string)($assignment['member_names'] ?? ''))), static fn($n) => $n !== '' && strcasecmp($n, $medic['full_name']) !== 0);
                                    ?>
                                    <td><span class="<?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                    <td><?= $memberNames ? htmlspecialchars(implode(', ', $memberNames)) : '-' ?></td>
                                    <td><?= dispatcherFormatInfo($assignment) ?></td>
                                    <td><?= htmlspecialchars(ems_dispatcher_datetime_label($assignment['started_at'] ?? null)) ?></td>
                                    <td><?= htmlspecialchars(ems_dispatcher_duration_label(dispatcherSecondsSince((string)$assignment['started_at']))) ?></td>
                                    <?php if ($canManage): ?>
                                        <td class="action-row-nowrap">
                                            <button type="button" class="btn-secondary action-icon-btn btn-dispatcher-reassign"
                                                data-medic-ids="<?= (int)$medicId ?>" title="Ubah Status">
                                                <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                            </button>
                                            <form method="POST" action="dispatcher_action.php" class="inline js-dispatcher-clear" data-confirm="Clear tugas/status untuk <?= htmlspecialchars($statusLabel) ?>?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="clear_assignment">
                                                <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                                                <button type="submit" class="btn-danger action-icon-btn" title="Clear">
                                                    <?= ems_icon('check-circle', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <td><span class="badge-success">Tersedia</span></td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <?php if ($canManage): ?>
                                        <td class="action-row-nowrap">
                                            <button type="button" class="btn-secondary action-icon-btn btn-dispatcher-reassign"
                                                data-medic-ids="<?= (int)$medicId ?>" title="Atur Status">
                                                <?= ems_icon('megaphone', 'h-4 w-4') ?>
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header-actions card-section">
                <div class="card-header-actions-title">Tugas / Respon Aktif</div>
            </div>
            <div class="table-wrapper">
                <table id="dispatcherActiveTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Status</th>
                            <th>Anggota</th>
                            <th>Info</th>
                            <th>Mulai</th>
                            <th>Durasi</th>
                            <?php if ($canManage): ?><th>Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeAssignments as $assignment): ?>
                            <?php
                            $statusLabel = ems_dispatcher_status_label((string)$assignment['status_code'], $assignment['status_label_custom'] ?? null);
                            $badgeClass = ems_dispatcher_status_badge_class((string)$assignment['status_code']);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$assignment['assignment_code']) ?></td>
                                <td><span class="<?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                <td><?= htmlspecialchars((string)($assignment['member_names'] ?? '-')) ?></td>
                                <td><?= dispatcherFormatInfo($assignment) ?></td>
                                <td><?= htmlspecialchars(ems_dispatcher_datetime_label($assignment['started_at'] ?? null)) ?></td>
                                <td><?= htmlspecialchars(ems_dispatcher_duration_label(dispatcherSecondsSince((string)$assignment['started_at']))) ?></td>
                                <?php if ($canManage): ?>
                                    <td class="action-row-nowrap">
                                        <form method="POST" action="dispatcher_action.php" class="inline js-dispatcher-clear" data-confirm="Clear tugas ini?">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="clear_assignment">
                                            <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                                            <button type="submit" class="btn-danger action-icon-btn" title="Clear">
                                                <?= ems_icon('check-circle', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header-actions card-section">
                <div class="card-header-actions-title">Riwayat Tugas (<?= htmlspecialchars($rangeLabel) ?>)</div>
                <div class="card-header-actions-right">
                    <form method="GET" class="filter-bar" id="dispatcherRangeForm">
                        <select name="range" id="dispatcherRangeSelect" class="form-control">
                            <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Hari Ini</option>
                            <option value="yesterday" <?= $range === 'yesterday' ? 'selected' : '' ?>>Kemarin</option>
                            <option value="last7" <?= $range === 'last7' ? 'selected' : '' ?>>7 Hari Terakhir</option>
                            <option value="week4" <?= $range === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                            <option value="month1" <?= $range === 'month1' ? 'selected' : '' ?>>Bulan Ini</option>
                            <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                        <input type="date" name="from" id="dispatcherRangeFrom" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>" class="form-control<?= $range === 'custom' ? '' : ' hidden' ?>">
                        <input type="date" name="to" id="dispatcherRangeTo" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>" class="form-control<?= $range === 'custom' ? '' : ' hidden' ?>">
                        <button type="submit" class="btn-secondary">Terapkan</button>
                    </form>
                </div>
            </div>
            <div class="table-wrapper">
                <table id="dispatcherHistoryTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Status</th>
                            <th>Anggota</th>
                            <th>Info</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Durasi</th>
                            <th>Di-clear Oleh</th>
                            <?php if ($canHardDelete): ?><th>Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyAssignments as $assignment): ?>
                            <?php
                            $statusLabel = ems_dispatcher_status_label((string)$assignment['status_code'], $assignment['status_label_custom'] ?? null);
                            $badgeClass = ems_dispatcher_status_badge_class((string)$assignment['status_code']);
                            $startTs = strtotime((string)$assignment['started_at']) ?: 0;
                            $endTs = strtotime((string)$assignment['cleared_at']) ?: $startTs;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$assignment['assignment_code']) ?></td>
                                <td><span class="<?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                <td><?= htmlspecialchars((string)($assignment['member_names'] ?? '-')) ?></td>
                                <td><?= dispatcherFormatInfo($assignment) ?></td>
                                <td><?= htmlspecialchars(ems_dispatcher_datetime_label($assignment['started_at'] ?? null)) ?></td>
                                <td><?= htmlspecialchars(ems_dispatcher_datetime_label($assignment['cleared_at'] ?? null)) ?></td>
                                <td><?= htmlspecialchars(ems_dispatcher_duration_label(max(0, $endTs - $startTs))) ?></td>
                                <td><?= htmlspecialchars((string)($assignment['cleared_by_name_snapshot'] ?? '-')) ?></td>
                                <?php if ($canHardDelete): ?>
                                    <td class="action-row-nowrap">
                                        <form method="POST" action="dispatcher_action.php" class="inline js-dispatcher-delete" data-confirm="Hapus riwayat tugas ini secara permanen?">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_assignment">
                                            <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                                            <button type="submit" class="btn-danger action-icon-btn" title="Hapus">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>

<?php if ($canManage): ?>
<div id="dispatcherAssignModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Atur Status Dispatcher</div>
            <button type="button" class="modal-close-btn btn-dispatcher-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <form method="POST" action="dispatcher_action.php" class="form modal-form" id="dispatcherAssignForm">
            <div class="modal-content">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="create_assignment">
                <input type="hidden" name="medic_ids" id="dispatcherMedicIdsInput" value="">

                <div class="form-group">
                    <label>Pilih Medis (solo, berpasangan, atau grup)</label>
                    <input type="text" id="dispatcherMedicSearch" placeholder="Ketik nama medis..." autocomplete="off">
                    <div id="dispatcherMedicSuggestions" class="ems-suggestion-box"></div>
                </div>
                <div class="form-group">
                    <label>Medis Terpilih</label>
                    <div id="dispatcherSelectedMedics" class="dispatcher-chip-list"></div>
                </div>

                <div class="form-group">
                    <label for="dispatcherStatusCode">Status</label>
                    <select name="status_code" id="dispatcherStatusCode" required>
                        <?php foreach ($statusOptions as $code => $option): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($option['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group hidden" id="dispatcherCustomLabelGroup">
                    <label for="dispatcherCustomLabel">Nama Status (khusus "Lainnya")</label>
                    <input type="text" name="status_label_custom" id="dispatcherCustomLabel" maxlength="100">
                </div>

                <div class="form-group hidden" id="dispatcherLocationGroup">
                    <label for="dispatcherCoordinate">Koordinat</label>
                    <input type="text" name="coordinate" id="dispatcherCoordinate" placeholder="Contoh: 456, Vinewood" maxlength="100">
                </div>
                <div class="form-group hidden" id="dispatcherLocationNameGroup">
                    <label for="dispatcherLocationName">Nama Tempat (opsional)</label>
                    <input type="text" name="location_name" id="dispatcherLocationName" maxlength="150">
                </div>

                <div class="form-group">
                    <label for="dispatcherKoordinasiNote">Koordinasi Radio / Instansi (opsional)</label>
                    <textarea name="koordinasi_note" id="dispatcherKoordinasiNote" rows="2" placeholder="Contoh: koordinasi freq 3 dengan LSPD"></textarea>
                </div>
                <div class="form-group">
                    <label for="dispatcherNote">Catatan (opsional)</label>
                    <textarea name="note" id="dispatcherNote" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-dispatcher-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan Status</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
    .dispatcher-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }
    .dispatcher-summary-value {
        font-size: 26px;
        font-weight: 800;
        margin-top: 4px;
    }
    .dispatcher-chip-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .dispatcher-medic-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        background: rgba(14, 165, 233, 0.12);
        color: #0369a1;
        font-size: 12.5px;
        font-weight: 600;
    }
    .dispatcher-chip-remove {
        border: none;
        background: transparent;
        color: inherit;
        cursor: pointer;
        font-weight: 800;
        line-height: 1;
        padding: 0;
    }
</style>

<script>
(function () {
    'use strict';

    var medics = <?= json_encode(array_map(static fn($m) => [
        'id' => (int)$m['id'],
        'name' => $m['full_name'],
        'jabatan' => ems_position_label($m['position'] ?? '-'),
    ], $allMedics), JSON_UNESCAPED_UNICODE) ?>;

    var locationStatuses = <?= json_encode(array_keys(array_filter($statusOptions, static fn($o) => !empty($o['requires_location'])))) ?>;
    var customLabelStatuses = <?= json_encode(array_keys(array_filter($statusOptions, static fn($o) => !empty($o['requires_custom_label'])))) ?>;

    var hiddenInput = document.getElementById('dispatcherMedicIdsInput');
    var searchInput = document.getElementById('dispatcherMedicSearch');
    var suggestionBox = document.getElementById('dispatcherMedicSuggestions');
    var selectedList = document.getElementById('dispatcherSelectedMedics');
    var selected = [];

    function renderSelected() {
        selectedList.innerHTML = '';
        if (selected.length === 0) {
            selectedList.innerHTML = '<span class="meta-text-xs">Belum ada medis dipilih.</span>';
        }
        selected.forEach(function (m) {
            var chip = document.createElement('span');
            chip.className = 'dispatcher-medic-chip';
            chip.innerHTML = m.name + ' (' + m.jabatan + ') <button type="button" data-id="' + m.id + '" class="dispatcher-chip-remove">&times;</button>';
            selectedList.appendChild(chip);
        });
        hiddenInput.value = selected.map(function (m) { return m.id; }).join(',');
        selectedList.querySelectorAll('.dispatcher-chip-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.dataset.id, 10);
                selected = selected.filter(function (m) { return m.id !== id; });
                renderSelected();
            });
        });
    }

    function addMedic(id) {
        if (selected.some(function (m) { return m.id === id; })) { return; }
        var medic = medics.find(function (m) { return m.id === id; });
        if (!medic) { return; }
        selected.push(medic);
        renderSelected();
    }

    function setSelectedByIds(ids) {
        selected = medics.filter(function (m) { return ids.indexOf(m.id) !== -1; });
        renderSelected();
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var keyword = searchInput.value.trim().toLowerCase();
            suggestionBox.innerHTML = '';
            if (keyword === '') { suggestionBox.style.display = 'none'; return; }

            var matches = medics.filter(function (m) {
                return m.name.toLowerCase().indexOf(keyword) !== -1 && !selected.some(function (s) { return s.id === m.id; });
            }).slice(0, 8);

            if (matches.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'medic-suggestion-item';
                empty.textContent = 'Tidak ada medis dengan nama tersebut.';
                suggestionBox.appendChild(empty);
                suggestionBox.style.display = 'block';
                return;
            }

            matches.forEach(function (m) {
                var item = document.createElement('div');
                item.className = 'medic-suggestion-item';
                item.textContent = m.name + ' (' + m.jabatan + ')';
                item.addEventListener('click', function () {
                    addMedic(m.id);
                    searchInput.value = '';
                    suggestionBox.style.display = 'none';
                });
                suggestionBox.appendChild(item);
            });
            suggestionBox.style.display = 'block';
        });

        document.addEventListener('click', function (event) {
            if (event.target !== searchInput && !suggestionBox.contains(event.target)) {
                suggestionBox.style.display = 'none';
            }
        });
    }

    function toggleConditionalFields() {
        var statusCode = document.getElementById('dispatcherStatusCode').value;
        var locationGroup = document.getElementById('dispatcherLocationGroup');
        var locationNameGroup = document.getElementById('dispatcherLocationNameGroup');
        var customLabelGroup = document.getElementById('dispatcherCustomLabelGroup');

        locationGroup.classList.toggle('hidden', locationStatuses.indexOf(statusCode) === -1);
        locationNameGroup.classList.toggle('hidden', locationStatuses.indexOf(statusCode) === -1);
        customLabelGroup.classList.toggle('hidden', customLabelStatuses.indexOf(statusCode) === -1);
    }

    var statusSelect = document.getElementById('dispatcherStatusCode');
    if (statusSelect) {
        statusSelect.addEventListener('change', toggleConditionalFields);
    }

    function openModal(modal) {
        if (!modal) { return; }
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }
    function closeModal(modal) {
        if (!modal) { return; }
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    var assignModal = document.getElementById('dispatcherAssignModal');
    var assignForm = document.getElementById('dispatcherAssignForm');

    document.getElementById('btnOpenAssignModal')?.addEventListener('click', function () {
        assignForm.reset();
        setSelectedByIds([]);
        toggleConditionalFields();
        if (searchInput) { searchInput.value = ''; }
        if (suggestionBox) { suggestionBox.style.display = 'none'; }
        openModal(assignModal);
    });

    document.querySelectorAll('.btn-dispatcher-reassign').forEach(function (button) {
        button.addEventListener('click', function () {
            assignForm.reset();
            var ids = String(button.dataset.medicIds || '').split(',').map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0; });
            setSelectedByIds(ids);
            toggleConditionalFields();
            if (searchInput) { searchInput.value = ''; }
            if (suggestionBox) { suggestionBox.style.display = 'none'; }
            openModal(assignModal);
        });
    });

    document.querySelectorAll('.btn-dispatcher-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () { closeModal(assignModal); });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeModal(assignModal); }
    });

    assignForm?.addEventListener('submit', function (event) {
        if (selected.length === 0) {
            event.preventDefault();
            window.alert('Pilih minimal 1 medis terlebih dahulu.');
        }
    });

    document.querySelectorAll('.js-dispatcher-clear, .js-dispatcher-delete').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm || 'Yakin ingin melanjutkan?')) {
                event.preventDefault();
            }
        });
    });

    var rangeSelect = document.getElementById('dispatcherRangeSelect');
    if (rangeSelect) {
        rangeSelect.addEventListener('change', function () {
            var isCustom = rangeSelect.value === 'custom';
            document.getElementById('dispatcherRangeFrom').classList.toggle('hidden', !isCustom);
            document.getElementById('dispatcherRangeTo').classList.toggle('hidden', !isCustom);
        });
    }

    var datatableLanguageUrl = '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#dispatcherBoardTable').DataTable({ pageLength: 25, scrollX: true, autoWidth: false, order: [], language: { url: datatableLanguageUrl } });
        jQuery('#dispatcherActiveTable').DataTable({ pageLength: 10, scrollX: true, autoWidth: false, order: [[4, 'desc']], language: { url: datatableLanguageUrl } });
        jQuery('#dispatcherHistoryTable').DataTable({ pageLength: 10, scrollX: true, autoWidth: false, order: [[5, 'desc']], language: { url: datatableLanguageUrl } });
    }
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
