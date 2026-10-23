<?php
$queueUserName = trim((string)($_SESSION['user_rh']['full_name'] ?? $_SESSION['user_rh']['name'] ?? 'Pengguna'));
$queueFeature = (string)($queueFeature ?? 'Permintaan AI');
?>
<main class="ai-queue-card" data-ai-queue data-job-id="<?= (int)$queueJobId ?>" data-status-url="<?= htmlspecialchars(ems_url('/ajax/ai_queue_status.php?job_id=' . (int)$queueJobId), ENT_QUOTES, 'UTF-8') ?>" data-next-url="<?= htmlspecialchars((string)$queueNextUrl, ENT_QUOTES, 'UTF-8') ?>">
    <div class="ai-queue-mark" aria-hidden="true">✦</div>
    <p class="ai-queue-kicker">Antrean AI Roxwood</p>
    <h1>Permintaan Anda sedang diproses</h1>
    <p class="ai-queue-summary"><strong><?= htmlspecialchars($queueFeature, ENT_QUOTES, 'UTF-8') ?></strong> untuk <?= htmlspecialchars($queueUserName, ENT_QUOTES, 'UTF-8') ?></p>
    <div class="ai-queue-progress"><span></span></div>
    <p class="ai-queue-status" data-queue-status aria-live="polite">Menghubungkan ke antrean…</p>
    <p class="ai-queue-position" data-queue-position></p>
    <section class="ai-queue-active" aria-label="Aktivitas AI saat ini">
        <h2>Penggunaan AI saat ini</h2><ul data-queue-active><li>Memuat aktivitas…</li></ul>
    </section>
    <pre class="ai-queue-result" data-queue-result hidden></pre>
    <a class="ai-queue-continue" data-queue-continue hidden href="<?= htmlspecialchars((string)$queueNextUrl, ENT_QUOTES, 'UTF-8') ?>">Lanjut ke halaman</a>
    <p class="ai-queue-footnote">Halaman ini akan memperbarui status secara otomatis. Anda dapat membiarkannya terbuka.</p>
</main>
<script src="<?= htmlspecialchars(ems_url('/assets/js/ai_queue.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
