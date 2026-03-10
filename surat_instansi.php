<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/auth/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/assets/design/ui/icon.php';

$pageTitle = 'Form Pertemuan Instansi';
$error = trim((string)($_GET['error'] ?? ''));
$success = trim((string)($_GET['success'] ?? '')) === '1';
$code = trim((string)($_GET['code'] ?? ''));

$recipients = [];
try {
    $stmt = $pdo->query("
        SELECT id, full_name, role
        FROM user_rh
        WHERE is_active = 1
        ORDER BY
            CASE LOWER(TRIM(role))
                WHEN 'director' THEN 1
                WHEN 'vice director' THEN 2
                WHEN 'manager' THEN 3
                WHEN 'staff manager' THEN 4
                ELSE 99
            END,
            full_name ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!ems_is_letter_receiver_role($row['role'] ?? '')) {
            continue;
        }
        $recipients[] = $row;
    }
} catch (Throwable $e) {
    $error = 'Daftar tujuan pertemuan belum tersedia.';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(ems_asset('/assets/logo.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/design/tailwind/build.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/css/overrides.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>

<body>
    <main class="main-content" style="padding-left:16px;padding-right:16px;">
        <section class="content">
            <div class="page page-shell-sm">
                <div class="flex items-center gap-3 mb-4">
                    <img src="<?= htmlspecialchars(ems_asset('/assets/logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="EMS Logo" class="w-14 h-14 rounded-2xl bg-white shadow-soft p-2.5">
                    <div>
                        <h1 class="page-title">Form Pertemuan Instansi</h1>
                        <p class="section-intro">Isi data pertemuan untuk manager yang ingin ditemui.</p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-info">
                        Surat berhasil dikirim<?= $code !== '' ? ' dengan kode <strong>' . htmlspecialchars($code) . '</strong>' : '' ?>.
                        Tim medis akan meneruskan informasi ini ke pihak terkait.
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="card card-section">
                    <div class="card-header">Data Surat Masuk / Janji Pertemuan</div>
                    <form method="POST" action="<?= htmlspecialchars(ems_url('/actions/submit_surat_instansi.php'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" class="form">
                        <?= csrfField(); ?>

                        <div class="row-form-2">
                            <div class="col">
                                <label>Nama Instansi</label>
                                <input type="text" name="institution_name" maxlength="160" required>
                            </div>
                            <div class="col">
                                <label>Nama</label>
                                <input type="text" name="sender_name" maxlength="160" required>
                            </div>
                        </div>

                        <div class="row-form-2">
                            <div class="col">
                                <label>Nomor HP IC</label>
                                <input type="text" name="sender_phone" maxlength="64" required>
                            </div>
                            <div class="col">
                                <label>Ingin Menemui</label>
                                <select name="target_user_id" required>
                                    <option value="">-- Pilih Tujuan --</option>
                                    <?php foreach ($recipients as $recipient): ?>
                                        <option value="<?= (int)$recipient['id'] ?>">
                                            <?= htmlspecialchars($recipient['full_name']) ?> — <?= htmlspecialchars(ems_role_label($recipient['role'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <label>Perihal / Agenda Pertemuan</label>
                        <textarea name="meeting_topic" rows="3" maxlength="255" required></textarea>

                        <div class="row-form-2">
                            <div class="col">
                                <label>Tanggal Temu</label>
                                <input type="date" name="appointment_date" required>
                            </div>
                            <div class="col">
                                <label>Jam Temu</label>
                                <input type="time" name="appointment_time" required>
                            </div>
                        </div>

                        <label>Catatan Tambahan</label>
                        <textarea name="notes" rows="4" placeholder="Contoh: membawa proposal kerja sama / konfirmasi ulang via telepon"></textarea>

                        <div class="doc-upload-wrapper m-0">
                            <div class="doc-upload-header">
                                <label class="text-sm font-semibold text-slate-900">Lampiran Surat Masuk</label>
                                <span class="badge-muted-mini">Opsional, bisa beberapa file</span>
                            </div>
                            <div class="doc-upload-input">
                                <label for="incomingAttachments" class="file-upload-label">
                                    <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                                    <span class="file-text">
                                        <strong>Pilih lampiran</strong>
                                        <small>JPG / PNG, multi file</small>
                                    </span>
                                </label>
                                <input type="file" id="incomingAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                                <div class="file-selected-name" data-for="incomingAttachments"></div>
                                <div id="incomingAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                            </div>
                        </div>

                        <div class="modal-actions mt-4">
                            <button type="submit" class="btn-success"><?= ems_icon('document-text', 'h-4 w-4') ?> <span>Kirim Surat</span></button>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </main>
<script>
    (function() {
        function setupMultiImagePreview(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
            if (!input || !preview || !nameBox) return;

            let objectUrls = [];

            function clearPreview() {
                objectUrls.forEach(function(url) {
                    try { URL.revokeObjectURL(url); } catch (_) {}
                });
                objectUrls = [];
                preview.innerHTML = '';
                nameBox.textContent = '';
                nameBox.classList.add('hidden');
            }

            input.addEventListener('change', function() {
                clearPreview();

                const files = Array.from(this.files || []);
                if (!files.length) {
                    return;
                }

                nameBox.textContent = files.length + ' file dipilih';
                nameBox.classList.remove('hidden');

                files.forEach(function(file) {
                    if (!String(file.type || '').startsWith('image/')) {
                        return;
                    }

                    const url = URL.createObjectURL(file);
                    objectUrls.push(url);

                    const item = document.createElement('div');
                    item.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-2';
                    item.innerHTML = `
                        <img src="${url}" class="identity-photo h-28 w-full rounded-xl object-cover cursor-zoom-in" alt="Preview lampiran">
                        <div class="mt-2 truncate text-xs text-slate-600">${file.name}</div>
                    `;
                    preview.appendChild(item);
                });
            });
        }

        setupMultiImagePreview('incomingAttachments', 'incomingAttachmentsPreview');
    })();
</script>

</body>

</html>
