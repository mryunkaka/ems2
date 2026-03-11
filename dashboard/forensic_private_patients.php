<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Forensic'], '/dashboard/index.php');

$pageTitle = 'Data Pasien Private';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function forensicCaseStatusMeta(string $status): array
{
    return match ($status) {
        'active' => ['label' => 'ACTIVE', 'class' => 'badge-counter'],
        'closed' => ['label' => 'CLOSED', 'class' => 'badge-success'],
        'archived' => ['label' => 'ARCHIVED', 'class' => 'badge-muted'],
        default => ['label' => strtoupper($status), 'class' => 'badge-muted'],
    };
}

function forensicConfidentialityMeta(string $level): array
{
    return match ($level) {
        'sealed' => ['label' => 'SEALED', 'class' => 'badge-danger'],
        'restricted' => ['label' => 'RESTRICTED', 'class' => 'badge-warning'],
        default => ['label' => strtoupper($level), 'class' => 'badge-muted'],
    };
}

$summary = ['total' => 0, 'active' => 0, 'sealed' => 0, 'archived' => 0];
$cases = [];

try {
    $summary['total'] = (int) $pdo->query("SELECT COUNT(*) FROM forensic_private_patients")->fetchColumn();
    $summary['active'] = (int) $pdo->query("SELECT COUNT(*) FROM forensic_private_patients WHERE status = 'active'")->fetchColumn();
    $summary['sealed'] = (int) $pdo->query("SELECT COUNT(*) FROM forensic_private_patients WHERE confidentiality_level = 'sealed'")->fetchColumn();
    $summary['archived'] = (int) $pdo->query("SELECT COUNT(*) FROM forensic_private_patients WHERE status = 'archived'")->fetchColumn();

    $cases = $pdo->query("
        SELECT
            fpp.*,
            creator.full_name AS created_by_name,
            mr.record_code AS linked_record_code,
            mr.patient_name AS linked_record_name,
            mr.patient_citizen_id AS linked_record_citizen_id
        FROM forensic_private_patients fpp
        INNER JOIN user_rh creator ON creator.id = fpp.created_by
        LEFT JOIN medical_records mr ON mr.id = fpp.medical_record_id
        ORDER BY fpp.incident_date DESC, fpp.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Tabel Forensic belum siap. Jalankan SQL `docs/sql/05_2026-03-11_forensic_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Pencatatan kasus pasien private dan dasar administrasi forensic.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Total Kasus', 'value' => $summary['total'], 'icon' => 'lock-closed', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Kasus Aktif', 'value' => $summary['active'], 'icon' => 'clipboard-document-check', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Level Sealed', 'value' => $summary['sealed'], 'icon' => 'shield-exclamation', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Arsip Kasus', 'value' => $summary['archived'], 'icon' => 'inbox', 'tone' => 'muted']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Kasus Private</div>
                <p class="meta-text mb-4">Buat data dasar kasus forensic sebelum visum dan arsip disimpan.</p>

                <form method="POST" action="forensic_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_private_patient">
                    <input type="hidden" name="redirect_to" value="forensic_private_patients.php">

                    <label>Nama Pasien</label>
                    <input type="text" name="patient_name" required>

                    <div class="row-form-2">
                        <div>
                            <label>No. Rekam Medis</label>
                            <div class="ems-form-group relative">
                                <input type="text" id="forensicMedicalRecordSearch" name="medical_record_no" placeholder="Ketik nama / citizen ID / no rekam medis">
                                <input type="hidden" name="medical_record_id" id="forensicMedicalRecordId">
                                <div id="forensicMedicalRecordList" class="consumer-search-dropdown consumer-search-dropdown-field hidden"></div>
                            </div>
                            <div class="meta-text-xs mt-1">
                                <a href="forensic_medical_records_list.php" class="text-primary">Lihat rekam medis private</a>
                            </div>
                        </div>
                        <div>
                            <label>No. Identitas</label>
                            <input type="text" name="identity_number">
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Lahir</label>
                            <input type="date" name="birth_date">
                        </div>
                        <div>
                            <label>Gender</label>
                            <select name="gender">
                                <option value="unknown">Unknown</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>
                    </div>

                    <label>Jenis Kasus</label>
                    <input type="text" name="case_type" required>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Kejadian</label>
                            <input type="date" name="incident_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Lokasi Kejadian</label>
                            <input type="text" name="incident_location" required>
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Level Kerahasiaan</label>
                            <select name="confidentiality_level">
                                <option value="confidential">Confidential</option>
                                <option value="restricted">Restricted</option>
                                <option value="sealed">Sealed</option>
                            </select>
                        </div>
                        <div>
                            <label>Status Kasus</label>
                            <select name="status">
                                <option value="draft">Draft</option>
                                <option value="active">Active</option>
                                <option value="closed">Closed</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                    </div>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Kasus</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Kasus Forensic</div>
                <p class="meta-text mb-4">Monitoring kasus private, level kerahasiaan, dan status operasional.</p>

                <div class="table-wrapper">
                    <table id="forensicCasesTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Pasien</th>
                                <th>Kasus</th>
                                <th>Kerahasiaan</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cases as $case): ?>
                                <?php $statusMeta = forensicCaseStatusMeta((string) $case['status']); ?>
                                <?php $confMeta = forensicConfidentialityMeta((string) $case['confidentiality_level']); ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $case['case_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $case['incident_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $case['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($case['linked_record_code'] ?: $case['medical_record_no'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $case['case_type'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $case['incident_location'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><span class="<?= htmlspecialchars($confMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($confMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <form method="POST" action="forensic_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_case_status">
                                            <input type="hidden" name="redirect_to" value="forensic_private_patients.php">
                                            <input type="hidden" name="case_id" value="<?= (int) $case['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['draft', 'active', 'closed', 'archived'] as $status): ?>
                                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $case['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-secondary btn-sm">Status</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && $.fn.DataTable) {
        $('#forensicCasesTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('forensicMedicalRecordSearch');
    const hidden = document.getElementById('forensicMedicalRecordId');
    const list = document.getElementById('forensicMedicalRecordList');
    let controller = null;

    if (!input || !hidden || !list) {
        return;
    }

    function closeList() {
        list.innerHTML = '';
        list.classList.add('hidden');
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();
        hidden.value = '';

        if (q.length < 2) {
            closeList();
            return;
        }

        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        fetch(`<?= htmlspecialchars(ems_url('/ajax/search_private_medical_records.php'), ENT_QUOTES, 'UTF-8') ?>?q=${encodeURIComponent(q)}`, {
            signal: controller.signal,
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (rows) {
                if (!Array.isArray(rows) || rows.length === 0) {
                    closeList();
                    return;
                }

                list.innerHTML = rows.map(function (row) {
                    const recordCode = row.record_code || ('MR-' + String(row.id).padStart(6, '0'));
                    const citizen = row.patient_citizen_id || '-';
                    return `
                        <button type="button" class="consumer-search-item w-full text-left" data-id="${row.id}" data-code="${recordCode}">
                            <div class="consumer-search-name">${recordCode} - ${row.patient_name}</div>
                            <div class="consumer-search-meta">
                                <span>Citizen ID: ${citizen}</span>
                            </div>
                        </button>
                    `;
                }).join('');
                list.classList.remove('hidden');
            })
            .catch(function () {
                closeList();
            });
    });

    list.addEventListener('click', function (event) {
        const button = event.target.closest('[data-id]');
        if (!button) {
            return;
        }

        hidden.value = button.getAttribute('data-id') || '';
        input.value = button.getAttribute('data-code') || '';
        closeList();
    });

    document.addEventListener('click', function (event) {
        if (!list.contains(event.target) && event.target !== input) {
            closeList();
        }
    });
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
