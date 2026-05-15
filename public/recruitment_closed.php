<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/recruitment_settings.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$settings = ems_recruitment_get_settings($pdo);
$closedMessage = trim((string)($settings['closed_message'] ?? ''));
if ($closedMessage === '') {
    $closedMessage = 'Pendaftaran Medis Roxwood saat ini belum dibuka. Silakan menunggu informasi selanjutnya.';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recruitment Closed - Roxwood Hospital</title>
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

                <h1 class="public-heading">Pendaftaran Sementara Ditutup</h1>
                <p class="public-copy">
                    Akses pendaftaran sedang dinonaktifkan sementara oleh tim rekrutmen. Silakan pantau pengumuman resmi untuk informasi pembukaan berikutnya.
                </p>

                <div class="public-feature-list">
                    <div class="public-feature-item">
                        <span class="public-feature-title">Status Portal</span>
                        Pendaftaran baru saat ini belum dapat dilakukan.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Proses Kandidat Lama</span>
                        Data yang sudah pernah masuk tetap dapat ditinjau oleh tim rekrutmen.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Informasi Berikutnya</span>
                        Tunggu pengumuman resmi saat portal dibuka kembali.
                    </div>
                </div>

                <div class="card mt-5 mb-0 border-white/10 bg-white/10 text-white shadow-none">
                    <div class="card-header border-white/10 pb-3 text-white">
                        <?= ems_icon('clock', 'h-5 w-5') ?>
                        <span>Catatan</span>
                    </div>
                    <div class="space-y-3 text-sm leading-6 text-slate-200">
                        <p>Halaman ini muncul otomatis ketika status rekrutmen diubah ke mode close oleh admin yang berwenang.</p>
                        <p>Anda tidak perlu melakukan refresh berulang. Silakan cek kembali pada informasi resmi berikutnya.</p>
                    </div>
                </div>
            </aside>

            <main class="public-panel">
                <div class="public-form-header">
                    <div>
                        <h2 class="public-form-title">Informasi Rekrutmen</h2>
                        <p class="public-form-subtitle">Status akses portal pendaftaran saat ini</p>
                    </div>
                    <div class="badge-danger">Portal Closed</div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('megaphone', 'h-5 w-5') ?>
                        <span>Pengumuman Resmi</span>
                    </div>
                    <div class="text-sm leading-7 text-slate-700">
                        <?= nl2br(htmlspecialchars($closedMessage, ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('information-circle', 'h-5 w-5') ?>
                        <span>Apa Yang Perlu Dilakukan?</span>
                    </div>
                    <div class="space-y-3 text-sm leading-6 text-slate-700">
                        <p>Tidak perlu melakukan pendaftaran ulang selama portal masih ditutup.</p>
                        <p>Jika Anda sudah pernah mengirim data sebelumnya, proses review tetap mengikuti alur internal tim rekrutmen.</p>
                        <p>Silakan tunggu informasi selanjutnya dari Roxwood Hospital.</p>
                    </div>
                </div>

                <div class="card mb-0 bg-slate-50/80">
                    <div class="card-header">
                        <?= ems_icon('shield-exclamation', 'h-5 w-5') ?>
                        <span>Status Saat Ini</span>
                    </div>
                    <p class="helper-note mb-0">
                        Portal rekrutmen sedang ditutup sementara. Akses akan kembali tersedia setelah status diubah menjadi open oleh admin.
                    </p>
                </div>
            </main>
        </div>
    </div>
</body>

</html>
