<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/training_groups.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
if (ems_is_staff_role($user['role'] ?? '')) {
    $_SESSION['flash_errors'][] = 'Halaman generator kelompok hanya bisa diakses selain role Staff.';
    header('Location: index.php');
    exit;
}

$pageTitle = 'Generator Kelompok';
$currentUnit = ems_effective_unit($pdo, $user);
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function ems_training_valid_batch($value): ?int
{
    $batch = (int)$value;
    return ($batch >= 1 && $batch <= 26) ? $batch : null;
}

function ems_training_assignment_meta(?string $assignmentSource): array
{
    return match ((string)$assignmentSource) {
        'auto_online_fill' => [
            'card' => 'rounded-2xl border border-emerald-200 bg-emerald-50 p-3 mb-2',
            'badge' => 'badge-success',
            'label' => 'Baru Masuk Online',
        ],
        'manual_manager' => [
            'card' => 'rounded-2xl border border-sky-200 bg-sky-50 p-3 mb-2',
            'badge' => 'badge-info',
            'label' => 'Manager Pilihan',
        ],
        default => [
            'card' => 'rounded-2xl bg-white p-3 border border-slate-200 mb-2',
            'badge' => 'badge-secondary',
            'label' => 'Generate Awal',
        ],
    };
}

function ems_training_group_export_rows(array $groups): array
{
    $rows = [];
    foreach ($groups as $group) {
        $mentorNames = array_map(static fn(array $row): string => (string)($row['full_name'] ?? '-'), (array)($group['mentors'] ?? []));
        $memberNames = array_map(static function (array $row): string {
            $position = ems_position_label($row['position'] ?? '');
            return trim((string)($row['full_name'] ?? '-') . ' (' . $position . ')');
        }, (array)($group['trainees'] ?? []));

        $rows[] = [
            'group_code' => (string)($group['group_code'] ?? ''),
            'group_name' => (string)($group['group_name'] ?? ''),
            'philosophy' => (string)($group['group_philosophy'] ?? ''),
            'mentor_summary' => implode(', ', $mentorNames),
            'member_count' => count((array)($group['trainees'] ?? [])),
            'members' => implode(', ', $memberNames),
        ];
    }

    return $rows;
}

