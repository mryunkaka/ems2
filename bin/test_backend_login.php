<?php
declare(strict_types=1);

/**
 * Read-only backend credential check for a single user.
 * Run from the application directory: php bin/test_backend_login.php
 * It verifies the submitted PIN against the stored password hash and does not
 * create a PHP session, remember token, login event, or database write.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/database.php';

function readHiddenPin(): string
{
    if (!function_exists('exec')) {
        throw new RuntimeException('Tidak tersedia terminal tersembunyi untuk membaca PIN dengan aman.');
    }

    $modeOutput = [];
    $modeStatus = 1;
    @exec('stty -g 2>/dev/null', $modeOutput, $modeStatus);
    $terminalMode = trim((string) ($modeOutput[0] ?? ''));
    if ($modeStatus !== 0 || $terminalMode === '') {
        throw new RuntimeException('Jalankan skrip dari terminal interaktif yang mendukung stty.');
    }

    fwrite(STDERR, 'PIN (input tidak ditampilkan): ');
    $disableOutput = [];
    $disableStatus = 1;
    @exec('stty -echo 2>/dev/null', $disableOutput, $disableStatus);
    if ($disableStatus !== 0) {
        throw new RuntimeException('Terminal tidak dapat menyembunyikan PIN; input dibatalkan.');
    }

    try {
        $pin = fgets(STDIN);
    } finally {
        $restoreOutput = [];
        $restoreStatus = 1;
        @exec('stty ' . escapeshellarg($terminalMode) . ' 2>/dev/null', $restoreOutput, $restoreStatus);
        fwrite(STDERR, PHP_EOL);
    }

    if ($pin === false) {
        throw new RuntimeException('PIN tidak terbaca.');
    }

    return trim($pin);
}

try {
    fwrite(STDOUT, 'Nama lengkap: ');
    $nameInput = fgets(STDIN);
    $name = trim((string) $nameInput);
    if ($name === '') {
        throw new RuntimeException('Nama lengkap wajib diisi.');
    }

    $pin = readHiddenPin();
    if (!preg_match('/^\d{4}$/', $pin)) {
        throw new RuntimeException('PIN harus terdiri dari 4 digit.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, full_name, pin, is_verified, is_active FROM user_rh WHERE full_name = ? LIMIT 2'
    );
    $stmt->execute([$name]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($matches) !== 1) {
        throw new RuntimeException(count($matches) === 0
            ? 'Akun dengan nama persis tersebut tidak ditemukan.'
            : 'Nama cocok dengan lebih dari satu akun; pemeriksaan dihentikan.');
    }

    $account = $matches[0];
    if (!password_verify($pin, (string) $account['pin'])) {
        unset($pin, $account);
        fwrite(STDERR, "HASIL: nama atau PIN tidak cocok.\n");
        exit(1);
    }

    unset($pin, $account['pin']);
    echo "HASIL: PIN cocok dengan hash akun.\n";
    echo 'Status akun: ' . ((int) $account['is_verified'] === 1 ? 'terverifikasi' : 'belum terverifikasi')
        . ', ' . ((int) $account['is_active'] === 1 ? 'aktif' : 'nonaktif') . ".\n";
    echo "Tidak ada sesi atau token login yang dibuat.\n";
} catch (Throwable $error) {
    unset($pin, $account);
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
