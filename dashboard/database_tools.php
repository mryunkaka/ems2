<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/database_sql_tools.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

if (!ems_current_user_is_programmer_roxwood()) {
    http_response_code(403);
    exit('Akses hanya untuk Programmer Roxwood.');
}

function databaseToolsFormatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = max(0, $bytes);
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) { $size /= 1024; $unit++; }
    return number_format($size, $unit === 0 ? 0 : 1, ',', '.') . ' ' . $units[$unit];
}

$backupDirectory = ems_database_sql_backup_directory();
$errors = [];
$messages = [];

if (isset($_GET['download'])) {
    $requested = basename((string) $_GET['download']);
    if (!preg_match('/^ems2_database_(?:export|preimport)_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}\.sql$/', $requested)) {
        http_response_code(400);
        exit('Nama cadangan tidak valid.');
    }
    $downloadPath = $backupDirectory . DIRECTORY_SEPARATOR . $requested;
    if (!is_file($downloadPath) || !is_readable($downloadPath)) {
        http_response_code(404);
        exit('Berkas SQL tidak ditemukan.');
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Length: ' . filesize($downloadPath));
    header('Content-Disposition: attachment; filename="' . $requested . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($downloadPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken(csrfRequestToken())) {
        $errors[] = 'Token keamanan tidak valid. Muat ulang halaman dan coba kembali.';
    } elseif (csrfRequestBodyTooLarge()) {
        $errors[] = 'Berkas melebihi batas unggah server.';
    } elseif (($_POST['action'] ?? '') !== 'import') {
        $errors[] = 'Aksi tidak dikenal.';
    } elseif (($_POST['confirm_replace'] ?? '') !== 'GANTI SELURUH DATABASE') {
        $errors[] = 'Ketik GANTI SELURUH DATABASE untuk mengonfirmasi proses penggantian.';
    } elseif (empty($_FILES['sql_file']) || !is_array($_FILES['sql_file'])) {
        $errors[] = 'Pilih berkas dump SQL terlebih dahulu.';
    } else {
        $upload = $_FILES['sql_file'];
        $temporaryImport = '';
        $backupPath = '';
        $changedDatabase = false;
        try {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Unggah gagal. Pastikan ukuran berkas sesuai batas server.');
            }
            $size = (int) ($upload['size'] ?? 0);
            if ($size < 1 || $size > 64 * 1024 * 1024) throw new RuntimeException('Ukuran dump harus di antara 1 byte dan 64 MB.');
            $originalName = (string) ($upload['name'] ?? '');
            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'sql') throw new RuntimeException('Format yang didukung adalah berkas .sql.');
            $temporaryImport = tempnam($backupDirectory, '.import_');
            if ($temporaryImport === false || !move_uploaded_file((string) $upload['tmp_name'], $temporaryImport)) {
                throw new RuntimeException('Berkas SQL gagal dipindahkan ke penyimpanan sementara.');
            }
            @chmod($temporaryImport, 0600);

            $summary = ems_database_sql_validate($pdo, $temporaryImport);
            $backupPath = $backupDirectory . '/ems2_database_preimport_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
            ems_database_sql_write($pdo, $backupPath);

            if (function_exists('set_time_limit')) @set_time_limit(0);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $changedDatabase = true;
            ems_database_sql_drop_current_tables($pdo);
            $summary = ems_database_sql_apply($pdo, $temporaryImport);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $messages[] = 'Impor berhasil. ' . $summary['tables'] . ' tabel dipulihkan dengan pemeriksaan relasi selesai.';
            $messages[] = 'Cadangan sebelum impor tersedia untuk diunduh di daftar berkas SQL.';
        } catch (Throwable $e) {
            $errors[] = 'Impor gagal: ' . $e->getMessage();
            if ($changedDatabase && $backupPath !== '' && is_file($backupPath)) {
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    ems_database_sql_drop_current_tables($pdo);
                    ems_database_sql_apply($pdo, $backupPath);
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    $errors[] = 'Cadangan otomatis berhasil dipulihkan; database dikembalikan ke kondisi sebelum impor.';
                } catch (Throwable $restoreError) {
                    $errors[] = 'Pemulihan otomatis gagal. Jangan gunakan aplikasi dahulu; cadangan tersimpan di ' . basename($backupPath) . '. Detail: ' . $restoreError->getMessage();
                }
            }
            error_log('EMS2 database SQL import failed: ' . $e->getMessage());
        } finally {
            if ($temporaryImport !== '' && is_file($temporaryImport)) @unlink($temporaryImport);
        }
    }
}

