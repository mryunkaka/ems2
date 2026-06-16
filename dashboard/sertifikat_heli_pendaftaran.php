<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../auth/csrf.php';

$pageTitle = 'Pendaftaran Sertifikat Heli';

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

// Filter out division access error to allow all logged-in users
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

$currentUser = $_SESSION['user_rh'] ?? null;
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserPosition = $currentUser['position'] ?? '';
$currentUserDivision = $currentUser['division'] ?? '';

// Get all registration periods (settings) ordered by creation
$allSettingsStmt = $pdo->query("SELECT * FROM sertifikat_heli_settings ORDER BY id ASC");
$allSettings = $allSettingsStmt->fetchAll(PDO::FETCH_ASSOC);

// Latest settings as fallback
$latestSettings = count($allSettings) > 0 ? $allSettings[count($allSettings) - 1] : null;

// Determine which period to display (default: latest / current)
$selectedSettingsId = isset($_GET['period']) ? (int)$_GET['period'] : ($latestSettings ? (int)$latestSettings['id'] : 0);

// If selected period not found, fallback to latest
$selectedSettings = null;
foreach ($allSettings as $s) {
    if ((int)$s['id'] === $selectedSettingsId) {
        $selectedSettings = $s;
        break;
    }
}
if (!$selectedSettings && $latestSettings) {
    $selectedSettings = $latestSettings;
    $selectedSettingsId = (int)$latestSettings['id'];
}

// Find index of selected period for navigation
$selectedPeriodIndex = -1;
foreach ($allSettings as $idx => $s) {
    if ((int)$s['id'] === $selectedSettingsId) {
        $selectedPeriodIndex = $idx;
        break;
    }
}
$prevSettings = ($selectedPeriodIndex > 0) ? $allSettings[$selectedPeriodIndex - 1] : null;
$nextSettings = ($selectedPeriodIndex >= 0 && $selectedPeriodIndex < count($allSettings) - 1) ? $allSettings[$selectedPeriodIndex + 1] : null;

// Use selected settings instead of latest for display
$settings = $selectedSettings;

$now = new DateTime();
$registrationOpen = false;
$registrationEnded = false;
$slotsAvailable = false;
$canRegister = false;
$remainingSlots = 0;
$registeredCount = 0;
$userAlreadyRegistered = false;
$activeSettingsId = $settings ? (int)$settings['id'] : 0;

if ($settings) {
    $startDatetime = new DateTime($settings['start_datetime']);
    $endDatetime = new DateTime($settings['end_datetime']);
    $maxSlots = (int)$settings['max_slots'];
    $minJabatan = trim($settings['min_jabatan'] ?? '');

    // Check if registration is open
    $registrationOpen = $now >= $startDatetime;
    $registrationEnded = $now > $endDatetime;

    // Count current registrations scoped to active settings period
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sertifikat_heli_registrations WHERE status = 'registered' AND settings_id = ?");
    $countStmt->execute([$activeSettingsId]);
    $registeredCount = (int)$countStmt->fetchColumn();
    $remainingSlots = $maxSlots - $registeredCount;
    $slotsAvailable = $registeredCount < $maxSlots;

    // Check if user already registered in current period
    if ($currentUserId > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM sertifikat_heli_registrations WHERE user_id = ? AND settings_id = ?");
        $checkStmt->execute([$currentUserId, $activeSettingsId]);
        $userAlreadyRegistered = (bool)$checkStmt->fetch();
    }

    // Check if user meets minimum position requirement (hierarchical check)
    $meetsPositionRequirement = true;
    if ($minJabatan !== '') {
        $meetsPositionRequirement = ems_position_meets_minimum($currentUserPosition, $minJabatan);
    }

    // User can register if:
    // - Registration is open
    // - Registration hasn't ended
    // - Slots are available
    // - User meets position requirement (if set)
    // Note: user who already registered CAN register again (e.g. if not accepted previously)
    $canRegister = $registrationOpen && !$registrationEnded && $slotsAvailable && $meetsPositionRequirement && $currentUserId > 0;
}

