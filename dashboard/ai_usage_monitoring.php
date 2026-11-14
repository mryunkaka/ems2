<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/ai_guard.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_programmer_roxwood_access();

$pageTitle = 'Monitoring Pemakaian AI';
$logs = [];
$summary = ['request_count' => 0, 'total_tokens' => 0];
$latest = null;
$loadError = false;
$hasUsageEstimatedColumn = false;
$queueMonitor = [];
try { if (ems_table_exists($pdo, 'system_ai_request_queue')) $queueMonitor = ems_ai_queue_monitor($pdo); } catch (Throwable) { $queueMonitor = []; }

function aiUsagePurposeLabel(string $featureKey): string
{
    $key = strtolower(trim($featureKey));
    $known = [
        'roxy_chat' => 'Chat Roxy',
        'roxy_chat_deep_research' => 'Riset mendalam Roxy',
        'work_anniversary_greeting' => 'Ucapan masa kerja',
        'work_anniversary_image' => 'Gambar ucapan masa kerja',
        'training_group_identity_generator' => 'Pembuatan identitas kelompok training',
        'interview_question_pack' => 'Pembuatan pertanyaan interview',
        'clinical_medical_record_validation' => 'Audit rekam medis',
        'clinical_medical_record_validation_dummy' => 'Uji dummy audit rekam medis',
    ];
    if (isset($known[$key])) {
        return $known[$key];
    }
    if (str_starts_with($key, 'candidate_summary_')) {
        return str_contains($key, 'assistant_manager') ? 'Ringkasan kandidat assistant manager' : 'Ringkasan kandidat medis';
    }
    if (str_contains($key, 'assessment_audit')) {
        return str_contains($key, 'assistant_manager') ? 'Audit assessment assistant manager' : 'Audit assessment kandidat medis';
    }
    if (str_contains($key, 'interview')) {
        return 'Keperluan interview kandidat';
    }

    $label = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $key)) ?: 'Penggunaan AI';
    return ucwords($label);
}

function aiUsageFormatDate(?string $value): string
{
    if (!$value) {
        return '-';
    }
    try {
        $formatted = (new DateTime($value))->format('d/m/Y H:i:s');
    } catch (Throwable) {
        $formatted = $value;
    }
    return htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');
}

function aiUsageFormatTokens($value, bool $estimated = false): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return ($estimated ? '&asymp;' : '') . number_format((int)$value, 0, ',', '.');
}

function aiUsageExtractText($value): string
{
    if (is_string($value)) {
        return $value;
    }
    if (!is_array($value)) {
        return '';
    }
    $text = '';
    foreach (['content', 'text', 'parts', 'messages', 'message', 'choices', 'delta'] as $key) {
        if (array_key_exists($key, $value)) {
            $text .= aiUsageExtractText($value[$key]);
        }
    }
    if (array_is_list($value)) {
        foreach ($value as $item) {
            $text .= aiUsageExtractText($item);
        }
    }
    return $text;
}

function aiUsageExtractPayload(array $payload): array
{
    $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
    $promptText = aiUsageExtractText($payload['messages'] ?? []);
    $responseText = '';
    if (isset($payload['choices']) && is_array($payload['choices'])) {
        foreach ($payload['choices'] as $choice) {
            if (is_array($choice)) {
                $responseText .= aiUsageExtractText($choice['message']['content'] ?? $choice['delta']['content'] ?? '');
            }
        }
    }
    return [$usage, $promptText, $responseText];
}

function aiUsageReadPayload(?string $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [[], '', ''];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cleanBody = preg_replace('/\s*data:\s*\[DONE\]\s*$/', '', trim($raw)) ?? $raw;
        $decoded = json_decode($cleanBody, true);
    }
    if (is_array($decoded)) {
        return aiUsageExtractPayload($decoded);
    }

    $usage = [];
    $responseText = '';
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'data:')) {
            continue;
        }
        $event = json_decode(trim(substr($line, 5)), true);
        if (is_array($event)) {
            if (is_array($event['usage'] ?? null)) {
                $usage = $event['usage'];
            }
            [, , $eventText] = aiUsageExtractPayload($event);
            $responseText .= $eventText;
        }
    }
    return [$usage, '', $responseText];
}

function aiUsageTokenValue(array $usage, array $keys): ?int
{
    foreach ($keys as $key) {
        if (isset($usage[$key]) && is_numeric($usage[$key])) {
            return max(0, (int)$usage[$key]);
        }
    }
    return null;
}

