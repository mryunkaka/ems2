<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
$role = strtolower(trim((string)($user['role'] ?? '')));
$division = ems_normalize_division($user['division'] ?? '');

if ($role === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

ems_require_division_access(['General Affair'], '/dashboard/index.php');

$pageTitle = 'Blacklist Citizen ID';
$effectiveUnit = ems_effective_unit($pdo, $user);
$tableReady = ems_table_exists($pdo, 'consumer_blacklist');

function blacklistNameDisplay(?string $name): string
{
    return ems_normalize_citizen_id($name);
}

function blacklistNameKey(?string $name): string
{
    return blacklistNameDisplay($name);
}

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create') {
            $consumerName = blacklistNameDisplay($_POST['consumer_name'] ?? '');
            $consumerKey = blacklistNameKey($consumerName);
            $note = trim((string)($_POST['note'] ?? ''));

            if ($consumerName === '' || !ems_looks_like_citizen_id($consumerName)) {
                throw new RuntimeException('Citizen ID wajib diisi dengan format yang valid.');
            }

            $stmtExisting = $pdo->prepare("
                SELECT id
                FROM consumer_blacklist
                WHERE consumer_name_key = ?
                LIMIT 1
            ");
            $stmtExisting->execute([$consumerKey]);
            $existingId = (int)($stmtExisting->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE consumer_blacklist
                    SET unit_code = ?,
                        consumer_name = ?,
                        note = ?,
                        is_active = 1,
                        updated_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $effectiveUnit,
                    $consumerName,
                    $note !== '' ? $note : null,
                    (int)($user['id'] ?? 0),
                    $existingId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO consumer_blacklist (
                        unit_code,
                        consumer_name,
                        consumer_name_key,
                        note,
                        is_active,
                        created_by,
                        updated_by
                    ) VALUES (?, ?, ?, ?, 1, ?, ?)
                ");
                $stmt->execute([
                    $effectiveUnit,
                    $consumerName,
                    $consumerKey,
                    $note !== '' ? $note : null,
                    (int)($user['id'] ?? 0),
                    (int)($user['id'] ?? 0),
                ]);
            }

            $_SESSION['flash_messages'][] = 'Citizen ID berhasil dimasukkan ke blacklist global.';
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $consumerName = blacklistNameDisplay($_POST['consumer_name'] ?? '');
            $consumerKey = blacklistNameKey($consumerName);
            $note = trim((string)($_POST['note'] ?? ''));

            if ($id <= 0 || $consumerName === '' || !ems_looks_like_citizen_id($consumerName)) {
                throw new RuntimeException('Data blacklist tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE consumer_blacklist
                SET unit_code = ?,
                    consumer_name = ?,
                    consumer_name_key = ?,
                    note = ?,
                    updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $effectiveUnit,
                $consumerName,
                $consumerKey,
                $note !== '' ? $note : null,
                (int)($user['id'] ?? 0),
                $id,
            ]);

            $_SESSION['flash_messages'][] = 'Blacklist berhasil diperbarui.';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $targetState = (int)($_POST['target_state'] ?? 0) === 1 ? 1 : 0;

            if ($id <= 0) {
                throw new RuntimeException('Data blacklist tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE consumer_blacklist
                SET is_active = ?,
                    updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $targetState,
                (int)($user['id'] ?? 0),
                $id,
            ]);

            $_SESSION['flash_messages'][] = $targetState === 1
                ? 'Blacklist diaktifkan.'
                : 'Blacklist dinonaktifkan.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = $e->getMessage();
    }

    header('Location: blacklist_names.php');
    exit;
}

