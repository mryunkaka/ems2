<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/announcement.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Kelola Pengumuman';

ems_announcement_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];

if (!ems_announcement_can_manage($user)) {
    $_SESSION['flash_errors'][] = 'Hanya manager ke atas yang bisa mengelola pengumuman.';
    header('Location: /dashboard/index.php');
    exit;
}

$unitCode = ems_effective_unit($pdo, $user);
$canTargetAll = ems_announcement_can_target_all($user);
$csrfToken = generateCsrfToken();

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

$scopeOptions = ems_division_scope_options();
if (!$canTargetAll) {
    $broadValues = [ems_all_division_scope_value(), ems_management_division_scope_value()];
    $scopeOptions = array_values(array_filter($scopeOptions, static fn($opt) => !in_array($opt['value'], $broadValues, true)));
}

$announcements = ems_announcement_fetch_all($pdo, $unitCode);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<style>
.ann-form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
@media (max-width: 720px) { .ann-form-grid { grid-template-columns: 1fr; } }
.ann-form-grid label, .ann-form-full label { display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; }
.ann-form-grid input, .ann-form-grid select, .ann-form-full input, .ann-form-full select, .ann-form-full textarea {
    width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;
}
.ann-form-full { margin-top:12px; }
.ann-target-toggle { display:flex; gap:16px; margin-bottom:10px; font-size:14px; }
.ann-target-toggle label { display:flex; align-items:center; gap:6px; font-weight:500; color:#334155; }
.ann-badge { font-size:11px; border-radius:999px; padding:2px 10px; font-weight:600; }
.ann-badge-active { background:#dcfce7; color:#166534; }
.ann-badge-inactive { background:#f1f5f9; color:#64748b; }
</style>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Kelola Pengumuman</h1>
        <p class="page-subtitle">Buat notifikasi modal yang muncul otomatis ke user yang ditarget, di halaman mana pun mereka buka.</p>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string)$message, 'success', 'Pengumuman') ?>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <?= ems_render_toast_script((string)$warning, 'warning', 'Pengumuman') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string)$error, 'error', 'Pengumuman', 7500) ?>
        <?php endforeach; ?>

        <?php if ($canTargetAll): ?>
            <div class="card" style="margin-top:16px; background:#f0f9ff;">
                <div class="card-header"><strong>Aksi Cepat</strong></div>
                <p class="meta-text" style="margin-bottom:12px;">Untuk dipakai setiap kali selesai upload versi baru ke hosting — sekali klik langsung broadcast ke semua user.</p>
                <form method="post" action="/dashboard/announcement_manage_action.php" onsubmit="return confirm('Broadcast pengumuman \'Ada Update Baru\' ke SEMUA user sekarang?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="quick_broadcast_update">
                    <button type="submit" class="btn-primary"><?= ems_icon('megaphone', 'h-4 w-4') ?> Broadcast: Ada Update Baru</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><strong>Buat Pengumuman Baru</strong></div>
            <form method="post" action="/dashboard/announcement_manage_action.php" onsubmit="return documentAnnLoading();">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">

                <div class="ann-form-full">
                    <label>Judul</label>
                    <input type="text" name="title" required maxlength="255" placeholder="Mis. Jadwal Maintenance Server">
                </div>
                <div class="ann-form-full">
                    <label>Pesan</label>
                    <textarea name="message" required rows="4" placeholder="Isi pengumuman yang akan tampil di modal..."></textarea>
                </div>

                <div class="ann-form-full">
                    <label>Target</label>
                    <div class="ann-target-toggle">
                        <label><input type="radio" name="target_type" value="scope" checked onchange="document.getElementById('annScopeWrap').style.display='block'; document.getElementById('annUserWrap').style.display='none';"> Division / Semua User</label>
                        <label><input type="radio" name="target_type" value="user" onchange="document.getElementById('annScopeWrap').style.display='none'; document.getElementById('annUserWrap').style.display='block';"> Satu User Tertentu</label>
                    </div>
                    <div id="annScopeWrap">
                        <select name="target_scope">
                            <?php foreach ($scopeOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="annUserWrap" style="display:none;">
                        <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all">
                            <input type="text" class="form-input" data-user-autocomplete-input placeholder="Ketik nama user...">
                            <input type="hidden" name="target_user_id" data-user-autocomplete-hidden>
                            <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                        </div>
                    </div>
                </div>

                <div class="ann-form-full">
                    <label>Frekuensi Tampil</label>
                    <select name="frequency">
                        <option value="once">1x tampil (sampai ditutup, lalu tidak muncul lagi)</option>
                        <option value="every_login">Setiap login (muncul lagi tiap sesi login baru)</option>
                        <option value="every_visit">Setiap buka halaman (muncul terus tiap pindah halaman)</option>
                    </select>
                </div>

                <div style="margin-top:14px;">
                    <button type="submit" class="btn-primary"><?= ems_icon('paper-airplane', 'h-4 w-4') ?> Kirim Pengumuman</button>
                </div>
            </form>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><strong>Daftar Pengumuman</strong></div>
            <?php if (empty($announcements)): ?>
                <p class="meta-text">Belum ada pengumuman.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr><th>Judul</th><th>Target</th><th>Frekuensi</th><th>Dibuat Oleh</th><th>Sudah Dilihat</th><th>Status</th><th>Aksi</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcements as $a): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$a['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text" style="max-width:260px; white-space:normal;"><?= htmlspecialchars(mb_strimwidth((string)$a['message'], 0, 120, '...'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td class="meta-text"><?= htmlspecialchars(ems_announcement_target_label($a), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="meta-text"><?= htmlspecialchars(ems_announcement_frequency_label((string)$a['frequency']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="meta-text"><?= htmlspecialchars((string)$a['created_by_name_snapshot'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="meta-text"><?= (int)$a['dismissal_count'] ?> user</td>
                                    <td><span class="ann-badge <?= (int)$a['is_active'] === 1 ? 'ann-badge-active' : 'ann-badge-inactive' ?>"><?= (int)$a['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                                    <td>
                                        <form method="post" action="/dashboard/announcement_manage_action.php" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button type="submit" class="btn-secondary" style="padding:4px 10px;"><?= (int)$a['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                        </form>
                                        <form method="post" action="/dashboard/announcement_manage_action.php" style="display:inline;" onsubmit="return confirm('Hapus pengumuman ini?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button type="submit" class="btn-danger" style="padding:4px 10px;"><?= ems_icon('trash', 'h-4 w-4') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
function documentAnnLoading() {
    if (typeof window.emsShowUploadOverlay === 'function') {
        window.emsShowUploadOverlay('Mengirim Pengumuman', 'Mohon tunggu, pengumuman sedang dikirim ke user yang ditarget.');
    }
    return true;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
