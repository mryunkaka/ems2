<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/spreadsheet.php';

$pageTitle = 'Setting Spreadsheet';

// === Flash message ===
$messages = $_SESSION['flash_messages'] ?? [];
$errors   = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

// === HANDLE POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $spreadsheetId = trim($_POST['spreadsheet_id'] ?? '');
    $sheetGid      = trim($_POST['sheet_gid'] ?? '');

    if ($spreadsheetId === '' || $sheetGid === '') {
        $errors[] = 'Spreadsheet ID dan Sheet GID wajib diisi.';
    } else {
        $data = [
            'spreadsheet_id' => $spreadsheetId,
            'sheet_gid'      => $sheetGid,
        ];

        if (file_put_contents(
            __DIR__ . '/sheet_config.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) === false) {
            $errors[] = 'Gagal menyimpan konfigurasi spreadsheet.';
        } else {
            $messages[] = 'Konfigurasi Spreadsheet berhasil disimpan.';
        }
    }

    $_SESSION['flash_messages'] = $messages;
    $_SESSION['flash_errors']   = $errors;

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// === Helper URL ===
$currentCsvUrl = ($sheetConfig['spreadsheet_id'] && $sheetConfig['sheet_gid'])
    ? sprintf(
        'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s',
        $sheetConfig['spreadsheet_id'],
        $sheetConfig['sheet_gid']
    )
    : '';

$sheetEditUrl = ($sheetConfig['spreadsheet_id'] && $sheetConfig['sheet_gid'])
    ? sprintf(
        'https://docs.google.com/spreadsheets/d/%s/edit?gid=%s#gid=%s',
        $sheetConfig['spreadsheet_id'],
        $sheetConfig['sheet_gid'],
        $sheetConfig['sheet_gid']
    )
    : '';
?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto;">
        <h1>Setting Spreadsheet</h1>

        <p style="font-size:13px;color:#9ca3af;">
            Digunakan untuk import data Google Sheets (CSV).
        </p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-success"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header">Konfigurasi Google Spreadsheet</div>

            <form method="post">
                <div class="form-group">
                    <label>Spreadsheet ID</label>
                    <input type="text"
                        name="spreadsheet_id"
                        class="form-control"
                        value="<?= htmlspecialchars($sheetConfig['spreadsheet_id']) ?>"
                        placeholder="contoh: 1300EqaCtHs8PrHKepzEQRk-ALwtfh1FcBAeaW95XKWU">
                </div>

                <div class="form-group">
                    <label>Sheet GID</label>
                    <input type="text"
                        name="sheet_gid"
                        class="form-control"
                        value="<?= htmlspecialchars($sheetConfig['sheet_gid']) ?>"
                        placeholder="contoh: 1891016011">
                </div>

                <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="btn-primary">
                        Simpan Konfigurasi
                    </button>
                </div>

            </form>

            <?php if ($currentCsvUrl): ?>
                <hr>
                <small>CSV Aktif:</small><br>
                <code><?= htmlspecialchars($currentCsvUrl) ?></code><br><br>
                <a href="<?= htmlspecialchars($sheetEditUrl) ?>" target="_blank">
                    Buka Spreadsheet
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>