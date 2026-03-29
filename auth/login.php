<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

function authRenderRegisterDocField(string $label, string $inputId, string $fieldName, bool $optional = true): void
{
?>
    <div class="doc-upload-wrapper m-0">
        <div class="doc-upload-header">
            <label class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($label) ?></label>
            <span class="badge-muted-mini"><?= $optional ? 'Opsional' : 'Wajib' ?></span>
        </div>
        <div class="doc-upload-input">
            <label for="<?= htmlspecialchars($inputId) ?>" class="file-upload-label">
                <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                <span class="file-text">
                    <strong>Pilih file</strong>
                    <small>PNG atau JPG</small>
                </span>
            </label>
            <input type="file"
                id="<?= htmlspecialchars($inputId) ?>"
                name="<?= htmlspecialchars($fieldName) ?>"
                accept="image/png,image/jpeg"
                class="sr-only">
            <div class="file-selected-name" data-for="<?= htmlspecialchars($inputId) ?>"></div>
        </div>
    </div>
<?php
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Masuk - EMS</title>

    <!-- Local assets only (no old CSS, no CDN) -->
    <link rel="stylesheet" href="/assets/vendor/photoswipe/photoswipe.css">
    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-sky-900">

    <div id="authShell" class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="mx-auto flex w-full max-w-6xl justify-center">
            <div id="authCard" class="w-full max-w-[420px] transition-[max-width] duration-300">
                <div class="rounded-3xl border border-white/60 bg-white/85 p-6 shadow-modal backdrop-blur">

                    <!-- BRAND / LOGO -->
                    <div class="mb-6 flex flex-col items-center gap-3 text-center">
                        <img src="/assets/logo.png" alt="Logo EMS" class="h-14 w-14 rounded-2xl bg-white object-contain p-2.5 shadow-soft">
                        <div class="min-w-0">
                            <div class="text-sm font-extrabold tracking-wide text-slate-900">Roxwood Hospital</div>
                            <div class="text-xs font-semibold text-slate-600">Emergency Medical System</div>
                        </div>
                    </div>

                    <!-- NOTIFIKASI ERROR -->
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-error">
                            <?= htmlspecialchars($_SESSION['error']) ?>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <!-- NOTIFIKASI SUCCESS -->
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success">
                            <?= htmlspecialchars($_SESSION['success']) ?>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>

                    <!-- ============================================
                 LOGIN FORM
                 ============================================ -->
                    <form id="loginForm" method="POST" action="/auth/login_process.php" class="space-y-4">
                        <?php if (isset($_GET['confirm'])): ?>
                            <div class="modal-overlay" id="confirmModal">
                                <div class="modal-box modal-shell modal-frame-md">
                                    <div class="modal-head">
                                        <div class="modal-title inline-flex items-center gap-2">
                                            <?= ems_icon('exclamation-triangle', 'h-5 w-5 text-amber-600') ?>
                                            <span>Perangkat Lain Terdeteksi</span>
                                        </div>
                                        <a href="login.php" class="modal-close-btn" aria-label="Tutup">
                                            <?= ems_icon('x-mark', 'h-5 w-5') ?>
                                        </a>
                                    </div>

                                    <div class="modal-content">
                                        <p class="text-sm text-slate-700">
                                            Akun ini sedang aktif di perangkat lain.
                                            Jika Anda melanjutkan, perangkat sebelumnya akan otomatis keluar.
                                        </p>
                                    </div>

                                    <div class="modal-foot">
                                        <div class="modal-actions justify-end">
                                            <a href="login.php" class="btn-secondary">Batal</a>
                                            <button
                                                type="submit"
                                                name="force_login"
                                                value="1"
                                                class="btn-warning"
                                                formnovalidate>
                                                Lanjutkan di perangkat ini
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="text-lg font-semibold text-slate-900">Masuk</div>
                            <div class="text-sm text-slate-600">Gunakan nama lengkap dan PIN 4 digit.</div>
                        </div>

                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">Nama Lengkap</label>
                            <input
                                type="text"
                                name="full_name"
                                placeholder="Contoh: Michael Moore"
                                required
                                autocomplete="username"
                                autocorrect="off"
                                autocapitalize="words">
                        </div>

                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">PIN</label>
                            <input
                                type="password"
                                name="pin"
                                placeholder="4 digit"
                                maxlength="4"
                                pattern="[0-9]{4}"
                                inputmode="numeric"
                                required
                                autocomplete="current-password">
                        </div>

                        <div class="form-submit-wrapper">
                            <button type="submit" class="btn-success w-full justify-center">
                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                <span>Masuk</span>
                            </button>
                        </div>

                        <p class="mt-3 text-sm text-slate-600">
                            Belum punya akun?
                            <a href="javascript:void(0)" class="font-semibold text-sky-700 hover:text-sky-800" onclick="showRegister()">Daftar</a>
                        </p>
                    </form>

                    <!-- ============================================
                 REGISTER FORM
                 ============================================ -->
                    <form id="registerForm"
                        method="POST"
                        action="/auth/register_process.php"
                        class="hidden"
                        enctype="multipart/form-data">
                        <div class="space-y-4">
                            <div>
                                <div class="text-lg font-semibold text-slate-900">Daftar</div>
                                <div class="text-sm text-slate-600">Lengkapi data dan unggah lampiran pendukung.</div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-4">
                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">Nama Lengkap</label>
                                        <input
                                            type="text"
                                            name="full_name"
                                            placeholder="Contoh: Michael Moore"
                                            required
                                            autocomplete="name"
                                            autocorrect="off"
                                            autocapitalize="words">
                                    </div>

                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">PIN</label>
                                        <input
                                            type="password"
                                            name="pin"
                                            placeholder="4 digit"
                                            maxlength="4"
                                            pattern="[0-9]{4}"
                                            inputmode="numeric"
                                            required
                                            autocomplete="new-password">
                                    </div>

                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">Batch</label>
                                        <input
                                            type="number"
                                            name="batch"
                                            placeholder="Contoh: 3"
                                            min="1"
                                            max="26"
                                            required>
                                    </div>

                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">Unit</label>
                                        <select name="unit_code" required>
                                            <option value="">-- Pilih Unit --</option>
                                            <?php foreach (ems_unit_options() as $unitOption): ?>
                                                <option value="<?= htmlspecialchars($unitOption['value']) ?>">
                                                    <?= htmlspecialchars($unitOption['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">Role</label>
                                        <input type="text" value="Staff" disabled class="bg-slate-50 text-slate-700">
                                        <input type="hidden" name="role" value="Staff">
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div class="section-form-title">Data Pribadi</div>

                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">Citizen ID</label>
                                        <input
                                            type="text"
                                            name="citizen_id"
                                            placeholder="Contoh: RH39IQLC"
                                            required>
                                    </div>

                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">No HP IC</label>
                                        <input
                                            type="number"
                                            name="no_hp_ic"
                                            placeholder="Contoh: 5523244"
                                            required>
                                    </div>

                                    <div class="form-group">
                                        <label class="text-sm font-semibold text-slate-900">Jenis Kelamin</label>
                                        <select name="jenis_kelamin" required>
                                            <option value="">-- Pilih Jenis Kelamin --</option>
                                            <option value="Laki-laki">Laki-laki</option>
                                            <option value="Perempuan">Perempuan</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="section-form-title">Dokumen Pendukung</div>

                            <div class="grid gap-4 md:grid-cols-3">
                                <?php authRenderRegisterDocField('Upload KTP', 'registerFileKtp', 'file_ktp'); ?>
                                <?php authRenderRegisterDocField('Upload SKB', 'registerFileSkb', 'file_skb'); ?>
                                <?php authRenderRegisterDocField('Upload SIM', 'registerFileSim', 'file_sim'); ?>
                                <?php authRenderRegisterDocField('Upload KTA', 'registerFileKta', 'file_kta'); ?>
                                <?php authRenderRegisterDocField('Sertifikat Heli', 'registerSertifikatHeli', 'sertifikat_heli'); ?>
                                <?php authRenderRegisterDocField('Sertifikat Operasi', 'registerSertifikatOperasi', 'sertifikat_operasi'); ?>
                            </div>

                            <div class="doc-upload-wrapper doc-upload-dashed m-0">
                                <div class="doc-upload-header doc-upload-header-stack">
                                    <label class="text-sm font-semibold text-slate-900">File Lainnya</label>
                                    <small class="text-slate-500">Nama dokumen diisi sendiri. Bisa tambah beberapa file seperti di Setting Akun.</small>
                                </div>

                                <div id="registerOtherDocsContainer" class="space-y-4">
                                    <div class="academy-doc-row" data-row="register-other-doc">
                                        <input type="hidden" name="academy_doc_id[]" value="">

                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="form-group">
                                                <label class="text-sm font-semibold text-slate-900">Nama File Lainnya</label>
                                                <input type="text"
                                                    name="academy_doc_name[]"
                                                    placeholder="Contoh: Surat Kontrak Kerja atau Dokumen Pendukung">
                                            </div>
                                            <div class="form-group">
                                                <label class="text-sm font-semibold text-slate-900">File</label>
                                                <div class="doc-upload-input doc-upload-input-reset">
                                                    <label for="registerOtherDoc0" class="file-upload-label">
                                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                                        <span class="file-text">
                                                            <strong>Pilih file</strong>
                                                            <small>PNG atau JPG</small>
                                                        </span>
                                                    </label>
                                                    <input type="file"
                                                        id="registerOtherDoc0"
                                                        name="academy_doc_file[]"
                                                        accept="image/png,image/jpeg"
                                                        class="sr-only">
                                                    <div class="file-selected-name" data-for="registerOtherDoc0"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="action-row-end">
                                    <button type="button" id="btnAddRegisterOtherDoc" class="btn-secondary button-compact">
                                        <?= ems_icon('plus', 'h-4 w-4') ?> Tambah File Lainnya
                                    </button>
                                </div>
                            </div>

                            <div class="form-submit-wrapper">
                                <button type="submit" class="btn-success w-full justify-center">
                                    <?= ems_icon('plus', 'h-4 w-4') ?>
                                    <span>Daftar</span>
                                </button>
                            </div>
                        </div>

                        <p class="mt-3 text-sm text-slate-600">
                            Sudah punya akun?
                            <a href="javascript:void(0)" class="font-semibold text-sky-700 hover:text-sky-800" onclick="showLogin()">Masuk</a>
                        </p>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <script src="/assets/vendor/photoswipe/photoswipe.umd.min.js"></script>
    <script src="/assets/vendor/photoswipe/photoswipe-lightbox.umd.min.js"></script>
    <script src="/assets/design/js/photoswipe-init.js"></script>

    <script>
        // ============================================
        // TOGGLE LOGIN / REGISTER FORM
        // ============================================
        function showRegister() {
            document.getElementById('loginForm').classList.add('hidden');
            document.getElementById('registerForm').classList.remove('hidden');
            const card = document.getElementById('authCard');
            if (card) {
                card.classList.remove('max-w-[420px]');
                card.classList.add('max-w-[860px]');
            }
            const shell = document.getElementById('authShell');
            if (shell) {
                shell.classList.remove('items-center');
                shell.classList.add('items-start');
            }
        }

        function showLogin() {
            document.getElementById('registerForm').classList.add('hidden');
            document.getElementById('loginForm').classList.remove('hidden');
            const card = document.getElementById('authCard');
            if (card) {
                card.classList.remove('max-w-[860px]');
                card.classList.add('max-w-[420px]');
            }
            const shell = document.getElementById('authShell');
            if (shell) {
                shell.classList.remove('items-start');
                shell.classList.add('items-center');
            }
        }

        // ============================================
        // PREVENT DOUBLE-TAP ZOOM (iOS)
        // ============================================
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // ============================================
        // AUTO-HIDE NOTIFICATIONS
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            const notifications = document.querySelectorAll('.alert');
            notifications.forEach(function(notif) {
                setTimeout(function() {
                    notif.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    notif.style.opacity = '0';
                    notif.style.transform = 'translateY(-10px)';
                    setTimeout(function() {
                        notif.remove();
                    }, 500);
                }, 5000); // Hilang setelah 5 detik
            });
        });

        // ============================================
        // FORM VALIDATION ENHANCEMENT
        // ============================================
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');

        // Validasi PIN harus 4 digit angka
        function validatePIN(input) {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 4) {
                    this.value = this.value.slice(0, 4);
                }
            });
        }

        // Apply validasi ke semua input PIN
        document.querySelectorAll('input[name="pin"]').forEach(validatePIN);

        // Preview lampiran (PhotoSwipe) untuk form daftar.
        (function() {
            function setSelectedName(inputId) {
                const input = document.getElementById(inputId);
                if (!input) return;
                const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
                if (!nameBox) return;

                input.addEventListener('change', function() {
                    const file = this.files && this.files[0] ? this.files[0] : null;
                    if (!file) {
                        nameBox.textContent = '';
                        nameBox.classList.add('hidden');
                        return;
                    }
                    nameBox.textContent = file.name || 'File dipilih';
                    nameBox.classList.remove('hidden');
                });
            }

            function addRegisterOtherDocRow() {
                const container = document.getElementById('registerOtherDocsContainer');
                if (!container) return;

                const index = container.querySelectorAll('[data-row="register-other-doc"]').length;
                const inputId = 'registerOtherDoc' + index;
                const row = document.createElement('div');
                row.className = 'academy-doc-row';
                row.setAttribute('data-row', 'register-other-doc');
                row.innerHTML = `
                    <input type="hidden" name="academy_doc_id[]" value="">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">Nama File Lainnya</label>
                            <input type="text"
                                name="academy_doc_name[]"
                                placeholder="Contoh: Surat Kontrak Kerja atau Dokumen Pendukung">
                        </div>
                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">File</label>
                            <div class="doc-upload-input doc-upload-input-reset">
                                <label for="${inputId}" class="file-upload-label">
                                    <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                    <span class="file-text">
                                        <strong>Pilih file</strong>
                                        <small>PNG atau JPG</small>
                                    </span>
                                </label>
                                <input type="file"
                                    id="${inputId}"
                                    name="academy_doc_file[]"
                                    accept="image/png,image/jpeg"
                                    class="sr-only">
                                <div class="file-selected-name" data-for="${inputId}"></div>
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(row);
                setSelectedName(inputId);
            }

            [
                'registerFileKtp',
                'registerFileSkb',
                'registerFileSim',
                'registerFileKta',
                'registerSertifikatHeli',
                'registerSertifikatOperasi',
                'registerOtherDoc0'
            ].forEach(setSelectedName);

            const btnAddRegisterOtherDoc = document.getElementById('btnAddRegisterOtherDoc');
            if (btnAddRegisterOtherDoc) {
                btnAddRegisterOtherDoc.addEventListener('click', addRegisterOtherDocRow);
            }
        })();

        // Prevent form resubmission
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>

</html>
