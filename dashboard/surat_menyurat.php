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
$canManageRecords = ems_is_letter_receiver_role($userRole);

function surat_excerpt(?string $text, int $limit = 120): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '-';
    }

    $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit - 3)) . '...';
}

function surat_group_attachments(array $rows, string $foreignKey): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $key = (int)($row[$foreignKey] ?? 0);
        if ($key <= 0) {
            continue;
        }
        $grouped[$key][] = $row;
    }
    return $grouped;
}

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
$incomingAttachmentsMap = [];
$outgoingAttachmentsMap = [];

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

    $incomingIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $incomingRows)));
    if (!empty($incomingIds)) {
        $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM incoming_letter_attachments
            WHERE incoming_letter_id IN ($placeholders)
            ORDER BY incoming_letter_id ASC, sort_order ASC, id ASC
        ");
        $stmt->execute($incomingIds);
        $incomingAttachmentsMap = surat_group_attachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'incoming_letter_id');
    }

    $outgoingIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $outgoingRows)));
    if (!empty($outgoingIds)) {
        $placeholders = implode(',', array_fill(0, count($outgoingIds), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM outgoing_letter_attachments
            WHERE outgoing_letter_id IN ($placeholders)
            ORDER BY outgoing_letter_id ASC, sort_order ASC, id ASC
        ");
        $stmt->execute($outgoingIds);
        $outgoingAttachmentsMap = surat_group_attachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'outgoing_letter_id');
    }
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
                <table id="incomingLettersTable" class="table-custom">
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
                            <th>Lampiran</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($incomingRows): ?>
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
                                        <?php $attachments = $incomingAttachmentsMap[(int)$row['id']] ?? []; ?>
                                        <?php if (!empty($attachments)): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <a href="#"
                                                        class="doc-badge btn-preview-doc"
                                                        data-src="/<?= htmlspecialchars(ltrim((string)$attachment['file_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-title="<?= htmlspecialchars((string)($attachment['file_name'] ?: ('Lampiran ' . $row['letter_code'])), ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= ems_icon('paper-clip', 'h-4 w-4') ?>
                                                        <span><?= htmlspecialchars((string)($attachment['file_name'] ?: 'Lampiran')) ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="meta-text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-2">
                                            <?php if ($canReadThis): ?>
                                                <form method="POST" action="surat_menyurat_action.php" class="inline">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="action" value="mark_incoming_read">
                                                    <input type="hidden" name="letter_id" value="<?= (int)$row['id'] ?>">
                                                    <button type="submit" class="btn-success">
                                                        <?= ems_icon('check-circle', 'h-4 w-4') ?> <span>Tandai Dibaca</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($canManageRecords): ?>
                                                <form method="POST" action="surat_menyurat_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus surat masuk ini? Relasi surat keluar dan notulen akan dilepas.">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_incoming_letter">
                                                    <input type="hidden" name="letter_id" value="<?= (int)$row['id'] ?>">
                                                    <button type="submit" class="btn-danger">
                                                        <?= ems_icon('trash', 'h-4 w-4') ?> <span>Hapus</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (!$canReadThis && !$canManageRecords): ?>
                                                <span class="meta-text-xs">-</span>
                                            <?php endif; ?>
                                        </div>
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
                <form method="POST" action="surat_menyurat_action.php" enctype="multipart/form-data" class="form">
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

                    <div class="doc-upload-wrapper m-0">
                        <div class="doc-upload-header">
                            <label class="text-sm font-semibold text-slate-900">Lampiran Surat Keluar</label>
                            <span class="badge-muted-mini">Opsional, bisa beberapa file</span>
                        </div>
                        <div class="doc-upload-input">
                            <label for="outgoingAttachments" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih lampiran</strong>
                                    <small>JPG / PNG, multi file</small>
                                </span>
                            </label>
                            <input type="file" id="outgoingAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                            <div class="file-selected-name" data-for="outgoingAttachments"></div>
                            <div id="outgoingAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                        </div>
                    </div>

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
                    <table id="outgoingLettersTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Instansi</th>
                                <th>Subjek</th>
                                <th>Jadwal</th>
                                <th>Dibuat Oleh</th>
                                <th>Lampiran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($outgoingRows): ?>
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
                                        <td>
                                            <?php $attachments = $outgoingAttachmentsMap[(int)$row['id']] ?? []; ?>
                                            <?php if (!empty($attachments)): ?>
                                                <div class="flex flex-wrap gap-2">
                                                    <?php foreach ($attachments as $attachment): ?>
                                                        <a href="#"
                                                            class="doc-badge btn-preview-doc"
                                                            data-src="/<?= htmlspecialchars(ltrim((string)$attachment['file_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-title="<?= htmlspecialchars((string)($attachment['file_name'] ?: ('Lampiran ' . $row['outgoing_code'])), ENT_QUOTES, 'UTF-8') ?>">
                                                            <?= ems_icon('paper-clip', 'h-4 w-4') ?>
                                                            <span><?= htmlspecialchars((string)($attachment['file_name'] ?: 'Lampiran')) ?></span>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="meta-text-xs">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($canManageRecords): ?>
                                                <form method="POST" action="surat_menyurat_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus surat keluar ini? Relasi notulen akan dilepas.">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_outgoing_letter">
                                                    <input type="hidden" name="letter_id" value="<?= (int)$row['id'] ?>">
                                                    <button type="submit" class="btn-danger">
                                                        <?= ems_icon('trash', 'h-4 w-4') ?> <span>Hapus</span>
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

            <div class="card card-section">
                <div class="card-header">Riwayat Notulen</div>
                <div class="table-wrapper table-wrapper-sm">
                    <table id="meetingMinutesTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Pertemuan</th>
                                <th>Tanggal</th>
                                <th>Peserta</th>
                                <th>Hasil</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($minutesRows): ?>
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
                                        <td>
                                            <div class="text-sm text-slate-700"><?= htmlspecialchars(surat_excerpt((string)$row['participants'], 90)) ?></div>
                                        </td>
                                        <td>
                                            <div class="text-sm text-slate-700"><?= htmlspecialchars(surat_excerpt((string)$row['summary'], 110)) ?></div>
                                        </td>
                                        <td>
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    class="btn-secondary btn-view-minutes"
                                                    data-title="<?= htmlspecialchars((string)$row['meeting_title'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-date="<?= htmlspecialchars((string)$row['meeting_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-time="<?= htmlspecialchars(substr((string)$row['meeting_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-created-by="<?= htmlspecialchars((string)($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-participants="<?= htmlspecialchars((string)$row['participants'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-summary="<?= htmlspecialchars((string)$row['summary'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-decisions="<?= htmlspecialchars((string)($row['decisions'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-follow-up="<?= htmlspecialchars((string)($row['follow_up'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= ems_icon('eye', 'h-4 w-4') ?> <span>View</span>
                                                </button>
                                                <?php if ($canManageRecords): ?>
                                                    <form method="POST" action="surat_menyurat_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus notulen ini?">
                                                        <?= csrfField(); ?>
                                                        <input type="hidden" name="action" value="delete_meeting_minutes">
                                                        <input type="hidden" name="minutes_id" value="<?= (int)$row['id'] ?>">
                                                        <button type="submit" class="btn-danger">
                                                            <?= ems_icon('trash', 'h-4 w-4') ?> <span>Hapus</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
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

<div id="minutesViewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('clipboard-document-list', 'h-5 w-5 text-primary') ?>
                <span id="minutesModalTitle">Detail Notulen</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="card">
                    <div class="meta-text-xs">Tanggal</div>
                    <div id="minutesModalDate" class="text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs">Jam</div>
                    <div id="minutesModalTime" class="text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs">Dibuat Oleh</div>
                    <div id="minutesModalCreatedBy" class="text-sm font-semibold text-slate-800">-</div>
                </div>
            </div>
            <div class="grid gap-3 mt-4">
                <div class="card">
                    <div class="meta-text-xs mb-2">Peserta</div>
                    <div id="minutesModalParticipants" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs mb-2">Hasil Notulen</div>
                    <div id="minutesModalSummary" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs mb-2">Keputusan</div>
                    <div id="minutesModalDecisions" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs mb-2">Tindak Lanjut</div>
                    <div id="minutesModalFollowUp" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const datatableLanguageUrl = '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';

        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#incomingLettersTable').DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                order: [
                    [7, 'desc']
                ],
                language: {
                    url: datatableLanguageUrl
                }
            });

            jQuery('#outgoingLettersTable').DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                order: [
                    [0, 'desc']
                ],
                language: {
                    url: datatableLanguageUrl
                }
            });

            jQuery('#meetingMinutesTable').DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                order: [
                    [1, 'desc']
                ],
                language: {
                    url: datatableLanguageUrl
                }
            });
        }

        document.querySelectorAll('.js-delete-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                const message = form.dataset.confirm || 'Yakin ingin menghapus data ini?';
                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        function setupMultiImagePreview(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
            if (!input || !preview || !nameBox) {
                return;
            }

            let objectUrls = [];

            function clearPreview() {
                objectUrls.forEach(function(url) {
                    try { URL.revokeObjectURL(url); } catch (_) {}
                });
                objectUrls = [];
                preview.innerHTML = '';
                nameBox.textContent = '';
                nameBox.classList.add('hidden');
            }

            input.addEventListener('change', function() {
                clearPreview();

                const files = Array.from(this.files || []);
                if (!files.length) {
                    return;
                }

                nameBox.textContent = files.length + ' file dipilih';
                nameBox.classList.remove('hidden');

                files.forEach(function(file) {
                    if (!String(file.type || '').startsWith('image/')) {
                        return;
                    }

                    const url = URL.createObjectURL(file);
                    objectUrls.push(url);

                    const item = document.createElement('div');
                    item.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-2';
                    item.innerHTML = `
                        <img src="${url}" class="identity-photo h-28 w-full rounded-xl object-cover cursor-zoom-in" alt="Preview lampiran">
                        <div class="mt-2 truncate text-xs text-slate-600">${file.name}</div>
                    `;
                    preview.appendChild(item);
                });
            });
        }

        setupMultiImagePreview('outgoingAttachments', 'outgoingAttachmentsPreview');

        const modal = document.getElementById('minutesViewModal');
        if (!modal) {
            return;
        }

        const modalTitle = document.getElementById('minutesModalTitle');
        const modalDate = document.getElementById('minutesModalDate');
        const modalTime = document.getElementById('minutesModalTime');
        const modalCreatedBy = document.getElementById('minutesModalCreatedBy');
        const modalParticipants = document.getElementById('minutesModalParticipants');
        const modalSummary = document.getElementById('minutesModalSummary');
        const modalDecisions = document.getElementById('minutesModalDecisions');
        const modalFollowUp = document.getElementById('minutesModalFollowUp');

        function closeMinutesModal() {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        function openMinutesModal(button) {
            modalTitle.textContent = button.dataset.title || 'Detail Notulen';
            modalDate.textContent = button.dataset.date || '-';
            modalTime.textContent = (button.dataset.time || '-') + ' WIB';
            modalCreatedBy.textContent = button.dataset.createdBy || '-';
            modalParticipants.textContent = button.dataset.participants || '-';
            modalSummary.textContent = button.dataset.summary || '-';
            modalDecisions.textContent = button.dataset.decisions || '-';
            modalFollowUp.textContent = button.dataset.followUp || '-';

            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }

        document.querySelectorAll('.btn-view-minutes').forEach(function(button) {
            button.addEventListener('click', function() {
                openMinutesModal(button);
            });
        });

        modal.querySelectorAll('.btn-cancel').forEach(function(button) {
            button.addEventListener('click', closeMinutesModal);
        });

        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeMinutesModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeMinutesModal();
            }
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
