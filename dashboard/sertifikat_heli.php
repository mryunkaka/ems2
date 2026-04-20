<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../auth/csrf.php';

ems_require_division_access(['General Affair']);
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Sertifikat Heli Medis';

function heliPageHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

$hasDivisionColumn = heliPageHasColumn($pdo, 'division');
$divisionSelect = $hasDivisionColumn ? 'u.division,' : "'' AS division,";

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$currentUser = $_SESSION['user_rh'] ?? null;
$currentUserDivision = $currentUser['division'] ?? '';

$stmt = $pdo->query("
    SELECT
        u.id,
        u.full_name,
        u.position,
        u.role,
        {$divisionSelect}
        u.tanggal_masuk,
        u.is_active,
        u.sertifikat_heli
    FROM user_rh u
    WHERE TRIM(COALESCE(u.sertifikat_heli, '')) <> ''
    ORDER BY u.is_active DESC, u.full_name ASC
");
$certificateUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalLicensed = count($certificateUsers);
$totalActiveLicensed = 0;
$divisionCounts = [];

foreach ($certificateUsers as $row) {
    if ((int)($row['is_active'] ?? 0) === 1) {
        $totalActiveLicensed++;
    }

    $divisionName = ems_normalize_division($row['division'] ?? '') ?: 'Tanpa Division';
    $divisionCounts[$divisionName] = ($divisionCounts[$divisionName] ?? 0) + 1;
}

arsort($divisionCounts);
$topDivisionLabel = array_key_first($divisionCounts) ?: '-';
$topDivisionCount = $topDivisionLabel !== '-' ? (int)$divisionCounts[$topDivisionLabel] : 0;

function heliFormatJoinDate(?string $date): string
{
    if (!$date) {
        return '-';
    }

    try {
        return (new DateTime($date))->format('d M Y');
    } catch (Throwable $e) {
        return (string)$date;
    }
}
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="page-title">Sertifikat Heli Medis</h1>
                <p class="page-subtitle">Menampilkan user yang sudah memiliki lisensi sertifikat heli.</p>
            </div>
            <button type="button" onclick="openHeliSettingsModal()" class="btn-secondary">
                <?= ems_icon('cog-6-tooth', 'h-4 w-4') ?>
                <span>Setting Pendaftaran</span>
            </button>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="card">
                <div class="card-header">Total Pemilik Sertifikat</div>
                <div class="text-3xl font-semibold text-slate-900"><?= $totalLicensed ?></div>
            </div>

            <div class="card">
                <div class="card-header">User Aktif</div>
                <div class="text-3xl font-semibold text-slate-900"><?= $totalActiveLicensed ?></div>
            </div>

            <div class="card">
                <div class="card-header">Division Terbanyak</div>
                <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($topDivisionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-sm text-slate-500"><?= $topDivisionCount ?> user</div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header card-header-between">
                <span>Daftar Lisensi Sertifikat Heli</span>
                <div class="flex gap-2 items-center">
                    <button id="btnExportText" class="btn-secondary button-compact" type="button">
                        <?= ems_icon('document-text', 'h-4 w-4') ?> Export Teks
                    </button>
                    <span class="badge-muted"><?= $totalLicensed ?> data</span>
                </div>
            </div>

            <div class="table-wrapper">
                <table id="heliCertificateTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Role</th>
                            <th>Division</th>
                            <th>Tanggal Masuk</th>
                            <th>Status</th>
                            <th>Sertifikat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($certificateUsers as $index => $row): ?>
                            <?php $divisionName = ems_normalize_division($row['division'] ?? '') ?: '-'; ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= htmlspecialchars($row['full_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= htmlspecialchars(ems_position_label($row['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(ems_role_label($row['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($divisionName, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(heliFormatJoinDate($row['tanggal_masuk'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ((int)($row['is_active'] ?? 0) === 1): ?>
                                        <span class="badge-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge-muted">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a
                                        href="#"
                                        class="doc-badge btn-preview-doc"
                                        data-src="/<?= htmlspecialchars(ltrim((string)($row['sertifikat_heli'] ?? ''), '/'), ENT_QUOTES, 'UTF-8') ?>"
                                        data-title="<?= htmlspecialchars('Sertifikat Heli - ' . ($row['full_name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?>">
                                        Lihat Dokumen
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!window.jQuery || !jQuery.fn.DataTable) {
            return;
        }

        jQuery('#heliCertificateTable').DataTable({
            pageLength: 10,
            order: [
                [1, 'asc']
            ],
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    });
</script>

<script>
function openHeliSettingsModal() {
    const modal = document.getElementById('heliSettingsModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeHeliSettingsModal() {
    const modal = document.getElementById('heliSettingsModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
}

// Close modal on overlay click
document.body.addEventListener('click', function(e) {
    const modal = document.getElementById('heliSettingsModal');
    if (!modal) return;

    if (e.target.classList.contains('modal-overlay')) {
        closeHeliSettingsModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeHeliSettingsModal();
    }
});

// Export text functionality
document.body.addEventListener('click', function(e) {
    const exportBtn = e.target.closest('#btnExportText');
    if (exportBtn) {
        const table = document.getElementById('heliCertificateTable');
        if (!table) {
            alert('Tabel tidak ditemukan.');
            return;
        }

        const rows = table.querySelectorAll('tbody tr');
        if (!rows.length) {
            alert('Tidak ada data untuk diexport.');
            return;
        }

        const lines = [];
        let no = 1;

        rows.forEach(function(row) {
            const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
            const jabatan = row.querySelector('td:nth-child(3)')?.innerText || '';
            const noStr = String(no).padStart(2, '0');
            lines.push(`${noStr}. ${nama} - ${jabatan}`);
            no++;
        });

        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        const timestamp = `${year}-${month}-${day}_${hours}-${minutes}-${seconds}`;

        const content = 'Daftar Pemegang Sertifikat Heli\n\n' + lines.join('\n') + '\n';
        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `daftar_sertifikat_heli_${timestamp}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
});
</script>

<!-- Modal Setting Pendaftaran Sertifikat Heli -->
<div id="heliSettingsModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('cog-6-tooth', 'h-5 w-5') ?>
                <span>Setting Pendaftaran Sertifikat Heli</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeHeliSettingsModal()" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/sertifikat_heli_action.php')) ?>" class="form modal-form">
            <div class="modal-content">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_settings">

                <label for="start_datetime">Tanggal dan Jam Mulai</label>
                <input
                    id="start_datetime"
                    name="start_datetime"
                    type="datetime-local"
                    required>

                <label for="end_datetime">Tanggal dan Jam Selesai</label>
                <input
                    id="end_datetime"
                    name="end_datetime"
                    type="datetime-local"
                    required>

                <label for="max_slots">Maksimal Pendaftaran</label>
                <input
                    id="max_slots"
                    name="max_slots"
                    type="number"
                    min="1"
                    max="100"
                    value="10"
                    required>
                <small class="helper-note">Jumlah maksimal orang yang bisa mendaftar.<br></small>

                <label for="min_jabatan">Position Minimal (Opsional)</label>
                <select id="min_jabatan" name="min_jabatan">
                    <option value="">Semua Position</option>
                    <?php foreach (ems_position_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>" <?= ($opt['value'] === 'co-asst') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="helper-note">Biarkan kosong jika semua position bisa mendaftar.</small>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <?= ems_icon('check', 'h-4 w-4') ?>
                        <span>Simpan Setting</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
