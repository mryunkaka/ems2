<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/ocr_config.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/ai_guard.php';
require_once __DIR__ . '/../helpers/ocr_key_manager.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Status OCR API';
$isOcrAdmin = function_exists('ems_current_user_is_programmer_roxwood') && ems_current_user_is_programmer_roxwood();
$manager = null;
$keys = [];
$setupError = '';
$runResult = null;
$flash = $_SESSION['ocr_key_flash'] ?? null;
unset($_SESSION['ocr_key_flash']);

try {
    $manager = ems_ocr_key_manager($pdo);
    if ($isOcrAdmin) {
        $manager->importConfiguredKeyOnce(EMS_OCR_API_KEY);
        $keys = $manager->listKeys();
    }
} catch (Throwable) {
    $setupError = 'Penyimpanan key OCR belum siap. Pastikan secret enkripsi tersedia dan migration ocr_api_keys sudah dijalankan.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ocr_action'])) {
    $csrfValid = validateCsrfToken(csrfRequestToken());
    if (!ems_ocr_key_action_is_allowed($isOcrAdmin, (string)($_SERVER['REQUEST_METHOD'] ?? ''), $csrfValid)) {
        http_response_code($isOcrAdmin ? 400 : 403);
        exit($isOcrAdmin ? 'CSRF token tidak valid. Muat ulang halaman dan coba lagi.' : 'Akses hanya untuk Programmer Roxwood.');
    }

    try {
        if (!$manager instanceof OcrKeyManager) {
            throw new RuntimeException('OCR key storage unavailable.');
        }
        $action = (string)$_POST['ocr_action'];
        $id = max(0, (int)($_POST['key_id'] ?? 0));
        $message = 'Perubahan pengaturan OCR tersimpan.';
        $flashType = 'success';
        switch ($action) {
            case 'add':
                $manager->saveKey((string)($_POST['api_key'] ?? ''), null, (string)($_POST['label'] ?? ''));
                $message = 'API key ditambahkan dan disimpan terenkripsi.';
                break;
            case 'replace':
                $manager->saveKey((string)($_POST['api_key'] ?? ''), $id, (string)($_POST['label'] ?? ''));
                $message = 'API key diganti dan disimpan terenkripsi.';
                break;
            case 'enable':
            case 'disable':
                $manager->setEnabled($id, $action === 'enable');
                $message = $action === 'enable' ? 'API key diaktifkan.' : 'API key dinonaktifkan.';
                break;
            case 'remove':
                $manager->removeKey($id);
                $message = 'API key dihapus.';
                break;
            case 'test':
                $test = $manager->testKey($id);
                $message = (string)$test['message'];
                $flashType = !empty($test['success']) ? 'success' : 'error';
                break;
            case 'test_enabled':
                $test = $manager->testEnabledKeys();
                $message = (string)$test['message'] . ' (' . (int)($test['attempts'] ?? 0) . ' key diuji)';
                $flashType = !empty($test['success']) ? 'success' : 'error';
                if (!empty($test['failure_category'])) {
                    $message .= ' Kategori: ' . (string)$test['failure_category'] . '.';
                }
                break;
            case 'usage':
                $usage = $manager->probeUsage($id);
                $message = !empty($usage['success']) ? 'Pemakaian bulanan berhasil diperbarui.' : (string)$usage['message'];
                break;
            default:
                throw new InvalidArgumentException('Unknown OCR action.');
        }
        $_SESSION['ocr_key_flash'] = ['type' => $flashType, 'message' => $message];
    } catch (Throwable) {
        $_SESSION['ocr_key_flash'] = ['type' => 'error', 'message' => 'Aksi OCR gagal. Periksa isian, status database, dan koneksi provider.'];
    }
    header('Location: ocr_api_status.php');
    exit;
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Status OCR API</h1>
        <p class="page-subtitle">Kelola API key OCR.Space dan pantau pemakaian layanan.</p>

        <?php if ($flash): ?>
            <div class="notice-box <?= ($flash['type'] ?? '') === 'success' ? 'notice-info' : 'notice-danger' ?>" style="display:block;">
                <?= htmlspecialchars((string)($flash['message'] ?? '')) ?>
            </div>
        <?php endif; ?>

        <?php if ($setupError !== ''): ?>
            <div class="notice-box notice-danger" style="display:block;"><?= htmlspecialchars($setupError) ?></div>
        <?php endif; ?>

        <?php if ($isOcrAdmin): ?>
            <div class="card card-section">
                <div class="card-header">API key OCR.Space</div>
                <div class="card-body">
                    <p class="text-sm text-slate-600">Key hanya disimpan terenkripsi. Masukkan key yang dibuat dari akun OCR.Space organisasi Anda. Tes gambar menggunakan satu konversi OCR biasa. Gunakan sejumlah kecil key milik organisasi; pergantian key tidak menaikkan batas harian berbasis IP.</p>
                    <form method="post" class="mt-4 grid gap-3 md:grid-cols-[1fr_2fr_auto] items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="ocr_action" value="add">
                        <label class="block text-sm">Label
                            <input name="label" maxlength="100" class="form-input mt-1 w-full" placeholder="Contoh: Key utama" required>
                        </label>
                        <label class="block text-sm">API key baru
                            <input name="api_key" type="password" autocomplete="new-password" maxlength="512" class="form-input mt-1 w-full" required>
                        </label>
                        <button class="btn-primary" type="submit">Tambah key</button>
                    </form>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="ocr_action" value="test_enabled">
                            <button class="btn-primary" type="submit"><?= ems_icon('arrow-path', 'h-4 w-4') ?> Uji key aktif</button>
                        </form>
                        <a href="https://ocr.space/ocrapi" target="_blank" rel="noopener noreferrer" class="btn-secondary"><?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?> Buka OCR.Space</a>
                    </div>
                </div>
            </div>

            <div class="card card-section">
                <div class="card-header">Pemakaian dan status key</div>
                <div class="card-body">
                    <p class="mb-4 text-sm text-slate-600">Kuota bulanan OCR.Space yang ditampilkan: Engine 1+2 sampai 25.000 konversi dan Engine 3 sampai 1.000 konversi. Data pemakaian dapat tertinggal hingga akhir hari sebelumnya; nilai sisa adalah perkiraan.</p>
                    <?php if ($keys === []): ?>
                        <p class="text-sm text-slate-600">Belum ada API key tersimpan.</p>
                    <?php endif; ?>
                    <div class="space-y-4">
                        <?php foreach ($keys as $key): ?>
                            <?php
                            $used12 = isset($key['count_engine1'], $key['count_engine2']) ? (int)$key['count_engine1'] + (int)$key['count_engine2'] : null;
                            $used3 = $key['count_engine3'] !== null ? (int)$key['count_engine3'] : null;
                            $usageVerified = (($used12 ?? 0) + ($used3 ?? 0)) > 0 || ($key['last_test_status'] ?? '') === 'success';
                            ?>
                            <article class="rounded-xl border border-slate-200 bg-white p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 class="font-semibold text-slate-900"><?= htmlspecialchars((string)$key['label']) ?></h2>
                                        <p class="font-mono text-sm text-slate-600"><?= htmlspecialchars((string)$key['masked_key']) ?></p>
                                        <p class="mt-1 text-sm <?= !empty($key['enabled']) ? 'text-emerald-700' : 'text-slate-500' ?>"><?= !empty($key['enabled']) ? 'Aktif' : 'Nonaktif' ?> · Tes terakhir: <?= htmlspecialchars((string)($key['last_tested_at'] ?? 'belum pernah')) ?> · <?= htmlspecialchars((string)($key['last_test_status'] ?? 'belum diuji')) ?></p>
                                    </div>
                                    <div class="text-sm text-slate-700">
                                        <?php if ($used12 !== null && $usageVerified): ?>Engine 1+2: <?= number_format($used12) ?> / 25.000 · sisa ~<?= number_format(max(0, 25000 - $used12)) ?><br><?php elseif ($used12 !== null): ?>Engine 1+2: pemakaian belum terverifikasi<br><?php else: ?>Engine 1+2: belum dicek<br><?php endif; ?>
                                        <?php if ($used3 !== null && $usageVerified): ?>Engine 3: <?= number_format($used3) ?> / 1.000 · sisa ~<?= number_format(max(0, 1000 - $used3)) ?><br><?php elseif ($used3 !== null): ?>Engine 3: pemakaian belum terverifikasi<br><?php else: ?>Engine 3: belum dicek<br><?php endif; ?>
                                        Snapshot provider diperbarui: <?= htmlspecialchars((string)($key['usage_checked_at'] ?? 'belum pernah')) ?>. Data pada snapshot sampai akhir hari sebelumnya.
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <?php foreach (['test' => 'Uji key', 'usage' => 'Perbarui kuota', (!empty($key['enabled']) ? 'disable' : 'enable') => (!empty($key['enabled']) ? 'Nonaktifkan' : 'Aktifkan'), 'remove' => 'Hapus'] as $action => $label): ?>
                                        <form method="post" onsubmit="return <?= $action === 'remove' ? "confirm('Hapus key ini?')" : 'true' ?>;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="ocr_action" value="<?= htmlspecialchars($action) ?>">
                                            <input type="hidden" name="key_id" value="<?= (int)$key['id'] ?>">
                                            <button class="btn-secondary" type="submit"><?= htmlspecialchars($label) ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                    <details class="basis-full">
                                        <summary class="cursor-pointer text-sm font-semibold text-sky-700">Ganti key</summary>
                                        <form method="post" class="mt-3 grid gap-3 md:grid-cols-[1fr_2fr_auto] items-end">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="ocr_action" value="replace">
                                            <input type="hidden" name="key_id" value="<?= (int)$key['id'] ?>">
                                            <label class="block text-sm">Label
                                                <input name="label" maxlength="100" class="form-input mt-1 w-full" value="<?= htmlspecialchars((string)$key['label']) ?>" required>
                                            </label>
                                            <label class="block text-sm">API key pengganti
                                                <input name="api_key" type="password" autocomplete="new-password" maxlength="512" class="form-input mt-1 w-full" required>
                                            </label>
                                            <button class="btn-primary" type="submit">Simpan pengganti</button>
                                        </form>
                                    </details>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card card-section"><div class="card-body">Pengaturan key OCR hanya tersedia untuk Programmer Roxwood.</div></div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