if (isset($_GET['export']) && $_GET['export'] === '1') {
    try {
        $filename = 'ems2_database_export_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        $exportPath = $backupDirectory . '/' . $filename;
        ems_database_sql_write($pdo, $exportPath);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Length: ' . filesize($exportPath));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($exportPath);
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Ekspor gagal: ' . $e->getMessage();
    }
}

$backups = [];
foreach (glob($backupDirectory . '/ems2_database_*.sql') ?: [] as $path) {
    if (!is_file($path)) continue;
    $backups[] = ['name' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
}
usort($backups, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
$pageTitle = 'Impor & Ekspor Database';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title">Impor &amp; Ekspor Database</h1>
        <p class="page-subtitle">Cadangkan dan pulihkan struktur serta data tabel EMS2. Akses dibatasi untuk Programmer Roxwood.</p>

        <?php foreach ($errors as $error): ?><div class="alert alert-error mb-3"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <?php foreach ($messages as $message): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

        <div class="alert alert-warning mb-4"><strong>Perhatian:</strong> impor mengganti seluruh tabel database aktif. Pastikan dump lengkap dan cocok dengan sistem ini. Cadangan otomatis dibuat sebelum data diganti; pemeriksaan foreign key dimatikan selama pemulihan lalu setiap relasi diperiksa kembali.</div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="card p-5">
                <h2 class="text-lg font-bold">Ekspor database</h2>
                <p class="mt-2 text-sm text-slate-600">Unduh struktur dan isi semua tabel sebagai dump SQL. Relasi foreign key ikut disertakan.</p>
                <a class="btn btn-primary mt-4 inline-flex" href="/dashboard/database_tools.php?export=1"><?= ems_icon('arrow-down-tray', 'h-4 w-4') ?><span>Ekspor SQL</span></a>
            </div>

            <div class="card p-5">
                <h2 class="text-lg font-bold">Impor dan ganti database</h2>
                <p class="mt-2 text-sm text-slate-600">Gunakan dump SQL MySQL/MariaDB berisi semua tabel. Berkas maksimal 64 MB. View, trigger, procedure, dan event tidak didukung.</p>
                <form method="post" enctype="multipart/form-data" class="mt-4 space-y-3" onsubmit="return confirm('Impor akan mengganti seluruh tabel database aktif. Lanjutkan hanya jika Anda sudah memeriksa berkas SQL.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="import">
                    <div><label class="form-label" for="sql_file">Berkas dump SQL</label><input class="form-control" type="file" name="sql_file" id="sql_file" accept=".sql,text/plain" required></div>
                    <div><label class="form-label" for="confirm_replace">Ketik <code>GANTI SELURUH DATABASE</code> untuk konfirmasi</label><input class="form-control" type="text" name="confirm_replace" id="confirm_replace" autocomplete="off" required></div>
                    <button class="btn btn-danger" type="submit"><?= ems_icon('arrow-path', 'h-4 w-4') ?><span>Impor dan Ganti Data</span></button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">Berkas cadangan terakhir</div>
            <?php if ($backups): ?><div class="table-wrapper"><table class="table-custom"><thead><tr><th>Nama</th><th>Ukuran</th><th>Waktu</th><th></th></tr></thead><tbody>
                <?php foreach (array_slice($backups, 0, 20) as $backup): ?><tr><td class="font-mono text-xs"><?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?></td><td><?= databaseToolsFormatBytes($backup['size']) ?></td><td><?= date('d-m-Y H:i:s', $backup['time']) ?></td><td><a class="btn-secondary" href="/dashboard/database_tools.php?download=<?= rawurlencode($backup['name']) ?>">Unduh</a></td></tr><?php endforeach; ?>
            </tbody></table></div><?php else: ?><p class="p-4 text-sm text-slate-600">Belum ada berkas ekspor atau cadangan.</p><?php endif; ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>
