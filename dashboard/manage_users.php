<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';

// HARD GUARD: staff dilarang
if ($role === 'Staff') {
    header('Location: setting_akun.php');
    exit;
}

$pageTitle = 'Manajemen User';

// FLASH NOTIF EMS
$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// AMBIL SEMUA USER (SESUAI DATABASE)
$users = $pdo->query("
        SELECT 
        u.id,
        u.full_name,
        u.position,
        u.role,
        u.is_active,
        u.tanggal_masuk,

        u.batch,
        u.kode_nomor_induk_rs,

        u.sertifikat_heli,
        u.sertifikat_operasi,
        u.dokumen_lainnya,

        u.resign_reason,
        u.resigned_at,
        r.full_name AS resigned_by_name,

        u.reactivated_at,
        u.reactivated_note,
        ra.full_name AS reactivated_by_name

    FROM user_rh u
    LEFT JOIN user_rh r  ON r.id  = u.resigned_by
    LEFT JOIN user_rh ra ON ra.id = u.reactivated_by

    ORDER BY 
        u.is_active DESC,
        u.full_name ASC

")->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// KELOMPOKKAN USER BERDASARKAN BATCH
// ===============================
$usersByBatch = [];

function formatDurasiMedis(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) return '-';

    $start = new DateTime($tanggalMasuk);
    $now   = new DateTime();

    if ($start > $now) return '-';

    $diff = $start->diff($now);

    if ($diff->y > 0) {
        return $diff->y . ' tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' bulan' : '');
    }

    if ($diff->m > 0) {
        return $diff->m . ' bulan';
    }

    $days = $diff->days;

    if ($days >= 7) {
        return floor($days / 7) . ' minggu';
    }

    return $days . ' hari';
}

foreach ($users as $u) {
    $batchKey = !empty($u['batch']) ? 'Batch ' . (int)$u['batch'] : 'Tanpa Batch';
    $usersByBatch[$batchKey][] = $u;
}

// Urutkan batch (Batch 1,2,3... lalu Tanpa Batch di akhir)
uksort($usersByBatch, function ($a, $b) {
    if ($a === 'Tanpa Batch') return 1;
    if ($b === 'Tanpa Batch') return -1;

    preg_match('/\d+/', $a, $ma);
    preg_match('/\d+/', $b, $mb);

    return ((int)$ma[0]) <=> ((int)$mb[0]);
});

