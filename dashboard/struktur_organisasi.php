<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Struktur Organisasi';

function orgHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare('SHOW COLUMNS FROM user_rh LIKE ?');
    $stmt->execute([$column]);
    $cache[$column] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

function orgRoleRank(?string $role): int
{
    return match (ems_normalize_role($role)) {
        'director' => 1,
        'vice director' => 2,
        'head manager' => 3,
        'lead manager' => 4,
        'assisten manager' => 5,
        default => 99,
    };
}

function orgJoinDuration(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) {
        return '-';
    }

    try {
        $start = new DateTime((string) $tanggalMasuk);
        $now = new DateTime();

        if ($start > $now) {
            return '-';
        }

        $diff = $start->diff($now);
        $months = ((int) $diff->y * 12) + (int) $diff->m;

        if ($months >= 1) {
            return $months . ' bulan';
        }

        if ((int) $diff->days >= 1) {
            return $diff->days . ' hari';
        }

        return (((int) $diff->days * 24) + (int) $diff->h) . ' jam';
    } catch (Throwable $e) {
        return '-';
    }
}

function orgDivisionRank(string $division): int
{
    static $order = [
        'Secretary' => 1,
        'Human Capital' => 2,
        'Human Resource' => 3,
        'Disciplinary Committee' => 4,
        'General Affair' => 5,
        'Specialist Medical Authority' => 6,
        'Forensic' => 7,
    ];

    return $order[$division] ?? 99;
}

$errors = [];
$directors = [];
$viceDirectors = [];
$secretaries = [];
$divisionManagers = [];
$orgTree = [];
$stats = [
    'executive' => 0,
    'division' => 0,
    'manager' => 0,
];

