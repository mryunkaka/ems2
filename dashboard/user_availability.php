<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/training_groups.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
$pageTitle = 'Availability User';
$currentUnit = ems_effective_unit($pdo, $user);

if (ems_training_groups_tables_ready($pdo) && ems_training_availability_tables_ready($pdo)) {
    ems_training_auto_fill_groups($pdo, $currentUnit, null);
}

$currentStatus = 'offline';
$onlineUsers = [];
if (ems_training_availability_tables_ready($pdo)) {
    $statusStmt = $pdo->prepare("
        SELECT status
        FROM training_user_availability
        WHERE user_id = ?
        LIMIT 1
    ");
    $statusStmt->execute([(int)($user['id'] ?? 0)]);
    $currentStatus = (string)($statusStmt->fetchColumn() ?: 'offline');

    $onlineUsersStmt = $pdo->prepare("
        SELECT
            ur.id,
            ur.full_name,
            ur.role,
            ur.position,
            ur.batch,
            ur.division,
            tua.status,
            (
                SELECT COALESCE(TIMESTAMPDIFF(SECOND, session_start, NOW()), 0)
                FROM training_user_availability_sessions
                WHERE user_id = ur.id
                  AND session_end IS NULL
                ORDER BY session_start DESC
                LIMIT 1
            ) AS current_online_seconds
        FROM user_rh ur
        JOIN training_user_availability tua ON tua.user_id = ur.id
        WHERE tua.status = 'online'
          AND ur.is_active = 1
          AND COALESCE(ur.unit_code, 'roxwood') = ?
        ORDER BY ur.role DESC, ur.full_name ASC
    ");
    $onlineUsersStmt->execute([$currentUnit]);
    $onlineUsers = $onlineUsersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$assignments = ems_training_fetch_user_active_assignments($pdo, (int)($user['id'] ?? 0));

foreach ($onlineUsers as &$onlineUser) {
    $seconds = (int)($onlineUser['current_online_seconds'] ?? 0);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $onlineUser['online_text'] = $hours . 'j ' . $minutes . 'm';
    $onlineUser['role_label'] = ems_role_label($onlineUser['role'] ?? '');
    $onlineUser['position_label'] = ems_position_label($onlineUser['position'] ?? '');
    $onlineUser['division_label'] = ems_normalize_division($onlineUser['division'] ?? '');
}
unset($onlineUser);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <div class="card mb-4">
            <div class="card-header-between">
                <div>
                    <h1 class="page-title">Availability User RH</h1>
                    <p class="page-subtitle">Status online ini dipakai untuk menentukan siapa yang tersedia masuk kelompok aktif.</p>
                </div>
                <span id="availabilityBadge" class="<?= $currentStatus === 'online' ? 'badge-success' : 'badge-secondary' ?>">
                    <?= $currentStatus === 'online' ? 'Sedang Tersedia' : 'Belum Tersedia' ?>
                </span>
            </div>
        </div>

        <?php if (!ems_training_availability_tables_ready($pdo)): ?>
            <div class="alert alert-danger mb-4">Jalankan SQL <code>docs/sql/40_2026-05-16_training_user_availability.sql</code> terlebih dahulu agar availability kelompok aktif.</div>
        <?php endif; ?>

        <div class="grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)]">
            <div class="space-y-4">
                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('signal', 'h-5 w-5') ?>
                        <span>Status Saya</span>
                    </div>
                    <div class="space-y-4">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="meta-text">User</div>
                            <div class="text-lg font-bold text-slate-900 mt-2"><?= htmlspecialchars((string)($user['full_name'] ?? $user['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="meta-text mt-1"><?= htmlspecialchars(ems_role_label($user['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars(ems_position_label($user['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <button type="button" id="toggleAvailabilityBtn" data-status="<?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentStatus === 'online' ? 'btn-danger' : 'btn-primary' ?>">
                            <?= $currentStatus === 'online' ? 'Set Offline' : 'Set Online' ?>
                        </button>
                        <div id="availabilityMessage" class="meta-text text-slate-600"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('user-group', 'h-5 w-5') ?>
                        <span>Grup Aktif Saya</span>
                    </div>
                    <div id="availabilityAssignments" class="space-y-3">
                        <?php if ($assignments === []): ?>
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                Belum ada penempatan grup aktif.
                            </div>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$assignment['group_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="meta-text mt-1"><?= htmlspecialchars((string)$assignment['group_code'], ENT_QUOTES, 'UTF-8') ?> • Batch <?= (int)$assignment['batch'] ?> • <?= htmlspecialchars(ucfirst((string)$assignment['member_role']), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <?= ems_icon('table-cells', 'h-5 w-5') ?>
                    <span>User RH Yang Sedang Online</span>
                </div>
                <div class="table-wrapper">
                    <table class="table-custom" data-auto-datatable="true" data-dt-page-length="10" data-dt-order="[[0,&quot;asc&quot;]]">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Role</th>
                                <th>Jabatan</th>
                                <th>Batch</th>
                                <th>Durasi Online</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($onlineUsers as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text"><?= htmlspecialchars((string)$row['division_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string)$row['role_label'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)$row['position_label'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int)($row['batch'] ?? 0) > 0 ? (int)$row['batch'] : '-' ?></td>
                                    <td><?= htmlspecialchars((string)$row['online_text'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($onlineUsers === []): ?>
                                <tr><td colspan="5" class="text-center text-slate-500">Belum ada user yang online.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggleButton = document.getElementById('toggleAvailabilityBtn');
    var messageBox = document.getElementById('availabilityMessage');
    var badge = document.getElementById('availabilityBadge');

    if (!toggleButton) {
        return;
    }

    toggleButton.addEventListener('click', async function () {
        var currentStatus = toggleButton.dataset.status === 'online' ? 'online' : 'offline';
        var nextStatus = currentStatus === 'online' ? 'offline' : 'online';

        toggleButton.disabled = true;
        messageBox.textContent = 'Memproses status availability...';

        try {
            var response = await fetch(window.emsUrl('/actions/toggle_user_availability.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ status: nextStatus })
            });

            var json = await response.json();
            if (!json || json.success !== true) {
                throw new Error(json && json.message ? json.message : 'Gagal memperbarui availability.');
            }

            window.location.reload();
        } catch (error) {
            messageBox.textContent = error.message || 'Gagal memperbarui availability.';
            toggleButton.disabled = false;
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
