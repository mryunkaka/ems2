<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

if (!ems_current_user_is_programmer_roxwood()) {
    $_SESSION['flash_errors'][] = 'Halaman status deployment hanya untuk Programmer Roxwood.';
    header('Location: /dashboard/index.php');
    exit;
}

$pageTitle = 'Status Deployment';
$statusFile = getenv('DEPLOY_STATUS_FILE') ?: '/home/fouf9972/.deploy-status';
$logFile = getenv('DEPLOY_LOG_FILE') ?: '/home/fouf9972/git-deploy.log';
$status = null;
$statusDetail = '';

if (is_readable($statusFile)) {
    $parts = explode('|', trim((string)file_get_contents($statusFile)), 5);
    if (count($parts) === 5 && in_array($parts[0], ['success', 'failed'], true)) {
        $status = ['result' => $parts[0], 'timestamp' => $parts[1], 'branch' => $parts[2], 'commit' => $parts[3]];
        $statusDetail = base64_decode($parts[4], true) ?: '';
    }
}

function deploymentMonitoringRecentLog(string $path): array
{
    if (!is_readable($path)) return [];
    $handle = @fopen($path, 'rb');
    if (!$handle) return [];
    $size = filesize($path);
    $offset = max(0, $size - 65536);
    if ($offset > 0) {
        fseek($handle, $offset);
        fgets($handle);
    }
    $tail = stream_get_contents($handle) ?: '';
    fclose($handle);

    $lines = preg_split('/\r\n|\n|\r/', $tail) ?: [];
    $events = array_values(array_filter($lines, static fn(string $line): bool =>
        trim($line) !== '' &&
        stripos($line, 'Tidak ada perubahan') === false &&
        preg_match('/Deploy baru|ERROR:|migrasi SQL gagal|Baseline \d+ dicatat|Menjalankan /i', $line) === 1
    ));
    return array_map(static fn(string $line): string =>
        preg_replace('#(https?://)[^/\s@]+@#i', '$1[redacted]@', $line) ?? $line,
        array_slice($events, -40)
    );
}

$recentEvents = deploymentMonitoringRecentLog($logFile);
$logAvailable = is_readable($logFile);
if ($status === null) {
    foreach ($recentEvents as $event) {
        if (preg_match('/^\[([^\]]+)\]\s+(ERROR:|Deploy baru(?:\s+\[[^\]]+\])?:)\s*(.*)$/i', $event, $matches) !== 1) continue;
        $isSuccess = stripos($matches[2], 'Deploy baru') === 0;
        $status = [
            'result' => $isSuccess ? 'success' : 'failed',
            'timestamp' => $matches[1],
            'branch' => 'main',
            'commit' => '',
        ];
        $statusDetail = $matches[3];
    }
}
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div>
                <h1 class="page-title">Status Deployment</h1>
                <p class="page-subtitle">Pemantauan pull GitHub ke hosting. Halaman diperbarui otomatis setiap 30 detik.</p>
            </div>
            <span class="badge-info">Programmer Roxwood</span>
        </div>

        <div class="alert alert-info mb-4">
            Cron memeriksa branch <strong>main</strong> setiap menit. Push baru akan ditarik pada pemeriksaan berikutnya; jika tidak ada commit baru, tidak dibuat log pull baru.
        </div>

        <div class="card mb-4">
            <div class="card-header">Hasil Deployment Terakhir</div>
            <?php if ($status): ?>
                <div class="<?= $status['result'] === 'success' ? 'alert alert-success' : 'alert alert-error' ?>">
                    <strong><?= $status['result'] === 'success' ? 'Pull/deploy berhasil' : 'Pull/deploy gagal' ?></strong>
                </div>
                <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                    <div><dt class="text-slate-500">Waktu</dt><dd class="font-semibold"><?= htmlspecialchars($status['timestamp'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div><dt class="text-slate-500">Branch</dt><dd class="font-semibold"><?= htmlspecialchars($status['branch'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div><dt class="text-slate-500">Commit checkout saat hasil dicatat</dt><dd class="font-mono text-xs break-all"><?= htmlspecialchars($status['commit'] ?: '-', ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div class="sm:col-span-2"><dt class="text-slate-500">Detail</dt><dd class="whitespace-pre-wrap break-words"><?= htmlspecialchars($statusDetail ?: '-', ENT_QUOTES, 'UTF-8') ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="text-slate-600">Belum ada hasil pull baru yang tercatat oleh pemantau ini. Riwayat cron lama dapat dilihat di bawah.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header card-header-between">
                <span>Riwayat Perubahan dan Error</span>
                <span class="badge-muted"><?= count($recentEvents) ?> event terakhir</span>
            </div>
            <?php if ($recentEvents): ?>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead><tr><th>Log Git Deploy</th></tr></thead>
                        <tbody>
                            <?php foreach (array_reverse($recentEvents) as $event): ?>
                                <tr><td class="font-mono text-xs whitespace-pre-wrap break-words"><?= htmlspecialchars($event, ENT_QUOTES, 'UTF-8') ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php if ($logAvailable): ?>
                    <p class="text-slate-600">Belum ada event deployment atau error di log hosting.</p>
                <?php else: ?>
                    <div class="alert alert-error">File <code>git-deploy.log</code> belum tersedia atau tidak dapat dibaca oleh PHP.</div>
                <?php endif; ?>
            <?php endif; ?>
            <p class="helper-note mt-3">Log cron lama hanya mencatat exit code. Detail Git dan migrasi disimpan di <code>git-deploy.log</code>.</p>
        </div>
    </div>
</section>

<script>window.setTimeout(() => window.location.reload(), 30000);</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
