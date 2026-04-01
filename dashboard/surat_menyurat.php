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
$divisionOptions = ems_division_options();
$allDivisionValue = 'All Divisi';

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

function surat_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$column]);
    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$key];
}

function surat_revision_badge(?string $label): string
{
    $label = trim((string)$label);
    return $label !== '' ? $label : 'draft-awal';
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
$hasIncomingDivisionScope = false;
$hasOutgoingDivisionScope = false;
$hasMinutesDivisionScope = false;
$hasOutgoingRevisionColumns = false;
$hasMinutesRevisionColumns = false;
$hasMinutesCodeColumn = false;

try {
    $hasIncomingDivisionScope = surat_table_has_column($pdo, 'incoming_letters', 'division_scope');
    $hasOutgoingDivisionScope = surat_table_has_column($pdo, 'outgoing_letters', 'division_scope');
    $hasMinutesDivisionScope = surat_table_has_column($pdo, 'meeting_minutes', 'division_scope');
    $hasMinutesCodeColumn = surat_table_has_column($pdo, 'meeting_minutes', 'minutes_code');
    $hasOutgoingRevisionColumns = surat_table_has_column($pdo, 'outgoing_letters', 'revision_count')
        && surat_table_has_column($pdo, 'outgoing_letters', 'revision_label')
        && surat_table_has_column($pdo, 'outgoing_letters', 'updated_at')
        && surat_table_has_column($pdo, 'outgoing_letters', 'updated_by');
    $hasMinutesRevisionColumns = surat_table_has_column($pdo, 'meeting_minutes', 'revision_count')
        && surat_table_has_column($pdo, 'meeting_minutes', 'revision_label')
        && surat_table_has_column($pdo, 'meeting_minutes', 'updated_at')
        && surat_table_has_column($pdo, 'meeting_minutes', 'updated_by');

    $summary['incoming_unread'] = (int)$pdo->query("SELECT COUNT(*) FROM incoming_letters WHERE status = 'unread'")->fetchColumn();
    $summary['incoming_read'] = (int)$pdo->query("SELECT COUNT(*) FROM incoming_letters WHERE status = 'read'")->fetchColumn();
    $summary['outgoing_total'] = (int)$pdo->query("SELECT COUNT(*) FROM outgoing_letters")->fetchColumn();
    $summary['minutes_total'] = (int)$pdo->query("SELECT COUNT(*) FROM meeting_minutes")->fetchColumn();

    $stmt = $pdo->query("
        SELECT
            l.*,
            rb.full_name AS read_by_name,
            " . ($hasIncomingDivisionScope ? "l.division_scope" : "'All Divisi' AS division_scope") . "
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
            u.full_name AS created_by_name,
            " . ($hasOutgoingDivisionScope ? "o.division_scope" : "'All Divisi' AS division_scope") . ",
            " . ($hasOutgoingRevisionColumns ? "o.revision_count, o.revision_label, o.updated_at" : "0 AS revision_count, NULL AS revision_label, NULL AS updated_at") . "
        FROM outgoing_letters o
        LEFT JOIN user_rh u ON u.id = o.created_by
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $outgoingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT
            m.*,
            u.full_name AS created_by_name,
            " . ($hasMinutesDivisionScope ? "m.division_scope" : "'All Divisi' AS division_scope") . ",
            " . ($hasMinutesCodeColumn ? "m.minutes_code" : "NULL AS minutes_code") . ",
            " . ($hasMinutesRevisionColumns ? "m.revision_count, m.revision_label, m.updated_at" : "0 AS revision_count, NULL AS revision_label, NULL AS updated_at") . "
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
        <?php if (!$hasOutgoingRevisionColumns || !$hasMinutesRevisionColumns || !$hasIncomingDivisionScope || !$hasOutgoingDivisionScope || !$hasMinutesDivisionScope || !$hasMinutesCodeColumn): ?>
            <div class="alert alert-warning">Sebagian fitur edit/revisi/divisi/kode notulen memerlukan SQL terbaru di folder <code>docs/sql</code>.</div>
        <?php endif; ?>

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
                            <th>Divisi</th>
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
                                    <td><?= htmlspecialchars((string)($row['division_scope'] ?: $allDivisionValue)) ?></td>
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
                                        <div class="action-row-nowrap">
                                            <?php if ($canReadThis): ?>
                                                <form method="POST" action="surat_menyurat_action.php" class="inline">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="action" value="mark_incoming_read">
                                                    <input type="hidden" name="letter_id" value="<?= (int)$row['id'] ?>">
                                                    <button type="submit" class="btn-success action-icon-btn" title="Tandai surat sebagai dibaca" aria-label="Tandai surat sebagai dibaca">
                                                        <?= ems_icon('check-circle', 'h-4 w-4') ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($canManageRecords): ?>
                                                <form method="POST" action="surat_menyurat_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus surat masuk ini? Relasi surat keluar dan notulen akan dilepas.">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_incoming_letter">
                                                    <input type="hidden" name="letter_id" value="<?= (int)$row['id'] ?>">
                                                    <button type="submit" class="btn-danger action-icon-btn" title="Hapus surat masuk" aria-label="Hapus surat masuk">
                                                        <?= ems_icon('trash', 'h-4 w-4') ?>
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

                    <label>Nomor Surat</label>
                    <div class="flex gap-2">
                        <input type="text" name="outgoing_code" id="addOutgoingCode" maxlength="32" placeholder="Otomatis muncul setelah field wajib lengkap">
                        <button type="button" class="btn-secondary whitespace-nowrap" id="addOutgoingCodeAutoBtn">Auto</button>
                    </div>
                    <div class="meta-text-xs mt-1">Nomor surat otomatis bisa diedit manual.</div>

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
                            <input type="text" name="institution_name" id="addOutgoingInstitutionName" maxlength="160" required>
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

                    <label>Divisi Tujuan</label>
                    <select name="division_scope" required>
                        <option value="<?= htmlspecialchars($allDivisionValue) ?>"><?= htmlspecialchars($allDivisionValue) ?></option>
                        <?php foreach ($divisionOptions as $division): ?>
                            <option value="<?= htmlspecialchars((string)$division['value']) ?>"><?= htmlspecialchars((string)$division['label']) ?></option>
                        <?php endforeach; ?>
                    </select>

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

                    <label>Nomor Notulen</label>
                    <div class="flex gap-2">
                        <input type="text" name="minutes_code" id="addMinutesCode" maxlength="32" placeholder="Otomatis muncul setelah field wajib lengkap">
                        <button type="button" class="btn-secondary whitespace-nowrap" id="addMinutesCodeAutoBtn">Auto</button>
                    </div>
                    <div class="meta-text-xs mt-1">Nomor notulen otomatis bisa diedit manual.</div>

                    <div class="row-form-2">
                        <div class="col">
                            <label>Relasi Surat Masuk</label>
                            <select name="incoming_letter_id" id="addMinutesIncomingLetterId">
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
                            <select name="outgoing_letter_id" id="addMinutesOutgoingLetterId">
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

                    <label>Divisi Tujuan</label>
                    <select name="division_scope" required>
                        <option value="<?= htmlspecialchars($allDivisionValue) ?>"><?= htmlspecialchars($allDivisionValue) ?></option>
                        <?php foreach ($divisionOptions as $division): ?>
                            <option value="<?= htmlspecialchars((string)$division['value']) ?>"><?= htmlspecialchars((string)$division['label']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="row-form-2">
                        <div class="col">
                            <label>Tanggal Pertemuan</label>
                            <input type="date" name="meeting_date" id="addMinutesDate" required>
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
                                <th>Divisi</th>
                                <th>Revisi</th>
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
                                        <td><?= htmlspecialchars((string)($row['division_scope'] ?: $allDivisionValue)) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars(surat_revision_badge($row['revision_label'] ?? null)) ?></strong>
                                            <?php if (!empty($row['updated_at'])): ?>
                                                <div class="meta-text-xs">Edit <?= htmlspecialchars((string)$row['updated_at']) ?></div>
                                            <?php endif; ?>
                                        </td>
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
                                                <div class="action-row-nowrap">
                                                    <?php if ($hasOutgoingRevisionColumns): ?>
                                                        <button
                                                            type="button"
                                                            class="btn-secondary action-icon-btn btn-edit-outgoing"
                                                            data-id="<?= (int)$row['id'] ?>"
                                                            data-outgoing-code="<?= htmlspecialchars((string)$row['outgoing_code'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-incoming-letter-id="<?= (int)($row['incoming_letter_id'] ?? 0) ?>"
                                                            data-institution-name="<?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-recipient-name="<?= htmlspecialchars((string)($row['recipient_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-recipient-contact="<?= htmlspecialchars((string)($row['recipient_contact'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-subject="<?= htmlspecialchars((string)$row['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-appointment-date="<?= htmlspecialchars((string)($row['appointment_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-appointment-time="<?= htmlspecialchars((string)($row['appointment_time'] ? substr((string)$row['appointment_time'], 0, 5) : ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-division-scope="<?= htmlspecialchars((string)($row['division_scope'] ?: $allDivisionValue), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-letter-body="<?= htmlspecialchars((string)$row['letter_body'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-revision-label="<?= htmlspecialchars(surat_revision_badge($row['revision_label'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
                                                            title="Edit surat keluar"
                                                            aria-label="Edit surat keluar">
                                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="meta-text-xs">Edit butuh SQL revisi</span>
                                                    <?php endif; ?>
                                                    <form method="POST" action="surat_menyurat_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus surat keluar ini? Relasi notulen akan dilepas.">
                                                        <?= csrfField(); ?>
                                                        <input type="hidden" name="action" value="delete_outgoing_letter">
                                                        <input type="hidden" name="letter_id" value="<?= (int)$row['id'] ?>">
                                                        <button type="submit" class="btn-danger action-icon-btn" title="Hapus surat keluar" aria-label="Hapus surat keluar">
                                                            <?= ems_icon('trash', 'h-4 w-4') ?>
                                                        </button>
                                                    </form>
                                                </div>
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
                                <th>Kode / Pertemuan</th>
                                <th>Tanggal</th>
                                <th>Divisi</th>
                                <th>Revisi</th>
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
                                            <strong><?= htmlspecialchars((string)($row['minutes_code'] ?: '-')) ?></strong>
                                            <div class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string)$row['meeting_title']) ?></div>
                                            <div class="meta-text-xs">Oleh <?= htmlspecialchars((string)($row['created_by_name'] ?? '-')) ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars((string)$row['meeting_date']) ?><br>
                                            <span class="meta-text-xs"><?= htmlspecialchars(substr((string)$row['meeting_time'], 0, 5)) ?> WIB</span>
                                        </td>
                                        <td><?= htmlspecialchars((string)($row['division_scope'] ?: $allDivisionValue)) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars(surat_revision_badge($row['revision_label'] ?? null)) ?></strong>
                                            <?php if (!empty($row['updated_at'])): ?>
                                                <div class="meta-text-xs">Edit <?= htmlspecialchars((string)$row['updated_at']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="text-sm text-slate-700"><?= htmlspecialchars(surat_excerpt((string)$row['participants'], 90)) ?></div>
                                        </td>
                                        <td>
                                            <div class="text-sm text-slate-700"><?= htmlspecialchars(surat_excerpt((string)$row['summary'], 110)) ?></div>
                                        </td>
                                        <td>
                                            <div class="action-row-nowrap">
                                                <button
                                                    type="button"
                                                    class="btn-secondary action-icon-btn btn-view-minutes"
                                                    data-title="<?= htmlspecialchars((string)$row['meeting_title'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-code="<?= htmlspecialchars((string)($row['minutes_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-date="<?= htmlspecialchars((string)$row['meeting_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-time="<?= htmlspecialchars(substr((string)$row['meeting_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-created-by="<?= htmlspecialchars((string)($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-participants="<?= htmlspecialchars((string)$row['participants'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-summary="<?= htmlspecialchars((string)$row['summary'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-decisions="<?= htmlspecialchars((string)($row['decisions'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-follow-up="<?= htmlspecialchars((string)($row['follow_up'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-division-scope="<?= htmlspecialchars((string)($row['division_scope'] ?: $allDivisionValue), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-revision-label="<?= htmlspecialchars(surat_revision_badge($row['revision_label'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
                                                    title="Lihat detail notulen"
                                                    aria-label="Lihat detail notulen">
                                                    <?= ems_icon('eye', 'h-4 w-4') ?>
                                                </button>
                                                <?php if ($canManageRecords): ?>
                                                    <?php if ($hasMinutesRevisionColumns): ?>
                                                        <button
                                                            type="button"
                                                            class="btn-secondary action-icon-btn btn-edit-minutes"
                                                            data-id="<?= (int)$row['id'] ?>"
                                                            data-minutes-code="<?= htmlspecialchars((string)($row['minutes_code'] ?: ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-incoming-letter-id="<?= (int)($row['incoming_letter_id'] ?? 0) ?>"
                                                            data-outgoing-letter-id="<?= (int)($row['outgoing_letter_id'] ?? 0) ?>"
                                                            data-meeting-title="<?= htmlspecialchars((string)$row['meeting_title'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-meeting-date="<?= htmlspecialchars((string)$row['meeting_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-meeting-time="<?= htmlspecialchars(substr((string)$row['meeting_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-participants="<?= htmlspecialchars((string)$row['participants'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-summary="<?= htmlspecialchars((string)$row['summary'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-decisions="<?= htmlspecialchars((string)($row['decisions'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-follow-up="<?= htmlspecialchars((string)($row['follow_up'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-division-scope="<?= htmlspecialchars((string)($row['division_scope'] ?: $allDivisionValue), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-revision-label="<?= htmlspecialchars(surat_revision_badge($row['revision_label'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
                                                            title="Edit notulen"
                                                            aria-label="Edit notulen">
                                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="meta-text-xs">Edit butuh SQL revisi</span>
                                                    <?php endif; ?>
                                                    <form method="POST" action="surat_menyurat_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus notulen ini?">
                                                        <?= csrfField(); ?>
                                                        <input type="hidden" name="action" value="delete_meeting_minutes">
                                                        <input type="hidden" name="minutes_id" value="<?= (int)$row['id'] ?>">
                                                        <button type="submit" class="btn-danger action-icon-btn" title="Hapus notulen" aria-label="Hapus notulen">
                                                            <?= ems_icon('trash', 'h-4 w-4') ?>
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
                    <div class="meta-text-xs">Kode</div>
                    <div id="minutesModalCode" class="text-sm font-semibold text-slate-800">-</div>
                </div>
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
            <div class="grid grid-cols-1 gap-3 mt-3 md:grid-cols-2">
                <div class="card">
                    <div class="meta-text-xs">Divisi</div>
                    <div id="minutesModalDivision" class="text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs">Revisi</div>
                    <div id="minutesModalRevision" class="text-sm font-semibold text-slate-800">-</div>
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

<div id="outgoingEditModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('pencil-square', 'h-5 w-5 text-primary') ?>
                <span>Edit Surat Keluar</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="alert alert-info">
                Revisi terakhir: <strong id="outgoingEditRevisionLabel">draft-awal</strong>.
                Saat disimpan, sistem akan menaikkan revisi otomatis.
            </div>
            <form method="POST" action="surat_menyurat_action.php" class="form">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="edit_outgoing_letter">
                <input type="hidden" name="letter_id" id="editOutgoingLetterId">

                <label>Nomor Surat</label>
                <div class="flex gap-2">
                    <input type="text" name="outgoing_code" id="editOutgoingCode" maxlength="32">
                    <button type="button" class="btn-secondary whitespace-nowrap" id="editOutgoingCodeAutoBtn">Auto</button>
                </div>
                <div class="meta-text-xs mt-1">Nomor surat bisa diubah manual.</div>

                <label>Relasi Surat Masuk</label>
                <select name="incoming_letter_id" id="editOutgoingIncomingLetterId">
                    <option value="">-- Opsional --</option>
                    <?php foreach ($linkableIncoming as $incoming): ?>
                        <option value="<?= (int)$incoming['id'] ?>">
                            <?= htmlspecialchars($incoming['letter_code'] . ' - ' . $incoming['institution_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="row-form-2">
                    <div class="col">
                        <label>Nama Instansi</label>
                        <input type="text" name="institution_name" id="editOutgoingInstitutionName" maxlength="160" required>
                    </div>
                    <div class="col">
                        <label>Nama Tujuan / Kontak</label>
                        <input type="text" name="recipient_name" id="editOutgoingRecipientName" maxlength="160">
                    </div>
                </div>

                <div class="row-form-2">
                    <div class="col">
                        <label>Kontak Tujuan</label>
                        <input type="text" name="recipient_contact" id="editOutgoingRecipientContact" maxlength="64">
                    </div>
                    <div class="col">
                        <label>Subjek Surat</label>
                        <input type="text" name="subject" id="editOutgoingSubject" maxlength="255" required>
                    </div>
                </div>

                <div class="row-form-2">
                    <div class="col">
                        <label>Tanggal Temu / Kirim</label>
                        <input type="date" name="appointment_date" id="editOutgoingAppointmentDate">
                    </div>
                    <div class="col">
                        <label>Jam</label>
                        <input type="time" name="appointment_time" id="editOutgoingAppointmentTime">
                    </div>
                </div>

                <label>Divisi Tujuan</label>
                <select name="division_scope" id="editOutgoingDivisionScope" required>
                    <option value="<?= htmlspecialchars($allDivisionValue) ?>"><?= htmlspecialchars($allDivisionValue) ?></option>
                    <?php foreach ($divisionOptions as $division): ?>
                        <option value="<?= htmlspecialchars((string)$division['value']) ?>"><?= htmlspecialchars((string)$division['label']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Isi Surat / Ringkasan Tindak Lanjut</label>
                <textarea name="letter_body" id="editOutgoingLetterBody" rows="5" required></textarea>

                <div class="modal-actions mt-4">
                    <button type="submit" class="btn-success"><?= ems_icon('check-circle', 'h-4 w-4') ?> <span>Simpan Perubahan</span></button>
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="minutesEditModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('pencil-square', 'h-5 w-5 text-primary') ?>
                <span>Edit Notulen</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="alert alert-info">
                Revisi terakhir: <strong id="minutesEditRevisionLabel">draft-awal</strong>.
                Saat disimpan, sistem akan menaikkan revisi otomatis.
            </div>
            <form method="POST" action="surat_menyurat_action.php" class="form">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="edit_meeting_minutes">
                <input type="hidden" name="minutes_id" id="editMinutesId">

                <label>Nomor Notulen</label>
                <div class="flex gap-2">
                    <input type="text" name="minutes_code" id="editMinutesCode" maxlength="32">
                    <button type="button" class="btn-secondary whitespace-nowrap" id="editMinutesCodeAutoBtn">Auto</button>
                </div>
                <div class="meta-text-xs mt-1">Nomor notulen bisa diubah manual.</div>

                <div class="row-form-2">
                    <div class="col">
                        <label>Relasi Surat Masuk</label>
                        <select name="incoming_letter_id" id="editMinutesIncomingLetterId">
                            <option value="">-- Opsional --</option>
                            <?php foreach ($linkableIncoming as $incoming): ?>
                                <option value="<?= (int)$incoming['id'] ?>">
                                    <?= htmlspecialchars($incoming['letter_code'] . ' - ' . $incoming['institution_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label>Relasi Surat Keluar</label>
                        <select name="outgoing_letter_id" id="editMinutesOutgoingLetterId">
                            <option value="">-- Opsional --</option>
                            <?php foreach ($linkableOutgoing as $outgoing): ?>
                                <option value="<?= (int)$outgoing['id'] ?>">
                                    <?= htmlspecialchars($outgoing['outgoing_code'] . ' - ' . $outgoing['institution_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label>Judul Pertemuan</label>
                <input type="text" name="meeting_title" id="editMinutesTitle" maxlength="255" required>

                <label>Divisi Tujuan</label>
                <select name="division_scope" id="editMinutesDivisionScope" required>
                    <option value="<?= htmlspecialchars($allDivisionValue) ?>"><?= htmlspecialchars($allDivisionValue) ?></option>
                    <?php foreach ($divisionOptions as $division): ?>
                        <option value="<?= htmlspecialchars((string)$division['value']) ?>"><?= htmlspecialchars((string)$division['label']) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="row-form-2">
                    <div class="col">
                        <label>Tanggal Pertemuan</label>
                        <input type="date" name="meeting_date" id="editMinutesDate" required>
                    </div>
                    <div class="col">
                        <label>Jam Pertemuan</label>
                        <input type="time" name="meeting_time" id="editMinutesTime" required>
                    </div>
                </div>

                <label>Peserta</label>
                <textarea name="participants" id="editMinutesParticipants" rows="3" required></textarea>

                <label>Hasil Notulen</label>
                <textarea name="summary" id="editMinutesSummary" rows="4" required></textarea>

                <label>Keputusan</label>
                <textarea name="decisions" id="editMinutesDecisions" rows="3"></textarea>

                <label>Tindak Lanjut</label>
                <textarea name="follow_up" id="editMinutesFollowUp" rows="3"></textarea>

                <div class="modal-actions mt-4">
                    <button type="submit" class="btn-success"><?= ems_icon('check-circle', 'h-4 w-4') ?> <span>Simpan Perubahan</span></button>
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const datatableLanguageUrl = '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
        const generateCodeUrl = '<?= htmlspecialchars(ems_url('/ajax/generate_surat_code.php'), ENT_QUOTES, 'UTF-8') ?>';

        function debounce(fn, delay) {
            let timer = null;
            return function() {
                const args = arguments;
                clearTimeout(timer);
                timer = setTimeout(function() {
                    fn.apply(null, args);
                }, delay);
            };
        }

        function setupAutoCode(options) {
            const codeInput = document.getElementById(options.codeInputId);
            const autoButton = document.getElementById(options.autoButtonId);
            const requiredInputs = options.requiredInputIds.map(function(id) {
                return document.getElementById(id);
            }).filter(Boolean);
            const watchedInputs = options.watchedInputIds.map(function(id) {
                return document.getElementById(id);
            }).filter(Boolean);

            if (!codeInput || !requiredInputs.length) {
                return {
                    refresh: function() {}
                };
            }

            codeInput.dataset.autoMode = 'true';
            codeInput.dataset.generatedCode = codeInput.value || '';

            async function refreshCode(forceAuto) {
                const requiredReady = requiredInputs.every(function(input) {
                    return String(input.value || '').trim() !== '';
                });

                if (!requiredReady) {
                    if (forceAuto) {
                        codeInput.value = '';
                        codeInput.dataset.generatedCode = '';
                    }
                    return;
                }

                const url = new URL(generateCodeUrl, window.location.origin);
                url.searchParams.set('type', options.type);

                const institutionInput = options.institutionInputId ? document.getElementById(options.institutionInputId) : null;
                if (institutionInput) {
                    url.searchParams.set('institution_name', (institutionInput.value || '').trim());
                }

                const dateInput = options.dateInputId ? document.getElementById(options.dateInputId) : null;
                if (dateInput) {
                    url.searchParams.set('date', (dateInput.value || '').trim());
                }

                const incomingInput = options.incomingInputId ? document.getElementById(options.incomingInputId) : null;
                if (incomingInput) {
                    url.searchParams.set('incoming_letter_id', incomingInput.value || '');
                }

                const outgoingInput = options.outgoingInputId ? document.getElementById(options.outgoingInputId) : null;
                if (outgoingInput) {
                    url.searchParams.set('outgoing_letter_id', outgoingInput.value || '');
                }

                const response = await fetch(url.toString(), { credentials: 'same-origin' });
                const payload = await response.json();
                if (!payload.success) {
                    return;
                }

                const currentValue = codeInput.value.trim();
                const previousGenerated = codeInput.dataset.generatedCode || '';
                const shouldApply = forceAuto || codeInput.dataset.autoMode === 'true' || currentValue === '' || currentValue === previousGenerated;

                codeInput.dataset.generatedCode = payload.code || '';
                if (shouldApply) {
                    codeInput.value = payload.code || '';
                    codeInput.dataset.autoMode = 'true';
                }
            }

            const debouncedRefresh = debounce(function() {
                refreshCode(false).catch(function() {});
            }, 250);

            watchedInputs.forEach(function(input) {
                input.addEventListener('input', debouncedRefresh);
                input.addEventListener('change', debouncedRefresh);
            });

            codeInput.addEventListener('input', function() {
                const currentValue = codeInput.value.trim();
                codeInput.dataset.autoMode = (currentValue === '' || currentValue === (codeInput.dataset.generatedCode || '')) ? 'true' : 'false';
            });

            if (autoButton) {
                autoButton.addEventListener('click', function() {
                    codeInput.dataset.autoMode = 'true';
                    refreshCode(true).catch(function() {});
                });
            }

            return {
                refresh: function(forceAuto) {
                    refreshCode(Boolean(forceAuto)).catch(function() {});
                }
            };
        }

        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#incomingLettersTable').DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                order: [
                    [8, 'desc']
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
        setupAutoCode({
            type: 'outgoing',
            codeInputId: 'addOutgoingCode',
            autoButtonId: 'addOutgoingCodeAutoBtn',
            institutionInputId: 'addOutgoingInstitutionName',
            requiredInputIds: ['addOutgoingInstitutionName'],
            watchedInputIds: ['addOutgoingInstitutionName']
        });
        setupAutoCode({
            type: 'minutes',
            codeInputId: 'addMinutesCode',
            autoButtonId: 'addMinutesCodeAutoBtn',
            dateInputId: 'addMinutesDate',
            incomingInputId: 'addMinutesIncomingLetterId',
            outgoingInputId: 'addMinutesOutgoingLetterId',
            requiredInputIds: ['addMinutesDate'],
            watchedInputIds: ['addMinutesDate', 'addMinutesIncomingLetterId', 'addMinutesOutgoingLetterId']
        });

        function openModal(modal) {
            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }

        function closeModal(modal) {
            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        function attachModalClose(modal) {
            if (!modal) {
                return;
            }

            modal.querySelectorAll('.btn-cancel').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(modal);
                });
            });

        }

        const minutesViewModal = document.getElementById('minutesViewModal');
        const outgoingEditModal = document.getElementById('outgoingEditModal');
        const minutesEditModal = document.getElementById('minutesEditModal');
        const editOutgoingCodeControl = setupAutoCode({
            type: 'outgoing',
            codeInputId: 'editOutgoingCode',
            autoButtonId: 'editOutgoingCodeAutoBtn',
            institutionInputId: 'editOutgoingInstitutionName',
            requiredInputIds: ['editOutgoingInstitutionName'],
            watchedInputIds: ['editOutgoingInstitutionName']
        });
        const editMinutesCodeControl = setupAutoCode({
            type: 'minutes',
            codeInputId: 'editMinutesCode',
            autoButtonId: 'editMinutesCodeAutoBtn',
            dateInputId: 'editMinutesDate',
            incomingInputId: 'editMinutesIncomingLetterId',
            outgoingInputId: 'editMinutesOutgoingLetterId',
            requiredInputIds: ['editMinutesDate'],
            watchedInputIds: ['editMinutesDate', 'editMinutesIncomingLetterId', 'editMinutesOutgoingLetterId']
        });

        attachModalClose(minutesViewModal);
        attachModalClose(outgoingEditModal);
        attachModalClose(minutesEditModal);

        if (minutesViewModal) {
            const modalTitle = document.getElementById('minutesModalTitle');
            const modalCode = document.getElementById('minutesModalCode');
            const modalDate = document.getElementById('minutesModalDate');
            const modalTime = document.getElementById('minutesModalTime');
            const modalCreatedBy = document.getElementById('minutesModalCreatedBy');
            const modalDivision = document.getElementById('minutesModalDivision');
            const modalRevision = document.getElementById('minutesModalRevision');
            const modalParticipants = document.getElementById('minutesModalParticipants');
            const modalSummary = document.getElementById('minutesModalSummary');
            const modalDecisions = document.getElementById('minutesModalDecisions');
            const modalFollowUp = document.getElementById('minutesModalFollowUp');

            function openMinutesModal(button) {
                modalTitle.textContent = button.dataset.title || 'Detail Notulen';
                modalCode.textContent = button.dataset.code || '-';
                modalDate.textContent = button.dataset.date || '-';
                modalTime.textContent = (button.dataset.time || '-') + ' WIB';
                modalCreatedBy.textContent = button.dataset.createdBy || '-';
                modalDivision.textContent = button.dataset.divisionScope || 'All Divisi';
                modalRevision.textContent = button.dataset.revisionLabel || 'draft-awal';
                modalParticipants.textContent = button.dataset.participants || '-';
                modalSummary.textContent = button.dataset.summary || '-';
                modalDecisions.textContent = button.dataset.decisions || '-';
                modalFollowUp.textContent = button.dataset.followUp || '-';

                openModal(minutesViewModal);
            }

            document.querySelectorAll('.btn-view-minutes').forEach(function(button) {
                button.addEventListener('click', function() {
                    openMinutesModal(button);
                });
            });
        }

        if (outgoingEditModal) {
            document.querySelectorAll('.btn-edit-outgoing').forEach(function(button) {
                button.addEventListener('click', function() {
                    document.getElementById('editOutgoingLetterId').value = button.dataset.id || '';
                    document.getElementById('editOutgoingCode').value = button.dataset.outgoingCode || '';
                    document.getElementById('editOutgoingCode').dataset.generatedCode = button.dataset.outgoingCode || '';
                    document.getElementById('editOutgoingCode').dataset.autoMode = 'false';
                    document.getElementById('editOutgoingIncomingLetterId').value = button.dataset.incomingLetterId || '';
                    document.getElementById('editOutgoingInstitutionName').value = button.dataset.institutionName || '';
                    document.getElementById('editOutgoingRecipientName').value = button.dataset.recipientName || '';
                    document.getElementById('editOutgoingRecipientContact').value = button.dataset.recipientContact || '';
                    document.getElementById('editOutgoingSubject').value = button.dataset.subject || '';
                    document.getElementById('editOutgoingAppointmentDate').value = button.dataset.appointmentDate || '';
                    document.getElementById('editOutgoingAppointmentTime').value = button.dataset.appointmentTime || '';
                    document.getElementById('editOutgoingDivisionScope').value = button.dataset.divisionScope || 'All Divisi';
                    document.getElementById('editOutgoingLetterBody').value = button.dataset.letterBody || '';
                    document.getElementById('outgoingEditRevisionLabel').textContent = button.dataset.revisionLabel || 'draft-awal';

                    openModal(outgoingEditModal);
                    editOutgoingCodeControl.refresh(false);
                });
            });
        }

        if (minutesEditModal) {
            document.querySelectorAll('.btn-edit-minutes').forEach(function(button) {
                button.addEventListener('click', function() {
                    document.getElementById('editMinutesId').value = button.dataset.id || '';
                    document.getElementById('editMinutesCode').value = button.dataset.minutesCode || '';
                    document.getElementById('editMinutesCode').dataset.generatedCode = button.dataset.minutesCode || '';
                    document.getElementById('editMinutesCode').dataset.autoMode = 'false';
                    document.getElementById('editMinutesIncomingLetterId').value = button.dataset.incomingLetterId || '';
                    document.getElementById('editMinutesOutgoingLetterId').value = button.dataset.outgoingLetterId || '';
                    document.getElementById('editMinutesTitle').value = button.dataset.meetingTitle || '';
                    document.getElementById('editMinutesDivisionScope').value = button.dataset.divisionScope || 'All Divisi';
                    document.getElementById('editMinutesDate').value = button.dataset.meetingDate || '';
                    document.getElementById('editMinutesTime').value = button.dataset.meetingTime || '';
                    document.getElementById('editMinutesParticipants').value = button.dataset.participants || '';
                    document.getElementById('editMinutesSummary').value = button.dataset.summary || '';
                    document.getElementById('editMinutesDecisions').value = button.dataset.decisions || '';
                    document.getElementById('editMinutesFollowUp').value = button.dataset.followUp || '';
                    document.getElementById('minutesEditRevisionLabel').textContent = button.dataset.revisionLabel || 'draft-awal';

                    openModal(minutesEditModal);
                    editMinutesCodeControl.refresh(false);
                });
            });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') {
                return;
            }

            [minutesViewModal, outgoingEditModal, minutesEditModal].forEach(function(modal) {
                if (modal && !modal.classList.contains('hidden')) {
                    closeModal(modal);
                }
            });
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