function aiUsageEstimateTokens(string $text): ?int
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    $characterCount = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if (!function_exists('mb_strlen') && preg_match_all('/./us', $text, $characters) !== false) {
        $characterCount = count($characters[0]);
    }
    return max(1, (int)ceil($characterCount / 4));
}

function aiUsageResolveLogTokens(array &$log, bool $hasUsageEstimatedColumn): void
{
    $promptTokens = is_numeric($log['prompt_tokens'] ?? null) ? (int)$log['prompt_tokens'] : null;
    $responseTokens = is_numeric($log['response_tokens'] ?? null) ? (int)$log['response_tokens'] : null;
    $totalTokens = is_numeric($log['total_tokens'] ?? null) ? (int)$log['total_tokens'] : null;
    $inputEstimated = $hasUsageEstimatedColumn && !empty($log['usage_estimated']);
    $outputEstimated = $inputEstimated;

    [$usage, $requestText] = aiUsageReadPayload(isset($log['request_payload']) ? (string)$log['request_payload'] : null);
    [$responseUsage, $responseRequestText, $responseText] = aiUsageReadPayload(isset($log['response_payload']) ? (string)$log['response_payload'] : null);
    if ($usage === []) {
        $usage = $responseUsage;
    }
    if ($requestText === '') {
        $requestText = $responseRequestText;
    }
    $providerUsageAvailable = $usage !== [];
    if (!$hasUsageEstimatedColumn && !$providerUsageAvailable && preg_match('/9router|custom|compatible/i', (string)($log['provider'] ?? '')) === 1) {
        $inputEstimated = $inputEstimated || $promptTokens !== null;
        $outputEstimated = $outputEstimated || $responseTokens !== null;
    }

    if ($promptTokens === null || $responseTokens === null) {
        $promptTokens ??= aiUsageTokenValue($usage, ['prompt_tokens', 'input_tokens', 'promptTokenCount', 'inputTokenCount']);
        $responseTokens ??= aiUsageTokenValue($usage, ['completion_tokens', 'output_tokens', 'candidatesTokenCount', 'completionTokenCount']);
        $totalTokens ??= aiUsageTokenValue($usage, ['total_tokens', 'totalTokenCount']);

        if ($promptTokens === null) {
            $promptTokens = aiUsageEstimateTokens($requestText);
            $inputEstimated = $promptTokens !== null;
        }
        if ($responseTokens === null) {
            $responseTokens = aiUsageEstimateTokens($responseText);
            $rawResponse = (string)($log['response_payload'] ?? '');
            $looksLikeCompletion = preg_match('/"choices"\s*:/i', $rawResponse) === 1
                || preg_match('/^data:\s*\{"choices"/mi', $rawResponse) === 1;
            if ($responseTokens === null && $looksLikeCompletion && !str_contains(strtolower($rawResponse), '"error"')) {
                $responseTokens = aiUsageEstimateTokens($rawResponse);
            }
            $outputEstimated = $responseTokens !== null;
        }
    }

    if ($totalTokens === null && $promptTokens !== null && $responseTokens !== null) {
        $totalTokens = $promptTokens + $responseTokens;
        $log['total_tokens_estimated'] = $inputEstimated || $outputEstimated || ($hasUsageEstimatedColumn && !empty($log['usage_estimated']));
    } else {
        $log['total_tokens_estimated'] = $inputEstimated || $outputEstimated || ($hasUsageEstimatedColumn && !empty($log['usage_estimated']));
    }
    $log['prompt_tokens'] = $promptTokens;
    $log['response_tokens'] = $responseTokens;
    $log['total_tokens'] = $totalTokens;
    $log['prompt_tokens_estimated'] = $inputEstimated;
    $log['response_tokens_estimated'] = $outputEstimated;
}

