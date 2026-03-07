<?php
/**
 * RUN MIGRATION SCRIPT
 *
 * Menjalankan SQL migration untuk sistem cuti & resign
 */

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRATION: SISTEM CUTI & RESIGN ===\n\n";

try {
    // Baca file SQL
    $sqlFile = __DIR__ . '/add_cuti_resign_tables.sql';
    if (!file_exists($sqlFile)) {
        die("ERROR: File SQL tidak ditemukan: {$sqlFile}\n");
    }

    $sql = file_get_contents($sqlFile);

    // Hapus komentar SQL dan split per statement
    $statements = [];
    $currentStatement = '';
    $lines = explode("\n", $sql);

    foreach ($lines as $line) {
        // Skip komentar dan baris kosong
        $trimmed = trim($line);
        if (empty($trimmed) ||
            preg_match('/^--/', $trimmed) ||
            preg_match('/^#/', $trimmed) ||
            preg_match('/^\/\*/', $trimmed)) {
            continue;
        }

        // Tambahkan line ke statement saat ini
        $currentStatement .= $line . "\n";

        // Jika ketika semicolon, execute statement
        if (preg_match('/;(\s*)$/', $trimmed)) {
            $statements[] = $currentStatement;
            $currentStatement = '';
        }
    }

    echo "Menjalankan " . count($statements) . " SQL statements...\n\n";

    // Execute setiap statement
    $successCount = 0;
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;

        try {
            $pdo->exec($statement);
            $successCount++;
            echo "✓ Statement " . ($index + 1) . " berhasil dijalankan\n";
        } catch (PDOException $e) {
            echo "✗ Statement " . ($index + 1) . " gagal: " . $e->getMessage() . "\n";
            echo "  Statement: " . substr($statement, 0, 100) . "...\n";
        }
    }

    echo "\n=== HASIL MIGRATION ===\n";
    echo "Sukses: {$successCount} dari " . count($statements) . " statements\n\n";

    // Verifikasi hasil migration
    echo "=== VERIFIKASI ===\n";

    // Cek tabel cuti_requests
    $stmt = $pdo->query("SHOW TABLES LIKE 'cuti_requests'");
    $cutiExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$cutiExists} Tabel cuti_requests\n";

    // Cek tabel resign_requests
    $stmt = $pdo->query("SHOW TABLES LIKE 'resign_requests'");
    $resignExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$resignExists} Tabel resign_requests\n";

    // Cek field cuti di user_rh
    $stmt = $pdo->query("SHOW COLUMNS FROM user_rh LIKE 'cuti_start_date'");
    $cutiFieldExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$cutiFieldExists} Field cuti_start_date di user_rh\n";

    $stmt = $pdo->query("SHOW COLUMNS FROM user_rh LIKE 'cuti_end_date'");
    $cutiFieldExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$cutiFieldExists} Field cuti_end_date di user_rh\n";

    $stmt = $pdo->query("SHOW COLUMNS FROM user_rh LIKE 'cuti_status'");
    $cutiFieldExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$cutiFieldExists} Field cuti_status di user_rh\n";

    echo "\n=== MIGRATION SELESAI ===\n";

} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
