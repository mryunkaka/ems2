<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';

// HARD GUARD: staff dilarang
if (ems_is_staff_role($role)) {
    header('Location: setting_akun.php');
    exit;
}

$pageTitle = 'Manajemen User';
$roleOptions = ems_role_options();
$divisionOptions = ems_division_options();

function manageUsersHasColumn(PDO $pdo, string $column): bool
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

// FLASH NOTIF EMS
$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

$hasDivisionColumn = manageUsersHasColumn($pdo, 'division');
$divisionSelect = $hasDivisionColumn ? "u.division," : "NULL AS division,";

// AMBIL SEMUA USER (SESUAI DATABASE)
$users = $pdo->query("
        SELECT 
        u.id,
        u.full_name,
        u.position,
        u.role,
        {$divisionSelect}
        u.is_active,
        u.tanggal_masuk,

        u.batch,
        u.kode_nomor_induk_rs,

        u.file_ktp,
        u.file_sim,
        u.file_kta,
        u.file_skb,
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
    <div class="page page-shell-md">

        <h1 class="page-title">Manajemen User</h1>
        <p class="page-subtitle">Kelola akun, jabatan, role, dan PIN pengguna</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header card-toolbar">
                <span>Daftar User</span>
	                <div class="toolbar-group">
	                    <select id="docStatusFilter" class="toolbar-select">
	                        <option value="all" selected>Semua Dokumen</option>
	                        <option value="missing_ktp">Belum Upload KTP</option>
	                        <option value="missing_sim">Belum Upload SIM</option>
	                        <option value="missing_kta">Belum Upload KTA</option>
	                        <option value="missing_skb">Belum Upload SKB</option>
	                        <option value="missing_sertifikat_heli">Belum Upload Sertifikat Heli</option>
	                        <option value="missing_sertifikat_operasi">Belum Upload Sertifikat Operasi</option>
	                        <option value="missing_dokumen_lainnya">Belum Upload Dokumen Lainnya</option>
	                    </select>
	                    <select id="searchColumn" class="toolbar-select">
	                        <option value="all" selected>Semua Kolom</option>
	                        <option value="name">Nama</option>
	                        <option value="position">Jabatan</option>
	                        <option value="role">Role</option>
	                        <option value="division">Division</option>
	                        <option value="docs">Dokumen</option>
	                        <option value="join">Tanggal Join</option>
	                    </select>
	                    <input type="text"
	                        id="searchUser"
                        placeholder="Cari nama..."
                        class="toolbar-input">

                    <button id="btnExportText" class="btn-secondary" type="button">
                        <?= ems_icon('document-text', 'h-4 w-4') ?> Export Teks
                    </button>

                    <button id="btnClearUserFilters" class="btn-secondary" type="button">
                        <?= ems_icon('x-mark', 'h-4 w-4') ?> Clear
                    </button>

                    <button id="btnAddUser" class="btn-success">
                        <?= ems_icon('plus', 'h-4 w-4') ?> Tambah Anggota
                    </button>
                </div>
            </div>

	            <div class="table-wrapper">
	                <?php foreach ($usersByBatch as $batchName => $batchUsers): ?>
	                    <div class="card batch-card">
	                        <div class="card-header batch-card-header">
	                            <div>
	                                <?= htmlspecialchars($batchName) ?>
	                                <span class="batch-count">
	                                    (<?= count($batchUsers) ?> user)
	                                </span>
	                            </div>

	                            <?php if ($batchName === 'Tanpa Batch'): ?>
	                                <button id="btnExportTanpaBatch" class="btn-secondary button-compact" type="button">
	                                    <?= ems_icon('document-text', 'h-4 w-4') ?> Export Tanpa Batch
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
                                        <th>Division</th>
                                        <th>Tanggal Join</th>
                                        <th>Dokumen</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
	                                <tbody>
	                                    <?php foreach ($batchUsers as $i => $u): ?>
	                                        <?php
	                                        $docs = [
	                                            'KTP' => $u['file_ktp'] ?? null,
	                                            'SIM' => $u['file_sim'] ?? null,
	                                            'KTA' => $u['file_kta'] ?? null,
	                                            'SKB' => $u['file_skb'] ?? null,
	                                            'SERTIFIKAT HELI' => $u['sertifikat_heli'] ?? null,
	                                            'SERTIFIKAT OPERASI' => $u['sertifikat_operasi'] ?? null,
	                                        ];

	                                        $academyDocs = parseAcademyDocs($u['dokumen_lainnya'] ?? '');
	                                        foreach ($academyDocs as $ad) {
	                                            $label = trim((string)($ad['name'] ?? 'File Lainnya'));
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
		                                        $divisionSearch = strtolower(trim((string)ems_normalize_division($u['division'] ?? '')));
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
		                                            $divisionSearch,
		                                            $joinSearch,
		                                            $docSearch,
		                                        ])));
		                                        $hasKtp = !empty($u['file_ktp']);
		                                        $hasSim = !empty($u['file_sim']);
		                                        $hasKta = !empty($u['file_kta']);
		                                        $hasSkb = !empty($u['file_skb']);
		                                        $hasSertifikatHeli = !empty($u['sertifikat_heli']);
		                                        $hasSertifikatOperasi = !empty($u['sertifikat_operasi']);
		                                        $hasDokumenLainnya = !empty($academyDocs);
		                                        $hasAnyDoc = false;
		                                        foreach ($docs as $path) {
		                                            if (!empty($path)) {
		                                                $hasAnyDoc = true;
		                                                break;
		                                            }
		                                        }
		                                        ?>
			                                        <tr
			                                            data-search-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>"
			                                            data-search-position="<?= htmlspecialchars($posSearch) ?>"
			                                            data-search-role="<?= htmlspecialchars($roleSearch) ?>"
			                                            data-search-division="<?= htmlspecialchars($divisionSearch) ?>"
			                                            data-search-join="<?= htmlspecialchars($joinSearch) ?>"
			                                            data-search-docs="<?= htmlspecialchars($docSearch) ?>"
			                                            data-search-all="<?= htmlspecialchars($allSearch) ?>"
			                                            data-has-ktp="<?= $hasKtp ? '1' : '0' ?>"
			                                            data-has-sim="<?= $hasSim ? '1' : '0' ?>"
			                                            data-has-kta="<?= $hasKta ? '1' : '0' ?>"
			                                            data-has-skb="<?= $hasSkb ? '1' : '0' ?>"
			                                            data-has-sertifikat-heli="<?= $hasSertifikatHeli ? '1' : '0' ?>"
			                                            data-has-sertifikat-operasi="<?= $hasSertifikatOperasi ? '1' : '0' ?>"
			                                            data-has-dokumen-lainnya="<?= $hasDokumenLainnya ? '1' : '0' ?>">
	                                            <td><?= $i + 1 ?></td>
	                                            <td>
                                                <strong><?= htmlspecialchars($u['full_name']) ?></strong>

                                                <?php if (!empty($u['reactivated_at'])): ?>
                                                    <div class="status-note-success">
                                                        Aktif kembali:
                                                        <?= (new DateTime($u['reactivated_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ((int)$u['is_active'] === 0 && !empty($u['resigned_at'])): ?>
                                                    <div class="status-note-muted">
                                                        Resign: <?= (new DateTime($u['resigned_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td><?= htmlspecialchars(ems_position_label($u['position'])) ?></td>
                                            <td><?= htmlspecialchars(ems_role_label($u['role'])) ?></td>
                                            <td><?= htmlspecialchars(ems_normalize_division($u['division'] ?? '') ?: '-') ?></td>
                                            <td>
                                                <?php if (!empty($u['tanggal_masuk'])): ?>
                                                    <div>
                                                        <?= (new DateTime($u['tanggal_masuk']))->format('d M Y') ?>
                                                    </div>
                                                    <small class="meta-text">
                                                        <?= formatDurasiMedis($u['tanggal_masuk']) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="muted-placeholder">-</span>
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

	                                                if (!$hasAnyDoc):
	                                                ?>
                                                        <span class="muted-placeholder">-</span>
                                                <?php
	                                                endif;
                                                ?>
                                            </td>
                                            <td>
                                                <div class="manage-user-action-stack">
                                                    <button
                                                        class="btn-secondary btn-sm candidate-action-btn btn-edit-user"
                                                        data-id="<?= (int)$u['id'] ?>"
                                                        data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                        data-position="<?= htmlspecialchars(ems_normalize_position($u['position']), ENT_QUOTES) ?>"
                                                        data-role="<?= strtolower(trim($u['role'])) ?>"
                                                        data-division="<?= htmlspecialchars(ems_normalize_division($u['division'] ?? ''), ENT_QUOTES) ?>"
                                                        data-batch="<?= (int)($u['batch'] ?? 0) ?>"
                                                        data-kode="<?= htmlspecialchars($u['kode_nomor_induk_rs'] ?? '', ENT_QUOTES) ?>">
                                                        Edit
                                                    </button>

                                                    <?php if ($u['is_active']): ?>
                                                        <button class="btn-resign btn-sm candidate-action-btn btn-resign-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                                            Resign
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-success btn-sm candidate-action-btn btn-reactivate-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                                            Kembali
                                                        </button>
                                                    <?php endif; ?>

                                                    <button class="btn-danger btn-sm candidate-action-btn btn-delete-user"
                                                        data-id="<?= (int)$u['id'] ?>"
                                                        data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                                        Hapus
                                                    </button>
                                                </div>
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

<div id="resignModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Resign User</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="resign">
            <input type="hidden" name="user_id" id="resignUserId">

            <p>
                Apakah Anda yakin ingin menonaktifkan
                <strong id="resignUserName"></strong>?
            </p>

	            <label for="resignReason">Alasan Resign</label>
	            <textarea id="resignReason" name="resign_reason" autocomplete="off" required></textarea>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-nonaktif">Nonaktifkan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="reactivateModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Kembali Bekerja</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="user_id" id="reactivateUserId">

            <p>
                Aktifkan kembali
                <strong id="reactivateUserName"></strong>?
            </p>

	            <label for="reactivateNote">Keterangan (opsional)</label>
	            <textarea id="reactivateNote" name="reactivate_note" autocomplete="off"
	                placeholder="Contoh: Kontrak baru / dipanggil kembali"></textarea>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Aktifkan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Edit User</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="user_id" id="editUserId">

	            <label for="editBatch">Batch</label>
		            <input type="number"
		                name="batch"
		                id="editBatch"
		                autocomplete="off"
		                min="1"
		                max="26"
		                placeholder="Contoh: 3">

		            <div class="hidden" aria-hidden="true">
		                <label for="editKodeMedis">Kode Medis / Nomor Induk RS</label>

		                <div class="ems-kode-medis">
		                    <input type="text"
		                        id="editKodeMedis"
		                        readonly>

		                    <button type="button"
		                        id="btnDeleteKodeMedis"
		                        title="Hapus kode medis">
		                        <?= ems_icon('trash', 'h-4 w-4') ?>
		                    </button>
		                </div>

		                <small class="danger-note-sm" id="kodeMedisWarning">
		                    Menghapus kode medis akan mengizinkan sistem membuat ulang kode baru.
		                </small>
		            </div>

		            <label for="editName">Nama</label>
		            <input type="text" name="full_name" id="editName" autocomplete="username" required>

		            <label for="editPosition">Jabatan</label>
		            <select name="position" id="editPosition" autocomplete="organization-title" required>
	                    <?php foreach (ems_position_options() as $opt): ?>
	                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
	                            <?= htmlspecialchars($opt['label']) ?>
	                        </option>
	                    <?php endforeach; ?>
	            </select>

	            <label for="editRole">Role</label>
	            <select name="role" id="editRole" autocomplete="off" required>
                <?php foreach ($roleOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($opt['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

                <label for="editDivision">Division</label>
                <select name="division" id="editDivision" autocomplete="organization" required>
                    <?php foreach ($divisionOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

		            <label for="editNewPin">PIN Baru <small>(4 digit, kosongkan jika tidak ganti)</small></label>
		            <input type="password"
		                id="editNewPin"
		                name="new_pin"
		                autocomplete="new-password"
		                inputmode="numeric"
		                pattern="[0-9]{4}"
		                maxlength="4">

            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Hapus User</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">

            <p class="danger-note">
                User <strong id="deleteUserName"></strong> akan dihapus permanen.
                <br>Tindakan ini <strong>tidak dapat dibatalkan</strong>.
            </p>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-danger">Hapus Permanen</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="addUserModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Tambah Anggota Baru</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="add_user">

	            <label for="addFullName">Nama Lengkap</label>
	            <input type="text" id="addFullName" name="full_name" autocomplete="name" required>

		            <label for="addPosition">Jabatan</label>
		            <select id="addPosition" name="position" autocomplete="organization-title" required>
	                    <?php foreach (ems_position_options() as $opt): ?>
	                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
	                            <?= htmlspecialchars($opt['label']) ?>
	                        </option>
	                    <?php endforeach; ?>
	            </select>

	            <label for="addRole">Role</label>
	            <select id="addRole" name="role" autocomplete="off" required>
                <?php foreach ($roleOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($opt['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

                <label for="addDivision">Division</label>
                <select id="addDivision" name="division" autocomplete="organization" required>
                    <?php foreach ($divisionOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

	            <label for="addBatch">Batch <small>(opsional)</small></label>
	            <input type="number" id="addBatch" name="batch" autocomplete="off" min="1" max="26" placeholder="Contoh: 3">

            <small class="helper-note">
                PIN awal akan otomatis dibuat: <strong>0000</strong>
            </small>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
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

            resignModal.classList.remove('hidden');
            resignModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                resignModal.classList.add('hidden');
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
            if (modal && modal.style.display === 'flex') {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const modal = document.getElementById('editModal');

        const roleMap = {
            'staff': 'Staff',
            'assisten manager': 'Assisten Manager',
            'lead manager': 'Lead Manager',
            'head manager': 'Head Manager',
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
            document.getElementById('editDivision').value = btn.dataset.division || 'Executive';

            document.getElementById('editBatch').value = btn.dataset.batch || '';
            document.getElementById('editKodeMedis').value = btn.dataset.kode || '';

            document.getElementById('kodeMedisWarning').style.display =
                btn.dataset.kode ? 'block' : 'none';

            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        // close modal
        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    function closeModal() {
        const modal = document.getElementById('editModal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

	    document.addEventListener('DOMContentLoaded', function() {
	        // ===============================
	        // FITUR PENCARIAN USER - VANILLA JS (NO DATATABLES API)
	        // ===============================
	        const searchInput = document.getElementById('searchUser');
	        const searchColumn = document.getElementById('searchColumn');
	        const docStatusFilter = document.getElementById('docStatusFilter');
	        const clearFilterButton = document.getElementById('btnClearUserFilters');

	        function updateSearchPlaceholder() {
	            if (!searchInput) return;
	            const mode = searchColumn ? searchColumn.value : 'all';
		            const map = {
		                all: 'Cari (semua kolom)...',
		                name: 'Cari nama...',
		                position: 'Cari jabatan...',
		                role: 'Cari role...',
		                division: 'Cari division...',
		                docs: 'Cari dokumen (KTP, SIM, KTA, SKB, Heli, Operasi, File Lainnya)...',
		                join: 'Cari tanggal join...'
	            };
	            searchInput.placeholder = map[mode] || 'Cari...';
	        }

	        function getRowSearchValue(row, mode) {
	            const getAttr = (attr) => (row.getAttribute(attr) || '');

	            switch (mode) {
	                case 'name':
	                    return getAttr('data-search-name');
	                case 'position':
	                    return getAttr('data-search-position');
	                case 'role':
	                    return getAttr('data-search-role');
	                case 'division':
	                    return getAttr('data-search-division');
	                case 'docs':
	                    return getAttr('data-search-docs');
	                case 'join':
	                    return getAttr('data-search-join');
	                case 'all':
	                default:
	                    return getAttr('data-search-all');
	            }
	        }

	        function applyUserFilters() {
	            const keyword = (searchInput?.value || '').toLowerCase().trim();
	            const terms = keyword.split(/\s+/).filter(Boolean);
	            const mode = searchColumn ? searchColumn.value : 'all';
	            const docFilterValue = docStatusFilter ? docStatusFilter.value : 'all';
	            const batchCards = document.querySelectorAll('.table-wrapper > .card');

	            batchCards.forEach(card => {
	                const table = card.querySelector('.user-batch-table');
	                if (!table) return;

	                const rows = table.querySelectorAll('tbody tr');
	                let visibleCount = 0;

	                rows.forEach(row => {
	                    const haystack = getRowSearchValue(row, mode);
	                    const docAttrMap = {
	                        missing_ktp: 'data-has-ktp',
	                        missing_sim: 'data-has-sim',
	                        missing_kta: 'data-has-kta',
	                        missing_skb: 'data-has-skb',
	                        missing_sertifikat_heli: 'data-has-sertifikat-heli',
	                        missing_sertifikat_operasi: 'data-has-sertifikat-operasi',
	                        missing_dokumen_lainnya: 'data-has-dokumen-lainnya'
	                    };
	                    const matchesSearch = terms.length === 0 ? true : terms.every(t => haystack.includes(t));
	                    const docAttr = docAttrMap[docFilterValue] || '';
	                    const matchesDocFilter = docAttr ? row.getAttribute(docAttr) !== '1' : true;
	                    const isMatch = matchesSearch && matchesDocFilter;

	                    if (isMatch) {
	                        row.style.display = '';
	                        visibleCount++;
	                    } else {
	                        row.style.display = 'none';
	                    }
	                });

	                const batchCountEl = card.querySelector('.batch-count');
	                if (batchCountEl) {
	                    batchCountEl.textContent = `(${visibleCount} user)`;
	                }

	                card.style.display = visibleCount === 0 ? 'none' : '';
	            });
	        }

	        if (searchInput) {
	            updateSearchPlaceholder();
	            if (searchColumn) {
	                searchColumn.addEventListener('change', function() {
	                    updateSearchPlaceholder();
	                    applyUserFilters();
	                });
	            }

		            searchInput.addEventListener('input', applyUserFilters);
	            if (docStatusFilter) {
	                docStatusFilter.addEventListener('change', applyUserFilters);
	            }
	            if (clearFilterButton) {
	                clearFilterButton.addEventListener('click', function() {
	                    if (docStatusFilter) {
	                        docStatusFilter.value = 'all';
	                    }
	                    if (searchColumn) {
	                        searchColumn.value = 'all';
	                    }
	                    if (searchInput) {
	                        searchInput.value = '';
	                    }
	                    updateSearchPlaceholder();
	                    applyUserFilters();
	                });
	            }
	            applyUserFilters();
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

            reactivateModal.classList.remove('hidden');
            reactivateModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                reactivateModal.classList.add('hidden');
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

            deleteModal.classList.remove('hidden');
            deleteModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                deleteModal.classList.add('hidden');
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
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            });
        }

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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

        function getDataTableInstance(table) {
            return null;
        }

        function collectVisibleRows(table) {
            return Array.from(table.querySelectorAll('tbody tr')).filter(function(row) {
                return window.getComputedStyle(row).display !== 'none';
            });
        }

        function withExpandedTable(table, work) {
            return work();
        }

        function downloadText(filename, content) {
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function exportTimestamp() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            return `${year}-${month}-${day}_${hours}-${minutes}-${seconds}`;
        }

        document.body.addEventListener('click', function(e) {
            const exportAllBtn = e.target.closest('#btnExportText');
            if (exportAllBtn) {
                const sections = [];
                const currentDocFilter = document.getElementById('docStatusFilter')?.value || 'all';
                const exportMetaMap = {
                    all: {
                        title: 'Daftar User',
                        empty: 'Tidak ada data untuk diexport.',
                        filename: `daftar_medis_${exportTimestamp()}.txt`
                    },
                    missing_ktp: {
                        title: 'Daftar User Belum Upload KTP',
                        empty: 'Tidak ada user yang belum upload KTP untuk diexport.',
                        filename: `daftar_user_belum_upload_ktp_${exportTimestamp()}.txt`
                    },
                    missing_sim: {
                        title: 'Daftar User Belum Upload SIM',
                        empty: 'Tidak ada user yang belum upload SIM untuk diexport.',
                        filename: `daftar_user_belum_upload_sim_${exportTimestamp()}.txt`
                    },
                    missing_kta: {
                        title: 'Daftar User Belum Upload KTA',
                        empty: 'Tidak ada user yang belum upload KTA untuk diexport.',
                        filename: `daftar_user_belum_upload_kta_${exportTimestamp()}.txt`
                    },
                    missing_skb: {
                        title: 'Daftar User Belum Upload SKB',
                        empty: 'Tidak ada user yang belum upload SKB untuk diexport.',
                        filename: `daftar_user_belum_upload_skb_${exportTimestamp()}.txt`
                    },
                    missing_sertifikat_heli: {
                        title: 'Daftar User Belum Upload Sertifikat Heli',
                        empty: 'Tidak ada user yang belum upload Sertifikat Heli untuk diexport.',
                        filename: `daftar_user_belum_upload_sertifikat_heli_${exportTimestamp()}.txt`
                    },
                    missing_sertifikat_operasi: {
                        title: 'Daftar User Belum Upload Sertifikat Operasi',
                        empty: 'Tidak ada user yang belum upload Sertifikat Operasi untuk diexport.',
                        filename: `daftar_user_belum_upload_sertifikat_operasi_${exportTimestamp()}.txt`
                    },
                    missing_dokumen_lainnya: {
                        title: 'Daftar User Belum Upload Dokumen Lainnya',
                        empty: 'Tidak ada user yang belum upload Dokumen Lainnya untuk diexport.',
                        filename: `daftar_user_belum_upload_dokumen_lainnya_${exportTimestamp()}.txt`
                    }
                };
                const exportMeta = exportMetaMap[currentDocFilter] || exportMetaMap.all;

                document.querySelectorAll('.user-batch-table').forEach(function(table) {
                    const batchCard = table.closest('.batch-card');
                    if (batchCard && window.getComputedStyle(batchCard).display === 'none') {
                        return;
                    }

                    withExpandedTable(table, function() {
                        const rows = collectVisibleRows(table);
                        if (!rows.length) {
                            return;
                        }

                        const batchCardHeader = batchCard?.querySelector('.batch-card-header')?.innerText || '';
                        const batchMatch = batchCardHeader.match(/Batch\s+(\d+)/i);
                        const sectionTitle = batchMatch ? `Batch ${batchMatch[1]}` : 'Tanpa Batch';
                        const sectionLines = [];
                        let no = 1;

                        rows.forEach(function(row) {
                            const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
                            const noStr = String(no).padStart(2, '0');
                            sectionLines.push(`${noStr}. ${nama}`);
                            no++;
                        });

                        sections.push(`${sectionTitle}\n${sectionLines.join('\n')}`);
                    });
                });

                if (!sections.length) {
                    alert(exportMeta.empty);
                    return;
                }

                downloadText(exportMeta.filename, exportMeta.title + '\n\n' + sections.join('\n\n') + '\n');
                return;
            }

            const exportNoBatchBtn = e.target.closest('#btnExportTanpaBatch');
            if (exportNoBatchBtn) {
                const batchCard = exportNoBatchBtn.closest('.batch-card');
                const table = batchCard ? batchCard.querySelector('.user-batch-table') : null;
                if (!table) {
                    alert('Tabel Tanpa Batch tidak ditemukan.');
                    return;
                }

                const lines = withExpandedTable(table, function() {
                    const rows = collectVisibleRows(table);
                    let no = 1;

                    return rows.map(function(row) {
                        const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
                        const noStr = String(no++).padStart(2, '0');
                        return `${noStr}. ${nama}`;
                    });
                });

                if (!lines.length) {
                    alert('Tidak ada data Tanpa Batch untuk diexport.');
                    return;
                }

                downloadText(`tanpa_batch_${exportTimestamp()}.txt`, 'Tanpa Batch\n' + lines.join('\n') + '\n');
            }
        });
    });
</script>


	<?php include __DIR__ . '/../partials/footer.php'; ?>

