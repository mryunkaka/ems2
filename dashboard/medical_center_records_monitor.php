<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/medical_records_api.php';

$user = $_SESSION['user_rh'] ?? [];
$userDivision = ems_normalize_division($user['division'] ?? '');
if (!ems_current_user_is_programmer_roxwood() && $userDivision !== 'Executive') {
    http_response_code(403);
    exit('Akses monitoring ditolak.');
}

$pageTitle = 'Monitoring Rekam Medis Medical Center | Farmasi EMS';
$latestRun = null;
$cacheCounts = [];
$monitorError = null;

try {
    if (!ems_table_exists($pdo, 'medical_record_api_sync_runs')) {
        throw new RuntimeException('Tabel monitoring belum tersedia.');
    }

    $latestStmt = $pdo->query(
        "SELECT * FROM medical_record_api_sync_runs
         WHERE provider = 'medical_center' AND hospital_code = 'roxwood'
         ORDER BY id DESC LIMIT 1"
    );
    $latestRun = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $countStmt = $pdo->query(
        "SELECT sync_state, COUNT(*) AS total
         FROM medical_record_integrations
         WHERE provider = 'medical_center' AND hospital_code = 'roxwood'
         GROUP BY sync_state"
    );
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $cacheCounts[(string) $row['sync_state']] = (int) $row['total'];
    }
} catch (Throwable $e) {
    $monitorError = $e->getMessage();
}

function monitorHtml(mixed $value, string $fallback = '-'): string
{
    $text = trim((string) ($value ?? ''));
    return htmlspecialchars($text !== '' ? $text : $fallback, ENT_QUOTES, 'UTF-8');
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center mb-4 gap-4 flex-wrap">
            <div>
                <h1 class="page-title">Monitoring GET Medical Center</h1>
                <p class="page-subtitle">Status cache read-only untuk hospital=roxwood.</p>
            </div>
            <span class="remote-readonly-badge">GET only</span>
        </div>

        <?php if ($monitorError !== null): ?>
            <div class="remote-status remote-status-warning"><?= monitorHtml($monitorError) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
            <?php foreach (['synced' => 'Synced', 'needs_review' => 'Needs review', 'stale' => 'Stale', 'failed' => 'Failed run'] as $key => $label): ?>
                <div class="card card-section"><div class="card-body"><div class="text-xs text-gray-500 uppercase"><?= $label ?></div><div class="text-2xl font-bold"><?= (int) ($key === 'failed' ? (($latestRun['status'] ?? '') === 'failed' ? 1 : 0) : ($cacheCounts[$key] ?? 0)) ?></div></div></div>
            <?php endforeach; ?>
        </div>

        <div class="card card-section">
            <div class="card-header">GET pull terakhir</div>
            <div class="card-body">
                <?php if ($latestRun === null): ?>
                    <p class="text-gray-500">Belum ada hasil pull.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table-custom w-full">
                            <tbody>
                                <tr><th>Status</th><td><?= monitorHtml($latestRun['status']) ?></td></tr>
                                <tr><th>Mulai</th><td><?= monitorHtml($latestRun['started_at']) ?></td></tr>
                                <tr><th>Selesai</th><td><?= monitorHtml($latestRun['finished_at']) ?></td></tr>
                                <tr><th>Halaman</th><td><?= (int) ($latestRun['pages_fetched'] ?? 0) ?></td></tr>
                                <tr><th>Response</th><td><?= (int) ($latestRun['records_received'] ?? 0) ?></td></tr>
                                <tr><th>Disimpan/diperbarui</th><td><?= (int) ($latestRun['records_stored'] ?? 0) ?></td></tr>
                                <tr><th>Dilewati</th><td><?= (int) ($latestRun['records_skipped'] ?? 0) ?></td></tr>
                                <tr><th>Error</th><td><?= monitorHtml($latestRun['error_message']) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<style>
.remote-readonly-badge{display:inline-flex;padding:.45rem .8rem;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.remote-status{padding:.85rem 1rem;margin-bottom:1rem;border-radius:.8rem}.remote-status-warning{background:#fffbeb;color:#92400e}
</style>
<?php include __DIR__ . '/../partials/footer.php'; ?>