$blacklistRows = [];
if ($tableReady) {
    $stmt = $pdo->prepare("
        SELECT id, unit_code, consumer_name, consumer_name_key, note, is_active, created_at, updated_at
        FROM consumer_blacklist
        ORDER BY is_active DESC, consumer_name ASC
    ");
    $stmt->execute();
    $blacklistRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Blacklist Citizen ID</h1>
        <p class="page-subtitle">Global &bull; Pemblokiran Citizen ID Konsumen farmasi lintas unit</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($warning) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <?php if (!$tableReady): ?>
            <div class="alert alert-warning">
                Tabel blacklist belum siap. Jalankan SQL `docs/sql/14_2026-03-29_consumer_blacklist.sql` terlebih dahulu.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                Blacklist ini berlaku global untuk semua unit. Jika Citizen ID diblacklist dari Alta, Citizen ID yang sama juga otomatis diblokir di Roxwood, dan sebaliknya.
            </div>
            <div class="card">
                <div class="card-header card-header-actions card-header-flex">
                    <div class="card-header-actions-title">
                        <?= ems_icon('no-symbol', 'h-5 w-5') ?> Daftar Citizen ID Blacklist
                    </div>
                    <button type="button" id="openAddBlacklistModal" class="btn-danger">
                        <?= ems_icon('plus', 'h-4 w-4') ?> <span>Tambah Blacklist</span>
                    </button>
                </div>

                <div class="table-wrapper">
                    <table id="blacklistTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Citizen ID</th>
                                <th>Unit Input</th>
                                <th>Note</th>
                                <th>Status</th>
                                <th>Dibuat</th>
                                <th>Diubah</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blacklistRows as $row): ?>
                                <tr
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= htmlspecialchars((string)$row['consumer_name'], ENT_QUOTES) ?>"
                                    data-note="<?= htmlspecialchars((string)($row['note'] ?? ''), ENT_QUOTES) ?>"
                                    data-active="<?= (int)$row['is_active'] ?>">
                                    <td><?= htmlspecialchars((string)$row['consumer_name']) ?></td>
                                    <td><?= htmlspecialchars(ems_unit_label((string)($row['unit_code'] ?? 'roxwood'))) ?></td>
                                    <td><?= htmlspecialchars((string)($row['note'] ?? '-')) ?></td>
                                    <td>
                                        <span class="badge-counter<?= (int)$row['is_active'] === 1 ? '' : ' badge-muted' ?>">
                                            <?= (int)$row['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                    <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string)$row['updated_at']))) ?></td>
                                    <td class="table-actions">
                                        <button type="button" class="btn-secondary btn-edit-blacklist">Ubah</button>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="target_state" value="<?= (int)$row['is_active'] === 1 ? '0' : '1' ?>">
                                            <button type="submit" class="<?= (int)$row['is_active'] === 1 ? 'btn-warning' : 'btn-success' ?>">
                                                <?= (int)$row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($tableReady): ?>
    <div id="addBlacklistModal" class="modal-overlay hidden">
            <div class="modal-box modal-shell modal-frame-md">
                <div class="modal-head">
                <div class="modal-title">Tambah Blacklist Citizen ID</div>
                <button type="button" class="modal-close-btn btn-add-cancel" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <form method="post" id="addBlacklistForm" class="form modal-form">
                <div class="modal-content">
                    <input type="hidden" name="action" value="create">

                    <label><?= htmlspecialchars(ems_consumer_identifier_label()) ?></label>
                    <input type="text" name="consumer_name" id="addBlacklistName" autocomplete="off" autocapitalize="characters" spellcheck="false" required>

                    <label>Note Blacklist</label>
                    <textarea name="note" id="addBlacklistNote" rows="4" placeholder="Contoh: indikasi penyalahgunaan / wajib konfirmasi ke GA"></textarea>
                </div>

                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary btn-add-cancel">Batal</button>
                        <button type="submit" class="btn-danger">Simpan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="editBlacklistModal" class="modal-overlay hidden">
            <div class="modal-box modal-shell modal-frame-md">
                <div class="modal-head">
                <div class="modal-title">Ubah Blacklist Citizen ID</div>
                <button type="button" class="modal-close-btn btn-edit-cancel" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <form method="post" id="editBlacklistForm" class="form modal-form">
                <div class="modal-content">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editBlacklistId">

                    <label><?= htmlspecialchars(ems_consumer_identifier_label()) ?></label>
                    <input type="text" name="consumer_name" id="editBlacklistName" autocomplete="off" autocapitalize="characters" spellcheck="false" required>

                    <label>Note Blacklist</label>
                    <textarea name="note" id="editBlacklistNote" rows="4"></textarea>
                </div>

                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary btn-edit-cancel">Batal</button>
                        <button type="submit" class="btn-success">Simpan Perubahan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.jQuery && jQuery.fn.DataTable) {
                jQuery('#blacklistTable').DataTable({
                    pageLength: 10,
                    language: {
                        url: '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
                    }
                });
            }

            const addModal = document.getElementById('addBlacklistModal');
            const editModal = document.getElementById('editBlacklistModal');
            const openAddBtn = document.getElementById('openAddBlacklistModal');
            const editId = document.getElementById('editBlacklistId');
            const editName = document.getElementById('editBlacklistName');
            const editNote = document.getElementById('editBlacklistNote');

            function openModal(modal) {
                if (!modal) return;
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            }

            function closeModal(modal) {
                if (!modal) return;
                modal.style.display = 'none';
                modal.classList.add('hidden');
                document.body.classList.remove('modal-open');
            }

            openAddBtn?.addEventListener('click', function() {
                const form = document.getElementById('addBlacklistForm');
                if (form) form.reset();
                openModal(addModal);
            });

            document.querySelectorAll('.btn-add-cancel').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    closeModal(addModal);
                });
            });

            document.querySelectorAll('.btn-edit-cancel').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    closeModal(editModal);
                });
            });

            document.body.addEventListener('click', function(event) {
                const btn = event.target.closest('.btn-edit-blacklist');
                if (!btn) return;

                const row = btn.closest('tr');
                if (!row) return;

                editId.value = row.dataset.id || '';
                editName.value = row.dataset.name || '';
                editNote.value = row.dataset.note || '';
                openModal(editModal);
            });
        });
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
