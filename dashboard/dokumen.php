<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/document_library.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Dokumen';

ems_document_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$unitCode = ems_effective_unit($pdo, $user);
$canOpenManage = ems_document_can_open_manage_page($user);

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// Dokumen adalah basis pengetahuan bersama untuk semua user login, jadi
// abaikan flash error guard division yang mungkin masih tersisa dari
// redirect halaman lain.
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

$allFolders = ems_document_fetch_folders($pdo, $unitCode);
$foldersById = ems_document_folders_by_id($allFolders);
$tree = ems_document_build_tree($allFolders);

$docCounts = [];
$docsByFolder = [];
$stmtAllDocs = $pdo->prepare("SELECT id, folder_id, title, file_ext FROM document_files WHERE unit_code = ? ORDER BY title ASC");
$stmtAllDocs->execute([$unitCode]);
foreach ($stmtAllDocs->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $fid = (int)$row['folder_id'];
    $docCounts[$fid] = ($docCounts[$fid] ?? 0) + 1;
    $docsByFolder[$fid][] = $row;
}

$selectedFolderId = isset($_GET['folder']) ? (int)$_GET['folder'] : 0;
$selectedFolder = $selectedFolderId > 0 ? ($foldersById[$selectedFolderId] ?? null) : null;
$selectedDocs = [];
if ($selectedFolder !== null) {
    $stmtDocs = $pdo->prepare("SELECT * FROM document_files WHERE unit_code = ? AND folder_id = ? ORDER BY title ASC");
    $stmtDocs->execute([$unitCode, $selectedFolderId]);
    $selectedDocs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
}

function dokumenExtIcon(string $ext): string
{
    return match (strtolower($ext)) {
        'pdf', 'doc', 'docx', 'odt' => 'document-text',
        'xlsx', 'xls', 'csv' => 'table-cells',
        'jpg', 'jpeg', 'png' => 'camera',
        default => 'document',
    };
}

function dokumenRenderFileItem(array $doc): void
{
    echo '<a href="/dashboard/document_view.php?id=' . (int)$doc['id'] . '" class="doc-file-leaf">';
    echo ems_icon(dokumenExtIcon((string)$doc['file_ext']), 'h-4 w-4');
    echo '<span>' . htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') . '</span>';
    echo '</a>';
}

// Tree selalu tampil terbuka penuh (folder + dokumennya langsung kelihatan
// tanpa perlu klik satu-satu) — dokumen ditampilkan sebagai item di dalam
// tree-nya sendiri, bukan cuma link ke panel kanan.
function dokumenRenderFolderNode(array $node, array $docCounts, array $docsByFolder, ?int $selectedFolderId): void
{
    $id = (int)$node['id'];
    $hasChildren = !empty($node['children']);
    $docsHere = $docsByFolder[$id] ?? [];
    $count = $docCounts[$id] ?? 0;
    $isActive = $selectedFolderId === $id;
    $label = htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8');
    $href = '?folder=' . $id;

    if ($hasChildren || !empty($docsHere)) {
        echo '<details class="doc-folder-node" open>';
        echo '<summary>' . ems_icon('folder', 'h-4 w-4') . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . ($isActive ? 'doc-folder-active' : '') . '">' . $label . '</a><span class="doc-folder-count">' . $count . '</span></summary>';
        echo '<ul class="doc-folder-children">';
        foreach ($node['children'] as $child) {
            echo '<li>';
            dokumenRenderFolderNode($child, $docCounts, $docsByFolder, $selectedFolderId);
            echo '</li>';
        }
        foreach ($docsHere as $doc) {
            echo '<li>';
            dokumenRenderFileItem($doc);
            echo '</li>';
        }
        echo '</ul>';
        echo '</details>';
    } else {
        echo '<div class="doc-folder-leaf">';
        echo ems_icon('folder', 'h-4 w-4');
        echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . ($isActive ? 'doc-folder-active' : '') . '">' . $label . '</a>';
        echo '<span class="doc-folder-count">' . $count . '</span>';
        echo '</div>';
    }
}

