<?php
/**
 * RUN MIGRATION - CUTI EARLY RETURN COLUMNS
 *
 * Menjalankan SQL migration untuk menambahkan kolom early return cuti
 */

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRATION: CUTI EARLY RETURN COLUMNS ===\n\n";

try {
    // Baca file SQL
    $sqlFile = __DIR__ . '/add_cuti_early_return_columns.sql';
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
            // Ignore duplicate column/key errors
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate') !== false) {
                echo "○ Statement " . ($index + 1) . " sudah ada (di-skip)\n";
            } else {
                echo "✗ Statement " . ($index + 1) . " gagal: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n=== HASIL MIGRATION ===\n";
    echo "Sukses: {$successCount} dari " . count($statements) . " statements\n\n";

    // Verifikasi hasil migration
    echo "=== VERIFIKASI ===\n";

    // Cek kolom cuti_ended_at
    $stmt = $pdo->query("SHOW COLUMNS FROM user_rh LIKE 'cuti_ended_at'");
    $colExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$colExists} Kolom cuti_ended_at\n";

    // Cek kolom cuti_ended_by
    $stmt = $pdo->query("SHOW COLUMNS FROM user_rh LIKE 'cuti_ended_by'");
    $colExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$colExists} Kolom cuti_ended_by\n";

    // Cek kolom cuti_original_days
    $stmt = $pdo->query("SHOW COLUMNS FROM user_rh LIKE 'cuti_original_days'");
    $colExists = $stmt->fetch() ? '✓' : '✗';
    echo "{$colExists} Kolom cuti_original_days\n";

    echo "\n=== MIGRATION SELESAI ===\n";

} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
}