try {
    if (!orgHasColumn($pdo, 'division')) {
        throw new RuntimeException('Kolom division belum tersedia pada tabel user_rh.');
    }

    $rows = $pdo->query("
        SELECT
            id,
            full_name,
            position,
            role,
            division,
            is_active,
            tanggal_masuk
        FROM user_rh
        WHERE is_active = 1
          AND role IS NOT NULL
          AND role <> ''
          AND id <> 5
          AND LOWER(TRIM(full_name)) <> 'admin'
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $role = ems_normalize_role($row['role'] ?? '');
        $division = ems_normalize_division($row['division'] ?? '');

        if (!ems_is_manager_plus_role($role)) {
            continue;
        }

        if ($division === '' || $division === 'Medis') {
            continue;
        }

        $item = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => trim((string) ($row['full_name'] ?? 'Tanpa Nama')),
            'position' => ems_position_label($row['position'] ?? ''),
            'role' => ems_role_label($role),
            'division' => $division,
            'initials' => initialsFromName((string) ($row['full_name'] ?? '')),
            'avatar_color' => avatarColorFromName((string) ($row['full_name'] ?? '')),
            'join_duration' => orgJoinDuration($row['tanggal_masuk'] ?? null),
        ];

        if ($division === 'Executive' && $role === 'director') {
            $directors[] = $item;
            continue;
        }

        if ($division === 'Executive' && $role === 'vice director') {
            $viceDirectors[] = $item;
            continue;
        }

        if ($division === 'Secretary') {
            $secretaries[] = $item;
            continue;
        }

        $divisionManagers[$division][] = $item;
    }

    $sortPeople = static function (array &$people): void {
        usort($people, static function (array $left, array $right): int {
            $roleCompare = orgRoleRank($left['role'] ?? '') <=> orgRoleRank($right['role'] ?? '');
            if ($roleCompare !== 0) {
                return $roleCompare;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
    };

    $sortPeople($directors);
    $sortPeople($viceDirectors);
    $sortPeople($secretaries);

    foreach ($divisionManagers as $division => &$people) {
        $sortPeople($people);
    }
    unset($people);

    uksort($divisionManagers, static function (string $left, string $right): int {
        $rankCompare = orgDivisionRank($left) <=> orgDivisionRank($right);
        if ($rankCompare !== 0) {
            return $rankCompare;
        }

        return strcmp($left, $right);
    });

    $orgTree = [
        [
            'type' => 'single',
            'division' => 'Secretary',
            'people' => $secretaries,
        ],
        [
            'type' => 'branch',
            'division' => 'Human Capital',
            'people' => $divisionManagers['Human Capital'] ?? [],
            'children' => [
                [
                    'division' => 'Human Resource',
                    'people' => $divisionManagers['Human Resource'] ?? [],
                ],
                [
                    'division' => 'Disciplinary Committee',
                    'people' => $divisionManagers['Disciplinary Committee'] ?? [],
                ],
            ],
        ],
        [
            'type' => 'single',
            'division' => 'General Affair',
            'people' => $divisionManagers['General Affair'] ?? [],
        ],
        [
            'type' => 'branch',
            'division' => 'Specialist Medical Authority',
            'people' => $divisionManagers['Specialist Medical Authority'] ?? [],
            'children' => [
                [
                    'division' => 'Forensic',
                    'people' => $divisionManagers['Forensic'] ?? [],
                ],
            ],
        ],
    ];

    $visibleDivisionCount = 0;
    foreach ($orgTree as $node) {
        if (($node['people'] ?? []) !== []) {
            $visibleDivisionCount++;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($child['people'] ?? []) !== []) {
                $visibleDivisionCount++;
            }
        }
    }

    $stats['executive'] = count($directors) + count($viceDirectors);
    $stats['division'] = $visibleDivisionCount;
    $stats['manager'] = $stats['executive']
        + count($secretaries)
        + array_sum(array_map(static fn(array $people): int => count($people), $divisionManagers));
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat struktur organisasi: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell org-page">
        <h1 class="page-title">Struktur Organisasi</h1>
        <p class="page-subtitle">Bagan manager dari level executive sampai divisi operasional. Role staff dan divisi medis tidak ditampilkan.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="org-hero">
            <div>
                <div class="org-kicker">Landscape View</div>
                <h2 class="org-hero-title">Struktur organisasi manager dengan urutan komando yang tetap.</h2>
                <p class="org-hero-copy">Susunan dibaca dari Director, turun ke Vice Director, lalu Secretary, kemudian bercabang ke Human Capital, General Affair, dan Specialist Medical Authority beserta turunan divisinya.</p>
            </div>
            <div class="org-stats">
                <article class="org-stat-card">
                    <span class="org-stat-label">Executive</span>
                    <strong class="org-stat-value"><?= (int) $stats['executive'] ?></strong>
                </article>
                <article class="org-stat-card">
                    <span class="org-stat-label">Divisi Aktif</span>
                    <strong class="org-stat-value"><?= (int) $stats['division'] ?></strong>
                </article>
                <article class="org-stat-card">
                    <span class="org-stat-label">Total Manager</span>
                    <strong class="org-stat-value"><?= (int) $stats['manager'] ?></strong>
                </article>
            </div>
        </div>

        <div class="org-board-shell">
            <div class="org-board">
                <section class="org-executive-section">
                    <div class="org-section-heading">
                        <span class="org-section-pill"><?= ems_icon('user-group', 'h-4 w-4') ?> Executive</span>
                    </div>

                    <?php if ($directors !== [] || $viceDirectors !== []): ?>
                        <div class="org-command-flow">
                            <div class="org-command-row is-director">
                                <?php foreach ($directors as $leader): ?>
                                    <article class="org-person-card is-executive is-top-level">
                                        <div class="org-person-topline">
                                            <span class="org-role-badge"><?= htmlspecialchars($leader['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="org-division-chip"><?= htmlspecialchars($leader['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="org-person-main">
                                            <div class="org-avatar" style="--avatar: <?= htmlspecialchars($leader['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                <?= htmlspecialchars($leader['initials'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div>
                                                <h3 class="org-person-name"><?= htmlspecialchars($leader['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <p class="org-person-meta"><?= htmlspecialchars($leader['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                            </div>
                                        </div>
                                        <div class="org-person-foot">
                                            <span>Masa aktif <?= htmlspecialchars($leader['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="org-connector-vertical"><span></span></div>

                            <div class="org-command-row is-vice-director">
                                <?php foreach ($viceDirectors as $leader): ?>
                                    <article class="org-person-card is-executive">
                                        <div class="org-person-topline">
                                            <span class="org-role-badge"><?= htmlspecialchars($leader['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="org-division-chip"><?= htmlspecialchars($leader['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="org-person-main">
                                            <div class="org-avatar" style="--avatar: <?= htmlspecialchars($leader['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                <?= htmlspecialchars($leader['initials'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div>
                                                <h3 class="org-person-name"><?= htmlspecialchars($leader['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <p class="org-person-meta"><?= htmlspecialchars($leader['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                            </div>
                                        </div>
                                        <div class="org-person-foot">
                                            <span>Masa aktif <?= htmlspecialchars($leader['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <p class="meta-text">Belum ada data executive aktif.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="org-divisions-section">
                    <?php if ($orgTree !== []): ?>
                        <div class="org-tree-layout">
                            <div class="org-secretary-row">
                                <?php foreach ($orgTree as $node): ?>
                                    <?php if (($node['division'] ?? '') !== 'Secretary') continue; ?>
                                    <article class="org-tree-node is-secretary">
                                        <div class="org-division-connector" aria-hidden="true"></div>
                                        <header class="org-division-head">
                                            <span class="org-division-kicker">Divisi</span>
                                            <h3><?= htmlspecialchars($node['division'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <p><?= count($node['people']) ?> manager aktif</p>
                                        </header>
                                        <div class="org-person-stack">
                                            <?php foreach ($node['people'] as $person): ?>
                                                <article class="org-person-card">
                                                    <div class="org-person-topline">
                                                        <span class="org-role-badge"><?= htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <span class="org-division-chip"><?= htmlspecialchars($person['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                    <div class="org-person-main">
                                                        <div class="org-avatar" style="--avatar: <?= htmlspecialchars($person['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                            <?= htmlspecialchars($person['initials'], ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                        <div>
                                                            <h4 class="org-person-name"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                            <p class="org-person-meta"><?= htmlspecialchars($person['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="org-person-foot">
                                                        <span>Masa aktif <?= htmlspecialchars($person['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="org-connector-hub" aria-hidden="true">
                                <span></span>
                            </div>

                            <div class="org-parent-row">
                                <?php foreach ($orgTree as $node): ?>
                                    <?php if (($node['division'] ?? '') === 'Secretary') continue; ?>
                                    <article class="org-tree-node <?= ($node['type'] ?? '') === 'branch' ? 'has-children' : 'is-single' ?>">
                                        <div class="org-division-connector" aria-hidden="true"></div>
                                        <header class="org-division-head">
                                            <span class="org-division-kicker">Divisi</span>
                                            <h3><?= htmlspecialchars($node['division'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <p><?= count($node['people']) ?> manager aktif</p>
                                        </header>

                                        <div class="org-person-stack">
                                            <?php foreach ($node['people'] as $person): ?>
                                                <article class="org-person-card">
                                                    <div class="org-person-topline">
                                                        <span class="org-role-badge"><?= htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <span class="org-division-chip"><?= htmlspecialchars($person['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                    <div class="org-person-main">
                                                        <div class="org-avatar" style="--avatar: <?= htmlspecialchars($person['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                            <?= htmlspecialchars($person['initials'], ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                        <div>
                                                            <h4 class="org-person-name"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                            <p class="org-person-meta"><?= htmlspecialchars($person['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="org-person-foot">
                                                        <span>Masa aktif <?= htmlspecialchars($person['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (($node['children'] ?? []) !== []): ?>

                                            <div class="org-child-grid child-count-<?= min(3, max(1, count($node['children']))) ?>">
                                                <?php foreach ($node['children'] as $child): ?>
                                                    <section class="org-child-node">
                                                        <header class="org-division-head is-child">
                                                            <span class="org-division-kicker">Sub Divisi</span>
                                                            <h3><?= htmlspecialchars($child['division'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                            <p><?= count($child['people']) ?> manager aktif</p>
                                                        </header>
                                                        <div class="org-person-stack">
                                                            <?php foreach ($child['people'] as $person): ?>
                                                                <article class="org-person-card is-child-card">
                                                                    <div class="org-person-topline">
                                                                        <span class="org-role-badge"><?= htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                                                        <span class="org-division-chip"><?= htmlspecialchars($person['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                                                    </div>
                                                                    <div class="org-person-main">
                                                                        <div class="org-avatar" style="--avatar: <?= htmlspecialchars($person['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                                            <?= htmlspecialchars($person['initials'], ENT_QUOTES, 'UTF-8') ?>
                                                                        </div>
                                                                        <div>
                                                                            <h4 class="org-person-name"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                                            <p class="org-person-meta"><?= htmlspecialchars($person['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                                                        </div>
                                                                    </div>
                                                                    <div class="org-person-foot">
                                                                        <span>Masa aktif <?= htmlspecialchars($person['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                                                    </div>
                                                                </article>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </section>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <p class="meta-text">Belum ada manager aktif di luar divisi medis.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</section>

<style>
    .org-page {
        display: grid;
        gap: 1.5rem;
    }

    .org-hero {
        position: relative;
        overflow: hidden;
        display: grid;
        grid-template-columns: minmax(0, 1.8fr) minmax(320px, 1fr);
        gap: 1.25rem;
        padding: 1.5rem;
        border: 1px solid rgba(14, 165, 233, 0.16);
        border-radius: 1.5rem;
        background:
            radial-gradient(circle at top left, rgba(14, 165, 233, 0.18), transparent 38%),
            linear-gradient(135deg, #f8fcff 0%, #eef7fb 48%, #f8fbf6 100%);
        box-shadow: 0 22px 48px rgba(15, 23, 42, 0.08);
    }

    .org-kicker,
    .org-division-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #0f766e;
    }

    .org-hero-title {
        margin: 0.45rem 0 0.55rem;
        font-size: clamp(1.5rem, 2vw, 2.2rem);
        line-height: 1.1;
        color: #0f172a;
    }

    .org-hero-copy {
        max-width: 54rem;
        margin: 0;
        color: #475569;
    }

    .org-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.85rem;
        align-self: start;
    }

    .org-stat-card {
        padding: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 1.1rem;
        background: rgba(255, 255, 255, 0.86);
        backdrop-filter: blur(12px);
    }

    .org-stat-label {
        display: block;
        font-size: 0.78rem;
        color: #64748b;
    }

    .org-stat-value {
        display: block;
        margin-top: 0.35rem;
        font-size: 1.8rem;
        color: #0f172a;
    }

    .org-board-shell {
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }

    .org-board {
        min-width: 1180px;
        display: grid;
        gap: 1rem;
        padding: 1.25rem;
        border-radius: 1.5rem;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98)),
            linear-gradient(135deg, rgba(15, 23, 42, 0.03), rgba(14, 165, 233, 0.03));
        border: 1px solid #dbe7ef;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    }

    .org-section-heading {
        display: flex;
        justify-content: center;
        margin-bottom: 1rem;
    }

    .org-section-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.55rem 0.9rem;
        border-radius: 999px;
        background: #0f172a;
        color: #f8fafc;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .org-command-flow {
        display: grid;
        gap: 0.8rem;
    }

    .org-command-row {
        display: flex;
        justify-content: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .org-command-row.is-director .org-person-card {
        width: min(100%, 360px);
    }

    .org-command-row.is-vice-director .org-person-card {
        width: min(100%, 340px);
    }

    .org-connector-vertical {
        display: flex;
        justify-content: center;
        height: 1.6rem;
    }

    .org-connector-vertical span {
        display: inline-block;
        width: 4px;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(180deg, #0ea5e9, #14b8a6);
    }

    .org-connector-hub {
        display: flex;
        justify-content: center;
        height: 2.6rem;
    }

    .org-connector-hub span {
        position: relative;
        display: inline-block;
        width: min(100%, 780px);
        height: 100%;
    }

    .org-connector-hub span::before,
    .org-connector-hub span::after {
        content: "";
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(180deg, #0ea5e9, #14b8a6);
        border-radius: 999px;
    }

    .org-connector-hub span::before {
        top: 0;
        width: 4px;
        height: 1.1rem;
    }

    .org-connector-hub span::after {
        top: 1.05rem;
        width: 100%;
        height: 4px;
    }

    .org-tree-layout {
        display: grid;
        gap: 1rem;
    }

    .org-secretary-row {
        display: flex;
        justify-content: center;
    }

    .org-parent-row {
        display: grid;
        grid-template-columns: minmax(320px, 1.1fr) minmax(260px, 0.82fr) minmax(320px, 1.1fr);
        gap: 1rem;
        align-items: start;
        justify-content: center;
    }

    .org-tree-node {
        position: relative;
        display: grid;
        gap: 0.9rem;
        max-width: 360px;
        width: 100%;
        margin: 0 auto;
    }

    .org-division-connector {
        display: flex;
        justify-content: center;
        height: 1.4rem;
    }

    .org-division-connector::before {
        content: "";
        width: 4px;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(180deg, #0ea5e9, #14b8a6);
    }

    .org-division-head {
        padding: 0.9rem 0.95rem 0.88rem;
        border-radius: 1.25rem;
        background: linear-gradient(135deg, #dff6fb, #c7ecf7 56%, #eef7ff);
        color: #0f172a;
        border: 1px solid rgba(14, 116, 144, 0.16);
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.16);
    }

    .org-division-head h3 {
        margin: 0.25rem 0 0.25rem;
        font-size: 1.02rem;
        color: #0f172a;
    }

    .org-division-head p {
        margin: 0;
        color: #334155;
        font-size: 0.84rem;
        font-weight: 600;
    }

    .org-division-head.is-child {
        background: linear-gradient(135deg, #f5fbff, #e4f2fb);
        border-style: dashed;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .org-person-stack {
        display: grid;
        gap: 0.85rem;
    }

    .org-person-card {
        display: grid;
        gap: 0.9rem;
        padding: 0.9rem;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 1.25rem;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98)),
            radial-gradient(circle at top right, rgba(20, 184, 166, 0.12), transparent 42%);
        box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
    }

    .org-person-card.is-executive {
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(241, 245, 249, 0.98)),
            radial-gradient(circle at top right, rgba(14, 165, 233, 0.18), transparent 40%);
        border-color: rgba(14, 165, 233, 0.2);
    }

    .org-person-card.is-top-level {
        box-shadow: 0 20px 42px rgba(2, 132, 199, 0.16);
    }

    .org-person-topline,
    .org-person-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .org-person-main {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.9rem;
        align-items: center;
    }

    .org-avatar {
        --avatar: #0ea5e9;
        width: 2.8rem;
        height: 2.8rem;
        border-radius: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        color: #fff;
        background: linear-gradient(135deg, var(--avatar), #0f172a);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18);
    }

    .org-role-badge,
    .org-division-chip {
        display: inline-flex;
        align-items: center;
        padding: 0.28rem 0.58rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
    }

    .org-role-badge {
        background: #0f172a;
        color: #f8fafc;
    }

    .org-division-chip {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .org-person-name {
        margin: 0;
        font-size: 0.92rem;
        color: #0f172a;
    }

    .org-person-meta,
    .org-person-foot {
        margin: 0;
        color: #475569;
        font-size: 0.76rem;
    }

.org-child-connector {
    display: flex;
    justify-content: center;
    height: 3.8rem;
    margin-top: 0.85rem;
    margin-bottom: 0.9rem;
}

    .org-child-connector span {
        position: relative;
        width: 100%;
        height: 100%;
    }

    .org-child-connector span::before,
    .org-child-connector span::after {
        content: "";
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        border-radius: 999px;
        background: linear-gradient(180deg, #22c55e, #0ea5e9);
    }

    .org-child-connector span::before {
        top: 0;
        width: 4px;
        height: 1.05rem;
    }

    .org-child-connector span::after {
        top: 1rem;
        width: calc(100% - 4.5rem);
        height: 4px;
    }

.org-child-grid {
    display: grid;
    gap: 0.9rem;
    align-items: start;
    justify-items: center;
    padding-top: 0.65rem;
}

    .org-child-grid.child-count-1 {
        grid-template-columns: 1fr;
    }

    .org-child-grid.child-count-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .org-child-grid.child-count-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .org-child-node {
        position: relative;
        display: grid;
        gap: 0.8rem;
        width: 100%;
        max-width: 320px;
    }

.org-child-node::before {
    content: "";
    position: absolute;
    top: -1.45rem;
    left: 50%;
    transform: translateX(-50%);
    width: 4px;
    height: 1.25rem;
    border-radius: 999px;
    background: linear-gradient(180deg, #22c55e, #0ea5e9);
}

    .org-child-node .org-person-stack {
        gap: 0.75rem;
    }

    .org-person-card.is-child-card {
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, 1)),
            radial-gradient(circle at top right, rgba(56, 189, 248, 0.1), transparent 40%);
    }

    @media (max-width: 1100px) {
        .org-hero {
            grid-template-columns: 1fr;
        }

        .org-stats {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .org-parent-row {
            grid-template-columns: 1fr;
        }

        .org-board {
            min-width: 0;
        }

        .org-child-grid.child-count-2,
        .org-child-grid.child-count-3 {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 720px) {
        .org-stats {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        @page {
            size: landscape;
            margin: 12mm;
        }

        .topbar,
        .sidebar,
        .sidebar-overlay {
            display: none !important;
        }

        .main-content,
        .content,
        .org-board-shell {
            overflow: visible !important;
        }

        .org-board {
            min-width: 0;
            box-shadow: none;
        }
    }
</style>
