<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Pengajuan Kenaikan Jabatan';

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, full_name, position, batch, tanggal_masuk
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$fullName = (string)($userDb['full_name'] ?? '');
$position = ems_normalize_position($userDb['position'] ?? '');
$batch = (int)($userDb['batch'] ?? 0);
$joinDateRaw = $userDb['tanggal_masuk'] ?? null;
$joinDate = $joinDateRaw ? (new DateTime($joinDateRaw)) : null;

$comingSoon = ($position === 'general_practitioner');

$expectedTo = ems_next_position($position);
$toPosition = $expectedTo;

// Detect misconfigured requirements (e.g. trainee -> co_asst) and ignore them for UI flow.
$misconfiguredTargets = [];
if ($expectedTo !== '') {
    $stmt = $pdo->prepare("
        SELECT to_position
        FROM position_promotion_requirements
        WHERE from_position = ?
          AND is_active = 1
    ");
    $stmt->execute([$position]);
    $targets = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($targets as $t) {
        $norm = ems_normalize_position((string)$t);
        if ($norm !== '' && $norm !== $expectedTo) {
            $misconfiguredTargets[] = $norm;
        }
    }
}

$req = null;
if ($toPosition !== '') {
    $stmt = $pdo->prepare("
        SELECT from_position, to_position, min_days_since_join, min_operations, notes
        FROM position_promotion_requirements
        WHERE from_position = ?
          AND to_position = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$position, $toPosition]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$reqMissing = ($toPosition !== '' && !$req);
$minDays = isset($req['min_days_since_join']) ? (int)$req['min_days_since_join'] : null;
$minOps = isset($req['min_operations']) ? (int)$req['min_operations'] : null;
$notes = (string)($req['notes'] ?? '');
if ($reqMissing) {
    $notes = 'Syarat untuk jalur ini belum diatur oleh manager. Silakan hubungi manager.';
}

$pending = null;
if ($toPosition !== '') {
    $stmt = $pdo->prepare("
        SELECT id, status, submitted_at
        FROM position_promotion_requests
        WHERE user_id = ?
          AND from_position = ?
          AND to_position = ?
          AND status = 'pending'
        ORDER BY submitted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $position, $toPosition]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.from_position,
        r.to_position,
        r.status,
        r.submitted_at,
        r.reviewed_at,
        rb.full_name AS reviewed_by_name,
        r.reviewer_note
    FROM position_promotion_requests
    r
    LEFT JOIN user_rh rb ON rb.id = r.reviewed_by
    WHERE r.user_id = ?
    ORDER BY r.submitted_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$daysSinceJoin = null;
$eligibleJoin = true;
if ($minDays !== null) {
    if (!$joinDate) {
        $eligibleJoin = false;
    } else {
        $daysSinceJoin = (int)$joinDate->diff(new DateTime('today'))->days;
        $eligibleJoin = ($daysSinceJoin >= $minDays);
    }
}

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$m) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars((string)$w) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$e) ?></div>
        <?php endforeach; ?>

        <div class="card card-section">
            <div class="card-header">Data Medis</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div><strong>Nama</strong><div><?= htmlspecialchars($fullName) ?></div></div>
                <div><strong>Jabatan Saat Ini</strong><div><?= htmlspecialchars(ems_position_label($position)) ?></div></div>
                <div><strong>Batch</strong><div><?= $batch > 0 ? (int)$batch : '-' ?></div></div>
                <div><strong>Tanggal Masuk</strong><div><?= $joinDate ? htmlspecialchars($joinDate->format('Y-m-d')) : '-' ?></div></div>
            </div>
            <?php if (!empty($_GET['debug'])): ?>
                <div class="mt-3 text-xs text-slate-600">
                    Debug: raw_position=<?= htmlspecialchars((string)($userDb['position'] ?? '')) ?>,
                    normalized=<?= htmlspecialchars($position) ?>,
                    expected_to=<?= htmlspecialchars($expectedTo) ?>,
                    to=<?= htmlspecialchars($toPosition) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($toPosition === ''): ?>
            <div class="card access-card">
                <h3 class="access-title"><?= $comingSoon ? 'Menyusul' : 'Belum ada jalur kenaikan' ?></h3>
                <p class="access-copy">
                    <?= $comingSoon
                        ? 'Pengajuan kenaikan dari Dokter Umum ke Dokter Spesialis menyusul.'
                        : 'Jabatan Anda saat ini belum memiliki jalur pengajuan kenaikan jabatan di sistem.' ?>
                </p>
            </div>
        <?php elseif ($pending): ?>
            <div class="alert alert-info">
                Pengajuan Anda untuk <strong><?= htmlspecialchars(ems_position_label($position)) ?></strong> → <strong><?= htmlspecialchars(ems_position_label($toPosition)) ?></strong>
                masih <strong>pending</strong> (<?= htmlspecialchars($pending['submitted_at'] ?? '') ?>).
            </div>
        <?php else: ?>
            <div class="card card-section">
                <div class="card-header">
                    Pengajuan: <?= htmlspecialchars(ems_position_label($position)) ?> → <?= htmlspecialchars(ems_position_label($toPosition)) ?>
                </div>

                <?php if (!empty($misconfiguredTargets)): ?>
                    <div class="alert alert-error" style="margin-bottom:12px;">
                        <strong>Perhatian</strong><br>
                        Terdeteksi konfigurasi syarat jabatan yang tidak sesuai jalur sistem:
                        <strong><?= htmlspecialchars(ems_position_label($position)) ?></strong> →
                        <strong><?= htmlspecialchars(implode(', ', array_map('ems_position_label', $misconfiguredTargets))) ?></strong>.<br>
                        Silakan manager cek menu <strong>Syarat Jabatan</strong>.
                    </div>
                <?php endif; ?>

                <?php if ($notes !== ''): ?>
                    <div class="alert alert-warning" style="margin-bottom:12px;">
                        <strong>Catatan / Syarat</strong><br>
                        <?= nl2br(htmlspecialchars($notes)) ?>
                    </div>
                <?php endif; ?>

                <?php if ($minDays !== null && !$eligibleJoin): ?>
                    <div class="alert alert-error">
                        Belum memenuhi syarat join minimal <strong><?= (int)$minDays ?></strong> hari.
                        <?php if ($joinDate): ?>
                            Saat ini: <strong><?= (int)$daysSinceJoin ?></strong> hari.
                        <?php else: ?>
                            Tanggal masuk belum terdata.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="pengajuan_jabatan_action.php" class="form" id="promoForm">
                    <?= csrfField(); ?>
                    <input type="hidden" name="to_position" value="<?= htmlspecialchars($toPosition, ENT_QUOTES) ?>">

                    <?php if (in_array($position, ['paramedic', 'co_asst'], true)): ?>
                        <h3 class="section-form-title">Riwayat Operasi yang Pernah Dilakukan</h3>
                        <p class="page-subtitle">
                            Minimal: <strong><?= (int)($minOps ?? 0) ?></strong> entri. Klik tambah jika lebih.
                        </p>

                        <datalist id="dpjpDatalist"></datalist>
                        <div id="opsList" class="space-y-3"></div>

                        <div style="margin-top:12px;">
                            <button type="button" class="btn-secondary" id="btnAddOp">
                                <?= ems_icon('plus', 'h-4 w-4') ?> <span>Tambah Riwayat Operasi</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($position === 'co_asst'): ?>
                        <hr class="section-divider">
                        <h3 class="section-form-title">Laporan Kasus</h3>

                        <label>Judul Kasus <span class="required">*</span></label>
                        <input type="text" name="case_title" required placeholder="Operasi ______">

                        <label>Perihal <span class="required">*</span></label>
                        <textarea name="case_subject" required rows="3"
                            placeholder="Kenaikan Jabatan Dari ______ Ke ______"></textarea>
                    <?php endif; ?>

                    <hr class="section-divider">

                    <?php
                    $submitBlocked = ($reqMissing || ($minDays !== null && !$eligibleJoin));
                    $blockedMsg = null;
                    if ($submitBlocked) {
                        if ($reqMissing) {
                            $blockedMsg = 'Belum bisa ajukan. Syarat jalur kenaikan jabatan ini belum diatur oleh manager.';
                        } elseif ($joinDate) {
                            $blockedMsg = "Belum bisa ajukan. Syarat join minimal {$minDays} hari (saat ini {$daysSinceJoin} hari).";
                        } else {
                            $blockedMsg = "Belum bisa ajukan. Tanggal masuk belum terdata.";
                        }
                    }
                    ?>

	                    <?php if ($submitBlocked): ?>
	                        <div id="promoBlockedAlert" class="alert alert-error hidden" style="margin-bottom:10px;"></div>
	                        <button type="button"
	                            class="btn-success opacity-60 cursor-not-allowed"
	                            data-toast-type="error"
	                            data-toast-message="<?= htmlspecialchars((string)$blockedMsg, ENT_QUOTES) ?>"
	                            onclick="window.emsShowToast && window.emsShowToast(this.getAttribute('data-toast-message') || 'Aksi tidak tersedia.', this.getAttribute('data-toast-type') || 'info'); return false;">
	                            <?= ems_icon('x-mark', 'h-4 w-4') ?> <span>Ajukan Sekarang</span>
	                        </button>
	                        <small class="hint-warning" style="display:block;margin-top:8px;">
	                            <?= htmlspecialchars((string)$blockedMsg) ?>
	                        </small>
	                    <?php else: ?>
                        <button type="submit" class="btn-success">
                            <?= ems_icon('arrow-up-tray', 'h-4 w-4') ?> <span>Ajukan Sekarang</span>
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <div class="card card-section">
            <div class="card-header">Riwayat Pengajuan (Terakhir 10)</div>
            <div class="table-wrapper-sm">
                <table id="promotionHistoryTable" class="table-custom" data-auto-datatable="true" data-dt-order='[[0,"desc"]]'>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Dari</th>
                            <th>Ke</th>
                            <th>Status</th>
                            <th>Diproses</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$history): ?>
                            <tr><td colspan="6" class="muted-placeholder">Belum ada pengajuan.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['submitted_at'] ?? '') ?></td>
                                    <td><?= htmlspecialchars(ems_position_label($h['from_position'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(ems_position_label($h['to_position'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($h['status'] ?? '') ?></td>
                                    <td>
                                        <?php if (!empty($h['reviewed_at'])): ?>
                                            <div><strong><?= htmlspecialchars((string)($h['reviewed_by_name'] ?? '-')) ?></strong></div>
                                            <small class="meta-text"><?= htmlspecialchars((string)$h['reviewed_at']) ?></small>
                                        <?php else: ?>
                                            <span class="muted-placeholder">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($h['reviewer_note'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
    (function() {
        const fromPos = <?= json_encode($position) ?>;
        const minOps = <?= json_encode($minOps) ?>;
        const list = document.getElementById('opsList');
        const btn = document.getElementById('btnAddOp');

        if (!list || !btn) return;

        function makeRow(i) {
            const wrap = document.createElement('div');
            wrap.className = 'card card-section';
            wrap.innerHTML = `
                <div class="card-header">Operasi #${i}</div>
                <label>Nama Pasien <span class="required">*</span></label>
                <input type="text" name="ops_patient_name[]" required>

                <label>Tindakan Operasi <span class="required">*</span></label>
                <input type="text" name="ops_procedure_name[]" required>

                <label>DPJP <span class="required">*</span></label>
                <input type="text" name="ops_dpjp[]" list="dpjpDatalist" required placeholder="Ketik nama DPJP...">

                <div class="row-form-2" style="margin-top:10px;">
                    <div>
                        <label>Peran <span class="required">*</span></label>
                        <select name="ops_role[]" required>
                            <option value="">-- Pilih --</option>
                            <option value="assistant">Asisten</option>
                            <option value="dpjp">DPJP</option>
                        </select>
                    </div>
                    <div>
                        <label>Tingkat <span class="required">*</span></label>
                        <select name="ops_level[]" required>
                            <option value="">-- Pilih --</option>
                            <option value="minor">Minor</option>
                            <option value="major">Mayor</option>
                        </select>
                    </div>
                </div>
            `;
            return wrap;
        }

        function countRows() {
            return list.querySelectorAll('input[name="ops_patient_name[]"]').length;
        }

        function ensureMin() {
            const need = Math.max(0, Number(minOps || 0));
            while (countRows() < need) {
                list.appendChild(makeRow(countRows() + 1));
            }
        }

        btn.addEventListener('click', () => {
            list.appendChild(makeRow(countRows() + 1));
        });

        if (fromPos === 'paramedic' || fromPos === 'co_asst') {
            ensureMin();
        }
    })();
</script>

<script>
    (function() {
        const datalist = document.getElementById('dpjpDatalist');
        if (!datalist) return;

        let timer = null;
        let lastQ = '';

        function setOptions(items) {
            datalist.innerHTML = '';
            (items || []).forEach((it) => {
                const opt = document.createElement('option');
                opt.value = it.full_name || '';
                opt.textContent = it.position_label ? `(${it.position_label})` : '';
                datalist.appendChild(opt);
            });
        }

        async function search(q) {
            if (!q || q.length < 2) {
                setOptions([]);
                return;
            }
            if (q === lastQ) return;
            lastQ = q;
            try {
                const res = await fetch(`/ajax/search_dpjp.php?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                setOptions(Array.isArray(data) ? data : []);
            } catch (e) {
                // ignore
            }
        }

        document.addEventListener('input', (e) => {
            const el = e.target;
            if (!(el instanceof HTMLInputElement)) return;
            if (el.name !== 'ops_dpjp[]') return;
            const q = (el.value || '').trim();
            clearTimeout(timer);
            timer = setTimeout(() => search(q), 200);
        });
    })();
</script>

<script>
    (function() {
        function ensureToastContainer() {
            let c = document.getElementById('toast-container');
            if (c) return c;
            c = document.createElement('div');
            c.id = 'toast-container';
            // Fallback minimal styling if CSS not loaded
            c.style.position = 'fixed';
            c.style.right = '16px';
            c.style.top = '16px';
            c.style.zIndex = '9999';
            document.body.appendChild(c);
            return c;
        }

        function showToast(message, type) {
            const container = ensureToastContainer();
            const t = document.createElement('div');
            t.className = 'toast ' + (type || 'info');
            t.textContent = message || 'Aksi tidak tersedia.';
            t.style.padding = '10px 12px';
            t.style.borderRadius = '12px';
            t.style.marginBottom = '10px';
            t.style.boxShadow = '0 10px 24px rgba(2,6,23,.18)';
            t.style.background = (type === 'error') ? '#fee2e2' : (type === 'success') ? '#dcfce7' : '#e0f2fe';
            t.style.color = '#0f172a';
            container.appendChild(t);
            setTimeout(() => t.remove(), 3200);
        }

	        window.emsShowToast = function(msg, type) {
	            try {
	                showToast(msg, type);
	            } catch (e) {}

	            const alertBox = document.getElementById('promoBlockedAlert');
	            if (alertBox) {
	                alertBox.textContent = msg || 'Aksi tidak tersedia.';
	                alertBox.classList.remove('hidden');
	                alertBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	                return;
	            }

	            window.alert(msg || 'Aksi tidak tersedia.');
	        };

	        document.addEventListener('click', function(e) {
	            const btn = e.target.closest('[data-toast-message]');
	            if (!btn) return;
	            e.preventDefault();
	            window.emsShowToast(
	                btn.getAttribute('data-toast-message') || 'Aksi tidak tersedia.',
	                btn.getAttribute('data-toast-type') || 'info'
	            );
	        });
	    })();
	</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
