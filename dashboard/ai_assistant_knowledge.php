<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_roxy_ensure_tables($pdo);
$user = $_SESSION['user_rh'] ?? [];
if (!ems_is_manager_plus_role((string) ($user['role'] ?? ''))) {
    $_SESSION['flash_errors'][] = 'Hanya manager ke atas yang dapat mengelola basis pengetahuan Roxy.';
    header('Location: /dashboard/ai_assistant.php');
    exit;
}

$unitCode = ems_effective_unit($pdo, $user);
$userId = (int) ($user['id'] ?? 0);
$errors = [];
$success = '';
$formRow = ['id' => 0, 'category' => '', 'title' => '', 'tags' => '', 'content' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken(csrfRequestToken())) {
        $errors[] = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'save'));
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'delete') {
            if ($id <= 0) {
                $errors[] = 'Artikel yang dipilih tidak valid.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM bot_knowledge_base WHERE id = ? AND unit_code = ?');
                $stmt->execute([$id, $unitCode]);
                $success = $stmt->rowCount() ? 'Artikel dihapus.' : 'Artikel tidak ditemukan.';
            }
        } else {
            $formRow = [
                'id' => $id,
                'category' => trim((string) ($_POST['category'] ?? '')),
                'title' => trim((string) ($_POST['title'] ?? '')),
                'tags' => trim((string) ($_POST['tags'] ?? '')),
                'content' => trim((string) ($_POST['content'] ?? '')),
            ];
            if ($formRow['title'] === '' || mb_strlen($formRow['title']) > 255) {
                $errors[] = 'Judul wajib diisi dan maksimal 255 karakter.';
            }
            if (mb_strlen($formRow['category']) > 100 || mb_strlen($formRow['tags']) > 255) {
                $errors[] = 'Kategori maksimal 100 karakter dan tags maksimal 255 karakter.';
            }
            if (mb_strlen($formRow['content']) < 30 || mb_strlen($formRow['content']) > 100000) {
                $errors[] = 'Isi artikel harus 30 sampai 100.000 karakter.';
            }

            if ($errors === []) {
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE bot_knowledge_base SET category = ?, title = ?, tags = ?, content = ? WHERE id = ? AND unit_code = ?');
                    $stmt->execute([$formRow['category'] ?: null, $formRow['title'], $formRow['tags'] ?: null, $formRow['content'], $id, $unitCode]);
                    $success = $stmt->rowCount() ? 'Artikel diperbarui.' : 'Tidak ada perubahan atau artikel tidak ditemukan.';
                } else {
                    $name = trim((string) ($user['full_name'] ?? $user['name'] ?? 'Manager'));
                    $stmt = $pdo->prepare('INSERT INTO bot_knowledge_base (unit_code, category, title, content, tags, created_by, created_by_name_snapshot) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$unitCode, $formRow['category'] ?: null, $formRow['title'], $formRow['content'], $formRow['tags'] ?: null, $userId, mb_substr($name, 0, 150)]);
                    $success = 'Artikel ditambahkan ke basis pengetahuan Roxy.';
                }
                $formRow = ['id' => 0, 'category' => '', 'title' => '', 'tags' => '', 'content' => ''];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editStmt = $pdo->prepare('SELECT id, category, title, tags, content FROM bot_knowledge_base WHERE id = ? AND unit_code = ? LIMIT 1');
    $editStmt->execute([(int) $_GET['edit'], $unitCode]);
    $formRow = $editStmt->fetch(PDO::FETCH_ASSOC) ?: $formRow;
}

$listStmt = $pdo->prepare('SELECT id, category, title, tags, content, updated_at, created_by_name_snapshot FROM bot_knowledge_base WHERE unit_code = ? ORDER BY updated_at DESC, id DESC LIMIT 200');
$listStmt->execute([$unitCode]);
$articles = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$pageTitle = 'Basis Pengetahuan Roxy | Farmasi EMS';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="page-title">Basis Pengetahuan Roxy</h1>
                <p class="page-subtitle">Kelola panduan aplikasi dan SOP yang menjadi sumber jawaban Roxy untuk unit ini. Tulis isi yang sudah disepakati dan rujukan yang jelas.</p>
            </div>
            <a class="btn-secondary" href="<?= ems_current_user_is_programmer_roxwood() ? '/dashboard/ai_assistant_monitoring.php' : '/dashboard/ai_assistant.php' ?>">
                <?= ems_icon('arrow-left', 'h-4 w-4') ?> <?= ems_current_user_is_programmer_roxwood() ? 'Monitoring Roxy' : 'Chat Roxy' ?>
            </a>
        </div>

        <?php foreach ($errors as $error): ?><div class="alert alert-danger mt-3"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <?php if ($success !== ''): ?><div class="alert alert-success mt-3"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="card mt-4">
            <div class="card-header"><?= (int) $formRow['id'] > 0 ? 'Edit Artikel' : 'Tambah Artikel' ?></div>
            <form method="POST" class="card-section">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) $formRow['id'] ?>">
                <label>Kategori</label>
                <input type="text" name="category" maxlength="100" value="<?= htmlspecialchars((string) $formRow['category'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Contoh: Penggunaan aplikasi, SOP IGD">
                <label>Judul</label>
                <input type="text" name="title" required maxlength="255" value="<?= htmlspecialchars((string) $formRow['title'], ENT_QUOTES, 'UTF-8') ?>">
                <label>Tag pencarian</label>
                <input type="text" name="tags" maxlength="255" value="<?= htmlspecialchars((string) $formRow['tags'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Pisahkan kata kunci dengan koma">
                <label>Isi / langkah kerja</label>
                <textarea name="content" rows="10" minlength="30" maxlength="100000" required><?= htmlspecialchars((string) $formRow['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="flex gap-2 mt-3">
                    <button type="submit" class="btn-primary"><?= ems_icon('check', 'h-4 w-4') ?> Simpan</button>
                    <?php if ((int) $formRow['id'] > 0): ?><a class="btn-secondary" href="ai_assistant_knowledge.php">Batal</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card mt-4">
            <div class="card-header">Artikel Unit Ini (<?= count($articles) ?>)</div>
            <?php if ($articles === []): ?>
                <p class="card-section meta-text">Belum ada artikel. Tambahkan ringkasan SOP atau panduan penggunaan yang sudah disepakati.</p>
            <?php else: ?>
                <?php foreach ($articles as $article): ?>
                    <article class="card-section" style="border-bottom:1px solid #e2e8f0;">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <strong><?= htmlspecialchars((string) $article['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="meta-text-xs"><?= htmlspecialchars((string) ($article['category'] ?: 'Tanpa kategori'), ENT_QUOTES, 'UTF-8') ?> · Diubah <?= htmlspecialchars((string) $article['updated_at'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="flex gap-2">
                                <a class="btn-secondary btn-sm" href="ai_assistant_knowledge.php?edit=<?= (int) $article['id'] ?>">Edit</a>
                                <form method="POST" onsubmit="return confirm('Hapus artikel ini dari basis pengetahuan Roxy?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $article['id'] ?>">
                                    <button type="submit" class="btn-danger btn-sm">Hapus</button>
                                </form>
                            </div>
                        </div>
                        <?php if (!empty($article['tags'])): ?><div class="meta-text-xs mt-2">Tag: <?= htmlspecialchars((string) $article['tags'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                        <details class="mt-2"><summary class="cursor-pointer">Lihat isi artikel</summary><div class="whitespace-pre-wrap mt-2"><?= htmlspecialchars((string) $article['content'], ENT_QUOTES, 'UTF-8') ?></div></details>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>