$foldersByDivision = [];
foreach ($tree as $node) {
    $foldersByDivision[(string)$node['division']][] = $node;
}
ksort($foldersByDivision);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<style>
.doc-search-wrap { position: relative; }
.doc-search-results {
    position: absolute; left: 0; right: 0; top: calc(100% + 6px); z-index: 40;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, .12); max-height: 420px; overflow-y: auto;
    display: none;
}
.doc-search-results.is-open { display: block; }
.doc-search-result-item { display: block; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: inherit; }
.doc-search-result-item:last-child { border-bottom: none; }
.doc-search-result-item:hover { background: #f8fafc; }
.doc-search-result-title { font-weight: 600; color: #0f172a; display: flex; align-items: center; gap: 6px; }
.doc-search-result-meta { font-size: 12px; color: #64748b; margin-top: 2px; }
.doc-search-result-snippet { font-size: 13px; color: #475569; margin-top: 6px; line-height: 1.5; }
.doc-search-result-snippet mark { background: #fef08a; color: inherit; padding: 0 2px; border-radius: 2px; }
.doc-search-empty, .doc-search-loading { padding: 16px; color: #64748b; font-size: 14px; }

.doc-layout { display: grid; grid-template-columns: 420px 1fr; gap: 16px; margin-top: 16px; }
@media (max-width: 1000px) { .doc-layout { grid-template-columns: 1fr; } }
.doc-folder-tree { max-height: 75vh; overflow-y: auto; }
.doc-division-group { margin-bottom: 14px; }
.doc-division-group-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #94a3b8; margin-bottom: 6px; }
.doc-folder-node summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 6px; padding: 6px 4px; border-radius: 8px; }
.doc-folder-node summary::-webkit-details-marker { display: none; }
.doc-folder-node summary:hover, .doc-folder-leaf:hover, .doc-file-leaf:hover { background: #f1f5f9; }
.doc-folder-leaf { display: flex; align-items: center; gap: 6px; padding: 6px 4px; border-radius: 8px; margin-left: 18px; }
.doc-folder-children { list-style: none; margin: 0 0 0 22px; padding: 0; }
.doc-folder-active { color: #0ea5e9; font-weight: 600; }
.doc-folder-count { margin-left: auto; font-size: 11px; color: #94a3b8; background: #f1f5f9; border-radius: 999px; padding: 1px 8px; }
.doc-file-leaf { display: flex; align-items: center; gap: 6px; padding: 5px 4px; border-radius: 8px; text-decoration: none; color: #334155; font-size: 13px; }
.doc-file-leaf svg { color: #94a3b8; flex-shrink: 0; }

.doc-file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.doc-file-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; text-decoration: none; color: inherit; display: block; transition: box-shadow .15s, border-color .15s; }
.doc-file-card:hover { border-color: #0ea5e9; box-shadow: 0 6px 18px rgba(14, 165, 233, .12); }
.doc-file-card-title { font-weight: 600; color: #0f172a; display: flex; align-items: center; gap: 8px; }
.doc-file-card-meta { font-size: 12px; color: #64748b; margin-top: 6px; }
</style>

<section class="content">
    <div class="page page-shell">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 class="page-title">Dokumen</h1>
                <p class="page-subtitle">Perpustakaan dokumen internal — cari berdasarkan isi dokumen, bukan cuma nama file.</p>
            </div>
            <?php if ($canOpenManage): ?>
                <a href="/dashboard/document_manage.php" class="btn-secondary">
                    <?= ems_icon('arrow-up-tray', 'h-4 w-4') ?> Kelola Dokumen
                </a>
            <?php endif; ?>
        </div>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string)$message, 'success', 'Dokumen') ?>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <?= ems_render_toast_script((string)$warning, 'warning', 'Dokumen') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string)$error, 'error', 'Dokumen', 6800) ?>
        <?php endforeach; ?>

        <div class="card" style="margin-top:16px;">
            <div class="doc-search-wrap">
                <input
                    type="text"
                    id="docSearchInput"
                    placeholder="Ketik kata kunci, mis. paramedic, SOP forensic, voucher..."
                    autocomplete="off"
                    style="width:100%; padding:12px 16px; border:1px solid #cbd5e1; border-radius:10px; font-size:15px;"
                >
                <div class="doc-search-results" id="docSearchResults"></div>
            </div>
        </div>

        <div class="doc-layout">
            <div class="card doc-folder-tree">
                <?php if (empty($tree)): ?>
                    <p class="meta-text">Belum ada folder dokumen.</p>
                <?php else: ?>
                    <?php foreach ($foldersByDivision as $divisionName => $nodes): ?>
                        <div class="doc-division-group">
                            <div class="doc-division-group-title"><?= htmlspecialchars($divisionName, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php foreach ($nodes as $node): ?>
                                <?php dokumenRenderFolderNode($node, $docCounts, $docsByFolder, $selectedFolderId > 0 ? $selectedFolderId : null); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <?php if ($selectedFolder === null): ?>
                    <p class="meta-text">Pilih folder di sebelah kiri untuk melihat dokumennya, atau gunakan pencarian di atas.</p>
                <?php else: ?>
                    <h3 style="margin-bottom:4px;"><?= htmlspecialchars((string)$selectedFolder['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="meta-text" style="margin-bottom:14px;"><?= htmlspecialchars(ems_document_folder_breadcrumb($foldersById, $selectedFolderId), ENT_QUOTES, 'UTF-8') ?></p>

                    <?php if (empty($selectedDocs)): ?>
                        <p class="meta-text">Belum ada dokumen di folder ini.</p>
                    <?php else: ?>
                        <div class="doc-file-grid">
                            <?php foreach ($selectedDocs as $doc): ?>
                                <a href="/dashboard/document_view.php?id=<?= (int)$doc['id'] ?>" class="doc-file-card">
                                    <div class="doc-file-card-title">
                                        <?= ems_icon(dokumenExtIcon((string)$doc['file_ext']), 'h-5 w-5') ?>
                                        <span><?= htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="doc-file-card-meta">
                                        <?= strtoupper(htmlspecialchars((string)$doc['file_ext'], ENT_QUOTES, 'UTF-8')) ?>
                                        &middot; <?= htmlspecialchars(number_format(((int)$doc['file_size_bytes']) / 1024, 0), ENT_QUOTES, 'UTF-8') ?> KB
                                        &middot; <?= htmlspecialchars((string)$doc['uploaded_by_name_snapshot'], ENT_QUOTES, 'UTF-8') ?: '-' ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    var input = document.getElementById('docSearchInput');
    var results = document.getElementById('docSearchResults');
    var debounceTimer = null;
    var currentController = null;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function renderResults(items, query) {
        if (!items.length) {
            results.innerHTML = '<div class="doc-search-empty">Tidak ada dokumen yang cocok dengan "' + escapeHtml(query) + '".</div>';
            results.classList.add('is-open');
            return;
        }

        var html = '';
        items.forEach(function (item) {
            html += '<a class="doc-search-result-item" href="/dashboard/document_view.php?id=' + encodeURIComponent(item.id) + '">';
            html += '<div class="doc-search-result-title">' + escapeHtml(item.title) + '</div>';
            html += '<div class="doc-search-result-meta">' + escapeHtml(item.breadcrumb) + '</div>';
            if (item.snippet) {
                html += '<div class="doc-search-result-snippet">' + item.snippet + '</div>';
            }
            html += '</a>';
        });
        results.innerHTML = html;
        results.classList.add('is-open');
    }

    input.addEventListener('input', function () {
        var query = input.value.trim();
        clearTimeout(debounceTimer);

        if (query.length < 2) {
            results.classList.remove('is-open');
            results.innerHTML = '';
            return;
        }

        debounceTimer = setTimeout(function () {
            if (currentController) {
                currentController.abort();
            }
            currentController = new AbortController();

            results.innerHTML = '<div class="doc-search-loading">Mencari...</div>';
            results.classList.add('is-open');

            fetch('/ajax/document_search.php?q=' + encodeURIComponent(query), { signal: currentController.signal })
                .then(function (res) { return res.json(); })
                .then(function (data) { renderResults(data.items || [], query); })
                .catch(function (err) {
                    if (err.name !== 'AbortError') {
                        results.innerHTML = '<div class="doc-search-empty">Gagal mencari, coba lagi.</div>';
                    }
                });
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== input) {
            results.classList.remove('is-open');
        }
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
