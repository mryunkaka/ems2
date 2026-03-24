<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$user = $_SESSION['user_rh'] ?? [];
$division = ems_normalize_division($user['division'] ?? '');

if ($division === 'Medis') {
    $_SESSION['flash_errors'][] = 'Halaman monitoring surat tidak tersedia untuk division Medis.';
    header('Location: /dashboard/index.php');
    exit;
}

$pageTitle = 'Monitoring Surat Menyurat';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function surat_monitoring_excerpt(?string $text, int $limit = 120): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '-';
    }

    $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit - 3)) . '...';
}

function surat_monitoring_status_meta(string $status): array
{
    return match (strtolower(trim($status))) {
        'unread', 'draft', 'scheduled', 'ongoing' => ['class' => 'badge-counter', 'label' => strtoupper($status)],
        'read', 'done', 'completed', 'issued' => ['class' => 'badge-success', 'label' => strtoupper($status)],
        'cancelled', 'archived' => ['class' => 'badge-danger', 'label' => strtoupper($status)],
        default => ['class' => 'badge-muted', 'label' => strtoupper($status !== '' ? $status : 'UNKNOWN')],
    };
}

function surat_monitoring_table_has_column(PDO $pdo, string $table, string $column): bool
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
    $cache[$key] = (bool) $stmt->fetchColumn();

    return $cache[$key];
}

function surat_monitoring_scope_label(string $division): string
{
    return $division !== '' ? $division : 'All Divisi';
}

function surat_monitoring_when(?string $date, ?string $time = null): string
{
    $date = trim((string) $date);
    $time = trim((string) $time);
    if ($date === '' && $time === '') {
        return '-';
    }

    $raw = trim($date . ' ' . $time);
    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return trim($date . ($time !== '' ? ' ' . substr($time, 0, 5) . ' WIB' : ''));
    }

    return date('d M Y H:i', $timestamp) . ' WIB';
}

function surat_monitoring_push_timeline(array &$items, string $type, string $title, string $subtitle, string $when, string $status, string $icon): void
{
    if ($when === '-') {
        return;
    }

    $sortKey = strtotime(str_replace(' WIB', '', $when)) ?: time();
    $items[] = [
        'type' => $type,
        'title' => $title,
        'subtitle' => $subtitle,
        'when' => $when,
        'status' => $status,
        'icon' => $icon,
        'sort_key' => $sortKey,
    ];
}

$scopeLabel = surat_monitoring_scope_label($division);
$summary = [
    'incoming' => 0,
    'outgoing' => 0,
    'minutes' => 0,
    'priority' => 0,
];
$incomingRows = [];
$outgoingRows = [];
$minutesRows = [];
$coordinationRows = [];
$timelineItems = [];
$hasMinutesCodeColumn = false;

