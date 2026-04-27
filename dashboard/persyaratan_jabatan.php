<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

$pageTitle = 'Syarat Kenaikan Jabatan';

$transitions = [
    ['from' => 'trainee', 'to' => 'paramedic'],
    ['from' => 'paramedic', 'to' => 'co_asst'],
    ['from' => 'co_asst', 'to' => 'general_practitioner'],
    ['from' => 'general_practitioner', 'to' => 'specialist'],
];

$reqMap = [];
$rows = $pdo->query("
    SELECT from_position, to_position, min_days_since_join, min_operations, notes, required_documents, operation_type, operation_role, min_operations_minor, min_operations_major, dpjp_minor, dpjp_major
    FROM position_promotion_requirements
    WHERE is_active = 1
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $key = ems_normalize_position($r['from_position'] ?? '') . ':' . ems_normalize_position($r['to_position'] ?? '');
    $reqMap[$key] = $r;
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

        <div class="alert alert-warning">
            Halaman ini untuk mengubah syarat (custom) pengajuan kenaikan jabatan.
            Perubahan berlaku untuk pengajuan berikutnya (syarat yang tersimpan di request memakai snapshot saat submit).
        </div>

        <div class="card card-section">
            <div class="card-header">Pengaturan Syarat</div>

            <form method="POST" action="persyaratan_jabatan_action.php" class="form">
                <?= csrfField(); ?>

                <?php foreach ($transitions as $t):
                    $key = $t['from'] . ':' . $t['to'];
                    $r = $reqMap[$key] ?? [];
                    $minDays = $r['min_days_since_join'] ?? null;
                    $minOps  = $r['min_operations'] ?? null;
                    $minOpsMinor = $r['min_operations_minor'] ?? null;
                    $minOpsMajor = $r['min_operations_major'] ?? null;
                    $dpjpMinor = $r['dpjp_minor'] ?? 0;
                    $dpjpMajor = $r['dpjp_major'] ?? 0;
                    $notes   = $r['notes'] ?? '';
                    $requiredDocs = $r['required_documents'] ?? '';
                    $requiredDocsArray = $requiredDocs ? explode(',', $requiredDocs) : [];
                    $operationType = $r['operation_type'] ?? '';
                    $operationRole = $r['operation_role'] ?? '';
                    
                    // Debug: log checkbox presence to file
                    $debugLog = "DEBUG FORM: " . date('Y-m-d H:i:s') . " key=$key, dpjp_minor_db=" . var_export($dpjpMinor, true) . ", dpjp_major_db=" . var_export($dpjpMajor, true) . "\n";
                    file_put_contents(__DIR__ . '/../logs/debug_persyaratan_form.log', $debugLog, FILE_APPEND);
                ?>
                    <div class="card card-section" style="margin-bottom:12px;">
                        <div class="card-header">
                            <?= htmlspecialchars(ems_position_label($t['from'])) ?> → <?= htmlspecialchars(ems_position_label($t['to'])) ?>
                        </div>

                        <div class="row-form-2">
                            <div>
                                <label>Minimal Hari Sejak Join</label>
                                <input type="number"
                                    name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][min_days_since_join]"
                                    value="<?= htmlspecialchars((string)$minDays) ?>"
                                    min="0"
                                    placeholder="(kosongkan jika tidak dipakai)">
                            </div>
                        </div>

                        <div class="row-form-2">
                            <div>
                                <label>Minimal Operasi Minor</label>
                                <div class="flex items-center gap-2">
                                    <input type="number"
                                        name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][min_operations_minor]"
                                        value="<?= htmlspecialchars((string)$minOpsMinor) ?>"
                                        min="0"
                                        placeholder="(kosongkan jika tidak dipakai)"
                                        class="flex-1">
                                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][dpjp_minor]"
                                            value="1"
                                            <?= (int)$dpjpMinor === 1 ? 'checked' : '' ?>
                                            class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/40"
                                        />
                                        <span>DPJP</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label>Minimal Operasi Mayor</label>
                                <div class="flex items-center gap-2">
                                    <input type="number"
                                        name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][min_operations_major]"
                                        value="<?= htmlspecialchars((string)$minOpsMajor) ?>"
                                        min="0"
                                        placeholder="(kosongkan jika tidak dipakai)"
                                        class="flex-1">
                                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][dpjp_major]"
                                            value="1"
                                            <?= (int)$dpjpMajor === 1 ? 'checked' : '' ?>
                                            class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/40"
                                        />
                                        <span>DPJP</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <label>Sertifikat yang Wajib Diupload</label>
                        <div style="margin-bottom:12px;">
                            <label style="font-weight:normal;">
                                <input type="checkbox"
                                    name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][required_documents][]"
                                    value="sertifikat_medical_class"
                                    <?= in_array('sertifikat_medical_class', $requiredDocsArray) ? 'checked' : '' ?>>
                                Sertifikat Medical Class
                            </label>
                            <label style="font-weight:normal; margin-left:15px;">
                                <input type="checkbox"
                                    name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][required_documents][]"
                                    value="sertifikat_operasi_minor"
                                    <?= in_array('sertifikat_operasi_minor', $requiredDocsArray) ? 'checked' : '' ?>>
                                Sertifikat Operasi Minor
                            </label>
                            <label style="font-weight:normal; margin-left:15px;">
                                <input type="checkbox"
                                    name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][required_documents][]"
                                    value="sertifikat_operasi_plastik_basic"
                                    <?= in_array('sertifikat_operasi_plastik_basic', $requiredDocsArray) ? 'checked' : '' ?>>
                                Sertifikat Operasi Plastik (Basic)
                            </label>
                        </div>

                        <label>Catatan / Syarat</label>
                        <textarea
                            name="req[<?= htmlspecialchars($key, ENT_QUOTES) ?>][notes]"
                            rows="3"
                            placeholder="Isi syarat detail untuk transisi ini..."><?= htmlspecialchars((string)$notes) ?></textarea>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn-success">
                    <?= ems_icon('wrench', 'h-4 w-4') ?> <span>Simpan Perubahan</span>
                </button>
            </form>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
