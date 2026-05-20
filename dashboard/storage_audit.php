<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

if (!ems_current_user_is_programmer_roxwood()) {
    $_SESSION['flash_errors'][] = 'Akses halaman audit storage hanya untuk Programmer Roxwood.';
    header('Location: /dashboard/index.php');
    exit;
}

$pageTitle = 'Audit Storage';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function storageAuditFormatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = max(0, $bytes);
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return number_format($size, $unitIndex === 0 ? 0 : 2, '.', ',') . ' ' . $units[$unitIndex];
}

function storageAuditCollectReferencedPaths(PDO $pdo): array
{
    return array_keys(storageAuditCollectReferencedFileDetails($pdo));
}

function storageAuditCollectReferencedFileDetails(PDO $pdo): array
{
    $columnsStmt = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND DATA_TYPE IN ('varchar', 'char', 'text', 'mediumtext', 'longtext')
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");

    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $paths = [];

    foreach ($columns as $column) {
        $table = (string)($column['TABLE_NAME'] ?? '');
        $field = (string)($column['COLUMN_NAME'] ?? '');
        if ($table === '' || $field === '') {
            continue;
        }

        $sql = "
            SELECT DISTINCT {$field} AS path_value
            FROM {$table}
            WHERE {$field} IS NOT NULL
              AND (
                    {$field} LIKE 'storage/%'
                 OR {$field} LIKE '/storage/%'
              )
        ";

        try {
            $stmt = $pdo->query($sql);
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                $value = trim((string)($row['path_value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $normalized = str_replace('\\', '/', ltrim($value, '/'));
                if (str_starts_with($normalized, 'storage/')) {
                    if (!isset($paths[$normalized])) {
                        $paths[$normalized] = [
                            'relative_path' => $normalized,
                            'sources' => [],
                        ];
                    }

                    $sourceLabel = $table . '.' . $field;
                    if (!in_array($sourceLabel, $paths[$normalized]['sources'], true)) {
                        $paths[$normalized]['sources'][] = $sourceLabel;
                    }
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return $paths;
}

function storageAuditResolveStoragePath(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
    if ($relativePath === '' || !str_starts_with($relativePath, 'storage/')) {
        return null;
    }

    $storageRoot = realpath(__DIR__ . '/../storage');
    if ($storageRoot === false) {
        return null;
    }

    $targetPath = __DIR__ . '/../' . $relativePath;
    $realDirectory = realpath(dirname($targetPath));
    if ($realDirectory === false) {
        return null;
    }

    $normalizedStorageRoot = str_replace('\\', '/', $storageRoot);
    $normalizedDirectory = str_replace('\\', '/', $realDirectory);
    if (!str_starts_with($normalizedDirectory, $normalizedStorageRoot)) {
        return null;
    }

    return $realDirectory . DIRECTORY_SEPARATOR . basename($targetPath);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_errors'][] = 'CSRF token tidak valid.';
        header('Location: /dashboard/storage_audit.php');
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'delete_orphan_file') {
        $relativePath = trim((string)($_POST['relative_path'] ?? ''));
        $resolvedPath = storageAuditResolveStoragePath($relativePath);

        if ($resolvedPath === null || !is_file($resolvedPath)) {
            $_SESSION['flash_errors'][] = 'File orphan tidak ditemukan atau path tidak valid.';
            header('Location: /dashboard/storage_audit.php');
            exit;
        }

        if (!@unlink($resolvedPath)) {
            $_SESSION['flash_errors'][] = 'Gagal menghapus file orphan.';
            header('Location: /dashboard/storage_audit.php');
            exit;
        }

        $_SESSION['flash_messages'][] = 'File orphan berhasil dihapus: ' . $relativePath;
        header('Location: /dashboard/storage_audit.php');
        exit;
    }

    if ($action === 'delete_all_orphan_files') {
        try {
            $referencedPaths = storageAuditCollectReferencedPaths($pdo);
            $referencedMap = array_fill_keys($referencedPaths, true);
            $storageRoot = realpath(__DIR__ . '/../storage');

            if ($storageRoot === false || !is_dir($storageRoot)) {
                throw new RuntimeException('Folder storage tidak ditemukan.');
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS)
            );

            $deletedCount = 0;
            $failedCount = 0;

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                $absolutePath = $fileInfo->getPathname();
                $relativePath = 'storage/' . str_replace('\\', '/', substr($absolutePath, strlen($storageRoot) + 1));

                if (isset($referencedMap[$relativePath])) {
                    continue;
                }

                if (@unlink($absolutePath)) {
                    $deletedCount++;
                } else {
                    $failedCount++;
                }
            }

            if ($deletedCount === 0 && $failedCount === 0) {
                $_SESSION['flash_messages'][] = 'Tidak ada file orphan yang perlu dihapus.';
            } elseif ($failedCount > 0) {
                $_SESSION['flash_errors'][] = 'Sebagian file orphan gagal dihapus. Berhasil: ' . $deletedCount . ', gagal: ' . $failedCount . '.';
            } else {
                $_SESSION['flash_messages'][] = 'Berhasil menghapus ' . $deletedCount . ' file orphan dari folder storage.';
            }
        } catch (Throwable $e) {
            $_SESSION['flash_errors'][] = 'Gagal menghapus semua file orphan: ' . $e->getMessage();
        }

        header('Location: /dashboard/storage_audit.php');
        exit;
    }
}

$storagePath = realpath(__DIR__ . '/../storage') ?: (__DIR__ . '/../storage');
$storagePathDisplay = str_replace('/', '\\', (string)$storagePath);
$storageRows = [];
$referencedFileRows = [];
$orphanRows = [];
$referencedPaths = [];
$summary = [
    'storage_file_count' => 0,
    'storage_total_bytes' => 0,
    'referenced_file_count' => 0,
    'orphan_file_count' => 0,
    'orphan_total_bytes' => 0,
];

try {
    $referencedDetails = storageAuditCollectReferencedFileDetails($pdo);
    $referencedPaths = array_keys($referencedDetails);
    $referencedMap = array_fill_keys($referencedPaths, true);
    $summary['referenced_file_count'] = count($referencedPaths);

    if (is_dir($storagePath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storagePath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            $relativePath = 'storage/' . str_replace('\\', '/', substr($absolutePath, strlen($storagePath) + 1));
            $size = (int)$fileInfo->getSize();
            $modifiedAt = date('Y-m-d H:i:s', (int)$fileInfo->getMTime());

            $storageRows[] = [
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'size' => $size,
                'modified_at' => $modifiedAt,
                'is_referenced' => isset($referencedMap[$relativePath]),
            ];

            $summary['storage_file_count']++;
            $summary['storage_total_bytes'] += $size;

            if (!isset($referencedMap[$relativePath])) {
                $orphanRows[] = [
                    'relative_path' => $relativePath,
                    'absolute_path' => $absolutePath,
                    'size' => $size,
                    'modified_at' => $modifiedAt,
                ];
                $summary['orphan_file_count']++;
                $summary['orphan_total_bytes'] += $size;
            }
        }
    }

    foreach ($referencedDetails as $relativePath => $detail) {
        $resolvedPath = storageAuditResolveStoragePath($relativePath);
        $exists = $resolvedPath !== null && is_file($resolvedPath);
        $size = $exists ? (int)filesize($resolvedPath) : 0;
        $modifiedAt = $exists ? date('Y-m-d H:i:s', (int)filemtime($resolvedPath)) : '-';

        $referencedFileRows[] = [
            'relative_path' => $relativePath,
            'absolute_path' => $exists ? $resolvedPath : '-',
            'size' => $size,
            'modified_at' => $modifiedAt,
            'exists' => $exists,
            'sources' => $detail['sources'] ?? [],
        ];
    }
} catch (Throwable $e) {
    $errors[] = 'Gagal memindai storage: ' . $e->getMessage();
}

usort($orphanRows, static function (array $a, array $b): int {
    return $b['size'] <=> $a['size'];
});

usort($referencedFileRows, static function (array $a, array $b): int {
    return $b['size'] <=> $a['size'];
});

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Audit file di folder storage, termasuk file yang tidak lagi direferensikan database.</p>
            </div>
            <div class="badge-info">Akses: Programmer Roxwood</div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="alert alert-warning mb-4">
            Folder yang dipindai: <strong><?= htmlspecialchars($storagePathDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>

        <div class="card mb-4">
            <div class="card-header card-header-actions card-header-flex">
                <div class="card-header-actions-title">Aksi Pembersihan</div>
                <form method="POST" class="inline js-delete-all-orphan-files" data-confirm="Yakin ingin menghapus semua file orphan? Sistem akan menghapus semua file di folder storage yang saat ini benar-benar tidak ditemukan di database.">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_all_orphan_files">
                    <button type="submit" class="btn-danger">
                        <?= ems_icon('trash', 'h-4 w-4') ?>
                        <span>Hapus Semua File Orphan</span>
                    </button>
                </form>
            </div>
            <div class="meta-text-xs">
                File orphan pada halaman ini adalah file yang ada di folder <code>storage</code>, tetapi saat pemindaian tidak ditemukan pada kolom path mana pun di database.
                Jika tidak ada di database, maka file tersebut tidak sedang terpanggil oleh data halaman aplikasi.
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4 mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Total File Storage</div>
                <div class="text-lg font-extrabold text-slate-900"><?= number_format((int)$summary['storage_file_count']) ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Total Ukuran Storage</div>
                <div class="text-lg font-extrabold text-primary"><?= htmlspecialchars(storageAuditFormatBytes((int)$summary['storage_total_bytes']), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">File Tercatat di DB</div>
                <div class="text-lg font-extrabold text-success"><?= number_format((int)$summary['referenced_file_count']) ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">File Orphan</div>
                <div class="text-lg font-extrabold text-amber-700"><?= number_format((int)$summary['orphan_file_count']) ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Ukuran File Orphan</div>
                <div class="text-lg font-extrabold text-rose-700"><?= htmlspecialchars(storageAuditFormatBytes((int)$summary['orphan_total_bytes']), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Daftar File Yang Tidak Ada di Database</div>
            <div class="table-wrapper">
                <table id="storageAuditTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Path Relatif</th>
                            <th>Ukuran</th>
                            <th>Terakhir Diubah</th>
                            <th>Path Absolut</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphanRows as $row): ?>
                            <tr>
                                <td><code><?= htmlspecialchars((string)$row['relative_path'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= htmlspecialchars(storageAuditFormatBytes((int)$row['size']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['modified_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><code><?= htmlspecialchars(str_replace('/', '\\', (string)$row['absolute_path']), ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td class="table-actions">
                                    <form method="POST" class="inline js-delete-orphan-file" data-confirm="Yakin ingin menghapus file orphan ini dari folder storage?">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_orphan_file">
                                        <input type="hidden" name="relative_path" value="<?= htmlspecialchars((string)$row['relative_path'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn-danger btn-sm action-icon-btn" title="Hapus file orphan" aria-label="Hapus file orphan">
                                            <?= ems_icon('trash', 'h-4 w-4') ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($orphanRows === []): ?>
                    <div class="muted-placeholder p-4">Tidak ada file orphan yang terdeteksi di folder storage.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">Daftar File Yang Ada di Database</div>
            <div class="meta-text-xs px-4 pt-2">Diurutkan dari ukuran file terbesar. Kolom sumber menunjukkan tabel dan field database yang mereferensikan file tersebut.</div>
            <div class="table-wrapper">
                <table id="storageReferencedTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Path Relatif</th>
                            <th>Ukuran</th>
                            <th>Terakhir Diubah</th>
                            <th>Status File</th>
                            <th>Sumber Database</th>
                            <th>Path Absolut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referencedFileRows as $row): ?>
                            <tr>
                                <td><code><?= htmlspecialchars((string)$row['relative_path'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td data-order="<?= (int)$row['size'] ?>"><?= htmlspecialchars(storageAuditFormatBytes((int)$row['size']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['modified_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge-counter<?= !empty($row['exists']) ? '' : ' badge-muted' ?>">
                                        <?= !empty($row['exists']) ? 'Ada di storage' : 'Path tercatat, file hilang' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php foreach (($row['sources'] ?? []) as $source): ?>
                                        <div><code><?= htmlspecialchars((string)$source, ENT_QUOTES, 'UTF-8') ?></code></div>
                                    <?php endforeach; ?>
                                </td>
                                <td><code><?= htmlspecialchars(!empty($row['exists']) ? str_replace('/', '\\', (string)$row['absolute_path']) : '-', ENT_QUOTES, 'UTF-8') ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($referencedFileRows === []): ?>
                    <div class="muted-placeholder p-4">Belum ada file storage yang tercatat di database.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#storageAuditTable').DataTable({
            pageLength: 25,
            scrollX: true,
            autoWidth: false,
            order: [[1, 'desc']],
            language: {
                url: '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            }
        });

        jQuery('#storageReferencedTable').DataTable({
            pageLength: 25,
            scrollX: true,
            autoWidth: false,
            order: [[1, 'desc']],
            language: {
                url: '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            }
        });
    }

    document.querySelectorAll('.js-delete-orphan-file').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus file ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.js-delete-all-orphan-files').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus semua file orphan?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
