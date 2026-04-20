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

$currentUser = $_SESSION['user_rh'] ?? null;
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserPosition = $currentUser['position'] ?? '';
$currentUserDivision = $currentUser['division'] ?? '';

// Get registration settings
$settingsStmt = $pdo->query("SELECT * FROM sertifikat_heli_settings ORDER BY id DESC LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

$now = new DateTime();
$registrationOpen = false;
$registrationEnded = false;
$slotsAvailable = false;
$canRegister = false;
$remainingSlots = 0;
$registeredCount = 0;
$userAlreadyRegistered = false;

if ($settings) {
    $startDatetime = new DateTime($settings['start_datetime']);
    $endDatetime = new DateTime($settings['end_datetime']);
    $maxSlots = (int)$settings['max_slots'];
    $minJabatan = trim($settings['min_jabatan'] ?? '');

    // Check if registration is open
    $registrationOpen = $now >= $startDatetime;
    $registrationEnded = $now > $endDatetime;

    // Count current registrations
    $countStmt = $pdo->query("SELECT COUNT(*) FROM sertifikat_heli_registrations WHERE status = 'registered'");
    $registeredCount = (int)$countStmt->fetchColumn();
    $remainingSlots = $maxSlots - $registeredCount;
    $slotsAvailable = $registeredCount < $maxSlots;

    // Check if user already registered
    if ($currentUserId > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM sertifikat_heli_registrations WHERE user_id = ?");
        $checkStmt->execute([$currentUserId]);
        $userAlreadyRegistered = (bool)$checkStmt->fetch();
    }

    // Check if user meets minimum position requirement
    $meetsPositionRequirement = true;
    if ($minJabatan !== '') {
        $allowedPositions = array_map('trim', explode(',', $minJabatan));
        $meetsPositionRequirement = in_array($currentUserPosition, $allowedPositions, true);
    }

    // User can register if:
    // - Registration is open
    // - Registration hasn't ended
    // - Slots are available
    // - User hasn't already registered
    // - User meets position requirement (if set)
    $canRegister = $registrationOpen && !$registrationEnded && $slotsAvailable && !$userAlreadyRegistered && $meetsPositionRequirement && $currentUserId > 0;
}

// Get registered users list
$registeredStmt = $pdo->query("
    SELECT
        shr.id,
        shr.user_id,
        shr.user_name,
        shr.user_jabatan,
        shr.user_division,
        shr.registered_at,
        shr.status
    FROM sertifikat_heli_registrations shr
    ORDER BY shr.registered_at ASC
");
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
                    <?php if ($userAlreadyRegistered): ?>
                        <div class="alert alert-success inline-flex items-center gap-2">
                            <?= ems_icon('check-circle', 'h-5 w-5') ?>
                            <span>Anda sudah terdaftar untuk pendaftaran sertifikat heli.</span>
                        </div>
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
                    <div class="flex gap-2 items-center">
                        <button id="btnExportText" class="btn-secondary button-compact" type="button">
                            <?= ems_icon('document-text', 'h-4 w-4') ?> Export Teks
                        </button>
                        <span class="badge-muted"><?= count($registeredUsers) ?> orang</span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="table-custom">
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
                                    <td><?= formatTanggalID($row['registered_at']) ?> <?= substr($row['registered_at'], 11, 5) ?></td>
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
