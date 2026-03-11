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

function surat_monitoring_excerpt(?string $text, int $limit = 110): string
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

function surat_monitoring_status_meta(string $status): array
{
    return match ($status) {
        'unread', 'draft', 'scheduled', 'ongoing', 'logged' => ['class' => 'badge-counter', 'label' => strtoupper($status)],
        'read', 'done', 'completed', 'distributed', 'issued' => ['class' => 'badge-success', 'label' => strtoupper($status)],
        'sealed', 'confidential' => ['class' => 'badge-warning', 'label' => strtoupper($status)],
        'cancelled', 'archived' => ['class' => 'badge-danger', 'label' => strtoupper($status)],
        default => ['class' => 'badge-muted', 'label' => strtoupper($status !== '' ? $status : 'UNKNOWN')],
    };
}

function surat_monitoring_push_timeline(array &$items, string $type, string $title, string $subtitle, string $status, string $dateTime, string $icon): void
{
    if ($dateTime === '') {
        return;
    }

    $items[] = [
        'type' => $type,
        'title' => $title,
        'subtitle' => $subtitle,
        'status' => $status,
        'datetime' => $dateTime,
        'icon' => $icon,
    ];
}

$summary = [
    'incoming' => 0,
    'outgoing' => 0,
    'minutes' => 0,
    'active_flows' => 0,
];
$incomingRows = [];
$outgoingRows = [];
$minutesRows = [];
$agendaRows = [];
$coordinationRows = [];
$confidentialRows = [];
$timelineItems = [];

