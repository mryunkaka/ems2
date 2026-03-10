<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');

$pageTitle = 'Point Pelanggaran';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$indications = [];

try {
    $stmt = $pdo->query("
        SELECT id, code, name, description, default_points, tolerance_type, is_active, updated_at
        FROM disciplinary_indications
        ORDER BY is_active DESC, default_points DESC, name ASC
    ");
    $indications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat master indikasi: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="page-subtitle">Kelola master indikasi pelanggaran, point default, dan kategori toleransi.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 lg:grid-cols-[360px_minmax(0,1fr)] gap-4">
            <div class="card">
                <div class="card-header">Tambah Indikasi</div>
                <form method="POST" action="disciplinary_committee_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_indication">
                    <input type="hidden" name="redirect_to" value="disciplinary_indications.php">

                    <label for="newIndicationName">Nama Indikasi</label>
                    <input type="text" id="newIndicationName" name="name" required>

                    <label for="newIndicationPoints">Default Point</label>
                    <input type="number" id="newIndicationPoints" name="default_points" min="0" required>

                    <label for="newIndicationTolerance">Toleransi</label>
                    <select id="newIndicationTolerance" name="tolerance_type" required>
                        <?php foreach (ems_disciplinary_tolerance_options() as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="newIndicationDescription">Deskripsi</label>
                    <textarea id="newIndicationDescription" name="description" rows="4" placeholder="Jelaskan ruang lingkup indikasi ini"></textarea>

                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="is_active" checked>
                        <span>Aktif</span>
                    </label>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Indikasi</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Daftar Indikasi</div>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Point</th>
                                <th>Toleransi</th>
                                <th>Status</th>
                                <th>Deskripsi</th>
                                <th>Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($indications as $row): ?>
                                <tr>
                                    <td colspan="6">
                                        <form method="POST" action="disciplinary_committee_action.php" class="grid grid-cols-1 md:grid-cols-[1.4fr_110px_160px_110px_1.4fr_120px] gap-3 items-start">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="save_indication">
                                            <input type="hidden" name="redirect_to" value="disciplinary_indications.php">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

                                            <div>
                                                <input type="text" name="name" value="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>" required>
                                                <div class="meta-text-xs mt-1"><?= htmlspecialchars($row['code']) ?></div>
                                            </div>

                                            <div>
                                                <input type="number" name="default_points" value="<?= (int)$row['default_points'] ?>" min="0" required>
                                            </div>

                                            <div>
                                                <select name="tolerance_type" required>
                                                    <?php foreach (ems_disciplinary_tolerance_options() as $value => $label): ?>
                                                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= ($row['tolerance_type'] === $value) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="pt-2">
                                                <label class="inline-flex items-center gap-2">
                                                    <input type="checkbox" name="is_active" <?= ((int)$row['is_active'] === 1) ? 'checked' : '' ?>>
                                                    <span><?= ((int)$row['is_active'] === 1) ? 'Aktif' : 'Nonaktif' ?></span>
                                                </label>
                                            </div>

                                            <div>
                                                <textarea name="description" rows="2" placeholder="Deskripsi"><?= htmlspecialchars((string)($row['description'] ?? '')) ?></textarea>
                                            </div>

                                            <div>
                                                <button type="submit" class="btn-secondary w-full">Update</button>
                                                <div class="meta-text-xs mt-1"><?= htmlspecialchars(formatTanggalID($row['updated_at'])) ?></div>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$indications): ?>
                        <div class="muted-placeholder p-4">Belum ada master indikasi.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
