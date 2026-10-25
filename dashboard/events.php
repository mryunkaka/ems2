<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Pendaftaran Event';
$currentUserId = (int)($_SESSION['user_rh']['id'] ?? 0);
$eventId = (int)($_GET['id'] ?? 0);

if ($eventId > 0) {
    $eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = ? AND is_active = 1 LIMIT 1');
    $eventStmt->execute([$eventId]);
} else {
    $eventStmt = $pdo->query('SELECT * FROM events WHERE is_active = 1 ORDER BY tanggal_event ASC LIMIT 1');
}
$event = $eventStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$eventUnavailable = $event === null;
$eventId = (int)($event['id'] ?? 0);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$stat = ['total' => 0, 'laki' => 0, 'perempuan' => 0];
$isRegistered = false;
$lastAction = null;
$lastReason = null;
$lastActionAt = null;

if (!$eventUnavailable) {
    $statStmt = $pdo->prepare("
        SELECT COUNT(ep.id) AS total,
            SUM(CASE WHEN u.jenis_kelamin = 'Laki-laki' THEN 1 ELSE 0 END) AS laki,
            SUM(CASE WHEN u.jenis_kelamin = 'Perempuan' THEN 1 ELSE 0 END) AS perempuan
        FROM event_participants ep
        LEFT JOIN user_rh u ON u.id = ep.user_id
        WHERE ep.event_id = ?
    ");
    $statStmt->execute([$eventId]);
    $stat = $statStmt->fetch(PDO::FETCH_ASSOC) ?: $stat;

    $activeStmt = $pdo->prepare('SELECT 1 FROM event_participants WHERE event_id = ? AND user_id = ? LIMIT 1');
    $activeStmt->execute([$eventId, $currentUserId]);
    $isRegistered = (bool)$activeStmt->fetchColumn();

    $historyStmt = $pdo->prepare('SELECT action, reason, created_at FROM event_registration_history WHERE event_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
    $historyStmt->execute([$eventId, $currentUserId]);
    $latestHistory = $historyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($latestHistory) {
        $lastAction = $latestHistory['action'];
        $lastReason = $latestHistory['reason'];
        $lastActionAt = $latestHistory['created_at'];
    }
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title">Pendaftaran Event</h1>
        <p class="page-subtitle">Daftar, lihat status, atau batalkan keikutsertaan Anda pada event.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($eventUnavailable): ?>
            <div class="alert alert-warning">Saat ini tidak ada event aktif yang tersedia. Silakan hubungi pengelola event.</div>
        <?php else: ?>
            <?php
            $hariMap = [
                'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
                'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu',
            ];
            $eventDate = new DateTime((string)$event['tanggal_event']);
            ?>
            <div class="card mb-4">
                <div class="card-header"><?= htmlspecialchars((string)$event['nama_event'], ENT_QUOTES, 'UTF-8') ?></div>
                <p class="text-muted">
                    <?= htmlspecialchars($hariMap[$eventDate->format('l')] ?? '', ENT_QUOTES, 'UTF-8') ?>,
                    <?= $eventDate->format('d M Y') ?> &bull;
                    <?= htmlspecialchars((string)($event['lokasi'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php if (trim((string)($event['keterangan'] ?? '')) !== ''): ?>
                    <p class="text-sm text-slate-700"><?= nl2br(htmlspecialchars((string)$event['keterangan'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
                <div class="info-notice mt-4">
                    <strong>Total peserta aktif:</strong> <?= (int)$stat['total'] ?> orang<br>
                    <strong>Laki-laki:</strong> <?= (int)$stat['laki'] ?> orang<br>
                    <strong>Perempuan:</strong> <?= (int)$stat['perempuan'] ?> orang
                </div>
            </div>

            <div class="card">
                <div class="card-header">Status Pendaftaran Anda</div>
                <?php if ($isRegistered): ?>
                    <div class="alert alert-success">Anda terdaftar sebagai peserta aktif event ini.</div>
                    <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/event_registration_action.php'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="action" value="cancel">
                        <div>
                            <label for="cancelReason">Alasan pembatalan <span class="required">*</span></label>
                            <textarea id="cancelReason" name="reason" rows="3" maxlength="1000" required placeholder="Jelaskan alasan Anda membatalkan pendaftaran"></textarea>
                        </div>
                        <button type="submit" class="btn-warning"><?= ems_icon('x-circle', 'h-4 w-4') ?> <span>Batalkan Pendaftaran</span></button>
                    </form>
                <?php else: ?>
                    <?php if ($lastAction === 'cancelled'): ?>
                        <div class="alert alert-info">
                            Pendaftaran sebelumnya dibatalkan pada <?= htmlspecialchars((new DateTime((string)$lastActionAt))->format('d M Y H:i'), ENT_QUOTES, 'UTF-8') ?>.
                            <?php if (trim((string)$lastReason) !== ''): ?>
                                <br><strong>Alasan:</strong> <?= nl2br(htmlspecialchars((string)$lastReason, ENT_QUOTES, 'UTF-8')) ?>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/event_registration_action.php'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                            <?= csrfField() ?>
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <input type="hidden" name="action" value="register">
                            <div>
                                <label for="registerReason">Alasan daftar ulang <span class="required">*</span></label>
                                <textarea id="registerReason" name="reason" rows="3" maxlength="1000" required placeholder="Jelaskan alasan Anda ingin mendaftar kembali"></textarea>
                            </div>
                            <button type="submit" class="btn-primary"><?= ems_icon('arrow-path', 'h-4 w-4') ?> <span>Daftar Kembali</span></button>
                        </form>
                    <?php else: ?>
                        <p class="text-sm text-slate-700 mb-4">Masuk sebagai <strong><?= htmlspecialchars((string)($_SESSION['user_rh']['full_name'] ?? $_SESSION['user_rh']['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>.</p>
                        <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/event_registration_action.php'), ENT_QUOTES, 'UTF-8') ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <input type="hidden" name="action" value="register">
                            <button type="submit" class="btn-primary"><?= ems_icon('user-plus', 'h-4 w-4') ?> <span>Daftar Event</span></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