function ems_training_export_text(int $batch, array $groups): void
{
    $lines = ["Kelompok Aktif Batch {$batch}", str_repeat('=', 28)];
    foreach ($groups as $index => $group) {
        $lines[] = '';
        $lines[] = ($index + 1) . '. ' . (string)($group['group_name'] ?? '-');
        $lines[] = 'Kode: ' . (string)($group['group_code'] ?? '-');
        $lines[] = 'Filosofi: ' . (string)($group['group_philosophy'] ?? '-');
        $lines[] = 'Mentor: ' . implode(', ', array_map(static fn(array $row): string => (string)($row['full_name'] ?? '-'), (array)($group['mentors'] ?? [])));
        $lines[] = 'Anggota:';
        foreach ((array)($group['trainees'] ?? []) as $member) {
            $lines[] = '- ' . (string)($member['full_name'] ?? '-') . ' / ' . ems_position_label($member['position'] ?? '') . ' / ' . trim((string)($member['jenis_kelamin'] ?? '-'));
        }
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="kelompok-batch-' . $batch . '.txt"');
    echo implode("\r\n", $lines);
    exit;
}

function ems_training_export_excel(int $batch, array $groups): void
{
    $rows = ems_training_group_export_rows($groups);

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="kelompok-batch-' . $batch . '.xls"');

    echo '<table border="1">';
    echo '<tr>';
    echo '<th>Batch</th><th>Kode</th><th>Nama Kelompok</th><th>Filosofi</th><th>Mentor</th><th>Jumlah Anggota</th><th>Anggota</th>';
    echo '</tr>';

    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . $batch . '</td>';
        echo '<td>' . htmlspecialchars($row['group_code'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['philosophy'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['mentor_summary'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . (int)$row['member_count'] . '</td>';
        echo '<td>' . htmlspecialchars($row['members'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    exit;
}

$selectedBatch = ems_training_valid_batch($_GET['batch'] ?? null);
$tablesReady = ems_training_groups_tables_ready($pdo);
$availabilityTablesReady = ems_training_availability_tables_ready($pdo);
if (!$tablesReady) {
    $errors[] = 'Jalankan SQL `docs/sql/39_2026-05-16_training_groups.sql` terlebih dahulu.';
}
if (!$availabilityTablesReady) {
    $errors[] = 'Jalankan SQL `docs/sql/40_2026-05-16_training_user_availability.sql` terlebih dahulu untuk availability kelompok.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_batch'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $redirectBatch = ems_training_valid_batch($_POST['batch'] ?? null);
    if ($redirectBatch === null) {
        $errors[] = 'Batch wajib diisi angka 1 sampai 26.';
    } else {
        header('Location: training_group_generator.php?batch=' . $redirectBatch);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_training_groups'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $selectedBatch = ems_training_valid_batch($_POST['batch'] ?? null);
    $groupSize = (int)($_POST['group_size'] ?? 1);
    $preferredManagerIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['preferred_manager_ids'] ?? '')))));

    if ($selectedBatch === null) {
        $errors[] = 'Batch wajib dipilih dulu sebelum generate kelompok.';
    } elseif (!$tablesReady || !$availabilityTablesReady) {
        $errors[] = 'Tabel grup training belum tersedia.';
    } else {
        try {
            $result = ems_training_generate_groups(
                $pdo,
                $currentUnit,
                $selectedBatch,
                max(1, $groupSize),
                $preferredManagerIds,
                (int)($user['id'] ?? 0) ?: null
            );

            $_SESSION['flash_messages'][] = 'Kelompok berhasil digenerate: '
                . (int)$result['group_count']
                . ' grup, '
                . (int)$result['trainee_count']
                . ' medis online, '
                . (int)$result['manager_count']
                . ' manager online.';
            header('Location: training_group_generator.php?batch=' . $selectedBatch);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_training_groups'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $selectedBatch = ems_training_valid_batch($_POST['batch'] ?? null);
    if ($selectedBatch === null) {
        $errors[] = 'Batch tidak valid untuk menutup kelompok.';
    } elseif (!$tablesReady || !$availabilityTablesReady) {
        $errors[] = 'Tabel grup training belum tersedia.';
    } else {
        try {
            $closedCount = ems_training_close_active_groups($pdo, $currentUnit, $selectedBatch);
            $_SESSION['flash_messages'][] = $closedCount > 0
                ? ('Kelompok aktif batch ' . $selectedBatch . ' berhasil ditutup.')
                : ('Tidak ada kelompok aktif batch ' . $selectedBatch . ' yang perlu ditutup.');
            header('Location: training_group_generator.php?batch=' . $selectedBatch);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$activeGroups = [];
$onlineMembers = [];
$offlineMembers = [];
$onlineManagers = [];
$registeredBatchCount = 0;
$autoFillSummary = ['trainees_assigned' => 0, 'mentors_assigned' => 0];

if ($tablesReady && $availabilityTablesReady && $selectedBatch !== null) {
    $autoFillSummary = ems_training_auto_fill_groups($pdo, $currentUnit, $selectedBatch);
    if (($autoFillSummary['trainees_assigned'] ?? 0) > 0 || ($autoFillSummary['mentors_assigned'] ?? 0) > 0) {
        $messages[] = 'Auto-fill grup aktif menambahkan '
            . (int)($autoFillSummary['trainees_assigned'] ?? 0)
            . ' medis dan '
            . (int)($autoFillSummary['mentors_assigned'] ?? 0)
            . ' mentor online.';
    }

    $onlineMembers = ems_training_fetch_online_trainees($pdo, $currentUnit, $selectedBatch);
    $batchMembers = ems_training_fetch_batch_members($pdo, $currentUnit, $selectedBatch);
    $offlineMembers = array_values(array_filter($batchMembers, static function (array $member): bool {
        return ($member['availability_status'] ?? 'offline') !== 'online';
    }));
    $onlineManagers = ems_training_fetch_online_managers($pdo, $currentUnit);
    $registeredBatchCount = ems_training_fetch_registered_batch_member_count($pdo, $currentUnit, $selectedBatch);
    $activeGroups = ems_training_attach_group_members($pdo, ems_training_fetch_active_groups($pdo, $currentUnit, $selectedBatch));

    if (isset($_GET['export']) && $_GET['export'] === 'text') {
        ems_training_export_text($selectedBatch, $activeGroups);
    }
    if (isset($_GET['export']) && $_GET['export'] === 'excel') {
        ems_training_export_excel($selectedBatch, $activeGroups);
    }
}

$seedManagerIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['preferred_manager_ids'] ?? '')))));
$seedManagers = [];
foreach ($onlineManagers as $manager) {
    if (in_array((int)$manager['id'], $seedManagerIds, true)) {
        $seedManagers[] = [
            'id' => (int)$manager['id'],
            'full_name' => (string)$manager['full_name'],
            'role' => ems_role_label($manager['role'] ?? ''),
            'position' => ems_position_label($manager['position'] ?? ''),
        ];
    }
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <div class="card mb-4">
            <div class="card-header-between">
                <div>
                    <h1 class="page-title">Generator Kelompok</h1>
                    <p class="page-subtitle">Pilih batch dulu, lalu generate kelompok medis online dengan mentor online, nama alat medis, dan filosofi mentor.</p>
                </div>
                <div class="badge-info"><?= htmlspecialchars(ems_unit_hospital_name($currentUnit), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success mb-4"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="card card-section mb-4">
            <div class="card-header">
                <?= ems_icon('funnel', 'h-5 w-5') ?>
                <span>Pilih Batch</span>
            </div>
            <div class="card-body">
                <form method="post" class="filter-bar">
                    <?= csrfField() ?>
                    <input type="hidden" name="open_batch" value="1">
                    <div class="filter-group">
                        <label>Batch Yang Ingin Dibuat Kelompok</label>
                        <input type="number" name="batch" min="1" max="26" value="<?= htmlspecialchars((string)($selectedBatch ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Contoh: 8" required>
                    </div>
                    <div class="filter-group filter-action-end">
                        <button type="submit" class="btn btn-primary">Buka Batch</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedBatch === null): ?>
            <div class="card">
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-600">
                    Batch belum dipilih. Masukkan angka batch dulu agar halaman menampilkan medis online, manager online, dan kelompok aktif untuk batch tersebut.
                </div>
            </div>
        <?php else: ?>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-4">
                <div class="card">
                    <div class="meta-text">Batch Terpilih</div>
                    <div class="text-2xl font-bold text-slate-900 mt-2"><?= $selectedBatch ?></div>
                </div>
                <div class="card">
                    <div class="meta-text">Medis Terdaftar Batch <?= $selectedBatch ?></div>
                    <div class="text-2xl font-bold text-slate-900 mt-2"><?= $registeredBatchCount ?></div>
                </div>
                <div class="card">
                    <div class="meta-text">Medis Online Batch <?= $selectedBatch ?></div>
                    <div class="text-2xl font-bold text-slate-900 mt-2"><?= count($onlineMembers) ?></div>
                    <div class="meta-text mt-2">Posisi: Trainee, Paramedic, Co. Asst</div>
                </div>
                <div class="card">
                    <div class="meta-text">Manager Online Tersedia</div>
                    <div class="text-2xl font-bold text-slate-900 mt-2"><?= count($onlineManagers) ?></div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[410px_minmax(0,1fr)]">
                <div class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="card">
                            <div class="card-header">
                                <?= ems_icon('signal', 'h-5 w-5') ?>
                                <span>Online Batch <?= $selectedBatch ?> (<?= count($onlineMembers) ?>)</span>
                            </div>
                            <div class="space-y-2" style="max-height: 360px; overflow-y: auto; padding-right: 4px;">
                                <?php if ($onlineMembers === []): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                        Belum ada medis batch ini yang online.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($onlineMembers as $member): ?>
                                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3">
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$member['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta-text">Batch <?= (int)$member['batch'] ?> • <?= htmlspecialchars(ems_position_label($member['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <?= ems_icon('pause-circle', 'h-5 w-5') ?>
                                <span>Belum Online Batch <?= $selectedBatch ?> (<?= count($offlineMembers) ?>)</span>
                            </div>
                            <div class="space-y-2" style="max-height: 360px; overflow-y: auto; padding-right: 4px;">
                                <?php if ($offlineMembers === []): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                        Semua medis batch ini sudah online.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($offlineMembers as $member): ?>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$member['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta-text">Batch <?= (int)$member['batch'] ?> • <?= htmlspecialchars(ems_position_label($member['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <?= ems_icon('users', 'h-5 w-5') ?>
                            <span>Generate Kelompok Batch <?= $selectedBatch ?></span>
                        </div>
                        <form method="post" class="space-y-4">
                            <?= csrfField() ?>
                            <input type="hidden" name="generate_training_groups" value="1">
                            <input type="hidden" name="batch" value="<?= $selectedBatch ?>">
                            <input type="hidden" id="preferredManagerIds" name="preferred_manager_ids" value="<?= htmlspecialchars(implode(',', $seedManagerIds), ENT_QUOTES, 'UTF-8') ?>">

                            <div class="form-group">
                                <label>Batch Aktif</label>
                                <input type="number" value="<?= $selectedBatch ?>" class="form-control" readonly>
                            </div>

                            <div class="form-group">
                                <label for="groupSize">Berapa orang medis dalam 1 kelompok</label>
                                <input type="number" id="groupSize" name="group_size" min="1" max="20" value="<?= htmlspecialchars((string)($_POST['group_size'] ?? '3'), ENT_QUOTES, 'UTF-8') ?>" required>
                                <div class="meta-text mt-2">Termasuk posisi `Trainee`, `Paramedic`, dan `Co. Asst` yang sedang online.</div>
                            </div>

                            <div class="form-group relative">
                                <label for="managerSearchInput">Tambah Manager / Mentor Tersedia</label>
                                <input type="text" id="managerSearchInput" placeholder="Ketik nama manager online..." autocomplete="off">
                                <div id="managerSuggestionList" class="ems-suggestion-box" style="display:none;"></div>
                            </div>

                            <div class="form-group">
                                <label>Manager Yang Dipilih</label>
                                <div id="selectedManagerList" class="space-y-2"></div>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <button type="submit" class="btn-primary" <?= ($tablesReady && $availabilityTablesReady) ? '' : 'disabled' ?>>Generate Kelompok</button>
                                <button type="button" id="resetManagerDraftBtn" class="btn-secondary">
                                    <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                                    <span>Reset Draft</span>
                                </button>
                                <a href="<?= htmlspecialchars(ems_url('/dashboard/user_availability.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                                    <?= ems_icon('signal', 'h-4 w-4') ?>
                                    <span>Availability User</span>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="card">
                        <div class="card-header-between">
                            <div class="card-header">
                                <?= ems_icon('sparkles', 'h-5 w-5') ?>
                                <span>Kelompok Aktif Batch <?= $selectedBatch ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <form method="post" onsubmit="return confirm('Tutup semua kelompok aktif batch ini?');" style="display:inline-flex;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="close_training_groups" value="1">
                                    <input type="hidden" name="batch" value="<?= $selectedBatch ?>">
                                    <button type="submit" class="btn-danger btn-sm">
                                        <?= ems_icon('lock-closed', 'h-4 w-4') ?>
                                        <span>Tutup Kelompok Batch Ini</span>
                                    </button>
                                </form>
                                <a href="?batch=<?= $selectedBatch ?>&export=text" class="btn-secondary btn-sm">
                                    <?= ems_icon('document-text', 'h-4 w-4') ?>
                                    <span>Export Teks</span>
                                </a>
                                <a href="?batch=<?= $selectedBatch ?>&export=excel" class="btn-secondary btn-sm">
                                    <?= ems_icon('document-arrow-down', 'h-4 w-4') ?>
                                    <span>Export Excel</span>
                                </a>
                            </div>
                        </div>

                        <?php if ($activeGroups === []): ?>
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                Belum ada kelompok aktif untuk batch ini.
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($activeGroups as $group): ?>
                                    <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                        <div class="flex items-start justify-between gap-4 flex-wrap">
                                            <div>
                                                <div class="text-lg font-bold text-slate-900"><?= htmlspecialchars((string)$group['group_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="meta-text mt-1"><?= htmlspecialchars((string)$group['group_code'], ENT_QUOTES, 'UTF-8') ?> • Target <?= (int)$group['target_member_count'] ?> medis</div>
                                            </div>
                                            <span class="badge-info"><?= count((array)$group['trainees']) ?> medis • <?= count((array)$group['mentors']) ?> mentor</span>
                                        </div>
                                        <div class="mt-3 text-sm text-slate-700"><?= htmlspecialchars((string)($group['group_philosophy'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                                            <div>
                                                <div class="meta-text mb-2">Mentor</div>
                                                <?php if (($group['mentors'] ?? []) === []): ?>
                                                    <div class="text-sm text-slate-500">Belum ada mentor aktif.</div>
                                                <?php else: ?>
                                                    <?php foreach ($group['mentors'] as $mentor): ?>
                                                        <?php $mentorMeta = ems_training_assignment_meta($mentor['assignment_source'] ?? null); ?>
                                                        <div class="<?= htmlspecialchars($mentorMeta['card'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <div class="flex items-start justify-between gap-2">
                                                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$mentor['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <span class="<?= htmlspecialchars($mentorMeta['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($mentorMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            </div>
                                                            <div class="meta-text"><?= htmlspecialchars(ems_role_label($mentor['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars(ems_position_label($mentor['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="meta-text mb-2">Anggota Medis</div>
                                                <?php if (($group['trainees'] ?? []) === []): ?>
                                                    <div class="text-sm text-slate-500">Belum ada anggota medis aktif.</div>
                                                <?php else: ?>
                                                    <?php foreach ($group['trainees'] as $member): ?>
                                                        <?php $memberMeta = ems_training_assignment_meta($member['assignment_source'] ?? null); ?>
                                                        <div class="<?= htmlspecialchars($memberMeta['card'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <div class="flex items-start justify-between gap-2">
                                                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$member['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <span class="<?= htmlspecialchars($memberMeta['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($memberMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            </div>
                                                            <div class="meta-text">Batch <?= (int)$member['batch'] ?> • <?= htmlspecialchars(ems_position_label($member['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars((string)($member['jenis_kelamin'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($selectedBatch !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var unitCode = <?= json_encode($currentUnit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var searchInput = document.getElementById('managerSearchInput');
    var suggestionList = document.getElementById('managerSuggestionList');
    var selectedList = document.getElementById('selectedManagerList');
    var hiddenInput = document.getElementById('preferredManagerIds');
    var resetDraftButton = document.getElementById('resetManagerDraftBtn');
    var selectedManagers = <?= json_encode($seedManagers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var managerDirectory = {};
    var timer = null;
    var draftStorageKey = 'ems_training_group_manager_draft:' + unitCode + ':batch:<?= (int)$selectedBatch ?>';

    if (!searchInput || !suggestionList || !selectedList || !hiddenInput) {
        return;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function syncSelectedManagers() {
        hiddenInput.value = selectedManagers.map(function (item) { return item.id; }).join(',');
        try {
            window.localStorage.setItem(draftStorageKey, JSON.stringify(selectedManagers));
        } catch (_error) {
        }

        if (!selectedManagers.length) {
            selectedList.innerHTML = '<div class="meta-text">Belum ada manager tambahan. Jika kosong, sistem memakai seluruh manager online secara acak.</div>';
            return;
        }

        selectedList.innerHTML = selectedManagers.map(function (item) {
            return '<div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 flex items-center justify-between gap-3">'
                + '<div><div class="font-semibold text-slate-900">' + escapeHtml(item.full_name) + '</div>'
                + '<div class="meta-text">' + escapeHtml(item.role) + ' • ' + escapeHtml(item.position) + '</div></div>'
                + '<button type="button" class="btn-danger btn-sm remove-selected-manager" data-id="' + escapeHtml(item.id) + '">Hapus</button>'
                + '</div>';
        }).join('');

        selectedList.querySelectorAll('.remove-selected-manager').forEach(function (button) {
            button.addEventListener('click', function () {
                selectedManagers = selectedManagers.filter(function (item) {
                    return String(item.id) !== String(button.dataset.id);
                });
                syncSelectedManagers();
            });
        });
    }

    function addSelectedManager(item) {
        if (selectedManagers.some(function (entry) { return String(entry.id) === String(item.id); })) {
            return;
        }

        selectedManagers.push(item);
        syncSelectedManagers();
        searchInput.value = '';
        suggestionList.style.display = 'none';
    }

    function renderSuggestions(items) {
        managerDirectory = {};

        if (!Array.isArray(items) || !items.length) {
            suggestionList.innerHTML = '<div class="p-3 text-sm text-slate-500">Manager online tidak ditemukan.</div>';
            suggestionList.style.display = 'block';
            return;
        }

        items.forEach(function (item) {
            managerDirectory[String(item.id)] = item;
        });

        suggestionList.innerHTML = items.map(function (item) {
            return '<button type="button" class="w-full text-left rounded-2xl border border-slate-200 bg-white p-3 mb-2 js-add-manager" data-id="' + escapeHtml(item.id) + '">'
                + '<div class="font-semibold text-slate-900">' + escapeHtml(item.full_name || '') + '</div>'
                + '<div class="meta-text">' + escapeHtml([item.role, item.position, item.division].filter(Boolean).join(' • ')) + '</div>'
                + '<div class="meta-text mt-1 text-primary">Klik untuk tambah manager</div>'
                + '</button>';
        }).join('');

        suggestionList.querySelectorAll('.js-add-manager').forEach(function (button) {
            button.addEventListener('click', function () {
                var manager = managerDirectory[String(button.dataset.id)];
                if (manager) {
                    addSelectedManager(manager);
                }
            });
        });

        suggestionList.style.display = 'block';
    }

    async function searchManagers(query) {
        if (!query || query.length < 2) {
            suggestionList.style.display = 'none';
            return;
        }

        try {
            var response = await fetch(window.emsUrl('/ajax/search_available_managers.php') + '?q=' + encodeURIComponent(query) + '&unit_code=' + encodeURIComponent(unitCode), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) {
                suggestionList.innerHTML = '<div class="p-3 text-sm text-slate-500">Gagal mengambil data manager online.</div>';
                suggestionList.style.display = 'block';
                return;
            }

            var data = await response.json();
            renderSuggestions(data);
        } catch (_error) {
            suggestionList.innerHTML = '<div class="p-3 text-sm text-slate-500">Gagal mengambil data manager online.</div>';
            suggestionList.style.display = 'block';
        }
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            searchManagers(searchInput.value.trim());
        }, 180);
    });

    searchInput.addEventListener('blur', function () {
        setTimeout(function () {
            suggestionList.style.display = 'none';
        }, 150);
    });

    if (resetDraftButton) {
        resetDraftButton.addEventListener('click', function () {
            selectedManagers = [];
            try {
                window.localStorage.removeItem(draftStorageKey);
            } catch (_error) {
            }
            syncSelectedManagers();
            searchInput.value = '';
            suggestionList.style.display = 'none';
        });
    }

    try {
        var savedDraft = window.localStorage.getItem(draftStorageKey);
        if (savedDraft) {
            var parsed = JSON.parse(savedDraft);
            if (Array.isArray(parsed)) {
                selectedManagers = parsed.filter(function (item) {
                    return item && item.id && item.full_name;
                });
            }
        }
    } catch (_error) {
    }

    syncSelectedManagers();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
