<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

function inboxTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();

    return $cache[$table];
}

function inboxTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();

    return $cache[$key];
}

function inboxText(?string $value, string $fallback = '-'): string
{
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function inboxHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function inboxMultiline(?string $value, string $fallback = '-'): string
{
    return nl2br(inboxHtml(inboxText($value, $fallback)));
}

function inboxDateLabel(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $time = strtotime($value);
    if ($time === false) {
        return $value;
    }

    return date('d M Y H:i', $time) . ' WIB';
}

function inboxGroupAttachments(array $rows, string $foreignKey): array
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

function inboxAttachmentPayload(array $attachments, string $fallbackName): array
{
    return array_map(static function (array $attachment) use ($fallbackName): array {
        $filePath = '/' . ltrim((string)($attachment['file_path'] ?? ''), '/');
        $fileName = trim((string)($attachment['file_name'] ?? ''));
        $resolvedName = $fileName !== '' ? $fileName : $fallbackName;
        $extension = strtolower((string)pathinfo($fileName !== '' ? $fileName : $filePath, PATHINFO_EXTENSION));

        return [
            'src' => $filePath,
            'name' => $resolvedName,
            'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true),
            'is_pdf' => $extension === 'pdf',
        ];
    }, $attachments);
}

function inboxBuildAttachmentSection(array $attachments, string $fallbackName): string
{
    $items = inboxAttachmentPayload($attachments, $fallbackName);
    if (!$items) {
        return '  <div class="card"><div class="meta-text-xs mb-2">Lampiran</div><div class="text-sm text-slate-700">Tidak ada lampiran.</div></div>';
    }

    $html = '  <div class="card"><div class="meta-text-xs mb-2">Lampiran</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">';
    foreach ($items as $item) {
        $src = inboxHtml((string)($item['src'] ?? ''));
        $name = inboxHtml((string)($item['name'] ?? 'Lampiran'));
        $preview = '<div style="padding:12px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;color:#64748b;font-size:12px;">Preview tidak tersedia.</div>';
        if (!empty($item['is_image']) && $src !== '') {
            $preview = '<a href="' . $src . '" target="_blank" rel="noopener"><img src="' . $src . '" alt="' . $name . '" style="display:block;width:100%;height:160px;object-fit:cover;border-radius:12px;border:1px solid #dbeafe;background:#e2e8f0;"></a>';
        } elseif (!empty($item['is_pdf']) && $src !== '') {
            $preview = '<iframe src="' . $src . '" title="' . $name . '" loading="lazy" style="width:100%;height:160px;border:1px solid #dbeafe;border-radius:12px;background:#fff;"></iframe>';
        }

        $html .= '<div style="border:1px solid #dbeafe;border-radius:16px;padding:12px;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);">';
        $html .= $preview;
        $html .= '<div style="margin-top:10px;font-size:12px;font-weight:700;color:#334155;word-break:break-word;">' . $name . '</div>';
        if ($src !== '') {
            $html .= '<a href="' . $src . '" target="_blank" rel="noopener" style="display:inline-flex;margin-top:8px;font-size:12px;font-weight:700;color:#0369a1;text-decoration:none;">Buka lampiran</a>';
        }
        $html .= '</div>';
    }
    $html .= '</div></div>';

    return $html;
}

function inboxBuildIncomingMessage(array $row, array $attachments = []): string
{
    $parts = [];
    $parts[] = '<div class="space-y-3">';
    $parts[] = '  <div class="grid grid-cols-1 gap-3 md:grid-cols-2">';
    $parts[] = '      <div class="card"><div class="meta-text-xs">Kode</div><div class="text-sm font-semibold text-slate-800">' . inboxHtml(inboxText($row['letter_code'] ?? '')) . '</div></div>';
    $parts[] = '      <div class="card"><div class="meta-text-xs">Divisi</div><div class="text-sm font-semibold text-slate-800">' . inboxHtml(inboxText($row['division_scope'] ?? 'All Divisi')) . '</div></div>';
    $parts[] = '      <div class="card"><div class="meta-text-xs">Jadwal Temu</div><div class="text-sm font-semibold text-slate-800">' . inboxHtml(inboxText($row['appointment_date'] ?? '')) . ' ' . inboxHtml(inboxText(substr((string)($row['appointment_time'] ?? ''), 0, 5), '-')) . ' WIB</div></div>';
    $parts[] = '      <div class="card"><div class="meta-text-xs">Tujuan</div><div class="text-sm font-semibold text-slate-800">' . inboxHtml(inboxText($row['target_name_snapshot'] ?? '')) . '<div class="meta-text-xs">' . inboxHtml(inboxText($row['target_role_snapshot'] ?? '')) . '</div></div></div>';
    $parts[] = '  </div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Instansi</div><div class="text-sm text-slate-700">' . inboxHtml(inboxText($row['institution_name'] ?? '')) . '</div></div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Pengirim</div><div class="text-sm text-slate-700">' . inboxHtml(inboxText($row['sender_name'] ?? '')) . '<br><span class="meta-text-xs">' . inboxHtml(inboxText($row['sender_phone'] ?? '')) . '</span></div></div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Agenda / Keperluan</div><div class="whitespace-pre-line text-sm text-slate-700">' . inboxMultiline($row['meeting_topic'] ?? '-') . '</div></div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Catatan</div><div class="whitespace-pre-line text-sm text-slate-700">' . inboxMultiline($row['notes'] ?? '-', '-') . '</div></div>';
    $parts[] = inboxBuildAttachmentSection($attachments, 'Lampiran surat masuk');
    $parts[] = '</div>';

    return implode('', $parts);
}

function inboxBuildMinutesMessage(array $row, array $attachments = []): string
{
    $parts = [];
    $parts[] = '<div class="space-y-3">';
    $parts[] = '  <div class="grid grid-cols-1 gap-3 md:grid-cols-3">';
    $parts[] = '      <div class="card"><div class="meta-text-xs">Tanggal</div><div class="text-sm font-semibold text-slate-800">' . inboxHtml(inboxText($row['meeting_date'] ?? '')) . '</div></div>';
    $parts[] = '      <div class="card"><div class="meta-text-xs">Jam</div><div class="text-sm font-semibold text-slate-800">' . inboxHtml(inboxText(substr((string)($row['meeting_time'] ?? ''), 0, 5), '-')) . ' WIB</div></div>';
    $parts[] = '      <div class="card"><div class="meta-text-xs">Divisi</div><div class="text-sm font-semibold text-slate-800">' . inboxHtml(inboxText($row['division_scope'] ?? 'All Divisi')) . '</div></div>';
    $parts[] = '  </div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Peserta</div><div class="whitespace-pre-line text-sm text-slate-700">' . inboxMultiline($row['participants'] ?? '-') . '</div></div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Hasil Notulen</div><div class="whitespace-pre-line text-sm text-slate-700">' . inboxMultiline($row['summary'] ?? '-') . '</div></div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Keputusan</div><div class="whitespace-pre-line text-sm text-slate-700">' . inboxMultiline($row['decisions'] ?? '-', '-') . '</div></div>';
    $parts[] = '  <div class="card"><div class="meta-text-xs mb-2">Tindak Lanjut</div><div class="whitespace-pre-line text-sm text-slate-700">' . inboxMultiline($row['follow_up'] ?? '-', '-') . '</div></div>';
    $parts[] = inboxBuildAttachmentSection($attachments, 'Lampiran notulen');
    $parts[] = '</div>';

    return implode('', $parts);
}

function inboxSortKey(array $item): int
{
    $time = strtotime((string)($item['created_at'] ?? ''));
    return $time !== false ? $time : 0;
}

$user = $_SESSION['user_rh'] ?? null;
if (!$user) {
    echo json_encode(['unread' => 0, 'items' => []]);
    exit;
}

$userId = (int)($user['id'] ?? 0);
$userDivision = ems_normalize_division($user['division'] ?? '');
$items = [];
$incomingAttachmentsMap = [];
$minutesAttachmentsMap = [];

if ($userId <= 0) {
    echo json_encode(['unread' => 0, 'items' => []]);
    exit;
}

if (inboxTableExists($pdo, 'user_inbox')) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            message,
            is_read,
            created_at,
            DATE_FORMAT(created_at, '%d %b %Y %H:%i WIB') AS created_at_label
        FROM user_inbox
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'item_id' => (int)$row['id'],
            'source_type' => 'user_inbox',
            'title' => (string)$row['title'],
            'message' => (string)$row['message'],
            'is_read' => (int)($row['is_read'] ?? 0),
            'created_at' => (string)$row['created_at'],
            'created_at_label' => (string)$row['created_at_label'],
            'delete_label' => 'Hapus',
            'badge' => 'SYSTEM',
        ];
    }
}

