<?php
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/recruitment_gate.php';

ems_public_recruitment_require_portal_open('assistant_manager');

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    ems_public_recruitment_gate_clear();
    header('Location: ' . ems_url('/public/ga_recruitment.php'));
    exit;
}

$existingGate = ems_public_recruitment_gate_get();
if ($existingGate && !empty($existingGate['citizen_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $freshGate = ems_public_recruitment_build_gate($pdo, (string)$existingGate['citizen_id'], [
        'ic_name' => (string)($existingGate['ic_name'] ?? ''),
    ], 'assistant_manager');
    ems_public_recruitment_gate_set($freshGate);
    ems_public_recruitment_redirect_for_gate($freshGate);
}

$errorMessage = '';
$citizenIdValue = '';
$icNameValue = '';
$profile = ems_recruitment_profile('assistant_manager');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $citizenIdValue = ems_normalize_citizen_id($_POST['citizen_id'] ?? '');
    $icNameValue = trim((string)($_POST['ic_name'] ?? ''));

    if ($icNameValue === '') {
        $errorMessage = 'Nama IC wajib diisi.';
    } elseif ($citizenIdValue === '') {
        $errorMessage = 'Citizen ID wajib diisi.';
    } else {
        $gate = ems_public_recruitment_build_gate($pdo, $citizenIdValue, [
            'ic_name' => $icNameValue,
        ], 'assistant_manager');
        ems_public_recruitment_gate_set($gate);
        ems_public_recruitment_redirect_for_gate($gate);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recruitment Asisten Manager - Roxwood Hospital</title>
    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>
<body>
    <div class="public-shell">
        <div class="public-layout">
            <aside class="public-panel public-panel-hero public-sticky">
                <div class="public-brand">
                    <img src="/assets/logo.png" alt="Logo Roxwood Hospital" class="public-brand-logo">
                    <div class="public-brand-text">
                        <span class="public-kicker">Recruitment Asisten Manager</span>
                        <strong class="text-lg font-bold text-white">Roxwood Hospital</strong>
                        <span class="meta-text">General Affair Recruitment Track</span>
                    </div>
                </div>

                <h1 class="public-heading">Cek Citizen ID</h1>
                <p class="public-copy">
                    Jalur ini khusus untuk staf EMS Roxwood yang sudah terdaftar dan ingin mendaftar sebagai calon Asisten Manager / Probation Manager. Masukkan Citizen ID untuk melanjutkan.
                </p>

                <div class="public-feature-list">
                    <div class="public-feature-item">
                        <span class="public-feature-title">Khusus Staf Terdaftar</span>
                        Citizen ID harus sudah terverifikasi pada akun EMS Roxwood.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Jalur Terpisah</span>
                        Status buka/tutup jalur ini terpisah dari rekrutmen medis.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Akses Terkontrol</span>
                        Halaman form, AI test, dan selesai hanya bisa dibuka lewat halaman ini.
                    </div>
                </div>
            </aside>

            <main class="public-panel">
                <div class="public-form-header">
                    <div>
                        <h2 class="public-form-title">Verifikasi Akses Recruitment Asisten Manager</h2>
                        <p class="public-form-subtitle"><?= htmlspecialchars($profile['badge']) ?></p>
                    </div>
                    <div class="badge-muted">Step 1</div>
                </div>

                <form method="post" class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('identification', 'h-5 w-5') ?>
                        <span>Data Akses</span>
                    </div>

                    <div class="form-group">
                        <label for="ic_name" class="text-sm font-semibold text-slate-900">Nama IC</label>
                        <input type="text" id="ic_name" name="ic_name" value="<?= htmlspecialchars($icNameValue) ?>" placeholder="Masukkan nama IC" autocomplete="off" required>
                    </div>

                    <div class="form-group">
                        <label for="citizen_id" class="text-sm font-semibold text-slate-900">Masukkan Citizen ID</label>
                        <input type="text" id="citizen_id" name="citizen_id" value="<?= htmlspecialchars($citizenIdValue) ?>" placeholder="Contoh: ABC12345" autocomplete="off" required>
                        <small class="hint-info">Input akan otomatis diubah ke format huruf besar saat diproses.</small>
                    </div>

                    <?php if ($errorMessage !== ''): ?>
                        <?= ems_render_toast_script((string)$errorMessage, 'error', 'Portal Asisten Manager', 6800) ?>
                    <?php endif; ?>

                    <div class="form-submit-wrapper mt-6">
                        <button type="submit" class="btn-success w-full justify-center md:w-auto">
                            <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                            <span>Lanjutkan</span>
                        </button>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const citizenInput = document.getElementById('citizen_id');
            if (!citizenInput) {
                return;
            }

            citizenInput.addEventListener('input', function() {
                this.value = String(this.value || '').toUpperCase().replace(/[^A-Z0-9]+/g, '');
            });
        });
    </script>
</body>
</html>
