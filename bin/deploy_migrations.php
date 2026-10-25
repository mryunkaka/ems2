<?php
declare(strict_types=1);

/**
 * Apply only numbered SQL migrations added after the production baseline.
 * Usage: php bin/deploy_migrations.php --directory=/path/to/docs/sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$envBootstrap = trim((string) (getenv('EMS_DEPLOY_ENV_BOOTSTRAP') ?: ''));
require_once ($envBootstrap !== '' ? $envBootstrap : __DIR__ . '/../config/env.php');

const EMS_MIGRATION_BASELINE = 102;
$directory = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--directory=')) {
        $directory = substr($argument, strlen('--directory='));
    }
}

if ($directory === null || !is_dir($directory)) {
    fwrite(STDERR, "ERROR: folder SQL tidak ditemukan.\n");
    exit(2);
}

/** Split ordinary MySQL scripts without breaking quoted strings/comments. */
function ems_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $state = 'normal';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($state === 'line') {
            if ($char === "\n") {
                $state = 'normal';
                $buffer .= "\n";
            }
            continue;
        }
        if ($state === 'block') {
            if ($char === '*' && $next === '/') {
                $state = 'normal';
                $i++;
                $buffer .= ' ';
            }
            continue;
        }
        if ($state === 'single' || $state === 'double' || $state === 'backtick') {
            $buffer .= $char;
            $terminator = $state === 'single' ? "'" : ($state === 'double' ? '"' : '`');
            if ($char === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
            } elseif ($char === $terminator) {
                if ($next === $terminator) {
                    $buffer .= $next;
                    $i++;
                } else {
                    $state = 'normal';
                }
            }
            continue;
        }

        if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
            $state = 'line';
            $i++;
        } elseif ($char === '#') {
            $state = 'line';
        } elseif ($char === '/' && $next === '*') {
            $state = 'block';
            $i++;
        } elseif ($char === "'") {
            $state = 'single';
            $buffer .= $char;
        } elseif ($char === '"') {
            $state = 'double';
            $buffer .= $char;
        } elseif ($char === '`') {
            $state = 'backtick';
            $buffer .= $char;
        } elseif ($char === ';') {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
        } else {
            $buffer .= $char;
        }
    }

    if ($state === 'block' || $state === 'single' || $state === 'double' || $state === 'backtick') {
        throw new RuntimeException('SQL memiliki komentar atau kutip yang belum ditutup.');
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

try {
    $dbHost = (string) ems_env('DB_HOST', '127.0.0.1');
    $dbName = (string) ems_env('DB_NAME', '');
    $dbUser = (string) ems_env('DB_USER', '');
    $dbPass = (string) ems_env('DB_PASS', '');
    $dbTimezone = (string) ems_env('DB_TIMEZONE', '+07:00');
    if ($dbName === '' || $dbUser === '') {
        throw new RuntimeException('Konfigurasi database di .env belum lengkap.');
    }
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]
    );
    $pdo->exec('SET time_zone = ' . $pdo->quote($dbTimezone));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ledgerExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ems_deploy_migrations'"
    )->fetchColumn();

    if (!$ledgerExists && (string) ems_env('EMS_MIGRATION_BASELINE', '') !== (string) EMS_MIGRATION_BASELINE) {
        throw new RuntimeException(
            'Migration belum diaktifkan. Pastikan database produksi sudah menerapkan migrasi lama sampai 102, lalu tambahkan EMS_MIGRATION_BASELINE=102 ke .env.'
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ems_deploy_migrations (\n" .
        "  migration_id VARCHAR(255) NOT NULL PRIMARY KEY,\n" .
        "  sha256 CHAR(64) NOT NULL,\n" .
        "  status VARCHAR(16) NOT NULL DEFAULT 'applied',\n" .
        "  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!$ledgerExists) {
        $baseline = $pdo->prepare('INSERT INTO ems_deploy_migrations (migration_id, sha256) VALUES (?, ?)');
        $baseline->execute(['__baseline_before_103', hash('sha256', 'EMS2 production baseline 102')]);
        echo "Baseline 102 dicatat; migrasi lama tidak dijalankan ulang.\n";
    }

    $interrupted = $pdo->query("SELECT migration_id FROM ems_deploy_migrations WHERE status = 'running' LIMIT 1")->fetchColumn();
    if ($interrupted !== false) {
        throw new RuntimeException(
            'Migrasi sebelumnya terhenti di ' . $interrupted . '. Periksa/perbaiki database secara manual sebelum cron dilanjutkan.'
        );
    }

    $files = [];
    foreach (new DirectoryIterator($directory) as $entry) {
        if (!$entry->isFile() || !preg_match('/^(\d{3,})_.+\.sql$/', $entry->getFilename(), $match)) {
            continue;
        }
        $number = (int) $match[1];
        if ($number > EMS_MIGRATION_BASELINE) {
            $files[] = ['number' => $number, 'path' => $entry->getPathname(), 'name' => $entry->getFilename()];
        }
    }
    usort($files, static fn(array $a, array $b): int => [$a['number'], $a['name']] <=> [$b['number'], $b['name']]);

    $insert = $pdo->prepare("INSERT INTO ems_deploy_migrations (migration_id, sha256, status) VALUES (?, ?, 'running')");
    $markApplied = $pdo->prepare("UPDATE ems_deploy_migrations SET status = 'applied' WHERE migration_id = ?");
    $appliedCount = 0;
    foreach ($files as $file) {
        $sql = file_get_contents($file['path']);
        if ($sql === false) {
            throw new RuntimeException('Tidak dapat membaca ' . $file['name']);
        }
        $checksum = hash('sha256', $sql);
        $check = $pdo->prepare('SELECT sha256 FROM ems_deploy_migrations WHERE migration_id = ?');
        $check->execute([$file['name']]);
        $existingChecksum = $check->fetchColumn();
        if ($existingChecksum !== false) {
            if (!hash_equals((string) $existingChecksum, $checksum)) {
                throw new RuntimeException('Checksum berubah untuk migrasi yang sudah tercatat: ' . $file['name']);
            }
            continue;
        }

        echo 'Menjalankan ' . $file['name'] . "\n";
        $insert->execute([$file['name'], $checksum]);
        // Record intent before non-transactional MySQL DDL can partially run.
        foreach (ems_split_sql($sql) as $index => $statement) {
            try {
                // EXECUTE may return a result set even when the prepared SQL
                // is DDL (for example, an IF branch containing SELECT 1).
                // Drain it just like a direct SELECT before the next statement.
                if (preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|WITH|EXECUTE)\b/i', ltrim($statement))) {
                    $result = $pdo->query($statement);
                    if ($result !== false) {
                        $result->fetchAll();
                        $result->closeCursor();
                    }
                } else {
                    $pdo->exec($statement);
                }
            } catch (Throwable $error) {
                throw new RuntimeException(
                    $file['name'] . ' gagal pada statement ' . ($index + 1) . ': ' . $error->getMessage(),
                    0,
                    $error
                );
            }
        }
        $markApplied->execute([$file['name']]);
        $appliedCount++;
        echo 'Selesai ' . $file['name'] . "\n";
    }

    echo $appliedCount === 0 ? "Tidak ada migrasi SQL baru.\n" : "Migrasi baru selesai: {$appliedCount}.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
