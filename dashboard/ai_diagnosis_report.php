<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_diagnosis_assistant.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'Laporan Diagnosis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$canDelete = function_exists('ems_is_manager_plus_role') ? ems_is_manager_plus_role($user['role'] ?? '') : false;

$reportId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_errors'] = ['Sesi kedaluwarsa, silakan coba lagi.'];
        header('Location: ai_diagnosis_report.php?id=' . $reportId);
        exit;
    }
    if (!$canDelete) {
        $_SESSION['flash_errors'] = ['Anda tidak memiliki akses untuk menghapus laporan ini.'];
        header('Location: ai_diagnosis_report.php?id=' . $reportId);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM ai_diagnosis_reports WHERE id = ? AND unit_code = ?");
    $del->execute([$reportId, $effectiveUnit]);
    $_SESSION['flash_messages'] = ['Laporan diagnosis berhasil dihapus.'];
    header('Location: ai_diagnosis_assistant.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.*, u.full_name AS created_by_name
    FROM ai_diagnosis_reports r
    LEFT JOIN user_rh u ON u.id = r.user_id
    WHERE r.id = ? AND r.unit_code = ?
");
$stmt->execute([$reportId, $effectiveUnit]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['flash_errors'] = ['Laporan diagnosis tidak ditemukan.'];
    header('Location: ai_diagnosis_assistant.php');
    exit;
}

$result = [];
if ($report['status'] === 'done' && $report['result_json']) {
    $decoded = json_decode((string) $report['result_json'], true);
    if (is_array($decoded)) {
        $result = $decoded;
    }
}

$roleBadgeClass = static function (string $role): string {
    return match ($role) {
        'DPJP' => 'inline-flex items-center rounded-full border border-blue-300 bg-blue-50 text-blue-800 px-3 py-1 text-xs font-bold',
        'Asisten 2' => 'inline-flex items-center rounded-full border border-amber-300 bg-amber-50 text-amber-800 px-3 py-1 text-xs font-bold',
        default => 'inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 text-emerald-800 px-3 py-1 text-xs font-bold',
    };
};
$emergencyLine = static function (array $action): string {
    $instruksi = trim((string) ($action['instruksi'] ?? ''));
    return trim(
        ($instruksi !== '' ? $instruksi . "\n" : '')
        . '/e ' . ($action['animasi'] ?? 'mechanic') . "\n"
        . '/me ' . ($action['aksi'] ?? '') . "\n"
        . '/do ' . ($action['hasil'] ?? '')
    );
};
$emergencyItems = is_array($result['emergency'] ?? null) ? $result['emergency'] : [];
$mantraAllText = implode("\n\n", array_map($emergencyLine, $emergencyItems));

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
            <div>
                <h1 class="page-title">Laporan Diagnosis IGD</h1>
                <p class="page-subtitle">
                    Dibuat <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $report['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                    oleh <?= htmlspecialchars((string) ($report['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="ai_diagnosis_assistant.php" class="btn-secondary">
                    <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                    <span>Kembali</span>
                </a>
                <?php if ($canDelete): ?>
                    <form method="POST" action="ai_diagnosis_report.php?id=<?= (int) $report['id'] ?>" onsubmit="return confirm('Hapus laporan diagnosis #<?= (int) $report['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn-danger">
                            <?= ems_icon('trash', 'h-4 w-4') ?>
                            <span>Hapus</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Anamnesis / Temuan Medis</div>
            <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) $report['anamnesis'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <?php if ($report['status'] !== 'done'): ?>
            <div class="alert alert-error">
                Model AI gagal menghasilkan laporan ini: <?= htmlspecialchars((string) ($report['error_message'] ?? 'Kesalahan tidak diketahui.'), ENT_QUOTES, 'UTF-8') ?>
                Silakan buat ulang diagnosis dari halaman AI Diagnosis Assistant.
            </div>
        <?php else: ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header">Diagnosis Utama & Banding</div>
                    <div class="p-4">
                        <div class="text-xs font-bold text-slate-500 tracking-wide">DIAGNOSIS UTAMA</div>
                        <p class="text-lg font-bold mt-1"><?= htmlspecialchars((string) ($result['diagnosis_utama'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="text-xs font-bold text-slate-500 tracking-wide mt-4">DIAGNOSIS BANDING</div>
                        <ul class="list-disc pl-5 mt-1 text-sm space-y-1">
                            <?php foreach ((array) ($result['diagnosis_banding'] ?? []) as $item): ?>
                                <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">GCS Score</div>
                    <div class="p-4 flex items-center justify-center">
                        <div class="text-3xl font-bold font-mono"><?= htmlspecialchars((string) ($result['gcs'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Kasus Medis / Tindakan yang Diperlukan</div>
                <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) ($result['kasus_tindakan'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header">Jenis Operasi</div>
                    <div class="p-4 text-sm"><?= htmlspecialchars((string) ($result['jenis_operasi'] ?? 'Belum ditentukan'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="card">
                    <div class="card-header">Jenis Anestesi</div>
                    <div class="p-4 text-sm"><?= htmlspecialchars((string) ($result['jenis_anestesi'] ?? 'Belum ditentukan'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Tanda-Tanda Vital (TTV)</div>
                <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php foreach ((array) ($result['ttv'] ?? []) as $vital): ?>
                        <div class="rounded-lg border border-slate-200 p-3">
                            <div class="text-xs text-slate-500 font-semibold"><?= htmlspecialchars((string) ($vital['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-lg font-bold mt-1"><?= htmlspecialchars((string) ($vital['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) ($vital['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header">Rekomendasi Laboratorium</div>
                    <ul class="list-disc pl-5 p-4 text-sm space-y-1">
                        <?php foreach ((array) ($result['lab'] ?? []) as $item): ?>
                            <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="card">
                    <div class="card-header">Rekomendasi Radiologi</div>
                    <ul class="list-disc pl-5 p-4 text-sm space-y-1">
                        <?php foreach ((array) ($result['radiologi'] ?? []) as $item): ?>
                            <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header flex items-center justify-between gap-3 flex-wrap">
                    <span>Penanganan Emergency (BLS &amp; Initial Care)</span>
                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars($mantraAllText, ENT_QUOTES, 'UTF-8') ?>">
                        <?= ems_icon('clipboard-document-check', 'h-4 w-4') ?>
                        <span>Salin Semua Mantra</span>
                    </button>
                </div>
                <div class="p-4 space-y-4">
                    <?php foreach ($emergencyItems as $i => $action): ?>
                        <?php
                            $pelaku = (string) ($action['pelaku'] ?? 'DPJP');
                            $instruksi = (string) ($action['instruksi'] ?? '');
                            $aksi = (string) ($action['aksi'] ?? '');
                            $hasil = (string) ($action['hasil'] ?? '');
                            $anim = (string) ($action['animasi'] ?? 'mechanic');
                        ?>
                        <div class="rounded-lg border border-slate-200 p-4">
                            <div class="flex items-center gap-3 mb-3 flex-wrap">
                                <div class="w-7 h-7 shrink-0 rounded-full bg-slate-100 flex items-center justify-center font-bold text-sm"><?= (int) $i + 1 ?></div>
                                <span class="text-xs font-bold text-slate-400 tracking-wide">TINDAKAN <?= (int) $i + 1 ?></span>
                                <span class="<?= $roleBadgeClass($pelaku) ?>"><?= htmlspecialchars($pelaku, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ($instruksi !== ''): ?>
                                <div class="mb-3 md:ml-10 rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs italic text-slate-600 whitespace-pre-line"><?= htmlspecialchars($instruksi, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div class="space-y-2 text-sm md:ml-10">
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                    <div><span class="font-bold">/e</span> <?= htmlspecialchars($anim, ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars('/e ' . $anim, ENT_QUOTES, 'UTF-8') ?>">Salin</button>
                                </div>
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                    <div><span class="font-bold">/me</span> <?= htmlspecialchars($aksi, ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars('/me ' . $aksi, ENT_QUOTES, 'UTF-8') ?>">Salin</button>
                                </div>
                                <div class="flex items-center justify-between gap-3 rounded-lg border-l-4 border-emerald-500 bg-emerald-50 px-3 py-2">
                                    <div><span class="font-bold">/do</span> <?= htmlspecialchars($hasil, ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars('/do ' . $hasil, ENT_QUOTES, 'UTF-8') ?>">Salin</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($emergencyItems)): ?>
                        <p class="text-sm text-slate-400">Tidak ada langkah penanganan.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Catatan Medis &amp; Roleplay</div>
                <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) ($result['roleplay_note'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <details class="card p-4">
                <summary class="cursor-pointer font-bold">Rujukan SOP</summary>
                <ul class="list-disc pl-5 mt-3 text-sm space-y-1">
                    <?php foreach ((array) ($result['sop_references'] ?? []) as $ref): ?>
                        <li><?= htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>

        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.mantra-copy-btn');
    if (!btn) return;

    var text = btn.getAttribute('data-copy') || '';
    var original = btn.innerHTML;

    function showResult(ok) {
        btn.textContent = ok ? 'Tersalin' : 'Gagal menyalin';
        setTimeout(function () { btn.innerHTML = original; }, 1500);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { showResult(true); }).catch(function () { showResult(false); });
        return;
    }

    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        showResult(true);
    } catch (err) {
        showResult(false);
    }
    document.body.removeChild(ta);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
