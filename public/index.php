<?php
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/recruitment_gate.php';

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    ems_public_recruitment_gate_clear();
    header('Location: ' . ems_url('/public/index.php'));
    exit;
}

$existingGate = ems_public_recruitment_gate_get();
if ($existingGate && !empty($existingGate['citizen_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $freshGate = ems_public_recruitment_build_gate($pdo, (string)$existingGate['citizen_id']);
    ems_public_recruitment_gate_set($freshGate);
    ems_public_recruitment_redirect_for_gate($freshGate);
}

$errorMessage = '';
$citizenIdValue = '';
$profile = ems_recruitment_profile('medical_candidate');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $citizenIdValue = ems_normalize_citizen_id($_POST['citizen_id'] ?? '');

    if ($citizenIdValue === '') {
        $errorMessage = 'Citizen ID wajib diisi.';
    } else {
        $gate = ems_public_recruitment_build_gate($pdo, $citizenIdValue);
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
    <title>Recruitment Access - Roxwood Hospital</title>
    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>
<body>
    <div class="public-shell">
        <div class="public-layout">
            <aside class="public-panel public-panel-hero public-sticky">
                <div class="public-brand">
                    <img src="/assets/logo.png" alt="Logo Roxwood Hospital" class="public-brand-logo">
                    <div class="public-brand-text">
                        <span class="public-kicker">Recruitment Portal</span>
                        <strong class="text-lg font-bold text-white">Roxwood Hospital</strong>
                        <span class="meta-text">Emergency Medical System</span>
                    </div>
                </div>

                <h1 class="public-heading">Cek Citizen ID</h1>
                <p class="public-copy">
                    Masukkan Citizen ID untuk melanjutkan ke tahap yang sesuai. Sistem akan otomatis mengarahkan ke form, AI test, atau halaman selesai.
                </p>

                <div class="public-feature-list">
                    <div class="public-feature-item">
                        <span class="public-feature-title">Input Fleksibel</span>
                        Huruf besar dan kecil tidak berpengaruh saat pengecekan.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Format Database</span>
                        Citizen ID akan dinormalisasi ke huruf besar.
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
                        <h2 class="public-form-title">Verifikasi Akses Recruitment</h2>
                        <p class="public-form-subtitle"><?= htmlspecialchars($profile['badge']) ?></p>
                    </div>
                    <div class="badge-muted">Step 1</div>
                </div>

                <form method="post" class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('identification', 'h-5 w-5') ?>
                        <span>Citizen ID</span>
                    </div>

                    <div class="form-group">
                        <label for="citizen_id" class="text-sm font-semibold text-slate-900">Masukkan Citizen ID</label>
                        <input type="text" id="citizen_id" name="citizen_id" value="<?= htmlspecialchars($citizenIdValue) ?>" placeholder="Contoh: ABC12345" autocomplete="off" required>
                        <small class="hint-info">Input akan otomatis diubah ke format huruf besar saat diproses.</small>
                    </div>

                    <?php if ($errorMessage !== ''): ?>
                        <div class="alert alert-danger mt-4 mb-0">
                            <?= htmlspecialchars($errorMessage) ?>
                        </div>
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
