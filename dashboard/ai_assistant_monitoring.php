<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
if (!ems_current_user_is_programmer_roxwood()) {
    $_SESSION['flash_errors'][] = 'Monitoring Roxy hanya dapat diakses oleh Programmer Roxwood.';
    header('Location: /dashboard/ai_assistant.php');
    exit;
}

ems_roxy_ensure_tables($pdo);

$pageTitle = 'Monitoring Roxy | Farmasi EMS';
$unitCode = ems_effective_unit($pdo, $user);

// Docs/AI_ASSISTANT_MODULE.md §4c: setiap percakapan sudah terikat ke satu
// user_id sejak awal (tidak pernah campur antar user) — yang perlu
// dipastikan di sini cuma di sisi TAMPILAN: kelompokkan per medis, bukan
// satu aliran tercampur, supaya kalau banyak medis tanya di jam yang sama
// manager tetap lihat jelas siapa tanya apa.
$stmt = $pdo->prepare("
    SELECT bc.id, bc.title, bc.last_message_at, bc.created_at,
        ur.id AS medic_id, ur.full_name AS medic_name,
        (SELECT COUNT(*) FROM bot_messages bm WHERE bm.conversation_id = bc.id) AS message_count
    FROM bot_conversations bc
    JOIN user_rh ur ON ur.id = bc.user_id
    WHERE bc.unit_code = ?
    ORDER BY ur.full_name ASC, bc.last_message_at DESC
");
$stmt->execute([$unitCode]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingStmt = $pdo->prepare("
    SELECT ac.*, c.title AS conversation_title
    FROM bot_answer_corrections ac
    LEFT JOIN bot_conversations c ON c.id = ac.conversation_id
    WHERE ac.unit_code = ? AND ac.verification_status = 'pending'
    ORDER BY ac.created_at ASC
    LIMIT 100
");
$pendingStmt->execute([$unitCode]);
$pendingCorrections = $pendingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$grouped = [];
foreach ($rows as $row) {
    $grouped[$row['medic_id']]['medic_name'] = $row['medic_name'];
    $grouped[$row['medic_id']]['conversations'][] = $row;
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<style>
.roxy-mon-group { margin-bottom:20px; }
.roxy-mon-group-header { font-weight:700; font-size:14px; color:#0f172a; margin-bottom:8px; padding-bottom:6px; border-bottom:2px solid #e0f2fe; }
.roxy-mon-conv-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:6px; font-size:13px; }
.roxy-mon-conv-row .meta { color:#94a3b8; font-size:11px; }
.roxy-mon-modal-msg { margin-bottom:10px; }
.roxy-mon-modal-msg .label { font-size:10px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px; }
.roxy-mon-modal-msg .bubble { padding:8px 12px; border-radius:10px; font-size:13px; white-space:pre-wrap; }
.roxy-mon-modal-msg.user .bubble { background:#0ea5e9; color:#fff; }
.roxy-mon-modal-msg.bot .bubble { background:#f1f5f9; color:#1e293b; }
</style>

<section class="content">
    <div class="page page-shell">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 class="page-title">Monitoring Roxy</h1>
                <p class="page-subtitle">Riwayat percakapan seluruh medis dengan Roxy, dikelompokkan per orang.</p>
            </div>
            <a href="/dashboard/ai_assistant.php" class="btn-secondary"><?= ems_icon('chat-bubble-left-right', 'h-4 w-4') ?> Chat Roxy Saya</a>
        </div>

        <div class="card mt-4">
            <?php if (empty($grouped)): ?>
                <p class="meta-text">Belum ada percakapan siapa pun dengan Roxy.</p>
            <?php else: ?>
                <?php foreach ($grouped as $group): ?>
                    <div class="roxy-mon-group">
                        <div class="roxy-mon-group-header"><?= ems_icon('user-group', 'h-4 w-4') ?> <?= htmlspecialchars((string) $group['medic_name'], ENT_QUOTES, 'UTF-8') ?> <span class="meta">(<?= count($group['conversations']) ?> percakapan)</span></div>
                        <?php foreach ($group['conversations'] as $conv): ?>
                            <div class="roxy-mon-conv-row">
                                <div>
                                    <div><?= htmlspecialchars((string) ($conv['title'] ?: 'Percakapan'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="meta"><?= (int) $conv['message_count'] ?> pesan &middot; terakhir <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $conv['last_message_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <button type="button" class="btn-secondary btn-sm roxy-mon-view-btn" data-id="<?= (int) $conv['id'] ?>" data-title="<?= htmlspecialchars((string) ($conv['title'] ?: 'Percakapan'), ENT_QUOTES, 'UTF-8') ?>" data-medic="<?= htmlspecialchars((string) $group['medic_name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= ems_icon('eye', 'h-4 w-4') ?> Lihat
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card mt-4">
            <div class="card-header">Koreksi Jawaban Menunggu Review (<?= count($pendingCorrections) ?>)</div>
            <?php if ($pendingCorrections === []): ?>
                <p class="meta-text">Belum ada koreksi yang menunggu review.</p>
            <?php else: ?>
                <?php foreach ($pendingCorrections as $correction): ?>
                    <article class="card-section" style="border-bottom:1px solid #e2e8f0;">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <strong><?= htmlspecialchars((string) $correction['submitted_by_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="meta-text-xs"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $correction['created_at'])), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($correction['conversation_title'] ?: 'Percakapan'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <p><strong>Pertanyaan:</strong> <?= nl2br(htmlspecialchars((string) $correction['question_snapshot'], ENT_QUOTES, 'UTF-8')) ?></p>
                        <details class="mt-2">
                            <summary class="cursor-pointer font-semibold">Bandingkan jawaban lama dan koreksi</summary>
                            <div class="mt-2"><strong>Jawaban Roxy:</strong><div class="whitespace-pre-wrap"><?= htmlspecialchars((string) $correction['wrong_answer_snapshot'], ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="mt-2"><strong>Usulan koreksi:</strong><div class="whitespace-pre-wrap"><?= htmlspecialchars((string) $correction['corrected_answer'], ENT_QUOTES, 'UTF-8') ?></div></div>
                        </details>
                        <form method="POST" action="/actions/roxy_correction_review_action.php" class="mt-3">
                            <?= csrfField() ?>
                            <input type="hidden" name="correction_id" value="<?= (int) $correction['id'] ?>">
                            <label>Alasan review / rujukan SOP (wajib)</label>
                            <textarea name="verification_note" rows="2" minlength="5" maxlength="2000" required placeholder="Contoh: sesuai SOP dokumen ... bagian ..."></textarea>
                            <div class="flex gap-2 mt-2">
                                <button type="submit" name="decision" value="verify" class="btn-primary btn-sm">Setujui &amp; Ajarkan ke Roxy</button>
                                <button type="submit" name="decision" value="reject" class="btn-danger btn-sm">Tolak Koreksi</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="modal-overlay hidden" id="roxyMonModalOverlay">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title" id="roxyMonModalTitle">Percakapan</div>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('roxyMonModalOverlay').classList.add('hidden');"><?= ems_icon('x-mark', 'h-5 w-5') ?></button>
        </div>
        <div class="modal-content">
            <div id="roxyMonModalBody">Memuat...</div>
        </div>
    </div>
</div>

<script>
(function () {
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    document.querySelectorAll('.roxy-mon-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.dataset.id;
            document.getElementById('roxyMonModalTitle').textContent = btn.dataset.medic + ' — ' + btn.dataset.title;
            var body = document.getElementById('roxyMonModalBody');
            body.innerHTML = 'Memuat...';
            document.getElementById('roxyMonModalOverlay').classList.remove('hidden');

            fetch('/ajax/roxy_conversations.php?action=messages&conversation_id=' + id)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.messages.length) {
                        body.innerHTML = '<p class="meta-text">Tidak ada pesan.</p>';
                        return;
                    }
                    var html = '';
                    data.messages.forEach(function (m) {
                        html += '<div class="roxy-mon-modal-msg ' + (m.sender === 'user' ? 'user' : 'bot') + '">' +
                            '<div class="label">' + (m.sender === 'user' ? 'Medis' : 'Roxy') + ' &middot; ' + escapeHtml(m.created_at || '') + '</div>' +
                            '<div class="bubble">' + escapeHtml(m.content) + '</div>' +
                            '</div>';
                    });
                    body.innerHTML = html;
                })
                .catch(function () {
                    body.innerHTML = '<p class="meta-text">Gagal memuat pesan.</p>';
                });
        });
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
