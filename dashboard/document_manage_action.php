<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/document_library.php';

$redirectTo = '/dashboard/document_manage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

ems_document_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$unitCode = ems_effective_unit($pdo, $user);
$isExecutiveManager = ems_document_is_executive_manager($user);

if (!ems_document_can_open_manage_page($user)) {
    $_SESSION['flash_errors'][] = 'Hanya manager ke atas yang bisa mengelola dokumen.';
    header('Location: ' . $redirectTo);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));

function documentFetchFolder(PDO $pdo, string $unitCode, int $folderId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM document_folders WHERE id = ? AND unit_code = ? LIMIT 1");
    $stmt->execute([$folderId, $unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function documentFetchDoc(PDO $pdo, string $unitCode, int $docId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM document_files WHERE id = ? AND unit_code = ? LIMIT 1");
    $stmt->execute([$docId, $unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

try {
    if ($action === 'upload') {
        $folderId = (int)($_POST['folder_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        $manualContent = trim((string)($_POST['manual_content'] ?? ''));

        $folder = documentFetchFolder($pdo, $unitCode, $folderId);
        if (!$folder) {
            throw new RuntimeException('Folder tujuan tidak ditemukan.');
        }
        if (!ems_document_can_upload_to_division($user, (string)$folder['division'])) {
            throw new RuntimeException('Anda tidak punya akses upload ke folder division tersebut.');
        }
        if ($title === '') {
            throw new RuntimeException('Judul dokumen wajib diisi.');
        }
        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('File dokumen wajib dipilih.');
        }

        $saved = ems_document_save_uploaded_file($_FILES['document']);
        if ($saved === null) {
            throw new RuntimeException('Upload gagal — pastikan tipe file didukung dan ukuran maksimal ' . ems_document_upload_limit_label() . '.');
        }

        $extraction = ems_document_extract_text($saved['full_path'], $saved['ext']);

        // Ekstraksi otomatis gagal (biasanya PDF hasil scan/berisi gambar,
        // tidak punya text layer) tapi admin sudah isi manual di form —
        // pakai isi manual itu langsung saat insert, bukan nunggu edit
        // terpisah lewat ems_document_store_manual_content().
        if ($extraction['status'] === 'failed' && $manualContent !== '') {
            $extraction = ['text' => $manualContent, 'status' => 'manual'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO document_files (
                unit_code, folder_id, division, title, original_filename, file_path, file_ext, mime_type,
                file_size_bytes, tags, extracted_text, extraction_status, uploaded_by, uploaded_by_name_snapshot, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $unitCode,
            $folderId,
            $folder['division'],
            $title,
            (string)($_FILES['document']['name'] ?? ''),
            $saved['path'],
            $saved['ext'],
            $saved['mime'],
            $saved['size'],
            $tags !== '' ? $tags : null,
            $extraction['text'] !== '' ? $extraction['text'] : null,
            $extraction['status'],
            (int)($user['id'] ?? 0) ?: null,
            trim((string)($user['full_name'] ?? $user['name'] ?? '')),
        ]);

        $newId = (int)$pdo->lastInsertId();
        ems_document_log_activity($pdo, $unitCode, $newId, $folderId, (string)$folder['division'], 'uploaded', $title, $user);

        if ($extraction['status'] === 'failed') {
            $_SESSION['flash_warnings'][] = 'Dokumen "' . $title . '" berhasil diupload, TAPI ekstraksi teks otomatis gagal (kemungkinan PDF hasil scan/berisi gambar) — dokumen ini belum bisa ditemukan lewat pencarian. Klik tombol Edit lalu isi "Isi Dokumen (Manual)" agar dokumen ini bisa dicari.';
        } else {
            $_SESSION['flash_messages'][] = 'Dokumen "' . $title . '" berhasil diupload.';
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'edit_document') {
        $docId = (int)($_POST['document_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        $manualContent = trim((string)($_POST['manual_content'] ?? ''));

        $doc = documentFetchDoc($pdo, $unitCode, $docId);
        if (!$doc) {
            throw new RuntimeException('Dokumen tidak ditemukan.');
        }
        if (!ems_document_can_edit_or_delete($user, $doc)) {
            throw new RuntimeException('Anda tidak punya akses mengedit dokumen ini.');
        }
        if ($title === '') {
            throw new RuntimeException('Judul dokumen wajib diisi.');
        }
        // "Isi Dokumen (Manual)" wajib diisi kalau ekstraksi otomatis
        // dokumen ini (saat ini) gagal — sama seperti yang sudah
        // ditandai `required` di sisi client (docEditManualContent),
        // dicek ulang di server karena JS bisa dimatikan.
        if ((string)$doc['extraction_status'] === 'failed' && $manualContent === '') {
            throw new RuntimeException('Ekstraksi otomatis dokumen ini gagal — "Isi Dokumen (Manual)" wajib diisi supaya dokumen bisa ditemukan lewat pencarian.');
        }

        $hasNewFile = !empty($_FILES['document']) && ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasNewFile) {
            $saved = ems_document_save_uploaded_file($_FILES['document']);
            if ($saved === null) {
                throw new RuntimeException('Ganti file gagal — pastikan tipe file didukung dan ukuran maksimal ' . ems_document_upload_limit_label() . '.');
            }

            $extraction = ems_document_extract_text($saved['full_path'], $saved['ext']);
            if ($extraction['status'] === 'failed' && $manualContent !== '') {
                $extraction = ['text' => $manualContent, 'status' => 'manual'];
            }
            $oldFullPath = __DIR__ . '/../' . $doc['file_path'];

            $stmt = $pdo->prepare("
                UPDATE document_files
                SET title = ?, tags = ?, original_filename = ?, file_path = ?, file_ext = ?, mime_type = ?,
                    file_size_bytes = ?, extracted_text = ?, extraction_status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $title,
                $tags !== '' ? $tags : null,
                (string)($_FILES['document']['name'] ?? ''),
                $saved['path'],
                $saved['ext'],
                $saved['mime'],
                $saved['size'],
                $extraction['text'] !== '' ? $extraction['text'] : null,
                $extraction['status'],
                $docId,
            ]);

            if (is_file($oldFullPath)) {
                @unlink($oldFullPath);
            }

            ems_document_log_activity($pdo, $unitCode, $docId, (int)$doc['folder_id'], (string)$doc['division'], 'replaced', $title, $user);
        } else {
            $stmt = $pdo->prepare("UPDATE document_files SET title = ?, tags = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$title, $tags !== '' ? $tags : null, $docId]);
            if ($manualContent !== '') {
                ems_document_store_manual_content($pdo, $docId, $manualContent);
            }
            ems_document_log_activity($pdo, $unitCode, $docId, (int)$doc['folder_id'], (string)$doc['division'], 'edited', $title, $user);
        }

        $_SESSION['flash_messages'][] = 'Dokumen berhasil diperbarui.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'delete_document') {
        $docId = (int)($_POST['document_id'] ?? 0);
        $doc = documentFetchDoc($pdo, $unitCode, $docId);
        if (!$doc) {
            throw new RuntimeException('Dokumen tidak ditemukan.');
        }
        if (!ems_document_can_edit_or_delete($user, $doc)) {
            throw new RuntimeException('Anda tidak punya akses menghapus dokumen ini.');
        }

        $pdo->prepare("DELETE FROM document_files WHERE id = ?")->execute([$docId]);
        $fullPath = __DIR__ . '/../' . $doc['file_path'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        ems_document_log_activity($pdo, $unitCode, null, (int)$doc['folder_id'], (string)$doc['division'], 'deleted', (string)$doc['title'], $user);

        $_SESSION['flash_messages'][] = 'Dokumen berhasil dihapus.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'move_document') {
        if (!$isExecutiveManager) {
            throw new RuntimeException('Hanya Executive yang bisa memindahkan dokumen antar folder.');
        }

        $docId = (int)($_POST['document_id'] ?? 0);
        $targetFolderId = (int)($_POST['target_folder_id'] ?? 0);

        $doc = documentFetchDoc($pdo, $unitCode, $docId);
        $targetFolder = documentFetchFolder($pdo, $unitCode, $targetFolderId);
        if (!$doc || !$targetFolder) {
            throw new RuntimeException('Dokumen atau folder tujuan tidak ditemukan.');
        }

        $pdo->prepare("UPDATE document_files SET folder_id = ?, division = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$targetFolderId, $targetFolder['division'], $docId]);

        ems_document_log_activity($pdo, $unitCode, $docId, $targetFolderId, (string)$targetFolder['division'], 'moved', (string)$doc['title'], $user);

        $_SESSION['flash_messages'][] = 'Dokumen berhasil dipindahkan.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'create_folder') {
        if (!$isExecutiveManager) {
            throw new RuntimeException('Hanya Executive yang bisa membuat folder.');
        }

        $parentId = (int)($_POST['parent_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $division = ems_normalize_division((string)($_POST['division'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Nama folder wajib diisi.');
        }

        if ($parentId > 0) {
            $parent = documentFetchFolder($pdo, $unitCode, $parentId);
            if (!$parent) {
                throw new RuntimeException('Folder induk tidak ditemukan.');
            }
            $division = (string)$parent['division'];
        } else {
            $validDivisions = array_column(ems_division_options(), 'value');
            if (!in_array($division, $validDivisions, true)) {
                throw new RuntimeException('Division tidak valid.');
            }
            $parentId = 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO document_folders (unit_code, division, parent_id, name, description, created_by, created_by_name_snapshot, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $unitCode,
            $division,
            $parentId > 0 ? $parentId : null,
            $name,
            $description !== '' ? $description : null,
            (int)($user['id'] ?? 0) ?: null,
            trim((string)($user['full_name'] ?? $user['name'] ?? '')),
        ]);

        $newFolderId = (int)$pdo->lastInsertId();
        ems_document_log_activity($pdo, $unitCode, null, $newFolderId, $division, 'folder_created', $name, $user);

        $_SESSION['flash_messages'][] = 'Folder "' . $name . '" berhasil dibuat.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'rename_folder') {
        if (!$isExecutiveManager) {
            throw new RuntimeException('Hanya Executive yang bisa mengganti nama folder.');
        }

        $folderId = (int)($_POST['folder_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Nama folder wajib diisi.');
        }

        $folder = documentFetchFolder($pdo, $unitCode, $folderId);
        if (!$folder) {
            throw new RuntimeException('Folder tidak ditemukan.');
        }

        $pdo->prepare("UPDATE document_folders SET name = ?, updated_at = NOW() WHERE id = ?")->execute([$name, $folderId]);
        ems_document_log_activity($pdo, $unitCode, null, $folderId, (string)$folder['division'], 'folder_renamed', $name, $user);

        $_SESSION['flash_messages'][] = 'Folder berhasil diganti nama.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'move_folder') {
        if (!$isExecutiveManager) {
            throw new RuntimeException('Hanya Executive yang bisa memindahkan folder.');
        }

        $folderId = (int)($_POST['folder_id'] ?? 0);
        $targetParentId = (int)($_POST['target_parent_id'] ?? 0);
        $rootDivision = ems_normalize_division((string)($_POST['division'] ?? ''));

        $folder = documentFetchFolder($pdo, $unitCode, $folderId);
        if (!$folder) {
            throw new RuntimeException('Folder tidak ditemukan.');
        }

        $allFolders = ems_document_fetch_folders($pdo, $unitCode);
        $descendantIds = ems_document_folder_descendant_ids($allFolders, $folderId);

        if ($targetParentId === $folderId || in_array($targetParentId, $descendantIds, true)) {
            throw new RuntimeException('Tidak bisa memindahkan folder ke dalam dirinya sendiri atau turunannya.');
        }

        $newDivision = null;
        if ($targetParentId > 0) {
            $targetParent = documentFetchFolder($pdo, $unitCode, $targetParentId);
            if (!$targetParent) {
                throw new RuntimeException('Folder tujuan tidak ditemukan.');
            }
            $newDivision = (string)$targetParent['division'];
        } else {
            $validDivisions = array_column(ems_division_options(), 'value');
            if (!in_array($rootDivision, $validDivisions, true)) {
                throw new RuntimeException('Pindah ke folder utama butuh division tujuan yang valid.');
            }
            $newDivision = $rootDivision;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE document_folders SET parent_id = ?, division = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$targetParentId > 0 ? $targetParentId : null, $newDivision, $folderId]);

            $allIdsToSync = array_merge([$folderId], $descendantIds);
            if ($newDivision !== (string)$folder['division'] && $descendantIds) {
                $placeholders = implode(',', array_fill(0, count($descendantIds), '?'));
                $pdo->prepare("UPDATE document_folders SET division = ?, updated_at = NOW() WHERE id IN ($placeholders)")
                    ->execute(array_merge([$newDivision], $descendantIds));
            }
            if ($newDivision !== (string)$folder['division']) {
                $placeholders = implode(',', array_fill(0, count($allIdsToSync), '?'));
                $pdo->prepare("UPDATE document_files SET division = ?, updated_at = NOW() WHERE folder_id IN ($placeholders)")
                    ->execute(array_merge([$newDivision], $allIdsToSync));
            }

            ems_document_log_activity($pdo, $unitCode, null, $folderId, $newDivision, 'folder_moved', (string)$folder['name'], $user);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $_SESSION['flash_messages'][] = 'Folder berhasil dipindahkan.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'delete_folder') {
        if (!$isExecutiveManager) {
            throw new RuntimeException('Hanya Executive yang bisa menghapus folder.');
        }

        $folderId = (int)($_POST['folder_id'] ?? 0);
        $force = (int)($_POST['force'] ?? 0) === 1;

        $folder = documentFetchFolder($pdo, $unitCode, $folderId);
        if (!$folder) {
            throw new RuntimeException('Folder tidak ditemukan.');
        }

        $allFolders = ems_document_fetch_folders($pdo, $unitCode);
        $descendantIds = ems_document_folder_descendant_ids($allFolders, $folderId);

        $docCountStmt = $pdo->prepare("SELECT COUNT(*) FROM document_files WHERE folder_id IN (" . implode(',', array_fill(0, count($descendantIds) + 1, '?')) . ")");
        $docCountStmt->execute(array_merge([$folderId], $descendantIds));
        $docCount = (int)$docCountStmt->fetchColumn();

        if (!$force && (count($descendantIds) > 0 || $docCount > 0)) {
            throw new RuntimeException('Folder tidak kosong (' . count($descendantIds) . ' subfolder, ' . $docCount . ' dokumen). Gunakan opsi hapus paksa untuk menghapus sekaligus isinya.');
        }

        $result = ems_document_delete_folder_cascade($pdo, $unitCode, $folderId, $user);

        $_SESSION['flash_messages'][] = 'Folder dihapus (' . $result['folders'] . ' folder, ' . $result['documents'] . ' dokumen ikut terhapus).';
        header('Location: ' . $redirectTo);
        exit;
    }

    throw new RuntimeException('Aksi tidak dikenali.');
} catch (\Throwable $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
    header('Location: ' . $redirectTo);
    exit;
}