try {
    $hasIncomingDivisionScope = surat_monitoring_table_has_column($pdo, 'incoming_letters', 'division_scope');
    $hasOutgoingDivisionScope = surat_monitoring_table_has_column($pdo, 'outgoing_letters', 'division_scope');
    $hasMinutesDivisionScope = surat_monitoring_table_has_column($pdo, 'meeting_minutes', 'division_scope');
    $hasMinutesCodeColumn = surat_monitoring_table_has_column($pdo, 'meeting_minutes', 'minutes_code');

    $incomingFilter = $hasIncomingDivisionScope ? "WHERE (l.division_scope = 'All Divisi' OR l.division_scope = :scope)" : '';
    $outgoingFilter = $hasOutgoingDivisionScope ? "WHERE (o.division_scope = 'All Divisi' OR o.division_scope = :scope)" : '';
    $minutesFilter = $hasMinutesDivisionScope ? "WHERE (m.division_scope = 'All Divisi' OR m.division_scope = :scope)" : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM incoming_letters l {$incomingFilter}");
    $stmt->execute($hasIncomingDivisionScope ? ['scope' => $scopeLabel] : []);
    $summary['incoming'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM outgoing_letters o {$outgoingFilter}");
    $stmt->execute($hasOutgoingDivisionScope ? ['scope' => $scopeLabel] : []);
    $summary['outgoing'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meeting_minutes m {$minutesFilter}");
    $stmt->execute($hasMinutesDivisionScope ? ['scope' => $scopeLabel] : []);
    $summary['minutes'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.letter_code,
            l.institution_name,
            l.sender_name,
            l.sender_phone,
            l.meeting_topic,
            l.notes,
            l.status,
            l.appointment_date,
            l.appointment_time,
            l.target_name_snapshot,
            l.submitted_at,
            " . ($hasIncomingDivisionScope ? "l.division_scope" : "'All Divisi'") . " AS division_scope
        FROM incoming_letters l
        {$incomingFilter}
        ORDER BY CASE WHEN l.status = 'unread' THEN 0 ELSE 1 END, l.submitted_at DESC
        LIMIT 8
    ");
    $stmt->execute($hasIncomingDivisionScope ? ['scope' => $scopeLabel] : []);
    $incomingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.outgoing_code,
            o.institution_name,
            o.recipient_name,
            o.recipient_contact,
            o.subject,
            o.letter_body,
            o.appointment_date,
            o.appointment_time,
            o.created_at,
            " . ($hasOutgoingDivisionScope ? "o.division_scope" : "'All Divisi'") . " AS division_scope,
            " . (surat_monitoring_table_has_column($pdo, 'outgoing_letters', 'revision_label') ? "o.revision_label" : "NULL") . " AS revision_label
        FROM outgoing_letters o
        {$outgoingFilter}
        ORDER BY o.created_at DESC
        LIMIT 8
    ");
    $stmt->execute($hasOutgoingDivisionScope ? ['scope' => $scopeLabel] : []);
    $outgoingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            m.id,
            " . ($hasMinutesCodeColumn ? "m.minutes_code" : "NULL") . " AS minutes_code,
            m.meeting_title,
            m.meeting_date,
            m.meeting_time,
            m.participants,
            m.summary,
            m.decisions,
            m.follow_up,
            m.created_at,
            " . ($hasMinutesDivisionScope ? "m.division_scope" : "'All Divisi'") . " AS division_scope,
            " . (surat_monitoring_table_has_column($pdo, 'meeting_minutes', 'revision_label') ? "m.revision_label" : "NULL") . " AS revision_label
        FROM meeting_minutes m
        {$minutesFilter}
        ORDER BY m.meeting_date DESC, m.meeting_time DESC, m.created_at DESC
        LIMIT 8
    ");
    $stmt->execute($hasMinutesDivisionScope ? ['scope' => $scopeLabel] : []);
    $minutesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $stmt = $pdo->prepare("
            SELECT id, title, division_scope, coordination_date, start_time, status, summary_notes, follow_up_notes
            FROM secretary_internal_coordinations
            WHERE division_scope = 'All Divisi' OR division_scope = ?
            ORDER BY coordination_date DESC, start_time DESC, id DESC
            LIMIT 6
        ");
        $stmt->execute([$scopeLabel]);
        $coordinationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $coordinationRows = [];
    }

    $summary['priority'] = count(array_filter($incomingRows, static fn($row) => ($row['status'] ?? '') === 'unread')) + count($minutesRows) + count($coordinationRows);

    foreach ($incomingRows as $row) {
        surat_monitoring_push_timeline($timelineItems, 'surat_masuk', (string) ($row['meeting_topic'] ?: 'Surat masuk baru'), (string) ($row['institution_name'] ?: '-'), surat_monitoring_when($row['appointment_date'] ?? '', $row['appointment_time'] ?? ''), (string) ($row['status'] ?: 'unread'), 'inbox');
    }

    foreach ($outgoingRows as $row) {
        surat_monitoring_push_timeline($timelineItems, 'surat_keluar', (string) ($row['subject'] ?: 'Surat keluar'), (string) ($row['institution_name'] ?: '-'), surat_monitoring_when($row['appointment_date'] ?? '', $row['appointment_time'] ?? ''), 'issued', 'paper-airplane');
    }

    foreach ($minutesRows as $row) {
        surat_monitoring_push_timeline($timelineItems, 'notulen', (string) ($row['meeting_title'] ?: 'Notulen'), surat_monitoring_excerpt((string) ($row['summary'] ?? ''), 70), surat_monitoring_when($row['meeting_date'] ?? '', $row['meeting_time'] ?? ''), 'done', 'clipboard-document-list');
    }

    usort($timelineItems, static fn($a, $b) => ($b['sort_key'] ?? 0) <=> ($a['sort_key'] ?? 0));
    $timelineItems = array_slice($timelineItems, 0, 9);
} catch (Throwable $e) {
    $errors[] = 'Data monitoring surat belum siap. Pastikan struktur tabel terbaru sudah dijalankan.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content surat-monitor-page">
    <div class="page surat-monitor-shell">
        <div class="surat-focus-hero">
            <div>
                <div class="surat-focus-kicker">Monitoring Khusus Divisi</div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Halaman ini hanya menampilkan surat masuk, surat keluar, notulen, dan koordinasi yang relevan untuk divisi <strong><?= htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
            </div>
            <div class="surat-scope-card">
                <div class="surat-scope-label">Scope Aktif</div>
                <div class="surat-scope-value"><?= htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="surat-scope-note">Data `All Divisi` tetap tampil untuk semua divisi.</div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="surat-stat-grid">
            <?php ems_component('ui/statistic-card', ['label' => 'Surat Masuk Relevan', 'value' => $summary['incoming'], 'icon' => 'inbox', 'tone' => 'warning']); ?>
            <?php ems_component('ui/statistic-card', ['label' => 'Surat Keluar Relevan', 'value' => $summary['outgoing'], 'icon' => 'paper-airplane', 'tone' => 'primary']); ?>
            <?php ems_component('ui/statistic-card', ['label' => 'Notulen Relevan', 'value' => $summary['minutes'], 'icon' => 'clipboard-document-list', 'tone' => 'success']); ?>
            <?php ems_component('ui/statistic-card', ['label' => 'Item Prioritas', 'value' => $summary['priority'], 'icon' => 'exclamation-triangle', 'tone' => 'danger']); ?>
        </div>

        <div class="card surat-search-card">
            <div class="surat-search-header">
                <div>
                    <div class="card-header">Pencarian Cepat</div>
                    <p class="meta-text">Cari instansi, topik, judul notulen, atau isi ringkas tanpa harus membaca semua kartu.</p>
                </div>
                <label class="surat-search-input">
                    <?= ems_icon('magnifying-glass', 'h-5 w-5') ?>
                    <input type="search" id="suratMonitoringSearch" placeholder="Cari data monitoring divisi ini...">
                </label>
            </div>
        </div>

        <div class="surat-focus-layout">
            <div class="surat-focus-grid">
                <div class="card surat-focus-card">
                    <div class="surat-card-head">
                        <div>
                            <div class="card-header">Prioritas Hari Ini</div>
                            <p class="meta-text">Ringkasan cepat untuk item yang paling relevan bagi divisi ini.</p>
                        </div>
                    </div>
                    <div class="surat-spotlight-list">
                        <?php if ($timelineItems): ?>
                            <?php foreach ($timelineItems as $item): $statusMeta = surat_monitoring_status_meta($item['status']); ?>
                                <article class="surat-spotlight-item surat-search-item" data-search-scope="<?= htmlspecialchars(strtolower($item['title'] . ' ' . $item['subtitle'] . ' ' . $item['type']), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="surat-spotlight-icon"><?= ems_icon($item['icon'], 'h-5 w-5') ?></div>
                                    <div class="surat-spotlight-body">
                                        <div class="surat-spotlight-top">
                                            <h3><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <p><?= htmlspecialchars($item['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <div class="surat-spotlight-meta"><?= htmlspecialchars($item['when'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">Belum ada aktivitas monitoring untuk divisi ini.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="surat-feed-grid">
                    <div class="card surat-focus-card">
                        <div class="surat-card-head">
                            <div class="card-header">Surat Masuk Untuk Scope Ini</div>
                            <span class="badge-muted"><?= count($incomingRows) ?> item</span>
                        </div>
                        <div class="surat-capsule-list">
                            <?php if ($incomingRows): ?>
                                <?php foreach ($incomingRows as $row): $statusMeta = surat_monitoring_status_meta((string) ($row['status'] ?? 'unread')); ?>
                                    <article class="surat-capsule surat-search-item" data-search-scope="<?= htmlspecialchars(strtolower(($row['institution_name'] ?? '') . ' ' . ($row['meeting_topic'] ?? '') . ' ' . ($row['notes'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="surat-capsule-top">
                                            <div>
                                                <div class="surat-capsule-title"><?= htmlspecialchars((string) ($row['meeting_topic'] ?: 'Surat Masuk'), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="surat-capsule-subtitle"><?= htmlspecialchars((string) ($row['institution_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="surat-capsule-text"><?= htmlspecialchars(surat_monitoring_excerpt((string) ($row['notes'] ?? ''), 120), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="surat-capsule-meta"><?= htmlspecialchars((string) ($row['division_scope'] ?: 'All Divisi'), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars(surat_monitoring_when($row['appointment_date'] ?? '', $row['appointment_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        <button type="button" class="btn-secondary btn-open-letter-modal" data-letter-type="Surat Masuk" data-title="<?= htmlspecialchars((string) ($row['meeting_topic'] ?: 'Surat Masuk'), ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars((string) ($row['letter_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-party="<?= htmlspecialchars((string) ($row['institution_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-contact="<?= htmlspecialchars((string) ($row['sender_name'] ?: '-') . ' | ' . (string) ($row['sender_phone'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-when="<?= htmlspecialchars(surat_monitoring_when($row['appointment_date'] ?? '', $row['appointment_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-scope="<?= htmlspecialchars((string) ($row['division_scope'] ?: 'All Divisi'), ENT_QUOTES, 'UTF-8') ?>" data-body="<?= htmlspecialchars((string) ($row['notes'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>">Lihat Detail</button>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">Tidak ada surat masuk untuk divisi ini.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card surat-focus-card">
                        <div class="surat-card-head">
                            <div class="card-header">Surat Keluar Divisi</div>
                            <span class="badge-muted"><?= count($outgoingRows) ?> item</span>
                        </div>
                        <div class="surat-capsule-list">
                            <?php if ($outgoingRows): ?>
                                <?php foreach ($outgoingRows as $row): ?>
                                    <article class="surat-capsule surat-search-item" data-search-scope="<?= htmlspecialchars(strtolower(($row['institution_name'] ?? '') . ' ' . ($row['subject'] ?? '') . ' ' . ($row['letter_body'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="surat-capsule-top">
                                            <div>
                                                <div class="surat-capsule-title"><?= htmlspecialchars((string) ($row['subject'] ?: 'Surat Keluar'), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="surat-capsule-subtitle"><?= htmlspecialchars((string) ($row['institution_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <span class="badge-success"><?= htmlspecialchars((string) (($row['revision_label'] ?: 'draft-awal')), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="surat-capsule-text"><?= htmlspecialchars(surat_monitoring_excerpt((string) ($row['letter_body'] ?? ''), 120), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="surat-capsule-meta"><?= htmlspecialchars((string) ($row['division_scope'] ?: 'All Divisi'), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars(surat_monitoring_when($row['appointment_date'] ?? '', $row['appointment_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        <button type="button" class="btn-secondary btn-open-letter-modal" data-letter-type="Surat Keluar" data-title="<?= htmlspecialchars((string) ($row['subject'] ?: 'Surat Keluar'), ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars((string) ($row['outgoing_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-party="<?= htmlspecialchars((string) ($row['institution_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-contact="<?= htmlspecialchars((string) ($row['recipient_name'] ?: '-') . ' | ' . (string) ($row['recipient_contact'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-when="<?= htmlspecialchars(surat_monitoring_when($row['appointment_date'] ?? '', $row['appointment_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-scope="<?= htmlspecialchars((string) ($row['division_scope'] ?: 'All Divisi'), ENT_QUOTES, 'UTF-8') ?>" data-body="<?= htmlspecialchars((string) ($row['letter_body'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>">Lihat Detail</button>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">Belum ada surat keluar pada scope ini.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="surat-focus-side">
                <div class="card surat-focus-card">
                    <div class="surat-card-head">
                        <div class="card-header">Notulen Yang Di-tag ke Divisi Ini</div>
                        <span class="badge-muted"><?= count($minutesRows) ?> item</span>
                    </div>
                    <div class="surat-mini-list">
                        <?php if ($minutesRows): ?>
                            <?php foreach ($minutesRows as $row): ?>
                                <article class="surat-mini-card surat-search-item" data-search-scope="<?= htmlspecialchars(strtolower(($row['meeting_title'] ?? '') . ' ' . ($row['summary'] ?? '') . ' ' . ($row['participants'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="surat-mini-top">
                                        <div>
                                            <h3><?= htmlspecialchars((string) ($row['meeting_title'] ?: 'Notulen'), ENT_QUOTES, 'UTF-8') ?></h3>
                                            <div class="surat-capsule-subtitle"><?= htmlspecialchars((string) ($row['minutes_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <span class="badge-success"><?= htmlspecialchars((string) (($row['revision_label'] ?: 'draft-awal')), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <p><?= htmlspecialchars(surat_monitoring_excerpt((string) ($row['summary'] ?? ''), 100), ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="surat-mini-meta"><?= htmlspecialchars((string) ($row['division_scope'] ?: 'All Divisi'), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars(surat_monitoring_when($row['meeting_date'] ?? '', $row['meeting_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="button" class="btn-secondary btn-view-minutes" data-title="<?= htmlspecialchars((string) ($row['meeting_title'] ?: 'Notulen'), ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars((string) ($row['minutes_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-date="<?= htmlspecialchars((string) ($row['meeting_date'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-time="<?= htmlspecialchars(substr((string) ($row['meeting_time'] ?: ''), 0, 5), ENT_QUOTES, 'UTF-8') ?>" data-participants="<?= htmlspecialchars((string) ($row['participants'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-summary="<?= htmlspecialchars((string) ($row['summary'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-decisions="<?= htmlspecialchars((string) ($row['decisions'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-follow-up="<?= htmlspecialchars((string) ($row['follow_up'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>" data-scope="<?= htmlspecialchars((string) ($row['division_scope'] ?: 'All Divisi'), ENT_QUOTES, 'UTF-8') ?>" data-revision="<?= htmlspecialchars((string) (($row['revision_label'] ?: 'draft-awal')), ENT_QUOTES, 'UTF-8') ?>">Buka Notulen</button>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">Belum ada notulen yang ditag ke divisi ini.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card surat-focus-card">
                    <div class="surat-card-head">
                        <div class="card-header">Koordinasi Terkait Divisi</div>
                        <span class="badge-muted"><?= count($coordinationRows) ?> item</span>
                    </div>
                    <div class="surat-mini-list">
                        <?php if ($coordinationRows): ?>
                            <?php foreach ($coordinationRows as $row): $statusMeta = surat_monitoring_status_meta((string) ($row['status'] ?? 'draft')); ?>
                                <article class="surat-mini-card surat-search-item" data-search-scope="<?= htmlspecialchars(strtolower(($row['title'] ?? '') . ' ' . ($row['summary_notes'] ?? '') . ' ' . ($row['follow_up_notes'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="surat-mini-top">
                                        <h3><?= htmlspecialchars((string) ($row['title'] ?: 'Koordinasi'), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <p><?= htmlspecialchars(surat_monitoring_excerpt((string) ($row['summary_notes'] ?? ''), 90), ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="surat-mini-meta"><?= htmlspecialchars((string) ($row['division_scope'] ?: 'All Divisi'), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars(surat_monitoring_when($row['coordination_date'] ?? '', $row['start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">Tidak ada koordinasi internal untuk scope ini.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>

<div id="letterDetailModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg surat-modal-box">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('document-text', 'h-5 w-5 text-primary') ?>
                <span id="letterModalType">Detail Surat</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal"><?= ems_icon('x-mark', 'h-5 w-5') ?></button>
        </div>
        <div class="modal-content">
            <div class="surat-modal-grid">
                <div class="card"><div class="meta-text-xs">Judul</div><div id="letterModalTitle" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Kode</div><div id="letterModalCode" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Scope</div><div id="letterModalScope" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Instansi</div><div id="letterModalParty" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Kontak</div><div id="letterModalContact" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Jadwal</div><div id="letterModalWhen" class="font-semibold text-slate-800">-</div></div>
            </div>
            <div class="card mt-4">
                <div class="meta-text-xs mb-2">Isi / Keterangan</div>
                <div id="letterModalBody" class="whitespace-pre-line text-sm text-slate-700">-</div>
            </div>
        </div>
        <div class="modal-foot"><div class="modal-actions justify-end"><button type="button" class="btn-secondary btn-cancel">Tutup</button></div></div>
    </div>
</div>

<div id="minutesViewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg surat-modal-box">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('clipboard-document-list', 'h-5 w-5 text-primary') ?>
                <span id="minutesModalTitle">Detail Notulen</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal"><?= ems_icon('x-mark', 'h-5 w-5') ?></button>
        </div>
        <div class="modal-content">
            <div class="surat-modal-grid">
                <div class="card"><div class="meta-text-xs">Kode</div><div id="minutesModalCode" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Tanggal</div><div id="minutesModalDate" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Jam</div><div id="minutesModalTime" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Scope</div><div id="minutesModalScope" class="font-semibold text-slate-800">-</div></div>
                <div class="card"><div class="meta-text-xs">Revisi</div><div id="minutesModalRevision" class="font-semibold text-slate-800">-</div></div>
            </div>
            <div class="card mt-4"><div class="meta-text-xs mb-2">Peserta</div><div id="minutesModalParticipants" class="whitespace-pre-line text-sm text-slate-700">-</div></div>
            <div class="card mt-3"><div class="meta-text-xs mb-2">Ringkasan</div><div id="minutesModalSummary" class="whitespace-pre-line text-sm text-slate-700">-</div></div>
            <div class="card mt-3"><div class="meta-text-xs mb-2">Keputusan</div><div id="minutesModalDecisions" class="whitespace-pre-line text-sm text-slate-700">-</div></div>
            <div class="card mt-3"><div class="meta-text-xs mb-2">Tindak Lanjut</div><div id="minutesModalFollowUp" class="whitespace-pre-line text-sm text-slate-700">-</div></div>
        </div>
        <div class="modal-foot"><div class="modal-actions justify-end"><button type="button" class="btn-secondary btn-cancel">Tutup</button></div></div>
    </div>
</div>

<style>
.surat-monitor-page{padding-bottom:2rem}.surat-monitor-shell{display:grid;gap:1.25rem}.surat-focus-hero{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(280px,.9fr);gap:1rem;padding:1.5rem;border-radius:28px;background:linear-gradient(135deg,#eff8ff 0%,#ffffff 52%,#f0fdf4 100%);border:1px solid rgba(148,163,184,.22);box-shadow:0 24px 48px rgba(15,23,42,.08)}.surat-focus-kicker{display:inline-flex;align-items:center;gap:.4rem;margin-bottom:.6rem;padding:.35rem .75rem;border-radius:999px;background:#083344;color:#ecfeff;font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.surat-scope-card{padding:1.1rem 1.15rem;border-radius:24px;background:linear-gradient(180deg,#0f172a 0%,#1e293b 100%);color:#e2e8f0}.surat-scope-label{font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8}.surat-scope-value{margin-top:.35rem;font-size:1.45rem;font-weight:800;color:#fff}.surat-scope-note{margin-top:.55rem;font-size:.92rem;color:#cbd5e1}.surat-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}.surat-search-card{padding:1rem 1.15rem}.surat-search-header{display:flex;align-items:center;justify-content:space-between;gap:1rem}.surat-search-input{display:flex;align-items:center;gap:.65rem;min-width:min(100%,360px);padding:.8rem 1rem;border:1px solid #cbd5e1;border-radius:18px;background:#fff;color:#475569}.surat-search-input input{width:100%;border:0;outline:0;background:transparent}.surat-focus-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(320px,.95fr);gap:1rem}.surat-focus-grid,.surat-focus-side{display:grid;gap:1rem}.surat-focus-card{border-radius:26px;padding:1.15rem;box-shadow:0 20px 40px rgba(15,23,42,.06)}.surat-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1rem}.surat-spotlight-list,.surat-capsule-list,.surat-mini-list{display:grid;gap:.85rem}.surat-spotlight-item,.surat-capsule,.surat-mini-card{border:1px solid #dbeafe;background:#fff;border-radius:22px;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.surat-spotlight-item:hover,.surat-capsule:hover,.surat-mini-card:hover{transform:translateY(-2px);box-shadow:0 18px 30px rgba(14,116,144,.08);border-color:#7dd3fc}.surat-spotlight-item{display:grid;grid-template-columns:52px minmax(0,1fr);gap:1rem;padding:1rem}.surat-spotlight-icon{display:flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:18px;background:linear-gradient(135deg,#0ea5e9,#22c55e);color:#fff}.surat-spotlight-top,.surat-mini-top,.surat-capsule-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem}.surat-spotlight-body h3,.surat-mini-top h3,.surat-capsule-title{margin:0;font-size:1.05rem;font-weight:800;color:#1e293b}.surat-spotlight-body p,.surat-mini-card p,.surat-capsule-text{margin:.4rem 0 0;color:#64748b;line-height:1.55}.surat-spotlight-meta,.surat-mini-meta,.surat-capsule-meta{margin-top:.6rem;font-size:.82rem;font-weight:700;color:#0f766e}.surat-feed-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}.surat-capsule{padding:1rem 1rem 1.05rem;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%)}.surat-capsule-subtitle{margin-top:.25rem;font-size:.88rem;color:#64748b}.surat-capsule .btn-secondary,.surat-mini-card .btn-secondary{margin-top:.8rem}.surat-mini-card{padding:1rem;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%)}.surat-modal-box{max-width:860px}.surat-modal-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.85rem}.surat-search-item.is-hidden{display:none!important}.empty-state{padding:1rem 1.1rem;border:1px dashed #cbd5e1;border-radius:18px;background:#f8fafc;color:#64748b}.badge-muted,.badge-counter,.badge-success,.badge-danger{white-space:nowrap}
@media (max-width:1200px){.surat-stat-grid,.surat-feed-grid,.surat-modal-grid,.surat-focus-layout,.surat-focus-hero{grid-template-columns:1fr}}
@media (max-width:720px){.surat-search-header,.surat-card-head,.surat-spotlight-top,.surat-mini-top,.surat-capsule-top{flex-direction:column;align-items:flex-start}.surat-focus-card{padding:1rem}.surat-search-input{min-width:100%}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('suratMonitoringSearch');
    const filterItems = document.querySelectorAll('.surat-search-item');
    const letterModal = document.getElementById('letterDetailModal');
    const minutesModal = document.getElementById('minutesViewModal');
    function openModal(modal){if(!modal)return;modal.classList.remove('hidden');document.body.classList.add('overflow-hidden')}
    function closeModal(modal){if(!modal)return;modal.classList.add('hidden');document.body.classList.remove('overflow-hidden')}
    document.querySelectorAll('.modal-overlay').forEach(function(modal){modal.addEventListener('click',function(event){if(event.target===modal||event.target.closest('.btn-cancel')||event.target.closest('.modal-close-btn')){closeModal(modal)}})});
    document.addEventListener('keydown',function(event){if(event.key==='Escape'){closeModal(letterModal);closeModal(minutesModal)}});
    if(searchInput){searchInput.addEventListener('input',function(){const keyword=searchInput.value.trim().toLowerCase();filterItems.forEach(function(item){const haystack=item.dataset.searchScope||'';item.classList.toggle('is-hidden',keyword!==''&&!haystack.includes(keyword))})})}
    document.querySelectorAll('.btn-open-letter-modal').forEach(function(button){button.addEventListener('click',function(){document.getElementById('letterModalType').textContent=button.dataset.letterType||'Detail Surat';document.getElementById('letterModalTitle').textContent=button.dataset.title||'-';document.getElementById('letterModalCode').textContent=button.dataset.code||'-';document.getElementById('letterModalScope').textContent=button.dataset.scope||'-';document.getElementById('letterModalParty').textContent=button.dataset.party||'-';document.getElementById('letterModalContact').textContent=button.dataset.contact||'-';document.getElementById('letterModalWhen').textContent=button.dataset.when||'-';document.getElementById('letterModalBody').textContent=button.dataset.body||'-';openModal(letterModal)})});
    document.querySelectorAll('.btn-view-minutes').forEach(function(button){button.addEventListener('click',function(){document.getElementById('minutesModalTitle').textContent=button.dataset.title||'Detail Notulen';document.getElementById('minutesModalCode').textContent=button.dataset.code||'-';document.getElementById('minutesModalDate').textContent=button.dataset.date||'-';document.getElementById('minutesModalTime').textContent=button.dataset.time?button.dataset.time+' WIB':'-';document.getElementById('minutesModalScope').textContent=button.dataset.scope||'-';document.getElementById('minutesModalRevision').textContent=button.dataset.revision||'-';document.getElementById('minutesModalParticipants').textContent=button.dataset.participants||'-';document.getElementById('minutesModalSummary').textContent=button.dataset.summary||'-';document.getElementById('minutesModalDecisions').textContent=button.dataset.decisions||'-';document.getElementById('minutesModalFollowUp').textContent=button.dataset.followUp||'-';openModal(minutesModal)})});
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