try {
    $summary['incoming'] = (int)$pdo->query("SELECT COUNT(*) FROM incoming_letters")->fetchColumn();
    $summary['outgoing'] = (int)$pdo->query("SELECT COUNT(*) FROM outgoing_letters")->fetchColumn();
    $summary['minutes'] = (int)$pdo->query("SELECT COUNT(*) FROM meeting_minutes")->fetchColumn();

    $incomingRows = $pdo->query("
        SELECT
            id,
            letter_code,
            institution_name,
            sender_name,
            sender_phone,
            meeting_topic,
            notes,
            status,
            appointment_date,
            appointment_time,
            target_name_snapshot,
            submitted_at
        FROM incoming_letters
        ORDER BY submitted_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $outgoingRows = $pdo->query("
        SELECT
            id,
            outgoing_code,
            institution_name,
            recipient_name,
            recipient_contact,
            subject,
            letter_body,
            appointment_date,
            appointment_time,
            created_at
        FROM outgoing_letters
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $minutesRows = $pdo->query("
        SELECT
            id,
            meeting_title,
            meeting_date,
            meeting_time,
            participants,
            summary,
            decisions,
            follow_up,
            created_at
        FROM meeting_minutes
        ORDER BY meeting_date DESC, meeting_time DESC, created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Data surat utama belum siap untuk ditampilkan penuh.';
}

try {
    $agendaRows = $pdo->query("
        SELECT agenda_code, visitor_name, origin_name, visit_date, visit_time, location, status
        FROM secretary_visit_agendas
        ORDER BY visit_date DESC, visit_time DESC, id DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $agendaRows = [];
}

try {
    $coordinationRows = $pdo->query("
        SELECT coordination_code, title, division_scope, coordination_date, start_time, status
        FROM secretary_internal_coordinations
        ORDER BY coordination_date DESC, start_time DESC, id DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $coordinationRows = [];
}

try {
    $confidentialRows = $pdo->query("
        SELECT confidential_code, subject, letter_direction, letter_date, confidentiality_level, status
        FROM secretary_confidential_letters
        ORDER BY letter_date DESC, id DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $confidentialRows = [];
}

$summary['active_flows'] =
    count(array_filter($incomingRows, static fn($row) => ($row['status'] ?? '') === 'unread')) +
    count($agendaRows) +
    count($coordinationRows);

foreach ($incomingRows as $row) {
    surat_monitoring_push_timeline(
        $timelineItems,
        'Incoming',
        (string)($row['letter_code'] ?? 'Incoming Letter'),
        trim((string)($row['institution_name'] ?? '-') . ' · ' . (string)($row['meeting_topic'] ?? '-')),
        (string)($row['status'] ?? 'unread'),
        (string)($row['submitted_at'] ?? ''),
        'inbox'
    );
}

foreach ($outgoingRows as $row) {
    surat_monitoring_push_timeline(
        $timelineItems,
        'Outgoing',
        (string)($row['outgoing_code'] ?? 'Outgoing Letter'),
        trim((string)($row['institution_name'] ?? '-') . ' · ' . (string)($row['subject'] ?? '-')),
        'issued',
        (string)($row['created_at'] ?? ''),
        'arrow-right-circle'
    );
}

foreach ($minutesRows as $row) {
    surat_monitoring_push_timeline(
        $timelineItems,
        'Minutes',
        (string)($row['meeting_title'] ?? 'Meeting Minutes'),
        surat_monitoring_excerpt((string)($row['summary'] ?? '-'), 90),
        'completed',
        (string)($row['created_at'] ?? ''),
        'clipboard-document-list'
    );
}

usort($timelineItems, static function (array $left, array $right): int {
    return strtotime($right['datetime']) <=> strtotime($left['datetime']);
});
$timelineItems = array_slice($timelineItems, 0, 12);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="surat-monitoring-hero card card-section mb-4">
            <div class="surat-monitoring-hero__copy">
                <div class="surat-monitoring-kicker">Secretary Bridge View</div>
                <h1 class="page-title mb-2"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle mb-0">
                    Pemantauan arus informasi antar division, internal ke executive, dan internal ke instansi eksternal dalam satu layar ringkas.
                </p>
            </div>
            <div class="surat-monitoring-legend">
                <div class="surat-legend-item"><span class="surat-legend-item__dot surat-legend-item__dot--incoming"></span> Surat masuk</div>
                <div class="surat-legend-item"><span class="surat-legend-item__dot surat-legend-item__dot--outgoing"></span> Surat keluar</div>
                <div class="surat-legend-item"><span class="surat-legend-item__dot surat-legend-item__dot--minutes"></span> Notulen</div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Surat Masuk', 'value' => $summary['incoming'], 'icon' => 'inbox', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Surat Keluar', 'value' => $summary['outgoing'], 'icon' => 'arrow-right-circle', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Notulen', 'value' => $summary['minutes'], 'icon' => 'clipboard-document-list', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Flow Aktif', 'value' => $summary['active_flows'], 'icon' => 'clock', 'tone' => 'muted']);
            ?>
        </div>

        <div class="card card-section surat-search-panel mb-4">
            <div class="surat-search-panel__copy">
                <div class="card-header">Pencarian Surat</div>
                <p class="meta-text mt-1 mb-0">Cari surat masuk, surat keluar, dan notulen langsung dari halaman monitoring.</p>
            </div>
            <div class="surat-search-panel__field">
                <input
                    type="search"
                    id="suratMonitoringSearch"
                    class="form-control"
                    placeholder="Cari kode surat, instansi, pengirim, penerima, topik, atau notulen..."
                    autocomplete="off">
            </div>
        </div>

        <div class="surat-monitoring-layout">
            <div class="surat-monitoring-main">
                <div class="card card-section surat-panel mb-4">
                    <div class="card-header-between">
                        <div>
                            <div class="card-header">Alur Informasi Terkini</div>
                            <p class="meta-text mt-1">Urutan terbaru dari surat masuk, surat keluar, dan notulen yang menjadi jembatan antar division.</p>
                        </div>
                        <span class="badge-counter"><?= count($timelineItems) ?> item</span>
                    </div>

                    <div class="surat-panel__body surat-panel__body--timeline" data-search-scope="timeline">
                    <div class="surat-timeline">
                        <?php if ($timelineItems): ?>
                            <?php foreach ($timelineItems as $item): ?>
                                <?php $meta = surat_monitoring_status_meta((string)$item['status']); ?>
                                <article
                                    class="surat-timeline-item surat-search-item"
                                    data-search="<?= htmlspecialchars(strtolower(trim((string)$item['type'] . ' ' . (string)$item['title'] . ' ' . (string)$item['subtitle'] . ' ' . (string)$item['status'])), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="surat-timeline-item__icon">
                                        <?= ems_icon((string)$item['icon'], 'h-4 w-4') ?>
                                    </div>
                                    <div class="surat-timeline-item__body">
                                        <div class="surat-timeline-item__head">
                                            <div>
                                                <div class="surat-timeline-item__type"><?= htmlspecialchars((string)$item['type'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <h3 class="surat-timeline-item__title"><?= htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            </div>
                                            <span class="<?= htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <p class="surat-timeline-item__subtitle"><?= htmlspecialchars((string)$item['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <div class="surat-timeline-item__time"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$item['datetime'])), ENT_QUOTES, 'UTF-8') ?> WIB</div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="medical-document-card__empty">Belum ada alur surat yang bisa ditampilkan.</div>
                        <?php endif; ?>
                    </div>
                    <div class="medical-document-card__empty surat-search-empty hidden">Tidak ada hasil yang cocok pada alur informasi.</div>
                    </div>
                </div>

                <div class="surat-monitoring-split">
                    <div class="card card-section surat-panel">
                        <div class="card-header">Surat Masuk Terbaru</div>
                        <div class="surat-panel__body surat-panel__body--feed" data-search-scope="incoming">
                        <div class="surat-feed">
                            <?php foreach ($incomingRows as $row): ?>
                                <?php $meta = surat_monitoring_status_meta((string)($row['status'] ?? '')); ?>
                                <button
                                    type="button"
                                    class="surat-feed-item surat-feed-item--button btn-open-letter-modal surat-search-item"
                                    data-search="<?= htmlspecialchars(strtolower(trim(
                                        (string)($row['letter_code'] ?? '') . ' ' .
                                        (string)($row['institution_name'] ?? '') . ' ' .
                                        (string)($row['sender_name'] ?? '') . ' ' .
                                        (string)($row['sender_phone'] ?? '') . ' ' .
                                        (string)($row['meeting_topic'] ?? '') . ' ' .
                                        (string)($row['notes'] ?? '') . ' ' .
                                        (string)($row['target_name_snapshot'] ?? '') . ' ' .
                                        (string)($row['status'] ?? '')
                                    )), ENT_QUOTES, 'UTF-8') ?>"
                                    data-letter-kind="incoming"
                                    data-title="<?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-code="<?= htmlspecialchars((string)$row['letter_code'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-status="<?= htmlspecialchars((string)$meta['label'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-primary="<?= htmlspecialchars((string)$row['meeting_topic'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-secondary="<?= htmlspecialchars((string)($row['notes'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-meta-left="<?= htmlspecialchars((string)$row['sender_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-meta-right="<?= htmlspecialchars((string)($row['sender_phone'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-date="<?= htmlspecialchars((string)$row['appointment_date'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-time="<?= htmlspecialchars((string)(substr((string)$row['appointment_time'], 0, 5)), ENT_QUOTES, 'UTF-8') ?>"
                                    data-target="<?= htmlspecialchars((string)($row['target_name_snapshot'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-created="<?= htmlspecialchars(date('d M Y H:i', strtotime((string)$row['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="surat-feed-item__title-row">
                                        <strong><?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="<?= htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)$row['letter_code'], ENT_QUOTES, 'UTF-8') ?> · Pengirim: <?= htmlspecialchars((string)$row['sender_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <p class="surat-feed-item__copy"><?= htmlspecialchars(surat_monitoring_excerpt((string)$row['meeting_topic'], 120), ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="surat-feed-item__foot">
                                        <span>Tujuan: <?= htmlspecialchars((string)($row['target_name_snapshot'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars(date('d M H:i', strtotime((string)$row['submitted_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="medical-document-card__empty surat-search-empty hidden">Tidak ada surat masuk yang cocok dengan pencarian.</div>
                        </div>
                    </div>

                    <div class="card card-section surat-panel">
                        <div class="card-header">Surat Keluar & Follow Up</div>
                        <div class="surat-panel__body surat-panel__body--feed" data-search-scope="outgoing">
                        <div class="surat-feed">
                            <?php foreach ($outgoingRows as $row): ?>
                                <button
                                    type="button"
                                    class="surat-feed-item surat-feed-item--button btn-open-letter-modal surat-search-item"
                                    data-search="<?= htmlspecialchars(strtolower(trim(
                                        (string)($row['outgoing_code'] ?? '') . ' ' .
                                        (string)($row['institution_name'] ?? '') . ' ' .
                                        (string)($row['recipient_name'] ?? '') . ' ' .
                                        (string)($row['recipient_contact'] ?? '') . ' ' .
                                        (string)($row['subject'] ?? '') . ' ' .
                                        (string)($row['letter_body'] ?? '')
                                    )), ENT_QUOTES, 'UTF-8') ?>"
                                    data-letter-kind="outgoing"
                                    data-title="<?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-code="<?= htmlspecialchars((string)$row['outgoing_code'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-status="ISSUED"
                                    data-primary="<?= htmlspecialchars((string)$row['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-secondary="<?= htmlspecialchars((string)($row['letter_body'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-meta-left="<?= htmlspecialchars((string)($row['recipient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-meta-right="<?= htmlspecialchars((string)($row['recipient_contact'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-date="<?= htmlspecialchars((string)($row['appointment_date'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-time="<?= htmlspecialchars((string)($row['appointment_time'] ? substr((string)$row['appointment_time'], 0, 5) : '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-target="<?= htmlspecialchars((string)($row['recipient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-created="<?= htmlspecialchars(date('d M Y H:i', strtotime((string)$row['created_at'])), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="surat-feed-item__title-row">
                                        <strong><?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="badge-success">ISSUED</span>
                                    </div>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)$row['outgoing_code'], ENT_QUOTES, 'UTF-8') ?> · Tujuan: <?= htmlspecialchars((string)($row['recipient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <p class="surat-feed-item__copy"><?= htmlspecialchars(surat_monitoring_excerpt((string)$row['subject'], 120), ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="surat-feed-item__foot">
                                        <span><?= htmlspecialchars((string)($row['appointment_date'] ?: '-'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($row['appointment_time'] ? substr((string)$row['appointment_time'], 0, 5) : '', ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars(date('d M H:i', strtotime((string)$row['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="medical-document-card__empty surat-search-empty hidden">Tidak ada surat keluar yang cocok dengan pencarian.</div>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="surat-monitoring-side">
                <div class="card card-section surat-panel mb-4">
                    <div class="card-header">Notulen Terakhir</div>
                    <div class="surat-panel__body surat-panel__body--feed" data-search-scope="minutes">
                    <div class="surat-feed">
                        <?php foreach ($minutesRows as $row): ?>
                            <button
                                type="button"
                                class="surat-feed-item surat-feed-item--button btn-view-minutes surat-search-item"
                                data-search="<?= htmlspecialchars(strtolower(trim(
                                    (string)($row['meeting_title'] ?? '') . ' ' .
                                    (string)($row['meeting_date'] ?? '') . ' ' .
                                    (string)($row['participants'] ?? '') . ' ' .
                                    (string)($row['summary'] ?? '') . ' ' .
                                    (string)($row['decisions'] ?? '') . ' ' .
                                    (string)($row['follow_up'] ?? '')
                                )), ENT_QUOTES, 'UTF-8') ?>"
                                data-title="<?= htmlspecialchars((string)$row['meeting_title'], ENT_QUOTES, 'UTF-8') ?>"
                                data-date="<?= htmlspecialchars((string)$row['meeting_date'], ENT_QUOTES, 'UTF-8') ?>"
                                data-time="<?= htmlspecialchars(substr((string)$row['meeting_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>"
                                data-created-by="<?= htmlspecialchars(date('d M Y H:i', strtotime((string)$row['created_at'])), ENT_QUOTES, 'UTF-8') ?>"
                                data-participants="<?= htmlspecialchars((string)$row['participants'], ENT_QUOTES, 'UTF-8') ?>"
                                data-summary="<?= htmlspecialchars((string)$row['summary'], ENT_QUOTES, 'UTF-8') ?>"
                                data-decisions="<?= htmlspecialchars((string)($row['decisions'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>"
                                data-follow-up="<?= htmlspecialchars((string)($row['follow_up'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="surat-feed-item__title-row">
                                    <strong><?= htmlspecialchars((string)$row['meeting_title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="badge-success">MINUTES</span>
                                </div>
                                <div class="meta-text-xs"><?= htmlspecialchars((string)$row['meeting_date'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars(substr((string)$row['meeting_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> WIB</div>
                                <p class="surat-feed-item__copy"><?= htmlspecialchars(surat_monitoring_excerpt((string)$row['summary'], 130), ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="surat-feed-item__foot">
                                    <span><?= htmlspecialchars(surat_monitoring_excerpt((string)$row['participants'], 50), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="medical-document-card__empty surat-search-empty hidden">Tidak ada notulen yang cocok dengan pencarian.</div>
                    </div>
                </div>

                <div class="card card-section surat-panel mb-4">
                    <div class="card-header">Agenda Kunjungan</div>
                    <div class="surat-panel__body surat-panel__body--compact">
                    <div class="surat-feed surat-feed--compact">
                        <?php if ($agendaRows): ?>
                            <?php foreach ($agendaRows as $row): ?>
                                <?php $meta = surat_monitoring_status_meta((string)($row['status'] ?? '')); ?>
                                <div class="surat-mini-row">
                                    <div>
                                        <strong><?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)($row['origin_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)$row['location'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="text-right">
                                        <span class="<?= htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="meta-text-xs mt-1"><?= htmlspecialchars((string)$row['visit_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="medical-document-card__empty">Belum ada agenda kunjungan terbaru.</div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>

                <div class="card card-section surat-panel">
                    <div class="card-header">Koordinasi & Surat Rahasia</div>
                    <div class="surat-panel__body surat-panel__body--compact">
                    <div class="surat-feed surat-feed--compact">
                        <?php foreach ($coordinationRows as $row): ?>
                            <?php $meta = surat_monitoring_status_meta((string)($row['status'] ?? '')); ?>
                            <div class="surat-mini-row">
                                <div>
                                    <strong><?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)$row['division_scope'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <span class="<?= htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($confidentialRows as $row): ?>
                            <?php $meta = surat_monitoring_status_meta((string)($row['status'] ?? '')); ?>
                            <div class="surat-mini-row">
                                <div>
                                    <strong><?= htmlspecialchars((string)$row['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)$row['confidential_code'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(strtoupper((string)$row['letter_direction']), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <span class="<?= htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($coordinationRows) && empty($confidentialRows)): ?>
                            <div class="medical-document-card__empty">Belum ada koordinasi atau surat rahasia terbaru.</div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>

<div id="letterDetailModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('document-text', 'h-5 w-5 text-primary') ?>
                <span id="letterDetailModalTitle">Detail Surat</span>
            </div>
            <button type="button" class="modal-close-btn btn-letter-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <div class="card">
                    <div class="meta-text-xs">Kode</div>
                    <div id="letterDetailCode" class="text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs">Status</div>
                    <div id="letterDetailStatus" class="text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs">Tanggal</div>
                    <div id="letterDetailDate" class="text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs">Jam</div>
                    <div id="letterDetailTime" class="text-sm font-semibold text-slate-800">-</div>
                </div>
            </div>
            <div class="grid gap-3 mt-4">
                <div class="card">
                    <div class="meta-text-xs mb-2">Instansi / Judul</div>
                    <div id="letterDetailMainTitle" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs mb-2">Topik / Subjek</div>
                    <div id="letterDetailPrimary" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
                <div class="card">
                    <div class="meta-text-xs mb-2">Isi / Catatan</div>
                    <div id="letterDetailSecondary" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="card">
                        <div class="meta-text-xs mb-2">Kontak 1</div>
                        <div id="letterDetailMetaLeft" class="whitespace-pre-line text-sm text-slate-700">-</div>
                    </div>
                    <div class="card">
                        <div class="meta-text-xs mb-2">Kontak 2</div>
                        <div id="letterDetailMetaRight" class="whitespace-pre-line text-sm text-slate-700">-</div>
                    </div>
                    <div class="card">
                        <div class="meta-text-xs mb-2">Tujuan</div>
                        <div id="letterDetailTarget" class="whitespace-pre-line text-sm text-slate-700">-</div>
                    </div>
                </div>
                <div class="card">
                    <div class="meta-text-xs mb-2">Dicatat Pada</div>
                    <div id="letterDetailCreated" class="whitespace-pre-line text-sm text-slate-700">-</div>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-letter-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<div id="minutesViewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('clipboard-document-list', 'h-5 w-5 text-primary') ?>
                <span id="minutesModalTitle">Detail Notulen</span>
            </div>
            <button type="button" class="modal-close-btn btn-minutes-cancel" aria-label="Tutup modal">
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
                    <div class="meta-text-xs">Dicatat Pada</div>
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
                <button type="button" class="btn-secondary btn-minutes-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<style>
.surat-monitoring-hero {
    background:
        radial-gradient(circle at right top, rgba(14, 165, 233, 0.14), transparent 26%),
        linear-gradient(135deg, #fcfeff 0%, #f3f8ff 100%);
    border: 1px solid rgba(148, 163, 184, 0.26);
}

.surat-monitoring-hero,
.surat-feed-item,
.surat-timeline-item,
.surat-mini-row,
.surat-legend-item {
    border-radius: 1rem;
}

.surat-monitoring-hero {
    display: flex;
    justify-content: space-between;
    gap: 1.5rem;
    align-items: flex-start;
}

.surat-monitoring-kicker {
    display: inline-flex;
    align-items: center;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.08);
    color: #334155;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 0.85rem;
}

.surat-monitoring-legend {
    display: grid;
    gap: 0.75rem;
    min-width: 230px;
}

.surat-search-panel {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
}

.surat-search-panel__copy {
    min-width: 0;
}

.surat-search-panel__field {
    width: min(100%, 460px);
}

.surat-search-panel__field .form-control {
    width: 100%;
}

.surat-legend-item {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.85rem 1rem;
    background: rgba(255, 255, 255, 0.84);
    border: 1px solid rgba(148, 163, 184, 0.22);
}

.surat-legend-item__dot {
    width: 0.85rem;
    height: 0.85rem;
    border-radius: 999px;
}

.surat-legend-item__dot--incoming { background: #0ea5e9; }
.surat-legend-item__dot--outgoing { background: #22c55e; }
.surat-legend-item__dot--minutes { background: #f59e0b; }

.surat-monitoring-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(310px, 0.95fr);
    gap: 1.25rem;
}

.surat-monitoring-split {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem;
}

.surat-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.surat-panel__body {
    min-height: 0;
}

.surat-panel__body--timeline {
    max-height: 38rem;
    overflow-y: auto;
    padding-right: 0.2rem;
}

.surat-panel__body--feed {
    max-height: 30rem;
    overflow-y: auto;
    padding-right: 0.2rem;
}

.surat-panel__body--compact {
    max-height: 22rem;
    overflow-y: auto;
    padding-right: 0.2rem;
}

.surat-timeline {
    display: grid;
    gap: 1rem;
}

.surat-timeline-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 1rem;
    padding: 1rem;
    border: 1px solid rgba(148, 163, 184, 0.22);
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.98));
}

.surat-timeline-item__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.85rem;
    background: #e2eefc;
    color: #1d4ed8;
}

.surat-timeline-item__head,
.surat-feed-item__title-row,
.surat-feed-item__foot,
.surat-mini-row {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: flex-start;
    flex-wrap: wrap;
}

.surat-timeline-item__type {
    font-size: 0.72rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 0.25rem;
}

.surat-timeline-item__title {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
}

.surat-timeline-item__subtitle,
.surat-feed-item__copy {
    margin: 0.55rem 0 0;
    color: #475569;
    line-height: 1.65;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.surat-timeline-item__time,
.surat-feed-item__foot,
.surat-mini-row .meta-text-xs {
    color: #64748b;
    font-size: 0.8rem;
}

.surat-feed {
    display: grid;
    gap: 0.95rem;
}

.surat-feed-item,
.surat-mini-row {
    padding: 1rem;
    border: 1px solid rgba(148, 163, 184, 0.18);
    background: rgba(255, 255, 255, 0.88);
    min-width: 0;
}

.surat-feed-item--button {
    width: 100%;
    text-align: left;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    overflow: hidden;
}

.surat-feed-item--button:hover {
    transform: translateY(-1px);
    box-shadow: 0 16px 30px -24px rgba(15, 23, 42, 0.28);
    border-color: rgba(59, 130, 246, 0.24);
}

.surat-feed--compact {
    gap: 0.8rem;
}

.surat-search-empty {
    margin-top: 0.95rem;
}

.surat-panel__body::-webkit-scrollbar {
    width: 0.55rem;
}

.surat-panel__body::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.55);
    border-radius: 999px;
}

.surat-panel__body::-webkit-scrollbar-track {
    background: rgba(226, 232, 240, 0.45);
    border-radius: 999px;
}

.surat-timeline-item__body,
.surat-feed-item__title-row > *,
.surat-feed-item__foot > *,
.surat-mini-row > *,
.surat-mini-row strong,
.surat-feed-item strong,
.surat-timeline-item__title,
.meta-text-xs,
.text-right {
    min-width: 0;
    overflow-wrap: anywhere;
    word-break: break-word;
}

@media (max-width: 1100px) {
    .surat-monitoring-layout,
    .surat-monitoring-split,
    .surat-monitoring-hero,
    .surat-search-panel {
        grid-template-columns: 1fr;
        flex-direction: column;
    }

    .surat-monitoring-legend {
        width: 100%;
        min-width: 0;
    }

    .surat-search-panel__field {
        width: 100%;
    }
}

@media (max-width: 640px) {
    .surat-timeline-item__head,
    .surat-feed-item__title-row,
    .surat-feed-item__foot,
    .surat-mini-row {
        flex-direction: column;
    }

    .surat-timeline-item {
        grid-template-columns: 1fr;
    }

    .surat-panel__body--timeline,
    .surat-panel__body--feed,
    .surat-panel__body--compact {
        max-height: 24rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const letterModal = document.getElementById('letterDetailModal');
    const minutesModal = document.getElementById('minutesViewModal');
    const searchInput = document.getElementById('suratMonitoringSearch');

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }

    function normalizeSearchText(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    function applySearchFilter() {
        const query = normalizeSearchText(searchInput ? searchInput.value : '');

        document.querySelectorAll('[data-search-scope]').forEach(function (scope) {
            const items = scope.querySelectorAll('.surat-search-item');
            let visibleCount = 0;

            items.forEach(function (item) {
                const haystack = normalizeSearchText(item.dataset.search || item.textContent || '');
                const match = query === '' || haystack.indexOf(query) !== -1;
                item.classList.toggle('hidden', !match);
                item.setAttribute('aria-hidden', match ? 'false' : 'true');
                if (match) {
                    visibleCount += 1;
                }
            });

            const emptyState = scope.querySelector('.surat-search-empty');
            if (emptyState) {
                emptyState.classList.toggle('hidden', visibleCount !== 0);
            }
        });
    }

    document.querySelectorAll('.btn-open-letter-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('letterDetailModalTitle').textContent =
                (button.dataset.letterKind === 'incoming' ? 'Detail Surat Masuk' : 'Detail Surat Keluar');
            document.getElementById('letterDetailCode').textContent = button.dataset.code || '-';
            document.getElementById('letterDetailStatus').textContent = button.dataset.status || '-';
            document.getElementById('letterDetailDate').textContent = button.dataset.date || '-';
            document.getElementById('letterDetailTime').textContent = (button.dataset.time || '-') + ((button.dataset.time && button.dataset.time !== '-') ? ' WIB' : '');
            document.getElementById('letterDetailMainTitle').textContent = button.dataset.title || '-';
            document.getElementById('letterDetailPrimary').textContent = button.dataset.primary || '-';
            document.getElementById('letterDetailSecondary').textContent = button.dataset.secondary || '-';
            document.getElementById('letterDetailMetaLeft').textContent = button.dataset.metaLeft || '-';
            document.getElementById('letterDetailMetaRight').textContent = button.dataset.metaRight || '-';
            document.getElementById('letterDetailTarget').textContent = button.dataset.target || '-';
            document.getElementById('letterDetailCreated').textContent = button.dataset.created || '-';
            openModal(letterModal);
        });
    });

    document.querySelectorAll('.btn-letter-cancel').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(letterModal);
        });
    });

    document.querySelectorAll('.btn-view-minutes').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('minutesModalTitle').textContent = button.dataset.title || 'Detail Notulen';
            document.getElementById('minutesModalDate').textContent = button.dataset.date || '-';
            document.getElementById('minutesModalTime').textContent = (button.dataset.time || '-') + ' WIB';
            document.getElementById('minutesModalCreatedBy').textContent = button.dataset.createdBy || '-';
            document.getElementById('minutesModalParticipants').textContent = button.dataset.participants || '-';
            document.getElementById('minutesModalSummary').textContent = button.dataset.summary || '-';
            document.getElementById('minutesModalDecisions').textContent = button.dataset.decisions || '-';
            document.getElementById('minutesModalFollowUp').textContent = button.dataset.followUp || '-';
            openModal(minutesModal);
        });
    });

    document.querySelectorAll('.btn-minutes-cancel').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(minutesModal);
        });
    });

    [letterModal, minutesModal].forEach(function (modal) {
        if (!modal) return;
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal(letterModal);
            closeModal(minutesModal);
        }
    });

    if (searchInput) {
        searchInput.addEventListener('input', applySearchFilter);
        applySearchFilter();
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