try {
    if (ems_ai_request_logs_table_exists($pdo)) {
        $hasUsageEstimatedColumn = ems_column_exists($pdo, 'system_ai_request_logs', 'usage_estimated');
        $usageEstimatedSelect = $hasUsageEstimatedColumn ? 'l.usage_estimated' : '0 AS usage_estimated';
        $logsStmt = $pdo->query("SELECT l.id, l.feature_key, l.provider, l.model_name, l.prompt_tokens, l.response_tokens, l.total_tokens, {$usageEstimatedSelect}, l.success_flag, l.created_by, l.created_at, l.request_payload, l.response_payload, u.full_name AS user_name
            FROM system_ai_request_logs l
            LEFT JOIN user_rh u ON u.id = l.created_by
            ORDER BY l.created_at DESC, l.id DESC");
        $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary['request_count'] = count($logs);
        $summary['total_tokens'] = 0;
        foreach ($logs as &$log) {
            aiUsageResolveLogTokens($log, $hasUsageEstimatedColumn);
            $summary['total_tokens'] += (int)($log['total_tokens'] ?? 0);
            unset($log['request_payload'], $log['response_payload']);
        }
        unset($log);
        $latest = $logs[0] ?? null;
    }
} catch (Throwable $e) {
    error_log('[AI usage monitoring] ' . $e->getMessage());
    $loadError = true;
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
            <div>
                <h1 class="page-title">Monitoring Pemakaian AI</h1>
                <p class="page-subtitle">Riwayat penggunaan model AI. Tanda ≈ adalah estimasi berbasis teks saat provider tidak mengirim angka token.</p>
            </div>
            <div class="badge-info">Akses: Programmer Roxwood</div>
        </div>

        <?php if ($loadError): ?>
            <div class="alert alert-danger mb-4">Riwayat pemakaian AI gagal dimuat. Silakan muat ulang halaman.</div>
        <?php elseif (!ems_ai_request_logs_table_exists($pdo)): ?>
            <div class="alert alert-warning mb-4">Log AI belum tersedia. Jalankan migrasi `docs/sql/20_2026-04-08_gemini_ai_foundation.sql` terlebih dahulu.</div>
        <?php else: ?>
            <div class="card mb-4" id="aiUsageQueueMonitor" data-status-url="<?= htmlspecialchars(ems_url('/ajax/ai_usage_queue_status.php'), ENT_QUOTES, 'UTF-8') ?>" data-worker-url="<?= htmlspecialchars(ems_url('/ajax/ai_queue_work.php'), ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                    <div>
                        <h2 class="font-semibold flex items-center gap-2"><span id="aiQueueLiveIndicator" class="inline-block w-2.5 h-2.5 rounded-full <?= !empty($queueMonitor['active']) ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300' ?>"></span><span id="aiQueueSpinner" class="<?= !empty($queueMonitor['active']) ? '' : 'hidden ' ?>inline-block h-4 w-4 rounded-full border-2 border-emerald-500 border-t-transparent animate-spin" aria-hidden="true"></span>Proses AI</h2>
                        <p class="text-sm text-slate-500">Pantau pekerjaan yang sedang dikerjakan model AI dan yang masih menunggu.</p>
                    </div>
                    <div class="text-sm text-slate-600" id="aiQueueSummary">Menunggu <strong><?= (int)($queueMonitor['counts']['queued'] ?? 0) ?></strong> · berjalan <strong><?= (int)($queueMonitor['counts']['running'] ?? 0) ?></strong> · gagal <strong><?= (int)($queueMonitor['counts']['failed'] ?? 0) ?></strong></div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3">
                        <div class="font-medium text-emerald-900 mb-2">Sedang dikerjakan</div>
                        <div id="aiQueueRunningJobs" class="space-y-2">
                            <?php if (!empty($queueMonitor['active'])): foreach ($queueMonitor['active'] as $job): ?>
                                <div class="rounded-lg bg-white border border-emerald-100 p-3"><div class="font-medium text-slate-800"><?= htmlspecialchars((string)($job['task_detail'] ?? $job['feature_label']), ENT_QUOTES, 'UTF-8') ?></div><div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars((string)$job['feature_label'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($job['requester_name'] ?: 'Pengguna'), ENT_QUOTES, 'UTF-8') ?> · Mulai <?= aiUsageFormatDate((string)$job['started_at']) ?></div></div>
                            <?php endforeach; else: ?><p class="text-sm text-slate-500">Tidak ada pekerjaan yang sedang berjalan.</p><?php endif; ?>
                        </div>
                    </div>
                    <div class="rounded-xl border border-sky-200 bg-sky-50/60 p-3">
                        <div class="font-medium text-sky-900 mb-2">Menunggu giliran</div>
                        <div id="aiQueueWaitingJobs" class="space-y-2">
                            <?php if (!empty($queueMonitor['queued'])): foreach ($queueMonitor['queued'] as $job): ?>
                                <div class="rounded-lg bg-white border border-sky-100 p-3"><div class="font-medium text-slate-800"><?= htmlspecialchars((string)($job['task_detail'] ?? $job['feature_label']), ENT_QUOTES, 'UTF-8') ?></div><div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars((string)$job['feature_label'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($job['requester_name'] ?: 'Pengguna'), ENT_QUOTES, 'UTF-8') ?> · Masuk <?= aiUsageFormatDate((string)$job['queued_at']) ?></div></div>
                            <?php endforeach; else: ?><p class="text-sm text-slate-500">Tidak ada pekerjaan yang menunggu.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <p id="aiQueueLastUpdated" class="text-xs text-slate-400 mt-3">Status diperbarui otomatis setiap 5 detik. Halaman ini akan membangunkan worker saat ada pekerjaan menunggu.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-3 mb-4">
                <div class="card mb-0">
                    <div class="text-sm text-slate-500">Total pemanggilan model</div>
                    <div class="text-2xl font-semibold mt-1"><?= number_format((int)$summary['request_count'], 0, ',', '.') ?></div>
                </div>
                <div class="card mb-0">
                    <div class="text-sm text-slate-500">Total token (data API + estimasi)</div>
                    <div class="text-2xl font-semibold mt-1"><?= aiUsageFormatTokens($summary['total_tokens']) ?></div>
                </div>
                <div class="card mb-0">
                    <div class="text-sm text-slate-500">Pemakaian terakhir</div>
                    <?php if ($latest): ?>
                        <div class="font-semibold mt-1"><?= htmlspecialchars((string)($latest['user_name'] ?: ('User #' . (int)$latest['created_by'])), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-sm mt-1"><?= htmlspecialchars((string)$latest['model_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(aiUsagePurposeLabel((string)$latest['feature_key']), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-xs text-slate-500 mt-1"><?= aiUsageFormatDate((string)$latest['created_at']) ?></div>
                    <?php else: ?>
                        <div class="text-sm mt-1">Belum ada pemakaian AI.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                    <div>
                        <h2 class="font-semibold">Riwayat lengkap</h2>
                        <p class="text-sm text-slate-500">Urutkan tanggal terbaru, cari pengguna/model, atau pilih jumlah baris yang ingin dilihat.</p>
                    </div>
                </div>
                <?php if ($logs === []): ?>
                    <p class="text-sm text-slate-500">Belum ada riwayat penggunaan model AI.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table id="aiUsageTable" class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left border-b">
                                    <th class="px-3 py-3">Tanggal &amp; jam</th>
                                    <th class="px-3 py-3">Pengguna</th>
                                    <th class="px-3 py-3">Model</th>
                                    <th class="px-3 py-3">Dipakai untuk</th>
                                    <th class="px-3 py-3 text-right">Input</th>
                                    <th class="px-3 py-3 text-right">Output</th>
                                    <th class="px-3 py-3 text-right">Total token</th>
                                    <th class="px-3 py-3">Status / sumber token</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="border-b last:border-0">
                                        <?php $dateSortKey = (string)$log['created_at'] . '-' . str_pad((string)$log['id'], 20, '0', STR_PAD_LEFT); ?>
                                        <td class="px-3 py-3 whitespace-nowrap" data-order="<?= htmlspecialchars($dateSortKey, ENT_QUOTES, 'UTF-8') ?>"><?= aiUsageFormatDate((string)$log['created_at']) ?></td>
                                        <td class="px-3 py-3"><?= htmlspecialchars((string)($log['user_name'] ?: ('User #' . (int)$log['created_by'])), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-3 py-3"><div class="font-medium"><?= htmlspecialchars((string)$log['model_name'], ENT_QUOTES, 'UTF-8') ?></div><div class="text-xs text-slate-500"><?= htmlspecialchars((string)$log['provider'], ENT_QUOTES, 'UTF-8') ?></div></td>
                                        <td class="px-3 py-3"><?= htmlspecialchars(aiUsagePurposeLabel((string)$log['feature_key']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-3 py-3 text-right" title="<?= !empty($log['prompt_tokens_estimated']) ? 'Estimasi lokal karena provider tidak mengirim jumlah token input.' : '' ?>"><?= aiUsageFormatTokens($log['prompt_tokens'], !empty($log['prompt_tokens_estimated'])) ?></td>
                                        <td class="px-3 py-3 text-right" title="<?= !empty($log['response_tokens_estimated']) ? 'Estimasi lokal karena provider tidak mengirim jumlah token output.' : '' ?>"><?= aiUsageFormatTokens($log['response_tokens'], !empty($log['response_tokens_estimated'])) ?></td>
                                        <td class="px-3 py-3 text-right font-medium" title="<?= !empty($log['total_tokens_estimated']) ? 'Total mengandung estimasi.' : '' ?>"><?= aiUsageFormatTokens($log['total_tokens'], !empty($log['total_tokens_estimated'])) ?></td>
                                        <td class="px-3 py-3"><div><?= (int)$log['success_flag'] === 1 ? 'Berhasil' : 'Gagal' ?></div><?php if (!empty($log['prompt_tokens_estimated']) || !empty($log['response_tokens_estimated'])): ?><div class="text-xs text-slate-500">Token estimasi</div><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const queueCard = document.getElementById('aiUsageQueueMonitor');
    if (queueCard) {
        const summary = document.getElementById('aiQueueSummary');
        const runningList = document.getElementById('aiQueueRunningJobs');
        const waitingList = document.getElementById('aiQueueWaitingJobs');
        const liveIndicator = document.getElementById('aiQueueLiveIndicator');
        const spinner = document.getElementById('aiQueueSpinner');
        const updated = document.getElementById('aiQueueLastUpdated');
        let workerRequestActive = false;
        let queueRefreshActive = false;
        const wakeWorker = async function () {
            if (workerRequestActive) return;
            workerRequestActive = true;
            try {
                const response = await fetch(queueCard.dataset.workerUrl, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json', 'X-CSRF-Token': queueCard.dataset.csrf || '' }
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'Worker tidak dapat berjalan.');
            } catch (error) {
                updated.textContent = (error.message || 'Worker belum dapat dijalankan.') + ' Periksa koneksi atau cron worker.';
            } finally {
                workerRequestActive = false;
            }
        };
        const renderJobs = function (target, jobs, status) {
            target.replaceChildren();
            if (!jobs.length) {
                const empty = document.createElement('p');
                empty.className = 'text-sm text-slate-500';
                empty.textContent = status === 'running' ? 'Tidak ada pekerjaan yang sedang berjalan.' : 'Tidak ada pekerjaan yang menunggu.';
                target.append(empty);
                return;
            }
            jobs.forEach(function (job) {
                const item = document.createElement('div');
                item.className = 'rounded-lg bg-white border p-3 ' + (status === 'running' ? 'border-emerald-100' : 'border-sky-100');
                const title = document.createElement('div');
                title.className = 'font-medium text-slate-800';
                title.textContent = job.task_detail || job.feature_label || 'Pekerjaan AI';
                const detail = document.createElement('div');
                detail.className = 'text-xs text-slate-500 mt-1';
                const time = status === 'running' ? job.started_at : job.queued_at;
                detail.textContent = (job.feature_label || 'Proses AI') + ' · ' + (job.requester_name || 'Pengguna') + ' · ' + (status === 'running' ? 'Mulai ' : 'Masuk ') + (time || '-');
                item.append(title, detail);
                target.append(item);
            });
        };
        const refreshQueue = async function () {
            if (queueRefreshActive) return;
            queueRefreshActive = true;
            try {
                const response = await fetch(queueCard.dataset.statusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || !data.ok || !data.available) return;
                const counts = data.counts || {};
                summary.innerHTML = 'Menunggu <strong>' + Number(counts.queued || 0) + '</strong> · berjalan <strong>' + Number(counts.running || 0) + '</strong> · gagal <strong>' + Number(counts.failed || 0) + '</strong>';
                renderJobs(runningList, data.active || [], 'running');
                renderJobs(waitingList, data.queued || [], 'queued');
                liveIndicator.className = 'inline-block w-2.5 h-2.5 rounded-full ' + (Number(counts.running || 0) ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300');
                spinner.classList.toggle('hidden', Number(counts.running || 0) === 0);
                updated.textContent = 'Terakhir diperbarui ' + new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date()) + ' · pembaruan otomatis setiap 5 detik.';
                if (Number(counts.queued || 0) > 0 && Number(counts.running || 0) < Number(data.max_concurrent || 1)) wakeWorker();
            } catch (error) {
                updated.textContent = 'Status antrean belum dapat diperbarui. Mencoba kembali otomatis.';
            } finally {
                queueRefreshActive = false;
            }
        };
        refreshQueue();
        window.setInterval(refreshQueue, 5000);
    }
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable || !document.getElementById('aiUsageTable')) return;
    jQuery('#aiUsageTable').DataTable({
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, 'desc']],
        autoWidth: false,
        scrollX: true,
        language: {
            url: '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
        }
    });
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
