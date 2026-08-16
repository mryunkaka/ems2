<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/forensic_private_access.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Kelola Akses Grup Forensic | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];

ems_forensic_private_ensure_tables($pdo);

if (!ems_forensic_private_can_manage_access($user)) {
    $_SESSION['flash_errors'][] = 'Anda tidak memiliki akses untuk mengelola izin grup Forensic.';
    header('Location: /dashboard/index.php');
    exit;
}

$grants = ems_forensic_private_list_grants($pdo);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title">Kelola Akses Grup Forensic</h1>
                <p class="page-subtitle">Beri atau cabut izin medis di luar division Forensic untuk membuka halaman-halaman grup Forensic (List Medis, Rekam Medis Private, Data Pasien Private, Hasil Visum, Arsip Forensic).</p>
            </div>
            <a href="forensic_medical_records_list.php" class="btn-secondary">
                <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                <span>Kembali ke Rekam Medis Private</span>
            </a>
        </div>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string) $message, 'info', 'Kelola Akses Forensic') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string) $error, 'error', 'Kelola Akses Forensic', 6800) ?>
        <?php endforeach; ?>

        <div class="card mb-4">
            <div class="card-header">
                <?= ems_icon('lock-closed', 'h-5 w-5') ?>
                <span id="forensicAccessFormTitle">Beri Izin Baru</span>
            </div>
            <div class="card-section">
                <form method="POST" action="forensic_private_access_action.php" id="forensicAccessForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="form-group">
                        <label class="form-label">Nama Medis</label>
                        <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-required>
                            <input type="text" class="form-input" id="forensicAccessMedicInput" data-user-autocomplete-input placeholder="Ketik nama medis..." required>
                            <input type="hidden" name="medic_user_id" id="forensicAccessMedicId" data-user-autocomplete-hidden required>
                            <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Klik salah satu baris di tabel bawah untuk mengedit izin medis yang sudah pernah diberi akses.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Halaman yang Bisa Diakses</label>
                        <div class="forensic-page-toggle-list">
                            <label class="forensic-access-checkbox">
                                <input type="checkbox" name="can_view_forensic_medics" value="1"> List Medis
                            </label>

                            <div>
                                <label class="forensic-access-checkbox">
                                    <input type="checkbox" class="forensic-access-group-toggle" data-group="rmp"> Rekam Medis Private
                                </label>
                                <div class="forensic-access-checkbox-grid forensic-access-nested hidden" data-group-detail="rmp">
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="can_view_all" value="1"> Bisa Melihat Semua</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="can_view_own" value="1"> Hanya Bisa Melihat yang Dia Input Sendiri</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="can_create" value="1"> Bisa Menginput</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="can_edit" value="1"> Bisa Mengedit</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="can_delete" value="1"> Bisa Menghapus</label>
                                </div>
                            </div>

                            <div>
                                <label class="forensic-access-checkbox">
                                    <input type="checkbox" class="forensic-access-group-toggle" data-group="patients"> Data Pasien Private
                                </label>
                                <div class="forensic-access-checkbox-grid forensic-access-nested hidden" data-group-detail="patients">
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="patients_view_all" value="1"> Bisa Melihat Semua</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="patients_view_own" value="1"> Hanya Bisa Melihat yang Dia Input Sendiri</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="patients_create" value="1"> Bisa Menginput</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="patients_edit" value="1"> Bisa Mengedit</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="patients_delete" value="1"> Bisa Menghapus</label>
                                </div>
                            </div>

                            <div>
                                <label class="forensic-access-checkbox">
                                    <input type="checkbox" class="forensic-access-group-toggle" data-group="visum"> Hasil Visum
                                </label>
                                <div class="forensic-access-checkbox-grid forensic-access-nested hidden" data-group-detail="visum">
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="visum_view_all" value="1"> Bisa Melihat Semua</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="visum_view_own" value="1"> Hanya Bisa Melihat yang Dia Input Sendiri</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="visum_create" value="1"> Bisa Menginput</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="visum_edit" value="1"> Bisa Mengedit</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="visum_delete" value="1"> Bisa Menghapus</label>
                                </div>
                            </div>

                            <div>
                                <label class="forensic-access-checkbox">
                                    <input type="checkbox" class="forensic-access-group-toggle" data-group="archive"> Arsip Forensic
                                </label>
                                <div class="forensic-access-checkbox-grid forensic-access-nested hidden" data-group-detail="archive">
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="archive_view_all" value="1"> Bisa Melihat Semua</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="archive_view_own" value="1"> Hanya Bisa Melihat yang Dia Input Sendiri</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="archive_create" value="1"> Bisa Menginput</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="archive_edit" value="1"> Bisa Mengedit</label>
                                    <label class="forensic-access-checkbox"><input type="checkbox" name="archive_delete" value="1"> Bisa Menghapus</label>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Centang dulu nama halamannya untuk memilih detail izin (lihat semua/lihat punya sendiri/input/edit/hapus) di bawahnya. "List Medis" tidak punya detail izin karena cuma daftar roster (tidak ada data per-medis yang perlu dibatasi lihat-semua/lihat-sendiri).</p>
                    </div>

                    <div class="modal-actions justify-end">
                        <button type="button" id="forensicAccessResetBtn" class="btn-secondary hidden">Batal Edit</button>
                        <button type="submit" class="btn-primary">Simpan Izin</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header">Medis yang Sudah Diberi Izin (<?= count($grants) ?>)</div>
            <div class="card-body">
                <?php if ($grants === []): ?>
                    <div class="text-center py-8 text-gray-500">Belum ada medis yang diberi izin khusus grup Forensic.</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table-custom w-full" data-auto-datatable="true">
                            <thead>
                                <tr>
                                    <th class="text-left">Nama</th>
                                    <th class="text-left">Division</th>
                                    <th class="text-left">Halaman Diizinkan</th>
                                    <th class="text-left">Diberikan Oleh</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                function forensicAccessCrudLabels(array $grant, string $prefix): array
                                {
                                    $labels = [];
                                    if (!empty($grant["{$prefix}view_all"])) $labels[] = 'Lihat Semua';
                                    if (!empty($grant["{$prefix}view_own"])) $labels[] = 'Lihat Punya Sendiri';
                                    if (!empty($grant["{$prefix}create"])) $labels[] = 'Input';
                                    if (!empty($grant["{$prefix}edit"])) $labels[] = 'Edit';
                                    if (!empty($grant["{$prefix}delete"])) $labels[] = 'Hapus';
                                    return $labels;
                                }
                                ?>
                                <?php foreach ($grants as $grant): ?>
                                    <?php
                                    $pageLabels = [];
                                    if (!empty($grant['can_view_forensic_medics'])) $pageLabels[] = 'List Medis';

                                    $rmpLabels = forensicAccessCrudLabels($grant, 'can_');
                                    if ($rmpLabels !== []) $pageLabels[] = 'Rekam Medis Private (' . implode(', ', $rmpLabels) . ')';

                                    $patientsLabels = forensicAccessCrudLabels($grant, 'patients_');
                                    if ($patientsLabels !== []) $pageLabels[] = 'Data Pasien Private (' . implode(', ', $patientsLabels) . ')';

                                    $visumLabels = forensicAccessCrudLabels($grant, 'visum_');
                                    if ($visumLabels !== []) $pageLabels[] = 'Hasil Visum (' . implode(', ', $visumLabels) . ')';

                                    $archiveLabels = forensicAccessCrudLabels($grant, 'archive_');
                                    if ($archiveLabels !== []) $pageLabels[] = 'Arsip Forensic (' . implode(', ', $archiveLabels) . ')';

                                    $medicName = (string) ($grant['medic_current_name'] ?: $grant['medic_name_snapshot']);
                                    $grantData = ['medic_user_id' => (int) $grant['medic_user_id'], 'medic_name' => $medicName];
                                    foreach ([
                                        'can_view_forensic_medics',
                                        'can_view_all', 'can_view_own', 'can_create', 'can_edit', 'can_delete',
                                        'patients_view_all', 'patients_view_own', 'patients_create', 'patients_edit', 'patients_delete',
                                        'visum_view_all', 'visum_view_own', 'visum_create', 'visum_edit', 'visum_delete',
                                        'archive_view_all', 'archive_view_own', 'archive_create', 'archive_edit', 'archive_delete',
                                    ] as $grantField) {
                                        $grantData[$grantField] = (bool) ($grant[$grantField] ?? false);
                                    }
                                    ?>
                                    <tr>
                                        <td class="font-medium"><?= htmlspecialchars($medicName, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($grant['medic_division'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($pageLabels === [] ? '-' : implode(' | ', $pageLabels), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?= htmlspecialchars((string) ($grant['granted_by_name_snapshot'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $grant['updated_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td class="text-center">
                                            <div class="flex justify-center gap-2">
                                                <button type="button" class="btn-secondary btn-sm btn-forensic-access-edit" data-grant="<?= htmlspecialchars(json_encode($grantData), ENT_QUOTES, 'UTF-8') ?>">Edit</button>
                                                <form method="POST" action="forensic_private_access_action.php" onsubmit="return confirm('Cabut semua akses grup Forensic untuk user ini?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="revoke">
                                                    <input type="hidden" name="medic_user_id" value="<?= (int) $grant['medic_user_id'] ?>">
                                                    <button type="submit" class="btn-error btn-sm">Cabut</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var groupToggles = Array.from(document.querySelectorAll('.forensic-access-group-toggle'));

    function detailFor(toggle) {
        var group = toggle.getAttribute('data-group');
        return document.querySelector('[data-group-detail="' + group + '"]');
    }

    function syncGroupToggle(toggle) {
        var detail = detailFor(toggle);
        if (!detail) return;
        var checkboxes = Array.from(detail.querySelectorAll('input[type=checkbox]'));
        var anyChecked = checkboxes.some(function (cb) { return cb.checked; });
        toggle.checked = anyChecked;
        detail.classList.toggle('hidden', !anyChecked);
    }

    groupToggles.forEach(function (toggle) {
        var detail = detailFor(toggle);
        if (!detail) return;

        toggle.addEventListener('change', function () {
            detail.classList.toggle('hidden', !toggle.checked);
            if (!toggle.checked) {
                Array.from(detail.querySelectorAll('input[type=checkbox]')).forEach(function (cb) { cb.checked = false; });
            }
        });

        syncGroupToggle(toggle);
    });

    var form = document.getElementById('forensicAccessForm');
    var medicInput = document.getElementById('forensicAccessMedicInput');
    var medicId = document.getElementById('forensicAccessMedicId');
    var formTitle = document.getElementById('forensicAccessFormTitle');
    var resetBtn = document.getElementById('forensicAccessResetBtn');

    var allPermissionFields = [
        'can_view_forensic_medics',
        'can_view_all', 'can_view_own', 'can_create', 'can_edit', 'can_delete',
        'patients_view_all', 'patients_view_own', 'patients_create', 'patients_edit', 'patients_delete',
        'visum_view_all', 'visum_view_own', 'visum_create', 'visum_edit', 'visum_delete',
        'archive_view_all', 'archive_view_own', 'archive_create', 'archive_edit', 'archive_delete',
    ];

    function resetForm() {
        form.reset();
        medicId.value = '';
        formTitle.textContent = 'Beri Izin Baru';
        resetBtn.classList.add('hidden');
        groupToggles.forEach(syncGroupToggle);
    }

    resetBtn?.addEventListener('click', resetForm);

    document.querySelectorAll('.btn-forensic-access-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var data = JSON.parse(btn.getAttribute('data-grant') || '{}');
            medicInput.value = data.medic_name || '';
            medicId.value = data.medic_user_id || '';
            formTitle.textContent = 'Edit Izin: ' + (data.medic_name || '');
            resetBtn.classList.remove('hidden');

            allPermissionFields.forEach(function (name) {
                var input = form.querySelector('[name="' + name + '"]');
                if (input) input.checked = Boolean(data[name]);
            });

            groupToggles.forEach(syncGroupToggle);
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
});
</script>

<style>
.forensic-page-toggle-list {
    display: grid;
    gap: 0.75rem;
    margin-top: 0.4rem;
}

.forensic-access-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
}

.forensic-access-checkbox input {
    width: 1rem;
    height: 1rem;
}

.forensic-access-checkbox-grid {
    display: grid;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.forensic-access-nested {
    margin-left: 1.6rem;
    padding: 0.75rem 1rem;
    border-left: 2px solid #e2e8f0;
    background: #f8fafc;
    border-radius: 0.5rem;
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
