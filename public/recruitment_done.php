<?php
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/helpers.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Selesai - Roxwood Hospital</title>

    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>

<body>
    <div class="public-shell">
        <div class="public-layout">
            <aside class="public-panel public-panel-hero public-sticky">
                <div class="public-brand">
                    <img src="/assets/logo.png" alt="Logo Roxwood Hospital" class="public-brand-logo">
                    <div class="public-brand-text">
                        <span class="public-kicker">Recruitment Complete</span>
                        <strong class="text-lg font-bold text-white">Roxwood Hospital</strong>
                        <span class="meta-text">Emergency Medical System</span>
                    </div>
                </div>

                <h1 class="public-heading">Pendaftaran Berhasil Dikirim</h1>
                <p class="public-copy">
                    Data pendaftaran dan jawaban assessment Anda sudah diterima oleh sistem rekrutmen Roxwood Hospital.
                </p>

                <div class="public-feature-list">
                    <div class="public-feature-item">
                        <span class="public-feature-title">Status Saat Ini</span>
                        Data Anda masuk ke tahap peninjauan tim rekrutmen.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tahap Berikutnya</span>
                        Tim akan memeriksa formulir, dokumen, dan hasil pertanyaan Anda.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tindakan Anda</span>
                        Tidak perlu mengirim ulang kecuali ada instruksi resmi dari tim.
                    </div>
                </div>
            </aside>

            <main class="public-panel">
                <div class="flex flex-col items-center text-center">
                    <div class="mb-4 grid h-16 w-16 place-items-center rounded-3xl border border-emerald-200 bg-emerald-50 text-emerald-700 shadow-soft">
                        <?= ems_icon('check-circle', 'h-8 w-8') ?>
                    </div>

                    <h1 class="public-form-title">Terima Kasih</h1>
                    <p class="public-form-subtitle mt-2 max-w-2xl">
                        Proses pendaftaran dan pengisian pertanyaan telah selesai. Tim rekrutmen akan meninjau data Anda sebelum memberikan informasi lanjutan.
                    </p>
                </div>

                <div class="card mt-6">
                    <div class="card-header">
                        <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                        <span>Informasi Selanjutnya</span>
                    </div>
                    <div class="space-y-3 text-sm leading-6 text-slate-700">
                        <p>Tim rekrutmen akan meninjau formulir, dokumen, dan jawaban yang sudah Anda kirim.</p>
                        <p>Jika dibutuhkan tindak lanjut, informasi akan diberikan melalui kanal pengumuman resmi atau media komunikasi yang berlaku.</p>
                        <p>Tidak perlu melakukan pendaftaran ulang. Mohon menunggu dengan sabar.</p>
                    </div>
                </div>

                <div class="card mb-0 bg-slate-50/80">
                    <div class="card-header">
                        <?= ems_icon('arrow-right-circle', 'h-5 w-5') ?>
                        <span>Akses Lanjutan</span>
                    </div>
                    <p class="helper-note mb-4">
                        Anda bisa memakai tombol reset device untuk menghapus state browser lokal tanpa mengulang data yang sudah tersimpan di server.
                    </p>
                    <div class="form-submit-wrapper gap-3">
                        <a href="<?= htmlspecialchars(ems_url('/public/recruitment_form.php'), ENT_QUOTES, 'UTF-8') ?>" id="resetRecruitmentDeviceState" class="btn-secondary w-full justify-center md:w-auto">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Reset Device ke Form Awal</span>
                        </a>
                        <a href="https://discord.gg/imeroleplay" class="btn-primary w-full justify-center md:w-auto">
                            <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                            <span>Kunjungi Web Utama IME</span>
                        </a>
                    </div>
                </div>

                <small class="mt-5 block text-center text-xs text-slate-500">
                    Roxwood Hospital Recruitment System
                </small>
            </main>
        </div>
    </div>
    <script>
        (function() {
            const FLOW_KEY = 'ems_medical_recruitment_flow_v1';
            const FORM_DRAFT_KEY = 'public_recruitment_form_draft_v1';
            const flowState = (() => {
                try {
                    const raw = localStorage.getItem(FLOW_KEY);
                    return raw ? JSON.parse(raw) : null;
                } catch (_) {
                    return null;
                }
            })();

            if (flowState && typeof flowState === 'object') {
                try {
                    localStorage.setItem(FLOW_KEY, JSON.stringify(Object.assign({}, flowState, {
                        phase: 'done',
                        updatedAt: Date.now()
                    })));
                } catch (_) {}

                if (flowState.aiTestStorageKey) {
                    try {
                        localStorage.removeItem(String(flowState.aiTestStorageKey));
                    } catch (_) {}
                }
            }

            try {
                localStorage.removeItem(FORM_DRAFT_KEY);
            } catch (_) {}

            document.getElementById('resetRecruitmentDeviceState')?.addEventListener('click', function() {
                try {
                    const current = flowState && typeof flowState === 'object' ? flowState : null;
                    if (current && current.aiTestStorageKey) {
                        localStorage.removeItem(String(current.aiTestStorageKey));
                    }
                    localStorage.removeItem(FLOW_KEY);
                    localStorage.removeItem(FORM_DRAFT_KEY);
                } catch (_) {}
            });
        })();
    </script>
</body>

</html>
