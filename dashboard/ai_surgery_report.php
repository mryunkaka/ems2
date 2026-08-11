<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_surgery_planner.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'Rencana Operasi | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$canDelete = function_exists('ems_is_manager_plus_role') ? ems_is_manager_plus_role($user['role'] ?? '') : false;

$planId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_errors'] = ['Sesi kedaluwarsa, silakan coba lagi.'];
        header('Location: ai_surgery_report.php?id=' . $planId);
        exit;
    }
    if (!$canDelete) {
        $_SESSION['flash_errors'] = ['Anda tidak memiliki akses untuk menghapus rencana operasi ini.'];
        header('Location: ai_surgery_report.php?id=' . $planId);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM ai_surgery_plans WHERE id = ? AND unit_code = ?");
    $del->execute([$planId, $effectiveUnit]);
    $_SESSION['flash_messages'] = ['Rencana operasi berhasil dihapus.'];
    header('Location: ai_surgery_planner.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.*, u.full_name AS created_by_name
    FROM ai_surgery_plans p
    LEFT JOIN user_rh u ON u.id = p.user_id
    WHERE p.id = ? AND p.unit_code = ?
");
$stmt->execute([$planId, $effectiveUnit]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    $_SESSION['flash_errors'] = ['Rencana operasi tidak ditemukan.'];
    header('Location: ai_surgery_planner.php');
    exit;
}

$result = [];
if ($plan['status'] === 'done' && $plan['result_json']) {
    $decoded = json_decode((string) $plan['result_json'], true);
    if (is_array($decoded)) {
        $result = $decoded;
    }
}

$pharmaSections = [
    'pra_operatif' => 'Pra-Operatif',
    'intra_operatif' => 'Intra-Operatif',
    'post_operatif' => 'Post-Operatif',
    'pemulangan' => 'Pemulangan (Discharge)',
];
$farmakologi = is_array($result['farmakologi'] ?? null) ? $result['farmakologi'] : [];

$roleBadgeClass = static function (string $role): string {
    return match ($role) {
        'DPJP' => 'inline-flex items-center rounded-full border border-blue-300 bg-blue-50 text-blue-800 px-3 py-1 text-xs font-bold',
        'Asisten 2' => 'inline-flex items-center rounded-full border border-amber-300 bg-amber-50 text-amber-800 px-3 py-1 text-xs font-bold',
        default => 'inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 text-emerald-800 px-3 py-1 text-xs font-bold',
    };
};
$stepLine = static function (array $step): string {
    $instruksi = trim((string) ($step['instruksi'] ?? ''));
    return trim(
        ($instruksi !== '' ? $instruksi . "\n" : '')
        . '/e ' . ($step['animasi'] ?? 'mechanic') . "\n"
        . '/me ' . ($step['aksi'] ?? '') . "\n"
        . '/do ' . ($step['hasil'] ?? '')
    );
};
$tahapan = is_array($result['tahapan_prosedur'] ?? null) ? $result['tahapan_prosedur'] : [];
$mantraAllText = implode("\n\n", array_map($stepLine, $tahapan));

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
            <div>
                <h1 class="page-title">Catatan Pra-Operatif & Operatif</h1>
                <p class="page-subtitle">
                    Dibuat <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $plan['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                    oleh <?= htmlspecialchars((string) ($plan['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="ai_surgery_planner.php" class="btn-secondary">
                    <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                    <span>Kembali</span>
                </a>
                <?php if ($canDelete): ?>
                    <form method="POST" action="ai_surgery_report.php?id=<?= (int) $plan['id'] ?>" onsubmit="return confirm('Hapus rencana operasi #<?= (int) $plan['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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

        <?php if ($plan['status'] !== 'done'): ?>
            <div class="alert alert-error">
                Model AI gagal menghasilkan rencana operasi ini: <?= htmlspecialchars((string) ($plan['error_message'] ?? 'Kesalahan tidak diketahui.'), ENT_QUOTES, 'UTF-8') ?>
                Silakan buat ulang rencana operasi dari halaman AI Surgery Planner.
            </div>
        <?php else: ?>

            <div class="flex flex-wrap items-center gap-2 mb-4">
                <div class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                    <span class="text-xs font-bold text-amber-800 tracking-wide">DURASI:</span>
                    <span class="text-sm font-bold text-amber-900"><?= htmlspecialchars((string) ($result['durasi'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="inline-flex items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2">
                    <span class="text-xs font-bold text-violet-800 tracking-wide">KOMPLEKSITAS:</span>
                    <span class="text-sm font-bold text-violet-900"><?= htmlspecialchars((string) $plan['kompleksitas'], ENT_QUOTES, 'UTF-8') ?> (<?= count($tahapan) ?> langkah)</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header">Jenis Operasi</div>
                    <div class="p-4 text-sm"><?= htmlspecialchars((string) $plan['jenis_operasi_kategori'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="card">
                    <div class="card-header">Jenis Anestesi</div>
                    <div class="p-4 text-sm"><?= htmlspecialchars((string) $plan['jenis_anestesi_input'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Kasus Medis / Tindakan</div>
                <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) $plan['kasus_tindakan'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Protokol Farmakologi (Obat-Obatan)</div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                    <?php foreach ($pharmaSections as $key => $label): ?>
                        <div class="rounded-lg border border-slate-200">
                            <div class="px-3 py-2 border-b border-slate-200 bg-slate-50">
                                <h4 class="text-xs font-bold tracking-wide"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h4>
                            </div>
                            <div class="p-3">
                                <ul class="space-y-3 text-sm">
                                    <?php foreach ((array) ($farmakologi[$key] ?? []) as $obat): ?>
                                        <li>
                                            <div class="font-bold">
                                                <?= htmlspecialchars((string) ($obat['nama'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                <span class="ml-1 rounded-md bg-slate-50 border border-slate-200 px-1.5 py-0.5 text-[11px] font-bold text-slate-600"><?= htmlspecialchars((string) ($obat['dosis'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars((string) ($obat['catatan'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header flex items-center justify-between gap-3 flex-wrap">
                    <span>Tahapan Prosedur Bedah</span>
                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars($mantraAllText, ENT_QUOTES, 'UTF-8') ?>">
                        <?= ems_icon('clipboard-document-check', 'h-4 w-4') ?>
                        <span>Salin Semua Mantra</span>
                    </button>
                </div>
                <div class="p-4 space-y-4">
                    <?php foreach ($tahapan as $i => $step): ?>
                        <?php
                            $pelaku = (string) ($step['pelaku'] ?? 'DPJP');
                            $instruksi = (string) ($step['instruksi'] ?? '');
                            $aksi = (string) ($step['aksi'] ?? '');
                            $hasil = (string) ($step['hasil'] ?? '');
                            $anim = (string) ($step['animasi'] ?? 'mechanic');
                        ?>
                        <div class="rounded-lg border border-slate-200 p-4">
                            <div class="flex items-center gap-3 mb-3 flex-wrap">
                                <div class="w-7 h-7 shrink-0 rounded-full bg-slate-100 flex items-center justify-center font-bold text-sm"><?= (int) $i + 1 ?></div>
                                <span class="text-xs font-bold text-slate-400 tracking-wide">LANGKAH <?= (int) $i + 1 ?></span>
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
                    <?php if (empty($tahapan)): ?>
                        <p class="text-sm text-slate-400">Tidak ada tahapan prosedur.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Risiko & Komplikasi Pasca-Operasi</div>
                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach ((array) ($result['risiko_komplikasi'] ?? []) as $risiko): ?>
                        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                            <div class="text-rose-700 font-bold text-sm"><?= htmlspecialchars((string) ($risiko['judul'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <p class="mt-1 text-sm text-rose-700/90"><?= htmlspecialchars((string) ($risiko['deskripsi'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Laporan Medis Pasca-Operasi</div>
                <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) ($result['laporan_pasca_operasi'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

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
