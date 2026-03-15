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
        'draft' => ['label' => 'DRAFT', 'class' => 'badge-warning'],
        'active' => ['label' => 'ACTIVE', 'class' => 'badge-counter'],
        'closed' => ['label' => 'CLOSED', 'class' => 'badge-success'],
        'archived' => ['label' => 'ARCHIVED', 'class' => 'badge-secondary'],
        default => ['label' => strtoupper($status), 'class' => 'badge-muted'],
    };
}

function forensicConfidentialityMeta(string $level): array
{
    return match ($level) {
        'confidential' => ['label' => 'CONFIDENTIAL', 'class' => 'badge-info'],
        'sealed' => ['label' => 'SEALED', 'class' => 'badge-danger'],
        'restricted' => ['label' => 'RESTRICTED', 'class' => 'badge-warning'],
        default => ['label' => strtoupper($level), 'class' => 'badge-muted'],
    };
}

function forensicTextValue(mixed $value, string $fallback = '-'): string
{
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
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
                    <label>No. Rekam Medis</label>
                    <div class="ems-form-group relative">
                        <input type="text" id="forensicMedicalRecordSearch" name="medical_record_no" placeholder="Ketik nama / citizen ID / no rekam medis" required>
                        <input type="hidden" name="medical_record_id" id="forensicMedicalRecordId">
                        <div id="forensicMedicalRecordList" class="consumer-search-dropdown consumer-search-dropdown-field hidden"></div>
                    </div>
                    <div class="meta-text-xs mt-1">
                        Data pasien private akan otomatis diambil dari rekam medis private yang dipilih.
                        <a href="forensic_medical_records_list.php" class="text-primary">Lihat rekam medis private</a>
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
                                        <div class="inline-flex gap-2 items-center">
                                        <button
                                            type="button"
                                            class="btn-primary btn-sm btn-forensic-detail"
                                            data-modal-title="<?= htmlspecialchars('Detail Kasus ' . (string) $case['case_code'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-modal-subtitle="<?= htmlspecialchars('Review keseluruhan data pasien private dan administrasi forensic.', ENT_QUOTES, 'UTF-8') ?>">
                                            <?= ems_icon('eye', 'h-4 w-4') ?>
                                            <span>Detail</span>
                                        </button>
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
                                        <form method="POST" action="forensic_action.php" onsubmit="return confirm('Hapus permanen kasus forensic ini? Tindakan ini tidak bisa dibatalkan.');" class="inline-flex">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_private_patient">
                                            <input type="hidden" name="redirect_to" value="forensic_private_patients.php">
                                            <input type="hidden" name="case_id" value="<?= (int) $case['id'] ?>">
                                            <button type="submit" class="btn-error btn-sm">Hapus</button>
                                        </form>
                                        <div class="hidden forensic-detail-template">
                                            <div class="forensic-detail-shell">
                                                <div class="forensic-detail-hero">
                                                    <div class="forensic-detail-panel">
                                                        <div class="forensic-detail-label">Identitas Kasus</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicTextValue($case['patient_name']), ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="forensic-detail-meta">
                                                            Kode kasus: <?= htmlspecialchars(forensicTextValue($case['case_code']), ENT_QUOTES, 'UTF-8') ?><br>
                                                            Rekam medis: <?= htmlspecialchars(forensicTextValue($case['linked_record_code'] ?: $case['medical_record_no']), ENT_QUOTES, 'UTF-8') ?><br>
                                                            Citizen ID: <?= htmlspecialchars(forensicTextValue($case['linked_record_citizen_id']), ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                    </div>
                                                    <div class="forensic-detail-panel">
                                                        <div class="forensic-detail-label">Status</div>
                                                        <div class="forensic-detail-badges">
                                                            <span class="<?= htmlspecialchars($confMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($confMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            <span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        </div>
                                                        <div class="forensic-detail-meta">
                                                            Dibuat oleh: <?= htmlspecialchars(forensicTextValue($case['created_by_name']), ENT_QUOTES, 'UTF-8') ?><br>
                                                            Tanggal input: <?= htmlspecialchars(forensicTextValue($case['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="forensic-detail-grid">
                                                    <div class="forensic-detail-block">
                                                        <div class="forensic-detail-label">Jenis Kasus</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicTextValue($case['case_type']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                    <div class="forensic-detail-block">
                                                        <div class="forensic-detail-label">Tanggal Kejadian</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicTextValue($case['incident_date']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                    <div class="forensic-detail-block">
                                                        <div class="forensic-detail-label">Lokasi Kejadian</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicTextValue($case['incident_location']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                    <div class="forensic-detail-block">
                                                        <div class="forensic-detail-label">Nama Rekam Medis Terkait</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicTextValue($case['linked_record_name']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                </div>
                                                <div class="forensic-detail-block">
                                                    <div class="forensic-detail-label">Catatan</div>
                                                    <div class="forensic-detail-value<?= trim((string) ($case['notes'] ?? '')) === '' ? ' is-muted' : '' ?>"><?= htmlspecialchars(forensicTextValue($case['notes']), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        </div>
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

<div id="forensicDetailModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg forensic-detail-modal">
        <div class="forensic-detail-head">
            <div class="min-w-0">
                <div id="forensicDetailModalTitle" class="forensic-detail-title">Detail</div>
                <div id="forensicDetailModalSubtitle" class="forensic-detail-subtitle"></div>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div id="forensicDetailModalBody" class="forensic-detail-content"></div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('forensicDetailModal');
    const title = document.getElementById('forensicDetailModalTitle');
    const subtitle = document.getElementById('forensicDetailModalSubtitle');
    const body = document.getElementById('forensicDetailModalBody');

    if (!modal || !title || !subtitle || !body) {
        return;
    }

    function closeModal() {
        modal.classList.add('hidden');
        body.innerHTML = '';
        document.body.classList.remove('modal-open');
    }

    document.body.addEventListener('click', function (event) {
        const trigger = event.target.closest('.btn-forensic-detail');
        if (trigger) {
            const template = trigger.parentElement ? trigger.parentElement.querySelector('.forensic-detail-template') : null;
            if (!template) {
                return;
            }

            title.textContent = trigger.getAttribute('data-modal-title') || 'Detail';
            subtitle.textContent = trigger.getAttribute('data-modal-subtitle') || '';
            body.innerHTML = template.innerHTML;
            modal.classList.remove('hidden');
            document.body.classList.add('modal-open');
            return;
        }

        if (event.target === modal || event.target.closest('.btn-cancel')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
