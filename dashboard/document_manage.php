<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/document_library.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Kelola Dokumen';

ems_document_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

if (!ems_document_can_open_manage_page($user)) {
    $_SESSION['flash_errors'][] = 'Hanya manager ke atas yang bisa mengelola dokumen.';
    header('Location: /dashboard/dokumen.php');
    exit;
}

$unitCode = ems_effective_unit($pdo, $user);
$isExecutiveManager = ems_document_is_executive_manager($user);
$userDivision = ems_normalize_division($user['division'] ?? '');
$csrfToken = generateCsrfToken();

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

$allFolders = ems_document_fetch_folders($pdo, $unitCode);
$foldersById = ems_document_folders_by_id($allFolders);
$uploadFolders = ems_document_folders_for_user($allFolders, $user);

// Dikoreksi 2026-08-30: manager melihat & kelola SEMUA dokumen di
// division-nya sendiri (bukan cuma yang dia upload sendiri) — selaras
// dengan ems_document_can_edit_or_delete() yang juga sudah diubah.
$stmtMine = $pdo->prepare("SELECT * FROM document_files WHERE unit_code = ? AND division = ? ORDER BY created_at DESC");
$stmtMine->execute([$unitCode, $userDivision]);
$myDocs = $stmtMine->fetchAll(PDO::FETCH_ASSOC);

$activityLogs = ems_document_activity_logs($pdo, $unitCode, $isExecutiveManager ? null : $userDivision, 50);

$browseFolderId = isset($_GET['browse_folder']) ? (int)$_GET['browse_folder'] : 0;
$browseFolder = $browseFolderId > 0 ? ($foldersById[$browseFolderId] ?? null) : null;
$browseDocs = [];
if ($isExecutiveManager && $browseFolder !== null) {
    $stmtBrowse = $pdo->prepare("SELECT * FROM document_files WHERE unit_code = ? AND folder_id = ? ORDER BY title ASC");
    $stmtBrowse->execute([$unitCode, $browseFolderId]);
    $browseDocs = $stmtBrowse->fetchAll(PDO::FETCH_ASSOC);
}