// Get registered users list - scoped to selected settings period
$registeredStmt = $pdo->prepare("
    SELECT
        shr.id,
        shr.user_id,
        shr.user_name,
        shr.user_jabatan,
        shr.user_division,
        shr.registered_at,
        shr.status
    FROM sertifikat_heli_registrations shr
    WHERE shr.settings_id = ?
    ORDER BY shr.registered_at ASC
");
$registeredStmt->execute([$activeSettingsId]);
$registeredUsers = $registeredStmt->fetchAll(PDO::FETCH_ASSOC);



include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title">Pendaftaran Sertifikat Heli</h1>
        <p class="page-subtitle">Halaman pendaftaran untuk sertifikat heli medis.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <!-- Period Filter & Navigation -->
        <?php if (count($allSettings) > 0): ?>
            <div class="card mb-4">
                <div class="card-header card-header-between">
                    <span class="inline-flex items-center gap-2">
                        <?= ems_icon('funnel', 'h-5 w-5') ?>
                        <span>Filter Periode Pendaftaran</span>
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <select id="periodFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" style="min-width:200px" onchange="window.location.href=this.value">
                        <?php foreach ($allSettings as $idx => $s): ?>
                            <?php
                                $periodLabel = 'Pendaftaran ' . ($idx + 1);
                                $periodUrl = ems_url('/dashboard/sertifikat_heli_pendaftaran.php?period=' . $s['id']);
                            ?>
                            <option value="<?= htmlspecialchars($periodUrl) ?>" <?= (int)$s['id'] === $selectedSettingsId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($periodLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="flex gap-2">
                        <?php if ($prevSettings): ?>
                            <a href="<?= htmlspecialchars(ems_url('/dashboard/sertifikat_heli_pendaftaran.php?period=' . $prevSettings['id'])) ?>" class="btn-secondary button-compact inline-flex items-center gap-1">
                                <?= ems_icon('chevron-left', 'h-4 w-4') ?> <span>Sebelumnya</span>
                            </a>
                        <?php else: ?>
                            <button class="btn-secondary button-compact inline-flex items-center gap-1" disabled>
                                <?= ems_icon('chevron-left', 'h-4 w-4') ?> <span>Sebelumnya</span>
                            </button>
                        <?php endif; ?>
                        <?php if ($nextSettings): ?>
                            <a href="<?= htmlspecialchars(ems_url('/dashboard/sertifikat_heli_pendaftaran.php?period=' . $nextSettings['id'])) ?>" class="btn-secondary button-compact inline-flex items-center gap-1">
                                <span>Selanjutnya</span> <?= ems_icon('chevron-right', 'h-4 w-4') ?>
                            </a>
                        <?php else: ?>
                            <button class="btn-secondary button-compact inline-flex items-center gap-1" disabled>
                                <span>Selanjutnya</span> <?= ems_icon('chevron-right', 'h-4 w-4') ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Registration Settings Display -->
        <?php if ($settings): ?>
            <div class="card mb-4">
                <div class="card-header card-header-between">
                    <span class="inline-flex items-center gap-2">
                        <?= ems_icon('calendar-days', 'h-5 w-5') ?>
                        <span>Informasi Pendaftaran</span>
                    </span>
                    <span class="badge-info"><?= $registeredCount ?>/<?= (int)$settings['max_slots'] ?> Terdaftar</span>
                </div>
                <div class="space-y-3 text-sm">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <div class="text-slate-500">Mulai Pendaftaran</div>
                            <div class="font-semibold text-slate-900"><?= formatTanggalID($settings['start_datetime']) ?> WIB</div>
                        </div>
                        <div>
                            <div class="text-slate-500">Selesai Pendaftaran</div>
                            <div class="font-semibold text-slate-900"><?= formatTanggalID($settings['end_datetime']) ?> WIB</div>
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <div class="text-slate-500">Maksimal Pendaftar</div>
                            <div class="font-semibold text-slate-900"><?= (int)$settings['max_slots'] ?> orang</div>
                        </div>
                        <div>
                            <div class="text-slate-500">Position Minimal</div>
                            <div class="font-semibold text-slate-900"><?= trim($settings['min_jabatan'] ?? '') !== '' ? ems_position_label($settings['min_jabatan']) : 'Semua position' ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="text-slate-500">Status Pendaftaran</div>
                        <?php if (!$registrationOpen): ?>
                            <div class="font-semibold text-amber-600">Belum dibuka</div>
                        <?php elseif ($registrationEnded): ?>
                            <div class="font-semibold text-red-600">Sudah ditutup</div>
                        <?php elseif (!$slotsAvailable): ?>
                            <div class="font-semibold text-red-600">Slot penuh</div>
                        <?php else: ?>
                            <div class="font-semibold text-green-600">Sedang dibuka (<?= $remainingSlots ?> slot tersedia)</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($settings): ?>
                        <div>
                            <div class="text-slate-500">Countdown</div>
                            <div class="font-semibold text-slate-900" id="countdownTimer">Loading...</div>
                        </div>
                    <?php endif; ?>
                    <?php if ($userAlreadyRegistered && $canRegister): ?>
                        <div class="alert alert-info inline-flex items-center gap-2 mb-2">
                            <?= ems_icon('information-circle', 'h-5 w-5') ?>
                            <span>Anda sudah terdaftar sebelumnya. Anda tetap bisa mendaftar ulang jika ingin mencoba lagi.</span>
                        </div>
                        <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/sertifikat_heli_action.php')) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="register">
                            <button type="submit" class="btn-primary w-full">
                                <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                                <span>Daftar Ulang</span>
                            </button>
                        </form>
                    <?php elseif ($canRegister): ?>
                        <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/sertifikat_heli_action.php')) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="register">
                            <button type="submit" class="btn-primary w-full">
                                <?= ems_icon('user-plus', 'h-4 w-4') ?>
                                <span>Daftar Sekarang</span>
                            </button>
                        </form>
                    <?php elseif ($currentUserId > 0): ?>
                        <?php if (!$registrationOpen): ?>
                            <div class="helper-note">Pendaftaran belum dibuka. Tunggu hingga waktu mulai pendaftaran.</div>
                        <?php elseif ($registrationEnded): ?>
                            <div class="helper-note">Pendaftaran sudah ditutup.</div>
                        <?php elseif (!$slotsAvailable): ?>
                            <div class="helper-note">Slot pendaftaran sudah penuh.</div>
                        <?php elseif (!$meetsPositionRequirement): ?>
                            <div class="helper-note">Jabatan Anda tidak memenuhi syarat untuk mendaftar.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="helper-note">Silakan login untuk mendaftar.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-4">
                Belum ada setting pendaftaran sertifikat heli. Silakan hubungi General Affair untuk mengatur jadwal pendaftaran.
            </div>
        <?php endif; ?>

        <!-- Registered Users List -->
        <?php if ($settings && count($registeredUsers) > 0): ?>
            <div class="card mb-4">
                <div class="card-header card-header-between">
                    <span class="inline-flex items-center gap-2">
                        <?= ems_icon('users', 'h-5 w-5') ?>
                        <span>Daftar Pendaftar</span>
                    </span>
                    <div class="flex gap-2 items-center flex-wrap">
                        <button id="btnExportText" class="btn-secondary button-compact" type="button">
                            <?= ems_icon('document-text', 'h-4 w-4') ?> Export Teks
                        </button>
                        <span class="badge-muted"><?= count($registeredUsers) ?> orang</span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="table-custom" id="registeredTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nama</th>
                                <th>Jabatan</th>
                                <th>Divisi</th>
                                <th>Waktu Daftar</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registeredUsers as $index => $row): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($row['user_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['user_jabatan']) ?></td>
                                    <td><?= htmlspecialchars($row['user_division'] ?? '-') ?></td>
                                    <td data-sort="<?= htmlspecialchars($row['registered_at']) ?>"><?= formatTanggalID($row['registered_at']) ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'registered'): ?>
                                            <span class="badge-success">Terdaftar</span>
                                        <?php elseif ($row['status'] === 'approved'): ?>
                                            <span class="badge-success">Disetujui</span>
                                        <?php elseif ($row['status'] === 'rejected'): ?>
                                            <span class="badge-muted">Ditolak</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
    // DataTables initialization
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable && document.getElementById('registeredTable')) {
            jQuery('#registeredTable').DataTable({
                pageLength: 25,
                order: [[4, 'asc']],
                language: {
                    url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
                }
            });
        }
    });

    // Countdown timer (pure JavaScript, no polling)
    <?php if ($settings): ?>
    (function() {
        const startTime = new Date('<?= (new DateTime($settings['start_datetime']))->format('c') ?>').getTime();
        const endTime = new Date('<?= (new DateTime($settings['end_datetime']))->format('c') ?>').getTime();
        const countdownElement = document.getElementById('countdownTimer');

        function updateCountdown() {
            const now = new Date().getTime();
            let targetTime;
            let labelText;

            if (now < startTime) {
                targetTime = startTime;
                labelText = 'Menuju pendaftaran: ';
            } else if (now < endTime) {
                targetTime = endTime;
                labelText = 'Sisa waktu: ';
            } else {
                countdownElement.textContent = 'Pendaftaran ditutup';
                return;
            }

            const distance = targetTime - now;

            if (distance < 0) {
                if (now < startTime) {
                    countdownElement.textContent = 'Pendaftaran dibuka';
                } else {
                    countdownElement.textContent = 'Pendaftaran ditutup';
                }
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            let countdownText = labelText;
            if (days > 0) {
                countdownText += days + ' hari ';
            }
            if (hours > 0 || days > 0) {
                countdownText += hours + ' jam ';
            }
            countdownText += minutes + ' menit ' + seconds + ' detik';

            countdownElement.textContent = countdownText;
        }

        updateCountdown();
        setInterval(updateCountdown, 1000);
    })();
    <?php endif; ?>

    // Export text functionality
    document.body.addEventListener('click', function(e) {
        const exportBtn = e.target.closest('#btnExportText');
        if (exportBtn) {
            const table = document.querySelector('.table-custom');
            if (!table) {
                alert('Tabel tidak ditemukan.');
                return;
            }

            const rows = table.querySelectorAll('tbody tr');
            if (!rows.length) {
                alert('Tidak ada data untuk diexport.');
                return;
            }

            const lines = [];
            let no = 1;

            rows.forEach(function(row) {
                const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
                const jabatan = row.querySelector('td:nth-child(3)')?.innerText || '';
                const noStr = String(no).padStart(2, '0');
                lines.push(`${noStr}. ${nama} - ${jabatan}`);
                no++;
            });

            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const timestamp = `${year}-${month}-${day}_${hours}-${minutes}-${seconds}`;

            const content = 'Daftar Pendaftar Sertifikat Heli\n\n' + lines.join('\n') + '\n';
            const blob = new Blob([content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `daftar_pendaftar_sertifikat_heli_${timestamp}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