?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page" style="max-width:1100px;margin:auto;">

        <h1 class="gradient-text">Manajemen User</h1>
        <p class="text-muted">Kelola akun, jabatan, role, dan PIN pengguna</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <span>Daftar User</span>
	                <div style="display:flex;gap:8px;flex-wrap:wrap;">
	                    <select id="searchColumn" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
	                        <option value="all" selected>Semua Kolom</option>
	                        <option value="name">Nama</option>
	                        <option value="position">Jabatan</option>
	                        <option value="role">Role</option>
	                        <option value="docs">Dokumen</option>
	                        <option value="join">Tanggal Join</option>
	                    </select>
	                    <input type="text"
	                        id="searchUser"
                        placeholder="🔍 Cari nama..."
                        style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;min-width:250px;">

                    <button id="btnExportText" class="btn-secondary">
                        📄 Export Text
                    </button>

                    <button id="btnAddUser" class="btn-success">
                        ➕ Tambah Anggota
                    </button>
                </div>
            </div>

	            <div class="table-wrapper">
	                <?php foreach ($usersByBatch as $batchName => $batchUsers): ?>
	                    <div class="card" style="margin-bottom:20px;">
	                        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
	                            <div>
	                                <?= htmlspecialchars($batchName) ?>
	                                <span style="font-size:12px;color:#64748b;">
	                                    (<?= count($batchUsers) ?> user)
	                                </span>
	                            </div>

	                            <?php if ($batchName === 'Tanpa Batch'): ?>
	                                <button id="btnExportTanpaBatch" class="btn-secondary" type="button" style="padding:6px 10px;font-size:13px;">
	                                    📄 Export Tanpa Batch
	                                </button>
	                            <?php endif; ?>
	                        </div>

	                        <div class="table-wrapper">
	                            <table class="table-custom user-batch-table">
	                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nama</th>
                                        <th>Jabatan</th>
                                        <th>Role</th>
                                        <th>Tanggal Join</th>
                                        <th>Dokumen</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
	                                <tbody>
	                                    <?php foreach ($batchUsers as $i => $u): ?>
	                                        <?php
	                                        $docs = [
	                                            'SERTIFIKAT HELI' => $u['sertifikat_heli'] ?? null,
	                                            'SERTIFIKAT OPERASI' => $u['sertifikat_operasi'] ?? null,
	                                        ];

	                                        $academyDocs = parseAcademyDocs($u['dokumen_lainnya'] ?? '');
	                                        foreach ($academyDocs as $ad) {
	                                            $label = 'ACADEMY: ' . ($ad['name'] ?? 'Sertifikat Academy');
	                                            $docs[$label] = $ad['path'] ?? null;
	                                        }

	                                        $docSearchTokens = [];
		                                        foreach ($docs as $label => $path) {
		                                            if (empty($path)) continue;
		                                            $docSearchTokens[] = strtolower($label);
		                                            $docSearchTokens[] = strtolower(basename((string)$path));
		                                        }
			                                        $docSearch = trim(implode(' ', $docSearchTokens));

		                                        $posSearch = strtolower(trim((string)($u['position'] ?? '')));
		                                        $roleSearch = strtolower(trim((string)($u['role'] ?? '')));
		                                        $joinSearch = '';
		                                        if (!empty($u['tanggal_masuk'])) {
		                                            try {
		                                                $dtJoin = new DateTime((string)$u['tanggal_masuk']);
		                                                $joinSearch = strtolower($dtJoin->format('d M Y')) . ' ' . strtolower($dtJoin->format('Y-m-d'));
		                                            } catch (Throwable $e) {
		                                                $joinSearch = strtolower((string)$u['tanggal_masuk']);
		                                            }
		                                        }

		                                        $allSearch = trim(implode(' ', array_filter([
		                                            strtolower((string)$u['full_name']),
		                                            $posSearch,
		                                            $roleSearch,
		                                            $joinSearch,
		                                            $docSearch,
		                                        ])));
		                                        ?>
			                                        <tr
			                                            data-search-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>"
			                                            data-search-position="<?= htmlspecialchars($posSearch) ?>"
			                                            data-search-role="<?= htmlspecialchars($roleSearch) ?>"
			                                            data-search-join="<?= htmlspecialchars($joinSearch) ?>"
			                                            data-search-docs="<?= htmlspecialchars($docSearch) ?>"
			                                            data-search-all="<?= htmlspecialchars($allSearch) ?>">
	                                            <td><?= $i + 1 ?></td>
	                                            <td>
	                                                <strong><?= htmlspecialchars($u['full_name']) ?></strong>

                                                <?php if (!empty($u['reactivated_at'])): ?>
                                                    <div style="margin-top:4px;font-size:12px;color:#16a34a;">
                                                        🔄 Aktif kembali:
                                                        <?= (new DateTime($u['reactivated_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ((int)$u['is_active'] === 0 && !empty($u['resigned_at'])): ?>
                                                    <div style="margin-top:4px;font-size:12px;color:#64748b;">
                                                        📅 Resign: <?= (new DateTime($u['resigned_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td><?= htmlspecialchars($u['position']) ?></td>
                                            <td><?= htmlspecialchars($u['role']) ?></td>
                                            <td>
                                                <?php if (!empty($u['tanggal_masuk'])): ?>
                                                    <div>
                                                        <?= (new DateTime($u['tanggal_masuk']))->format('d M Y') ?>
                                                    </div>
                                                    <small style="color:#64748b;">
                                                        <?= formatDurasiMedis($u['tanggal_masuk']) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span style="color:#9ca3af;">-</span>
                                                <?php endif; ?>
	                                            </td>
	                                            <td>
	                                                <?php
	                                                foreach ($docs as $label => $path):
	                                                    if (!empty($path)):
	                                                ?>
                                                        <a href="#"
                                                            class="doc-badge btn-preview-doc"
                                                            data-src="/<?= htmlspecialchars($path) ?>"
                                                            data-title="<?= htmlspecialchars($label) ?>"
                                                            title="Lihat <?= htmlspecialchars($label) ?>">
                                                            <?= $label ?>
                                                        </a>
                                                <?php
                                                    endif;
                                                endforeach;
                                                ?>
                                            </td>
                                            <td>
                                                <button
                                                    class="btn-secondary btn-edit-user"
                                                    data-id="<?= (int)$u['id'] ?>"
                                                    data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                    data-position="<?= htmlspecialchars($u['position'], ENT_QUOTES) ?>"
                                                    data-role="<?= strtolower(trim($u['role'])) ?>"
                                                    data-batch="<?= (int)($u['batch'] ?? 0) ?>"
                                                    data-kode="<?= htmlspecialchars($u['kode_nomor_induk_rs'] ?? '', ENT_QUOTES) ?>">
                                                    Edit
                                                </button>

                                                <?php if ($u['is_active']): ?>
                                                    <button class="btn-resign btn-resign-user"
                                                        data-id="<?= (int)$u['id'] ?>"
                                                        data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                                        Resign
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-success btn-reactivate-user"
                                                        data-id="<?= (int)$u['id'] ?>"
                                                        data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                                        Kembali
                                                    </button>
                                                <?php endif; ?>

                                                <button class="btn-danger btn-delete-user"
                                                    data-id="<?= (int)$u['id'] ?>"
                                                    data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                                    Hapus
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div>
        </div>
    </div>

</section>

<!-- ======================================
     MODAL PREVIEW DOKUMEN
     ====================================== -->
<div id="docPreviewModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:900px;">

        <!-- HEADER -->
        <div class="modal-header">
            <strong id="docPreviewTitle">📄 Preview Dokumen</strong>

            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="zoom-control-btn" id="docPrev" title="Sebelumnya">⬅️</button>
                <button type="button" class="zoom-control-btn" id="docNext" title="Berikutnya">➡️</button>

                <button type="button" class="zoom-control-btn" id="docZoomOut" title="Perkecil">➖</button>
                <button type="button" class="zoom-control-btn" id="docZoomIn" title="Perbesar">➕</button>
                <button type="button" class="zoom-control-btn" id="docZoomReset" title="Reset">🔄</button>

                <button type="button" onclick="closeDocModal()">✕</button>
            </div>
        </div>

        <!-- BODY -->
        <div class="modal-body"
            style="
                background:#f8fafc;
                display:flex;
                align-items:center;
                justify-content:center;
                min-height:60vh;
            ">
            <img id="docPreviewImage"
                src=""
                alt="Dokumen"
                style="
                    max-width:100%;
                    max-height:75vh;
                    object-fit:contain;
                    transition:transform 0.2s ease;
                ">
        </div>

    </div>
</div>

<div id="resignModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Resign User</h3>

        <form method="POST" action="manage_users_action.php" class="form">
            <input type="hidden" name="action" value="resign">
            <input type="hidden" name="user_id" id="resignUserId">

            <p>
                Apakah Anda yakin ingin menonaktifkan
                <strong id="resignUserName"></strong>?
            </p>

	            <label for="resignReason">Alasan Resign</label>
	            <textarea id="resignReason" name="resign_reason" autocomplete="off" required></textarea>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-nonaktif ">Nonaktifkan</button>
            </div>
        </form>
    </div>
</div>

<div id="reactivateModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Kembali Bekerja</h3>

        <form method="POST" action="manage_users_action.php" class="form">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="user_id" id="reactivateUserId">

            <p>
                Aktifkan kembali
                <strong id="reactivateUserName"></strong>?
            </p>

	            <label for="reactivateNote">Keterangan (opsional)</label>
	            <textarea id="reactivateNote" name="reactivate_note" autocomplete="off"
	                placeholder="Contoh: Kontrak baru / dipanggil kembali"></textarea>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Aktifkan</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Edit User</h3>

        <form method="POST" action="manage_users_action.php" class="form">
            <input type="hidden" name="user_id" id="editUserId">

	            <label for="editBatch">Batch</label>
	            <input type="number"
	                name="batch"
	                id="editBatch"
	                autocomplete="off"
	                min="1"
	                max="26"
	                placeholder="Contoh: 3">

	            <label for="editKodeMedis">Kode Medis / Nomor Induk RS</label>

            <div class="ems-kode-medis">
                <input type="text"
                    id="editKodeMedis"
                    readonly>

                <button type="button"
                    id="btnDeleteKodeMedis"
                    title="Hapus kode medis">
                    🗑
                </button>
            </div>

            <small style="color:#c0392b;display:none;" id="kodeMedisWarning">
                Menghapus kode medis akan mengizinkan sistem membuat ulang kode baru.
            </small>

	            <label for="editName">Nama</label>
	            <input type="text" name="full_name" id="editName" autocomplete="username" required>

	            <label for="editPosition">Jabatan</label>
	            <select name="position" id="editPosition" autocomplete="organization-title" required>
                <option value="Trainee">Trainee</option>
                <option value="Paramedic">Paramedic</option>
                <option value="(Co.Ast)">(Co.Ast)</option>
                <option value="Dokter Umum">Dokter Umum</option>
                <option value="Dokter Spesialis">Dokter Spesialis</option>
            </select>

	            <label for="editRole">Role</label>
	            <select name="role" id="editRole" autocomplete="off" required>
                <option value="Staff">Staff</option>
                <option value="Staff Manager">Staff Manager</option>
                <option value="Manager">Manager</option>
                <option value="Vice Director">Vice Director</option>
                <option value="Director">Director</option>
            </select>

		            <label for="editNewPin">PIN Baru <small>(4 digit, kosongkan jika tidak ganti)</small></label>
		            <input type="password"
		                id="editNewPin"
		                name="new_pin"
		                autocomplete="new-password"
		                inputmode="numeric"
		                pattern="[0-9]{4}"
		                maxlength="4">

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Simpan</button>
            </div>

        </form>
    </div>
</div>

<div id="deleteModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Hapus User</h3>

        <form method="POST" action="manage_users_action.php" class="form">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">

            <p style="color:#b91c1c;">
                ⚠️ User <strong id="deleteUserName"></strong> akan dihapus permanen.
                <br>Tindakan ini <strong>tidak dapat dibatalkan</strong>.
            </p>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-danger">Hapus Permanen</button>
            </div>
        </form>
    </div>
</div>

<div id="addUserModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Tambah Anggota Baru</h3>

        <form method="POST" action="manage_users_action.php" class="form">
            <input type="hidden" name="action" value="add_user">

	            <label for="addFullName">Nama Lengkap</label>
	            <input type="text" id="addFullName" name="full_name" autocomplete="name" required>

	            <label for="addPosition">Jabatan</label>
	            <select id="addPosition" name="position" autocomplete="organization-title" required>
                <option value="Trainee">Trainee</option>
                <option value="Paramedic">Paramedic</option>
                <option value="(Co.Ast)">(Co.Ast)</option>
                <option value="Dokter Umum">Dokter Umum</option>
                <option value="Dokter Spesialis">Dokter Spesialis</option>
            </select>

	            <label for="addRole">Role</label>
	            <select id="addRole" name="role" autocomplete="off" required>
                <option value="Staff">Staff</option>
                <option value="Staff Manager">Staff Manager</option>
                <option value="Manager">Manager</option>
                <option value="Vice Director">Vice Director</option>
                <option value="Director">Director</option>
            </select>

	            <label for="addBatch">Batch <small>(opsional)</small></label>
	            <input type="number" id="addBatch" name="batch" autocomplete="off" min="1" max="26" placeholder="Contoh: 3">

            <small style="color:#64748b;">
                PIN awal akan otomatis dibuat: <strong>0000</strong>
            </small>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const resignModal = document.getElementById('resignModal');

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-resign-user');
            if (!btn) return;

            document.getElementById('resignUserId').value = btn.dataset.id;
            document.getElementById('resignUserName').innerText = btn.dataset.name;

            resignModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                resignModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('editModal');
            if (modal) modal.style.display = 'none';
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const modal = document.getElementById('editModal');

        const roleMap = {
            'staff': 'Staff',
            'staff manager': 'Staff Manager',
            'manager': 'Manager',
            'vice director': 'Vice Director',
            'director': 'Director'
        };

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-edit-user');
            if (!btn) return;

            document.getElementById('editUserId').value = btn.dataset.id;
            document.getElementById('editName').value = btn.dataset.name;
            document.getElementById('editPosition').value = btn.dataset.position;
            document.getElementById('editRole').value = roleMap[btn.dataset.role] || 'Staff';

            document.getElementById('editBatch').value = btn.dataset.batch || '';
            document.getElementById('editKodeMedis').value = btn.dataset.kode || '';

            document.getElementById('kodeMedisWarning').style.display =
                btn.dataset.kode ? 'block' : 'none';

            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        // close modal
        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    function closeModal() {
        document.getElementById('editModal').style.display = 'none';
    }

	    document.addEventListener('DOMContentLoaded', function() {
	        // Simpan referensi DataTable instances untuk kontrol pagination
	        let dataTableInstances = [];

	        function scheduleWork(fn) {
	            if (typeof window.requestIdleCallback === 'function') {
	                window.requestIdleCallback(fn, {timeout: 800});
	            } else {
	                setTimeout(fn, 0);
	            }
	        }

	        scheduleWork(() => {
	            if (window.jQuery && jQuery.fn.DataTable) {
	                jQuery('.user-batch-table').each(function() {
	                    const table = jQuery(this);
	                    const dataTable = table.DataTable({
	                        pageLength: 10,
	                        searching: false,
	                        dom: 'rtip',
	                        order: [
	                            [1, 'asc']
	                        ],
	                        language: {
	                            url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
	                        }
	                    });

	                    dataTableInstances.push({
	                        tableElement: table[0],
	                        dataTable: dataTable
	                    });
	                });
	            }
	        });

        // ===============================
	        // FITUR PENCARIAN USER - VANILLA JS (NO DATATABLES API)
	        // ===============================
	        const searchInput = document.getElementById('searchUser');
	        const searchColumn = document.getElementById('searchColumn');

	        function updateSearchPlaceholder() {
	            if (!searchInput) return;
	            const mode = searchColumn ? searchColumn.value : 'all';
		            const map = {
		                all: '🔍 Cari (semua kolom)...',
		                name: '🔍 Cari nama...',
		                position: '🔍 Cari jabatan...',
		                role: '🔍 Cari role...',
		                docs: '🔍 Cari dokumen (Sertifikat Heli, Operasi, Academy)...',
		                join: '🔍 Cari tanggal join...'
		            };
	            searchInput.placeholder = map[mode] || '🔍 Cari...';
	        }

	        if (searchInput) {
	            updateSearchPlaceholder();
	            if (searchColumn) {
	                searchColumn.addEventListener('change', function() {
	                    updateSearchPlaceholder();
	                    searchInput.dispatchEvent(new Event('input', {bubbles: true}));
	                });
	            }

		            searchInput.addEventListener('input', function() {
		                const keyword = this.value.toLowerCase().trim();
		                const terms = keyword.split(/\s+/).filter(Boolean);
		                const mode = searchColumn ? searchColumn.value : 'all';
		                const batchCards = document.querySelectorAll('.table-wrapper > .card');

		                // Saat searching, tampilkan semua baris di DataTables
		                const isSearching = terms.length > 0;
		                dataTableInstances.forEach(({dataTable}) => {
		                    dataTable.page.len(isSearching ? -1 : 10).draw(false);
		                });

                batchCards.forEach(card => {
                    const table = card.querySelector('.user-batch-table');
                    if (!table) return;

                    const rows = table.querySelectorAll('tbody tr');
                    let visibleCount = 0;

		                    rows.forEach(row => {
		                        const getAttr = (attr) => (row.getAttribute(attr) || '');
		                        let haystack = '';

		                        switch (mode) {
		                            case 'name':
		                                haystack = getAttr('data-search-name');
		                                break;
		                            case 'position':
		                                haystack = getAttr('data-search-position');
		                                break;
		                            case 'role':
		                                haystack = getAttr('data-search-role');
		                                break;
		                            case 'docs':
		                                haystack = getAttr('data-search-docs');
		                                break;
		                            case 'join':
		                                haystack = getAttr('data-search-join');
		                                break;
		                            case 'all':
		                            default:
		                                haystack = getAttr('data-search-all');
		                                break;
		                        }

		                        // Cari berdasarkan nama + dokumen (mendukung multi-kata: "yora ktp")
		                        const isMatch = terms.length === 0 ? true : terms.every(t => haystack.includes(t));

		                        if (isMatch) {
	                            row.style.display = '';
	                            visibleCount++;
	                        } else {
	                            row.style.display = 'none';
	                        }
	                    });

                    // Sembunyikan card batch jika tidak ada user yang cocok
                    if (visibleCount === 0) {
                        card.style.display = 'none';
                    } else {
                        card.style.display = '';
                    }
                });
            });
        }

        // auto hide notif
        setTimeout(function() {
            document.querySelectorAll('.alert-info,.alert-error').forEach(function(el) {
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 600);
            });
        }, 5000);
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const reactivateModal = document.getElementById('reactivateModal');

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-reactivate-user');
            if (!btn) return;

            document.getElementById('reactivateUserId').value = btn.dataset.id;
            document.getElementById('reactivateUserName').innerText = btn.dataset.name;

            reactivateModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                reactivateModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const deleteModal = document.getElementById('deleteModal');

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-delete-user');
            if (!btn) return;

            document.getElementById('deleteUserId').value = btn.dataset.id;
            document.getElementById('deleteUserName').innerText = btn.dataset.name;

            deleteModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                deleteModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    document.getElementById('btnDeleteKodeMedis').addEventListener('click', function() {

        if (!confirm('Yakin ingin menghapus kode medis?')) return;

        const userId = document.getElementById('editUserId').value;

        fetch('manage_users_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'delete_kode_medis',
                    user_id: userId
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('editKodeMedis').value = '';
                    document.getElementById('kodeMedisWarning').style.display = 'none';
                    alert('Kode medis berhasil dihapus.');
                } else {
                    alert(data.message || 'Gagal menghapus kode medis.');
                }
            })
            .catch(() => alert('Terjadi kesalahan server.'));
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('addUserModal');
        const btnOpen = document.getElementById('btnAddUser');

        if (btnOpen) {
            btnOpen.addEventListener('click', () => {
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            });
        }

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const modal = document.getElementById('docPreviewModal');
        const img = document.getElementById('docPreviewImage');
        const title = document.getElementById('docPreviewTitle');

        const btnPrev = document.getElementById('docPrev');
        const btnNext = document.getElementById('docNext');

        let scale = 1;

        let docList = []; // daftar dokumen user aktif
        let currentIndex = 0; // index dokumen aktif

        // ===============================
        // OPEN PREVIEW
        // ===============================
        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-preview-doc');
            if (!btn) return;

            e.preventDefault();

            // 🔎 ambil SEMUA dokumen di cell yang sama (user yang sama)
            const cell = btn.closest('td');
            const docs = cell.querySelectorAll('.btn-preview-doc');

            docList = Array.from(docs).map(el => ({
                src: el.dataset.src,
                title: el.dataset.title || 'Dokumen'
            }));

            currentIndex = Array.from(docs).indexOf(btn);

            openDoc(currentIndex);
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        // ===============================
        // OPEN BY INDEX
        // ===============================
        function openDoc(index) {
            const doc = docList[index];
            if (!doc) return;

            img.src = doc.src;
            title.textContent = '📄 ' + doc.title;

            scale = 1;
            img.style.transform = 'scale(1)';
        }

        // ===============================
        // NAVIGATION (LOOP)
        // ===============================
        btnNext.onclick = () => {
            if (!docList.length) return;
            currentIndex = (currentIndex + 1) % docList.length;
            openDoc(currentIndex);
        };

        btnPrev.onclick = () => {
            if (!docList.length) return;
            currentIndex =
                (currentIndex - 1 + docList.length) % docList.length;
            openDoc(currentIndex);
        };

        // ===============================
        // ZOOM CONTROLS
        // ===============================
        document.getElementById('docZoomIn').onclick = () => {
            scale += 0.1;
            img.style.transform = `scale(${scale})`;
        };

        document.getElementById('docZoomOut').onclick = () => {
            scale = Math.max(0.3, scale - 0.1);
            img.style.transform = `scale(${scale})`;
        };

        document.getElementById('docZoomReset').onclick = () => {
            scale = 1;
            img.style.transform = 'scale(1)';
        };

        // ===============================
        // CLOSE MODAL
        // ===============================
        window.closeDocModal = function() {
            modal.style.display = 'none';
            img.src = '';
            docList = [];
            currentIndex = 0;
            scale = 1;
            document.body.classList.remove('modal-open');
        };

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeDocModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDocModal();
        });

    });
</script>

<script>
	    document.addEventListener('DOMContentLoaded', function() {

	        const btn = document.getElementById('btnExportText');
	        if (!btn) return;

	        btn.addEventListener('click', function() {

	            function toRoman(num) {
	                const map = [
	                    [1000, 'M'],
	                    [900, 'CM'],
	                    [500, 'D'],
	                    [400, 'CD'],
	                    [100, 'C'],
	                    [90, 'XC'],
	                    [50, 'L'],
	                    [40, 'XL'],
	                    [10, 'X'],
	                    [9, 'IX'],
	                    [5, 'V'],
	                    [4, 'IV'],
	                    [1, 'I']
	                ];

	                let n = Number(num);
	                if (!Number.isFinite(n) || n <= 0) return '';
	                n = Math.floor(n);

	                let out = '';
	                for (const [value, roman] of map) {
	                    while (n >= value) {
	                        out += roman;
	                        n -= value;
	                    }
	                }
	                return out;
	            }

	            function getBatchHeaderTitle(batchCard) {
	                const header = batchCard?.querySelector('.card-header');
	                if (!header) return '';

	                const firstTextNode = header.childNodes && header.childNodes[0];
	                const raw = firstTextNode && firstTextNode.textContent ? firstTextNode.textContent : header.textContent;
	                return String(raw || '').replace(/\s+/g, ' ').trim();
	            }

	            let output = '';

	            // LOOP SETIAP TABEL BATCH
	            document.querySelectorAll('.user-batch-table').forEach(table => {

	                // ambil instance DataTable
	                const dt = (window.jQuery && jQuery.fn && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(table)) ?
	                    jQuery(table).DataTable() :
	                    null;

                // simpan pageLength awal
                let originalLength = null;

                if (dt) {
                    originalLength = dt.page.len();
                    dt.page.len(-1).draw(false); // tampilkan SEMUA row
                }

	                const batchCard = table.closest('.card');
	                if (batchCard && window.getComputedStyle(batchCard).display === 'none') {
	                    // kembalikan pageLength semula
	                    if (dt && originalLength !== null) {
	                        dt.page.len(originalLength).draw(false);
	                    }
	                    return;
	                }

	                const batchTitleRaw = getBatchHeaderTitle(batchCard);
	                const rows = table.querySelectorAll('tbody tr');

	                if (!rows.length) return;

	                const lines = [];

	                let no = 1;
		                rows.forEach(row => {
		                    if (window.getComputedStyle(row).display === 'none') return;
		                    const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
		                    const jabatan = row.querySelector('td:nth-child(3)')?.innerText || '';

		                    const noStr = String(no).padStart(2, '0');
		                    lines.push(`${noStr}. ${nama} (${jabatan})`);
		                    no++;
		                });

	                if (lines.length > 0) {
	                    let batchTitleOut = batchTitleRaw;
	                    const m = batchTitleRaw.match(/^Batch\s+(\d+)\b/i);
	                    if (m) {
	                        const roman = toRoman(parseInt(m[1], 10));
	                        batchTitleOut = roman ? `BATCH ${roman}` : 'BATCH';
	                    } else if (/tanpa\s+batch/i.test(batchTitleRaw)) {
	                        batchTitleOut = 'TANPA BATCH';
	                    } else {
	                        batchTitleOut = (batchTitleRaw || 'BATCH').toUpperCase();
	                    }

	                    output += batchTitleOut + '\n';
	                    output += lines.join('\n') + '\n\n';
	                }

	                // kembalikan pageLength semula
	                if (dt && originalLength !== null) {
	                    dt.page.len(originalLength).draw(false);
	                }
            });

            if (!output.trim()) {
                alert('Tidak ada data untuk diexport.');
                return;
            }

            // download TXT
            const blob = new Blob([output], {
                type: 'text/plain;charset=utf-8;'
            });
            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');
            a.href = url;
            a.download = 'daftar_medis.txt';
            document.body.appendChild(a);
            a.click();

            document.body.removeChild(a);
            URL.revokeObjectURL(url);
	        });

	    });
	</script>

	<script>
	    document.addEventListener('DOMContentLoaded', function() {
	        const btn = document.getElementById('btnExportTanpaBatch');
	        if (!btn) return;

	        btn.addEventListener('click', function() {
	            const batchCard = btn.closest('.card');
	            const table = batchCard ? batchCard.querySelector('.user-batch-table') : null;
	            if (!table) return;

	            // ambil instance DataTable (kalau ada)
	            const dt = (window.jQuery && jQuery.fn && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(table)) ?
	                jQuery(table).DataTable() :
	                null;

	            // simpan pageLength awal
	            let originalLength = null;
	            if (dt) {
	                originalLength = dt.page.len();
	                dt.page.len(-1).draw(false); // tampilkan SEMUA row
	            }

	            const rows = table.querySelectorAll('tbody tr');
	            const lines = [];
	            let no = 1;

	            rows.forEach(row => {
	                if (window.getComputedStyle(row).display === 'none') return;
	                const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
	                const jabatan = row.querySelector('td:nth-child(3)')?.innerText || '';
	                const noStr = String(no).padStart(2, '0');
	                lines.push(`${noStr}. ${nama} (${jabatan})`);
	                no++;
	            });

	            // kembalikan pageLength semula
	            if (dt && originalLength !== null) {
	                dt.page.len(originalLength).draw(false);
	            }

	            if (!lines.length) {
	                alert('Tidak ada data Tanpa Batch untuk diexport.');
	                return;
	            }

	            const output = 'TANPA BATCH\n' + lines.join('\n') + '\n';

	            const blob = new Blob([output], {type: 'text/plain;charset=utf-8;'});
	            const url = URL.createObjectURL(blob);

	            const a = document.createElement('a');
	            a.href = url;
	            a.download = 'tanpa_batch.txt';
	            document.body.appendChild(a);
	            a.click();

	            document.body.removeChild(a);
	            URL.revokeObjectURL(url);
	        });
	    });
	</script>


	<?php include __DIR__ . '/../partials/footer.php'; ?>