function documentManageBreadcrumbOptions(array $foldersById, array $excludeIds = [], ?string $emptyLabel = null): string
{
    $options = [];
    foreach ($foldersById as $id => $folder) {
        if (in_array($id, $excludeIds, true)) {
            continue;
        }
        $options[] = ['id' => $id, 'label' => ems_document_folder_breadcrumb($foldersById, $id)];
    }
    usort($options, static fn($a, $b) => strcmp($a['label'], $b['label']));

    $html = '';
    if ($emptyLabel !== null) {
        $html .= '<option value="0">' . htmlspecialchars($emptyLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    foreach ($options as $opt) {
        $html .= '<option value="' . $opt['id'] . '">' . htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function documentManageFlattenTree(array $nodes, int $depth = 0): array
{
    $out = [];
    foreach ($nodes as $node) {
        $out[] = ['node' => $node, 'depth' => $depth];
        if (!empty($node['children'])) {
            $out = array_merge($out, documentManageFlattenTree($node['children'], $depth + 1));
        }
    }
    return $out;
}

$tree = ems_document_build_tree($allFolders);
$flatFolders = documentManageFlattenTree($tree);

$docCounts = [];
$stmtCounts = $pdo->prepare("SELECT folder_id, COUNT(*) AS c FROM document_files WHERE unit_code = ? GROUP BY folder_id");
$stmtCounts->execute([$unitCode]);
foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $docCounts[(int)$row['folder_id']] = (int)$row['c'];
}

$divisionOptions = ems_division_options();

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<style>
.doc-form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
@media (max-width: 720px) { .doc-form-grid { grid-template-columns: 1fr; } }
.doc-form-grid label, .doc-form-full label { display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; }
.doc-form-grid input, .doc-form-grid select, .doc-form-full input, .doc-form-full select, .doc-form-full textarea {
    width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;
}
.doc-form-full { margin-top:12px; }
.doc-folder-row { display:flex; align-items:center; gap:8px; padding:8px 6px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; }
.doc-folder-row-name { font-weight:600; color:#0f172a; white-space:nowrap; }
.doc-folder-row form { display:inline-flex; gap:6px; align-items:center; margin:0; }
.doc-folder-row input[type=text], .doc-folder-row select { padding:6px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; }
.doc-inline-badge { font-size:11px; color:#94a3b8; background:#f1f5f9; border-radius:999px; padding:1px 8px; }
</style>

<section class="content">
    <div class="page page-shell">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 class="page-title">Kelola Dokumen</h1>
                <p class="page-subtitle">Upload dokumen ke folder division Anda<?= $isExecutiveManager ? ', dan kelola seluruh folder/dokumen lintas division sebagai Executive.' : '.' ?></p>
            </div>
            <a href="/dashboard/dokumen.php" class="btn-secondary"><?= ems_icon('magnifying-glass', 'h-4 w-4') ?> Cari &amp; Lihat Dokumen</a>
        </div>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string)$message, 'success', 'Dokumen') ?>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <?= ems_render_toast_script((string)$warning, 'warning', 'Dokumen') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string)$error, 'error', 'Dokumen', 7500) ?>
        <?php endforeach; ?>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><strong>Upload Dokumen Baru</strong></div>
            <?php if (empty($uploadFolders)): ?>
                <p class="meta-text">Belum ada folder untuk division Anda. Hubungi Executive untuk membuat folder terlebih dahulu.</p>
            <?php else: ?>
                <form method="post" action="/dashboard/document_manage_action.php" enctype="multipart/form-data" onsubmit="return documentShowLoading('upload');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="upload">
                    <div class="doc-form-grid">
                        <div>
                            <label>Folder Tujuan</label>
                            <select name="folder_id" required>
                                <?= documentManageBreadcrumbOptions(ems_document_folders_by_id($uploadFolders)) ?>
                            </select>
                        </div>
                        <div>
                            <label>Judul Dokumen</label>
                            <input type="text" name="title" required maxlength="255" placeholder="Mis. SOP Penanganan Luka Bakar">
                        </div>
                    </div>
                    <div class="doc-form-full">
                        <label>Tag (opsional, pisahkan koma)</label>
                        <input type="text" name="tags" maxlength="255" placeholder="paramedic, sop, luka bakar">
                    </div>
                    <div class="doc-form-full">
                        <label>File (PDF, DOC/DOCX, ODT, TXT/MD/CSV/JSON/XML/LOG/INI, XLSX/XLS — maks <?= ems_document_upload_limit_label() ?>). Foto/gambar tidak diterima di sini.</label>
                        <input type="file" name="document" required accept=".pdf,.doc,.docx,.odt,.txt,.md,.csv,.json,.xml,.log,.ini,.xlsx,.xls">
                    </div>
                    <div class="doc-form-full">
                        <label>Isi Dokumen (Manual, opsional)</label>
                        <p class="meta-text-xs" style="margin-bottom:4px;">Hanya perlu diisi kalau file berupa PDF hasil scan/berisi gambar yang tidak bisa dibaca otomatis — ekstraksi teksnya akan gagal dan dokumen tidak akan muncul di pencarian kecuali isi ini diketik manual. Kosongkan saja kalau tidak yakin; Anda bisa isi ini nanti lewat tombol Edit setelah upload jika ekstraksi otomatis ternyata gagal.</p>
                        <textarea name="manual_content" rows="4" placeholder="Ketik ulang isi dokumen ini apa adanya (hanya jika ekstraksi otomatis gagal)..."></textarea>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" class="btn-primary"><?= ems_icon('arrow-up-tray', 'h-4 w-4') ?> Upload</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><strong>Dokumen Division <?= htmlspecialchars($userDivision, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <?php if (empty($myDocs)): ?>
                <p class="meta-text">Belum ada dokumen di division Anda.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr><th>Judul</th><th>Folder</th><th>Diupload Oleh</th><th>Tanggal</th><th>Status Ekstraksi</th><th>Aksi</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myDocs as $doc): ?>
                                <tr>
                                    <td><a href="/dashboard/document_view.php?id=<?= (int)$doc['id'] ?>"><?= htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                    <td class="meta-text"><?= htmlspecialchars(ems_document_folder_breadcrumb($foldersById, (int)$doc['folder_id']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="meta-text"><?= htmlspecialchars((string)$doc['uploaded_by_name_snapshot'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="meta-text"><?= htmlspecialchars(date('d M Y', strtotime((string)$doc['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="doc-inline-badge"<?= $doc['extraction_status'] === 'failed' ? ' style="background:#fee2e2;color:#b91c1c;"' : '' ?>><?= htmlspecialchars((string)$doc['extraction_status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($doc['extraction_status'] === 'failed'): ?>
                                            <div class="meta-text-xs" style="color:#b91c1c;">Ekstraksi gagal — isi manual lewat Edit</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn-secondary" style="padding:4px 10px;"
                                            data-edit-trigger="<?= (int)$doc['id'] ?>"
                                            data-doc-title="<?= htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-doc-tags="<?= htmlspecialchars((string)$doc['tags'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-doc-status="<?= htmlspecialchars((string)$doc['extraction_status'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-doc-manual-content="<?= $doc['extraction_status'] === 'manual' ? htmlspecialchars((string)$doc['extracted_text'], ENT_QUOTES, 'UTF-8') : '' ?>">
                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                        </button>
                                        <form method="post" action="/dashboard/document_manage_action.php" style="display:inline;" onsubmit="return confirm('Hapus dokumen ini?') && documentShowLoading('delete_document');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete_document">
                                            <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                                            <button type="submit" class="btn-danger" style="padding:4px 10px;"><?= ems_icon('trash', 'h-4 w-4') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><strong>Riwayat Aktivitas<?= $isExecutiveManager ? ' (Semua Division)' : ' Division ' . htmlspecialchars($userDivision, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <?php if (empty($activityLogs)): ?>
                <p class="meta-text">Belum ada aktivitas.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr><th>Waktu</th><th>Aksi</th><th>Oleh</th><th>Catatan</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activityLogs as $log): ?>
                                <tr>
                                    <td class="meta-text" style="white-space:nowrap;"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$log['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="doc-inline-badge"><?= htmlspecialchars(ems_document_activity_action_label((string)$log['action']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars((string)$log['actor_name_snapshot'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="meta-text"><?= htmlspecialchars((string)$log['note'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isExecutiveManager): ?>
            <div class="card" style="margin-top:16px;">
                <div class="card-header"><strong>Buat Folder Baru</strong></div>
                <form method="post" action="/dashboard/document_manage_action.php" onsubmit="return documentShowLoading('create_folder');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <div class="doc-form-grid">
                        <div>
                            <label>Folder Induk (opsional)</label>
                            <select name="parent_id" id="createFolderParent" onchange="document.getElementById('createFolderDivisionWrap').style.display = this.value === '0' ? 'block' : 'none';">
                                <?= documentManageBreadcrumbOptions($foldersById, [], 'Tidak ada (folder utama)') ?>
                            </select>
                        </div>
                        <div id="createFolderDivisionWrap">
                            <label>Division Pemilik</label>
                            <select name="division">
                                <?php foreach ($divisionOptions as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="doc-form-full">
                        <label>Nama Folder</label>
                        <input type="text" name="name" required maxlength="150" placeholder="Mis. SOP General Affairs">
                    </div>
                    <div class="doc-form-full">
                        <label>Deskripsi (opsional)</label>
                        <input type="text" name="description" maxlength="255">
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" class="btn-primary"><?= ems_icon('plus', 'h-4 w-4') ?> Buat Folder</button>
                    </div>
                </form>
            </div>

            <div class="card" style="margin-top:16px;">
                <div class="card-header"><strong>Kelola Folder</strong></div>
                <?php if (empty($flatFolders)): ?>
                    <p class="meta-text">Belum ada folder.</p>
                <?php else: ?>
                    <?php foreach ($flatFolders as $entry): ?>
                        <?php
                        $node = $entry['node'];
                        $fid = (int)$node['id'];
                        $depth = $entry['depth'];
                        $descendantIds = ems_document_folder_descendant_ids($allFolders, $fid);
                        $docCount = $docCounts[$fid] ?? 0;
                        ?>
                        <div class="doc-folder-row" style="padding-left: <?= 6 + $depth * 22 ?>px;">
                            <span class="doc-folder-row-name"><?= ems_icon('folder', 'h-4 w-4') ?> <?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="doc-inline-badge"><?= htmlspecialchars((string)$node['division'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="doc-inline-badge"><?= count($descendantIds) ?> subfolder &middot; <?= $docCount ?> dokumen</span>

                            <form method="post" action="/dashboard/document_manage_action.php" onsubmit="return documentShowLoading('rename_folder');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="rename_folder">
                                <input type="hidden" name="folder_id" value="<?= $fid ?>">
                                <input type="text" name="name" value="<?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?>" style="width:160px;">
                                <button type="submit" class="btn-secondary" style="padding:4px 8px;">Ganti Nama</button>
                            </form>

                            <form method="post" action="/dashboard/document_manage_action.php" onsubmit="return documentShowLoading('move_folder');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="move_folder">
                                <input type="hidden" name="folder_id" value="<?= $fid ?>">
                                <select name="target_parent_id" style="width:180px;">
                                    <?= documentManageBreadcrumbOptions($foldersById, array_merge([$fid], $descendantIds), 'Jadi folder utama...') ?>
                                </select>
                                <select name="division" title="Division baru — hanya dipakai jika dipindah jadi folder utama" style="width:150px;">
                                    <?php foreach ($divisionOptions as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8') ?>" <?= $opt['value'] === $node['division'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-secondary" style="padding:4px 8px;">Pindah</button>
                            </form>

                            <form method="post" action="/dashboard/document_manage_action.php"
                                onsubmit="return documentConfirmDeleteFolder(this, <?= count($descendantIds) ?>, <?= $docCount ?>);">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_folder">
                                <input type="hidden" name="folder_id" value="<?= $fid ?>">
                                <input type="hidden" name="force" value="0" class="doc-force-input">
                                <button type="submit" class="btn-danger" style="padding:4px 8px;"><?= ems_icon('trash', 'h-4 w-4') ?> Hapus</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top:16px;">
                <div class="card-header"><strong>Kelola Semua Dokumen</strong></div>
                <div class="doc-layout" style="display:grid; grid-template-columns: 280px 1fr; gap:16px;">
                    <div style="max-height:480px; overflow-y:auto;">
                        <?php foreach ($flatFolders as $entry): ?>
                            <?php $fid = (int)$entry['node']['id']; $depth = $entry['depth']; ?>
                            <div style="padding: 4px 0 4px <?= $depth * 16 ?>px;">
                                <a href="?browse_folder=<?= $fid ?>" style="<?= $browseFolderId === $fid ? 'color:#0ea5e9;font-weight:600;' : '' ?>">
                                    <?= ems_icon('folder', 'h-4 w-4') ?> <?= htmlspecialchars((string)$entry['node']['name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <span class="doc-inline-badge"><?= $docCounts[$fid] ?? 0 ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <?php if ($browseFolder === null): ?>
                            <p class="meta-text">Pilih folder di sebelah kiri.</p>
                        <?php elseif (empty($browseDocs)): ?>
                            <p class="meta-text">Belum ada dokumen di folder ini.</p>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table-custom">
                                    <thead><tr><th>Judul</th><th>Diupload Oleh</th><th>Pindah Ke</th><th>Aksi</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($browseDocs as $doc): ?>
                                            <tr>
                                                <td><a href="/dashboard/document_view.php?id=<?= (int)$doc['id'] ?>"><?= htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                                <td class="meta-text"><?= htmlspecialchars((string)$doc['uploaded_by_name_snapshot'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <form method="post" action="/dashboard/document_manage_action.php" style="display:inline-flex; gap:6px;" onsubmit="return documentShowLoading('move_document');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="move_document">
                                                        <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                                                        <select name="target_folder_id" style="width:170px;">
                                                            <?= documentManageBreadcrumbOptions($foldersById, [(int)$doc['folder_id']]) ?>
                                                        </select>
                                                        <button type="submit" class="btn-secondary" style="padding:4px 8px;">Pindah</button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn-secondary" style="padding:4px 10px;"
                                                        data-edit-trigger="<?= (int)$doc['id'] ?>"
                                                        data-doc-title="<?= htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-doc-tags="<?= htmlspecialchars((string)$doc['tags'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-doc-status="<?= htmlspecialchars((string)$doc['extraction_status'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-doc-manual-content="<?= $doc['extraction_status'] === 'manual' ? htmlspecialchars((string)$doc['extracted_text'], ENT_QUOTES, 'UTF-8') : '' ?>">
                                                        <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                                    </button>
                                                    <form method="post" action="/dashboard/document_manage_action.php" style="display:inline;" onsubmit="return confirm('Hapus dokumen ini?') && documentShowLoading('delete_document');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="delete_document">
                                                        <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                                                        <button type="submit" class="btn-danger" style="padding:4px 10px;"><?= ems_icon('trash', 'h-4 w-4') ?></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="modal-overlay" id="docEditModalOverlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header"><strong>Edit Dokumen</strong><button type="button" onclick="documentCloseEditModal();">&times;</button></div>
        <form method="post" action="/dashboard/document_manage_action.php" enctype="multipart/form-data" onsubmit="return documentShowLoading('edit_document');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit_document">
            <input type="hidden" name="document_id" id="docEditId" value="">
            <div class="modal-body">
                <label>Judul Dokumen</label>
                <input type="text" name="title" id="docEditTitle" required maxlength="255" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:12px;">
                <label>Tag (opsional)</label>
                <input type="text" name="tags" id="docEditTags" maxlength="255" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:12px;">
                <label>Ganti File (opsional, kosongkan jika tidak ingin mengganti). Foto/gambar tidak diterima.</label>
                <input type="file" name="document" accept=".pdf,.doc,.docx,.odt,.txt,.md,.csv,.json,.xml,.log,.ini,.xlsx,.xls">
                <div id="docEditManualWrap" style="margin-top:12px;">
                    <label id="docEditManualLabel">Isi Dokumen (Manual, opsional)</label>
                    <p class="meta-text-xs" id="docEditManualHint" style="margin-bottom:4px;">Hanya perlu diisi kalau ekstraksi teks otomatis dokumen ini gagal (mis. PDF hasil scan/berisi gambar).</p>
                    <textarea name="manual_content" id="docEditManualContent" rows="4" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px;" placeholder="Ketik ulang isi dokumen ini apa adanya..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="documentCloseEditModal();">Batal</button>
                <button type="submit" class="btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function documentCloseEditModal() {
    document.getElementById('docEditModalOverlay').style.display = 'none';
}

document.querySelectorAll('[data-edit-trigger]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('docEditId').value = btn.getAttribute('data-edit-trigger');
        document.getElementById('docEditTitle').value = btn.getAttribute('data-doc-title') || '';
        document.getElementById('docEditTags').value = btn.getAttribute('data-doc-tags') || '';

        var status = btn.getAttribute('data-doc-status') || '';
        var manualContent = btn.getAttribute('data-doc-manual-content') || '';
        var manualTextarea = document.getElementById('docEditManualContent');
        var manualLabel = document.getElementById('docEditManualLabel');
        var manualHint = document.getElementById('docEditManualHint');
        manualTextarea.value = manualContent;
        if (status === 'failed') {
            manualTextarea.required = true;
            manualLabel.innerHTML = 'Isi Dokumen (Manual) <span class="required">*</span>';
            manualHint.textContent = 'Ekstraksi otomatis dokumen ini GAGAL (kemungkinan PDF hasil scan/berisi gambar tanpa teks) — dokumen tidak akan muncul di pencarian sampai Anda isi ulang isinya di sini.';
            manualHint.style.color = '#b91c1c';
        } else {
            manualTextarea.required = false;
            manualLabel.textContent = 'Isi Dokumen (Manual, opsional)';
            manualHint.textContent = 'Hanya perlu diisi kalau ekstraksi teks otomatis dokumen ini gagal (mis. PDF hasil scan/berisi gambar).';
            manualHint.style.color = '';
        }

        document.getElementById('docEditModalOverlay').style.display = 'flex';
    });
});

// Loading overlay untuk semua form di halaman ini (upload/edit/hapus/pindah/
// buat folder dll) — bukan cuma yang ada file-nya, supaya tidak terlihat
// hang/diam saja selagi server memproses (ekstraksi PDF dkk bisa makan
// waktu beberapa detik). Dipanggil langsung dari onsubmit tiap form
// (synchronous, bukan lewat delegasi+requestAnimationFrame) supaya pasti
// jalan sebelum browser mulai pindah halaman — overlay & spinner reuse
// #globalUploadOverlay yang sudah dimuat di partials/footer.php.
var documentLoadingMessages = {
    upload: ['Mengupload Dokumen', 'Mohon tunggu, dokumen sedang diupload dan teksnya sedang diekstrak otomatis untuk pencarian.'],
    edit_document: ['Menyimpan Perubahan', 'Mohon tunggu, perubahan dokumen sedang disimpan.'],
    delete_document: ['Menghapus Dokumen', 'Mohon tunggu, dokumen sedang dihapus.'],
    move_document: ['Memindahkan Dokumen', 'Mohon tunggu, dokumen sedang dipindahkan ke folder tujuan.'],
    create_folder: ['Membuat Folder', 'Mohon tunggu, folder baru sedang dibuat.'],
    rename_folder: ['Mengganti Nama Folder', 'Mohon tunggu, nama folder sedang diperbarui.'],
    move_folder: ['Memindahkan Folder', 'Mohon tunggu, folder sedang dipindahkan.'],
    delete_folder: ['Menghapus Folder', 'Mohon tunggu, folder dan isinya sedang dihapus.'],
};

function documentShowLoading(actionKey) {
    var messages = documentLoadingMessages[actionKey] || ['Memproses', 'Mohon tunggu, sedang diproses.'];
    if (typeof window.emsShowUploadOverlay === 'function') {
        window.emsShowUploadOverlay(messages[0], messages[1]);
    }
    return true;
}

function documentConfirmDeleteFolder(form, subfolderCount, docCount) {
    if (subfolderCount === 0 && docCount === 0) {
        return confirm('Hapus folder ini?') && documentShowLoading('delete_folder');
    }
    var msg = 'Folder ini berisi ' + subfolderCount + ' subfolder dan ' + docCount + ' dokumen.\n' +
        'Menghapus akan MENGHAPUS SEMUANYA (hapus paksa). Lanjutkan?';
    if (confirm(msg)) {
        form.querySelector('.doc-force-input').value = '1';
        return documentShowLoading('delete_folder');
    }
    return false;
}

(function () {
    var editId = new URLSearchParams(window.location.search).get('edit');
    if (editId) {
        var trigger = document.querySelector('[data-edit-trigger="' + editId + '"]');
        if (trigger) {
            trigger.click();
        }
    }
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
