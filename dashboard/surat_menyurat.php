<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Surat Masuk, Keluar, dan Notulen';
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = $user['role'] ?? '';
$medicName = $user['name'] ?? 'User';
$medicJabatan = ems_position_label($user['position'] ?? '');
$avatarInitials = initialsFromName($medicName);
$avatarColor = avatarColorFromName($medicName);
$canAcknowledge = ems_is_letter_receiver_role($userRole);

if (!$canAcknowledge) {
    http_response_code(403);
    exit('Akses ditolak');
}

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

$summary = [
    'incoming_unread' => 0,
    'incoming_read' => 0,
    'outgoing_total' => 0,
    'minutes_total' => 0,
];
$incomingRows = [];
$outgoingRows = [];
$minutesRows = [];
$linkableIncoming = [];
$linkableOutgoing = [];

try {
    $summary['incoming_unread'] = (int)$pdo->query("SELECT COUNT(*) FROM incoming_letters WHERE status = 'unread'")->fetchColumn();
    $summary['incoming_read'] = (int)$pdo->query("SELECT COUNT(*) FROM incoming_letters WHERE status = 'read'")->fetchColumn();
    $summary['outgoing_total'] = (int)$pdo->query("SELECT COUNT(*) FROM outgoing_letters")->fetchColumn();
    $summary['minutes_total'] = (int)$pdo->query("SELECT COUNT(*) FROM meeting_minutes")->fetchColumn();

    $stmt = $pdo->query("
        SELECT
            l.*,
            rb.full_name AS read_by_name
        FROM incoming_letters l
        LEFT JOIN user_rh rb ON rb.id = l.read_by
        ORDER BY
            CASE l.status WHEN 'unread' THEN 0 ELSE 1 END,
            l.submitted_at DESC
        LIMIT 100
    ");
    $incomingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT
            o.*,
            u.full_name AS created_by_name
        FROM outgoing_letters o
        LEFT JOIN user_rh u ON u.id = o.created_by
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $outgoingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT
            m.*,
            u.full_name AS created_by_name
        FROM meeting_minutes m
        LEFT JOIN user_rh u ON u.id = m.created_by
        ORDER BY m.meeting_date DESC, m.meeting_time DESC, m.created_at DESC
        LIMIT 100
    ");
    $minutesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT id, letter_code, institution_name, meeting_topic
        FROM incoming_letters
        ORDER BY submitted_at DESC
        LIMIT 100
    ");
    $linkableIncoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT id, outgoing_code, institution_name, subject
        FROM outgoing_letters
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $linkableOutgoing = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Tabel surat/notulen belum siap. Jalankan SQL baru terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="section-intro">Pendataan surat masuk dari instansi, pencatatan surat keluar, dan notulen hasil pertemuan.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars((string)$warning) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Surat Masuk Belum Dibaca</div>
                <div class="text-2xl font-extrabold text-amber-700"><?= (int)$summary['incoming_unread'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Surat Keluar Tercatat</div>
                <div class="text-2xl font-extrabold text-primary"><?= (int)$summary['outgoing_total'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Notulen Pertemuan</div>
                <div class="text-2xl font-extrabold text-success"><?= (int)$summary['minutes_total'] ?></div>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header-between">
                <div>
                    <div class="card-header">Link Form Publik</div>
                    <p class="muted-copy-tight">Bagikan link ini ke instansi yang ingin membuat janji pertemuan.</p>
                </div>
                <a href="<?= htmlspecialchars(ems_url('/surat_instansi.php')) ?>" target="_blank" rel="noopener" class="btn-secondary">
                    <?= ems_icon('document-text', 'h-4 w-4') ?> <span>Buka Form Publik</span>
                </a>
            </div>
            <div class="mt-2 font-mono text-sm"><?= htmlspecialchars(ems_url('/surat_instansi.php')) ?></div>
        </div>

        <div class="card card-section">
            <div class="card-header">Surat Masuk</div>
            <div class="table-wrapper table-wrapper-sm">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Instansi</th>
                            <th>Pengirim</th>
                            <th>Agenda</th>
                            <th>Jadwal Temu</th>
                            <th>Tujuan</th>
                            <th>Status</th>
                            <th>Diterima</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$incomingRows): ?>
                            <tr>
                                <td colspan="9" class="muted-placeholder">Belum ada surat masuk.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($incomingRows as $row): ?>
                                <?php
                                $rowUnread = ($row['status'] ?? '') === 'unread';
                                $canReadThis = $rowUnread && ($canAcknowledge || (int)($row['target_user_id'] ?? 0) === $userId);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['letter_code']) ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)$row['submitted_at']) ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['institution_name']) ?></strong>
                                        <?php if (!empty($row['notes'])): ?>
                                            <div class="meta-text-xs whitespace-pre-line"><?= htmlspecialchars((string)$row['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string)$row['sender_name']) ?>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)$row['sender_phone']) ?></div>
                                    </td>
                                    <td class="whitespace-pre-line"><?= htmlspecialchars((string)$row['meeting_topic']) ?></td>
                                    <td>
                                        <?= htmlspecialchars((string)$row['appointment_date']) ?><br>
                                        <span class="meta-text-xs"><?= htmlspecialchars(substr((string)$row['appointment_time'], 0, 5)) ?> WIB</span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string)$row['target_name_snapshot']) ?>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)$row['target_role_snapshot']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-counter"><?= htmlspecialchars(strtoupper((string)$row['status'])) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['read_by_name'])): ?>
                                            <strong><?= htmlspecialchars((string)$row['read_by_name']) ?></strong>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string)$row['read_at']) ?></div>
                                        <?php else: ?>
                                            <span class="meta-text-xs">Belum diterima</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canReadThis): ?>
                                            <form method="POST" action="surat_menyurat_action.php" class="inline">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="mark_incoming_read">
                                                <input type="hidden" name="letter_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn-success">
                                                    <?= ems_icon('check-circle', 'h-4 w-4') ?> <span>Tandai Dibaca</span>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="meta-text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="card card-section">
                <div class="card-header">Input Surat Keluar</div>
                <form method="POST" action="surat_menyurat_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="add_outgoing_letter">

                    <label>Relasi Surat Masuk</label>
                    <select name="incoming_letter_id">
                        <option value="">-- Opsional --</option>
                        <?php foreach ($linkableIncoming as $incoming): ?>
                            <option value="<?= (int)$incoming['id'] ?>">
                                <?= htmlspecialchars($incoming['letter_code'] . ' — ' . $incoming['institution_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="row-form-2">
                        <div class="col">
                            <label>Nama Instansi</label>
                            <input type="text" name="institution_name" maxlength="160" required>
                        </div>
                        <div class="col">
                            <label>Nama Tujuan / Kontak</label>
                            <input type="text" name="recipient_name" maxlength="160">
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div class="col">
                            <label>Kontak Tujuan</label>
                            <input type="text" name="recipient_contact" maxlength="64">
                        </div>
                        <div class="col">
                            <label>Subjek Surat</label>
                            <input type="text" name="subject" maxlength="255" required>
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div class="col">
                            <label>Tanggal Temu / Kirim</label>
                            <input type="date" name="appointment_date">
                        </div>
                        <div class="col">
                            <label>Jam</label>
                            <input type="time" name="appointment_time">
                        </div>
                    </div>

                    <label>Isi Surat / Ringkasan Tindak Lanjut</label>
                    <textarea name="letter_body" rows="5" required></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success"><?= ems_icon('document-text', 'h-4 w-4') ?> <span>Simpan Surat Keluar</span></button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Input Notulen Pertemuan</div>
                <form method="POST" action="surat_menyurat_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="add_meeting_minutes">

                    <div class="row-form-2">
                        <div class="col">
                            <label>Relasi Surat Masuk</label>
                            <select name="incoming_letter_id">
                                <option value="">-- Opsional --</option>
                                <?php foreach ($linkableIncoming as $incoming): ?>
                                    <option value="<?= (int)$incoming['id'] ?>">
                                        <?= htmlspecialchars($incoming['letter_code'] . ' — ' . $incoming['institution_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <label>Relasi Surat Keluar</label>
                            <select name="outgoing_letter_id">
                                <option value="">-- Opsional --</option>
                                <?php foreach ($linkableOutgoing as $outgoing): ?>
                                    <option value="<?= (int)$outgoing['id'] ?>">
                                        <?= htmlspecialchars($outgoing['outgoing_code'] . ' — ' . $outgoing['institution_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label>Judul Pertemuan</label>
                    <input type="text" name="meeting_title" maxlength="255" required>

                    <div class="row-form-2">
                        <div class="col">
                            <label>Tanggal Pertemuan</label>
                            <input type="date" name="meeting_date" required>
                        </div>
                        <div class="col">
                            <label>Jam Pertemuan</label>
                            <input type="time" name="meeting_time" required>
                        </div>
                    </div>

                    <label>Peserta</label>
                    <textarea name="participants" rows="3" required></textarea>

                    <label>Hasil Notulen</label>
                    <textarea name="summary" rows="4" required></textarea>

                    <label>Keputusan</label>
                    <textarea name="decisions" rows="3"></textarea>

                    <label>Tindak Lanjut</label>
                    <textarea name="follow_up" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success"><?= ems_icon('clipboard-document-list', 'h-4 w-4') ?> <span>Simpan Notulen</span></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div class="card card-section">
                <div class="card-header">Riwayat Surat Keluar</div>
                <div class="table-wrapper table-wrapper-sm">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Instansi</th>
                                <th>Subjek</th>
                                <th>Jadwal</th>
                                <th>Dibuat Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$outgoingRows): ?>
                                <tr>
                                    <td colspan="5" class="muted-placeholder">Belum ada surat keluar.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($outgoingRows as $row): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$row['outgoing_code']) ?></strong>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string)$row['created_at']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars((string)$row['institution_name']) ?></td>
                                        <td class="whitespace-pre-line"><?= htmlspecialchars((string)$row['subject']) ?></td>
                                        <td>
                                            <?= htmlspecialchars((string)($row['appointment_date'] ?: '-')) ?><br>
                                            <span class="meta-text-xs"><?= htmlspecialchars($row['appointment_time'] ? substr((string)$row['appointment_time'], 0, 5) . ' WIB' : '-') ?></span>
                                        </td>
                                        <td><?= htmlspecialchars((string)($row['created_by_name'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card card-section">
                <div class="card-header">Riwayat Notulen</div>
                <div class="table-wrapper table-wrapper-sm">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Pertemuan</th>
                                <th>Tanggal</th>
                                <th>Peserta</th>
                                <th>Hasil</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$minutesRows): ?>
                                <tr>
                                    <td colspan="4" class="muted-placeholder">Belum ada notulen.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($minutesRows as $row): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$row['meeting_title']) ?></strong>
                                            <div class="meta-text-xs">Oleh <?= htmlspecialchars((string)($row['created_by_name'] ?? '-')) ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars((string)$row['meeting_date']) ?><br>
                                            <span class="meta-text-xs"><?= htmlspecialchars(substr((string)$row['meeting_time'], 0, 5)) ?> WIB</span>
                                        </td>
                                        <td class="whitespace-pre-line"><?= htmlspecialchars((string)$row['participants']) ?></td>
                                        <td class="whitespace-pre-line"><?= htmlspecialchars((string)$row['summary']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>