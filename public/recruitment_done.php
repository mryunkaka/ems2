<?php
// PUBLIC PAGE
// TANPA SESSION
require_once __DIR__ . '/../assets/design/ui/icon.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Selesai - Roxwood Hospital</title>

    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-sky-900">

    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-[560px]">
            <div class="rounded-3xl border border-white/60 bg-white/85 p-6 shadow-modal backdrop-blur">

                <div class="flex flex-col items-center text-center">
                    <div class="mb-4 grid h-14 w-14 place-items-center rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-700 shadow-soft">
                        <?= ems_icon('check-circle', 'h-7 w-7') ?>
                    </div>

                    <h1 class="text-2xl font-bold text-slate-900">Terima Kasih</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Proses pendaftaran dan pengisian pertanyaan telah selesai.
                    </p>
                </div>

                <div class="alert alert-info mt-5">
                    <div class="font-semibold">Informasi Selanjutnya</div>
                    <div class="mt-2 text-sm">
                        Tim rekrutmen akan meninjau data dan jawaban yang Anda berikan.
                        Silakan menunggu informasi lanjutan melalui pengumuman resmi.
                    </div>
                </div>

                <p class="mt-3 text-center text-xs text-slate-600">
                    Tidak perlu melakukan pendaftaran ulang. Mohon menunggu dengan sabar.
                </p>

                <div class="mt-5">
                    <a href="https://discord.gg/imeroleplay" class="btn-primary w-full justify-center">
                        <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                        <span>Kunjungi Web Utama IME</span>
                    </a>
                </div>

                <small class="mt-5 block text-center text-xs text-slate-500">
                    Roxwood Hospital Recruitment System
                </small>
            </div>
        </div>
    </div>

</body>

</html>