$hasInboxState = inboxTableExists($pdo, 'user_inbox_state');
$hasIncomingDivisionScope = inboxTableHasColumn($pdo, 'incoming_letters', 'division_scope');
$hasMinutesDivisionScope = inboxTableHasColumn($pdo, 'meeting_minutes', 'division_scope');

if ($hasInboxState) {
    $incomingWhere = "l.target_user_id = ?";
    $incomingParams = [$userId];
    if ($hasIncomingDivisionScope) {
        if (ems_is_management_division($userDivision)) {
            $incomingWhere = "((l.division_scope = 'All Divisi') OR (l.division_scope = 'All Divisi Manajemen') OR l.division_scope = ?)";
        } else {
            $incomingWhere = "((l.division_scope = 'All Divisi') OR l.division_scope = ?)";
        }
        $incomingParams = [$userDivision !== '' ? $userDivision : 'All Divisi'];
    }

    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.letter_code,
            l.institution_name,
            l.sender_name,
            l.sender_phone,
            l.meeting_topic,
            l.appointment_date,
            l.appointment_time,
            l.target_name_snapshot,
            l.target_role_snapshot,
            l.notes,
            " . ($hasIncomingDivisionScope ? "l.division_scope" : "'All Divisi'") . " AS division_scope,
            l.submitted_at AS created_at,
            COALESCE(s.is_read, 0) AS is_read
        FROM incoming_letters l
        LEFT JOIN user_inbox_state s
            ON s.user_id = ?
           AND s.item_type = 'incoming_letter'
           AND s.item_id = l.id
        WHERE {$incomingWhere}
          AND COALESCE(s.is_deleted, 0) = 0
        ORDER BY l.submitted_at DESC
        LIMIT 20
    ");
    $stmt->execute(array_merge([$userId], $incomingParams));
    $incomingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $incomingIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $incomingRows)));
    if ($incomingIds && inboxTableExists($pdo, 'incoming_letter_attachments')) {
        $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM incoming_letter_attachments
            WHERE incoming_letter_id IN ($placeholders)
            ORDER BY incoming_letter_id ASC, sort_order ASC, id ASC
        ");
        $stmt->execute($incomingIds);
        $incomingAttachmentsMap = inboxGroupAttachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'incoming_letter_id');
    }

    foreach ($incomingRows as $row) {
        $items[] = [
            'id' => 'incoming_letter:' . (int)$row['id'],
            'item_id' => (int)$row['id'],
            'source_type' => 'incoming_letter',
            'title' => 'Surat Masuk: ' . (string)$row['institution_name'],
            'message' => inboxBuildIncomingMessage($row, $incomingAttachmentsMap[(int)($row['id'] ?? 0)] ?? []),
            'is_read' => (int)($row['is_read'] ?? 0),
            'created_at' => (string)$row['created_at'],
            'created_at_label' => inboxDateLabel($row['created_at'] ?? ''),
            'delete_label' => 'Sembunyikan',
            'badge' => 'SURAT MASUK',
        ];
    }

    $minutesWhere = '1 = 0';
    $minutesParams = [];
    if ($hasMinutesDivisionScope) {
        if (ems_is_management_division($userDivision)) {
            $minutesWhere = "((m.division_scope = 'All Divisi') OR (m.division_scope = 'All Divisi Manajemen') OR m.division_scope = ?)";
        } else {
            $minutesWhere = "((m.division_scope = 'All Divisi') OR m.division_scope = ?)";
        }
        $minutesParams = [$userDivision !== '' ? $userDivision : 'All Divisi'];
    }

    if ($minutesWhere !== '1 = 0') {
        $stmt = $pdo->prepare("
            SELECT
                m.id,
                m.meeting_title,
                m.meeting_date,
                m.meeting_time,
                m.participants,
                m.summary,
                m.decisions,
                m.follow_up,
                m.division_scope,
                m.created_at,
                COALESCE(s.is_read, 0) AS is_read
            FROM meeting_minutes m
            LEFT JOIN user_inbox_state s
                ON s.user_id = ?
               AND s.item_type = 'meeting_minutes'
               AND s.item_id = m.id
            WHERE {$minutesWhere}
              AND COALESCE(s.is_deleted, 0) = 0
            ORDER BY m.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(array_merge([$userId], $minutesParams));
        $minutesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $minutesIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $minutesRows)));
        if ($minutesIds && inboxTableExists($pdo, 'meeting_minutes_attachments')) {
            $placeholders = implode(',', array_fill(0, count($minutesIds), '?'));
            $stmt = $pdo->prepare("
                SELECT *
                FROM meeting_minutes_attachments
                WHERE meeting_minutes_id IN ($placeholders)
                ORDER BY meeting_minutes_id ASC, sort_order ASC, id ASC
            ");
            $stmt->execute($minutesIds);
            $minutesAttachmentsMap = inboxGroupAttachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'meeting_minutes_id');
        }

        foreach ($minutesRows as $row) {
            $items[] = [
                'id' => 'meeting_minutes:' . (int)$row['id'],
                'item_id' => (int)$row['id'],
                'source_type' => 'meeting_minutes',
                'title' => 'Notulen: ' . (string)$row['meeting_title'],
                'message' => inboxBuildMinutesMessage($row, $minutesAttachmentsMap[(int)($row['id'] ?? 0)] ?? []),
                'is_read' => (int)($row['is_read'] ?? 0),
                'created_at' => (string)$row['created_at'],
                'created_at_label' => inboxDateLabel($row['created_at'] ?? ''),
                'delete_label' => 'Sembunyikan',
                'badge' => 'NOTULEN',
            ];
        }
    }
}

usort($items, static function (array $left, array $right): int {
    return inboxSortKey($right) <=> inboxSortKey($left);
});

$items = array_slice($items, 0, 30);
$unread = 0;
foreach ($items as $item) {
    if ((int)($item['is_read'] ?? 0) === 0) {
        $unread++;
    }
}

echo json_encode([
    'unread' => $unread,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
