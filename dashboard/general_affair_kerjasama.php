<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_general_affair_manager_access('/dashboard/index.php');
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Setting Kerjasama Instansi';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
$old = $_SESSION['flash_old'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors'], $_SESSION['flash_old']);

$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$unitLabel = ems_unit_label($effectiveUnit);
$tableReady = gaCooperationTablesReady($pdo);
$periodOptions = gaCooperationPeriodOptions();
$claimScopeOptions = gaCooperationClaimScopeOptions();
$medicineFields = gaCooperationMedicineFields();
$pricePerPcs = gaCooperationRegulationPricePerPcs($pdo, $effectiveUnit);

$stats = [
    'institutions' => 0,
    'members' => 0,
    'medicine_types' => 0,
];
$cooperations = [];

if ($tableReady) {
    $stmt = $pdo->prepare("
        SELECT
            gc.*,
            COUNT(DISTINCT gcm.id) AS total_members,
            COUNT(DISTINCT gcp.id) AS total_packages
        FROM general_affair_cooperations gc
        LEFT JOIN general_affair_cooperation_members gcm
            ON gcm.cooperation_id = gc.id
           AND gcm.is_active = 1
        LEFT JOIN general_affair_cooperation_packages gcp
            ON gcp.cooperation_id = gc.id
        WHERE gc.unit_code = :unit_code
        GROUP BY gc.id
        ORDER BY gc.is_active DESC, gc.institution_name ASC, gc.id DESC
    ");
    $stmt->execute([':unit_code' => $effectiveUnit]);
    $cooperationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cooperationRows as $row) {
        $cooperationId = (int)$row['id'];
        $notesMeta = gaCooperationParseNotesMeta((string)($row['notes'] ?? ''));
        $row['notes'] = (string)($notesMeta['notes'] ?? '');
        $row['claim_scope'] = (string)($notesMeta['claim_scope'] ?? 'per_person');

        $stmtMembers = $pdo->prepare("
            SELECT id, citizen_id, member_name, identity_id
            FROM general_affair_cooperation_members
            WHERE cooperation_id = :cooperation_id
              AND is_active = 1
            ORDER BY member_name ASC, citizen_id ASC
        ");
        $stmtMembers->execute([':cooperation_id' => $cooperationId]);
        $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

        $stmtPackages = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                p.price,
                p.bandage_qty,
                p.ifaks_qty,
                p.painkiller_qty
            FROM general_affair_cooperation_packages gcp
            INNER JOIN packages p ON p.id = gcp.package_id
            WHERE gcp.cooperation_id = :cooperation_id
            ORDER BY p.name ASC
        ");
        $stmtPackages->execute([':cooperation_id' => $cooperationId]);
        $packageRows = $stmtPackages->fetchAll(PDO::FETCH_ASSOC);
        $medicineQtys = gaCooperationResolveMedicineQtys($notesMeta, $packageRows);
        $medicineSummary = gaCooperationSummarizeMedicines($medicineQtys, $pricePerPcs);

        $cooperations[] = [
            'row' => $row,
            'members' => $members,
            'medicine_qtys' => $medicineQtys,
            'medicine_summary_lines' => $medicineSummary['lines'],
            'medicine_total_price' => $medicineSummary['total_price'],
            'configured_types' => $medicineSummary['configured_types'],
        ];
    }

    $stats['institutions'] = count($cooperations);
    foreach ($cooperations as $entry) {
        $stats['members'] += count($entry['members']);
        $stats['medicine_types'] += (int)($entry['configured_types'] ?? 0);
    }
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="ga-coop-page-head">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Kelola instansi kerja sama, anggota berdasarkan Citizen ID, dan jumlah obat gratis per periode untuk unit <?= htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <a href="<?= htmlspecialchars(ems_url('/dashboard/general_affair_kerjasama_history.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                <?= ems_icon('clipboard-document-list', 'h-4 w-4') ?>
                <span>List History Paket Gratis</span>
            </a>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if (!$tableReady): ?>
            <div class="alert alert-error">
                Modul kerja sama instansi belum siap. Jalankan SQL <strong>`docs/sql/33_2026-05-08_general_affair_cooperation_settings.sql`</strong> terlebih dahulu.
            </div>
        <?php endif; ?>

        <div class="ga-visit-stats-grid mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Instansi</div>
                <div class="text-2xl font-extrabold text-slate-900"><?= (int)$stats['institutions'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Anggota Aktif</div>
                <div class="text-2xl font-extrabold text-primary"><?= (int)$stats['members'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Jenis Obat Diatur</div>
                <div class="text-2xl font-extrabold text-emerald-700"><?= (int)$stats['medicine_types'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Unit Aktif</div>
                <div class="text-2xl font-extrabold text-amber-700"><?= htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-[460px_minmax(0,1fr)] gap-4">
            <div class="card">
                <div class="card-header">Form Kerjasama</div>
                <form method="POST" action="general_affair_kerjasama_action.php" class="form" id="cooperationForm">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" id="cooperationAction" value="create_cooperation">
                    <input type="hidden" name="redirect_to" value="general_affair_kerjasama.php">
                    <input type="hidden" name="cooperation_id" id="cooperationId" value="0">

                    <label>Instansi</label>
                    <input type="text" name="institution_name" id="institutionName" maxlength="150" required value="<?= htmlspecialchars((string)($old['institution_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    <label>Periode Gratis</label>
                    <select name="period_type" id="periodType" required>
                        <?php foreach ($periodOptions as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= (($old['period_type'] ?? 'daily') === $value) ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Mode Paket Gratis</label>
                    <div class="ga-coop-claim-scope">
                        <?php foreach ($claimScopeOptions as $value => $label): ?>
                            <label class="ga-coop-scope-option">
                                <input type="radio" name="claim_scope" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= (($old['claim_scope'] ?? 'per_person') === $value) ? ' checked' : '' ?>>
                                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <label>Jumlah Obat Gratis</label>
                    <div class="ga-coop-medicine-grid">
                        <?php foreach ($medicineFields as $field => $label): ?>
                            <?php
                            $priceKey = str_replace('_qty', '', $field);
                            $fieldValue = (string)($old[$field] ?? '');
                            ?>
                            <label class="ga-coop-medicine-card">
                                <span class="ga-coop-medicine-title"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="ga-coop-medicine-price">Harga regulasi: $<?= number_format((int)($pricePerPcs[$priceKey] ?? 0), 0, ',', '.') ?>/pcs</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                                    id="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                                    placeholder="0"
                                    value="<?= htmlspecialchars($fieldValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="ga-coop-regulation-box">
                        <div class="ga-coop-regulation-line">
                            <span>Total harga regulasi</span>
                            <strong id="medicineTotalPrice">$0</strong>
                        </div>
                        <div class="meta-text-xs" id="medicineTotalSummary">Isi jumlah obat yang diberikan. Nilai kosong atau 0 tidak dihitung.</div>
                    </div>

                    <label>Anggota Kerjasama</label>
                    <div id="membersContainer" class="space-y-2">
                        <?php
                        $oldMembers = (array)($old['members'] ?? []);
                        if ($oldMembers === []) {
                            $oldMembers = [
                                ['citizen_id' => '', 'member_name' => ''],
                            ];
                        }
                        ?>
                        <?php foreach ($oldMembers as $index => $member): ?>
                            <div class="ga-coop-member-row">
                                <input type="text" name="members[<?= (int)$index ?>][citizen_id]" placeholder="Citizen ID" value="<?= htmlspecialchars((string)($member['citizen_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" autocapitalize="characters" spellcheck="false">
                                <input type="text" name="members[<?= (int)$index ?>][member_name]" placeholder="Nama anggota (opsional)" value="<?= htmlspecialchars((string)($member['member_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button" class="btn-danger btn-sm js-remove-member" title="Hapus anggota"><?= ems_icon('trash', 'h-4 w-4') ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="modal-actions mt-3">
                        <button type="button" class="btn-secondary" id="addMemberBtn">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Tambah Anggota</span>
                        </button>
                    </div>

                    <label>Catatan</label>
                    <textarea name="notes" id="cooperationNotes" rows="3" placeholder="Opsional"><?= htmlspecialchars((string)($old['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success"<?= $tableReady ? '' : ' disabled' ?>>
                            <?= ems_icon('check', 'h-4 w-4') ?>
                            <span id="cooperationSubmitLabel">Simpan Kerjasama</span>
                        </button>
                        <button type="button" class="btn-secondary" id="resetCooperationForm">Reset Form</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Daftar Kerjasama</div>
                <div class="table-wrapper">
                    <table id="gaCooperationTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Instansi</th>
                                <th>Periode</th>
                                <th>Obat Gratis</th>
                                <th>Anggota</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cooperations as $entry): ?>
                                <?php
                                $row = $entry['row'];
                                $members = $entry['members'];
                                $medicineQtys = $entry['medicine_qtys'];
                                $medicineSummaryLines = $entry['medicine_summary_lines'];
                                $isActive = (int)($row['is_active'] ?? 0) === 1;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if (!empty($row['notes'])): ?>
                                            <div class="meta-text-xs whitespace-pre-line"><?= htmlspecialchars((string)$row['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars(gaCooperationPeriodLabel((string)$row['period_type']), ENT_QUOTES, 'UTF-8') ?>
                                        <div class="meta-text-xs"><?= htmlspecialchars(gaCooperationClaimScopeLabel((string)($row['claim_scope'] ?? 'per_person')), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <?php if ($medicineSummaryLines): ?>
                                            <div class="meta-text-xs whitespace-pre-line"><?= htmlspecialchars(implode("\n", $medicineSummaryLines), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta-text-xs mt-1"><strong>Total:</strong> $<?= number_format((int)($entry['medicine_total_price'] ?? 0), 0, ',', '.') ?></div>
                                        <?php else: ?>
                                            <div class="meta-text-xs">-</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= count($members) ?> anggota</strong>
                                        <?php if ($members): ?>
                                            <div class="meta-text-xs">
                                                <?= htmlspecialchars(implode(', ', array_map(static function ($memberRow) {
                                                    $name = trim((string)($memberRow['member_name'] ?? ''));
                                                    $citizenId = (string)($memberRow['citizen_id'] ?? '');
                                                    return $name !== '' ? $name . ' (' . $citizenId . ')' : $citizenId;
                                                }, $members)), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?= $isActive ? 'badge-success' : 'badge-muted' ?>">
                                            <?= $isActive ? 'Aktif' : 'Nonaktif' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-row-nowrap">
                                            <button
                                                type="button"
                                                class="btn-secondary btn-sm action-icon-btn js-edit-cooperation"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-institution-name="<?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-period-type="<?= htmlspecialchars((string)$row['period_type'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-claim-scope="<?= htmlspecialchars((string)($row['claim_scope'] ?? 'per_person'), ENT_QUOTES, 'UTF-8') ?>"
                                                data-notes="<?= htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-bandage-qty="<?= (int)($medicineQtys['bandage_qty'] ?? 0) ?>"
                                                data-ifaks-qty="<?= (int)($medicineQtys['ifaks_qty'] ?? 0) ?>"
                                                data-painkiller-qty="<?= (int)($medicineQtys['painkiller_qty'] ?? 0) ?>"
                                                data-members="<?= htmlspecialchars(json_encode(array_map(static fn($memberRow) => ['citizen_id' => (string)$memberRow['citizen_id'], 'member_name' => (string)($memberRow['member_name'] ?? '')], $members)), ENT_QUOTES, 'UTF-8') ?>"
                                                title="Edit kerjasama">
                                                <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                            </button>

                                            <form method="POST" action="general_affair_kerjasama_action.php" class="inline js-delete-visit" data-confirm="Yakin ingin menghapus kerjasama ini?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_cooperation">
                                                <input type="hidden" name="redirect_to" value="general_affair_kerjasama.php">
                                                <input type="hidden" name="cooperation_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm action-icon-btn" title="Hapus kerjasama"><?= ems_icon('trash', 'h-4 w-4') ?></button>
                                            </form>

                                            <form method="POST" action="general_affair_kerjasama_action.php" class="inline">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="toggle_cooperation_status">
                                                <input type="hidden" name="redirect_to" value="general_affair_kerjasama.php">
                                                <input type="hidden" name="cooperation_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                                                <button type="submit" class="btn-secondary btn-sm action-icon-btn" title="<?= $isActive ? 'Nonaktifkan' : 'Aktifkan' ?> kerjasama">
                                                    <?= ems_icon($isActive ? 'pause-circle' : 'play-circle', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$cooperations && $tableReady): ?>
                        <div class="muted-placeholder p-4">Belum ada kerjasama instansi untuk unit ini.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const datatableLanguageUrl = '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    const pricePerPcs = <?= json_encode($pricePerPcs, JSON_NUMERIC_CHECK); ?>;
    const membersContainer = document.getElementById('membersContainer');
    const addMemberBtn = document.getElementById('addMemberBtn');
    const resetButton = document.getElementById('resetCooperationForm');
    const form = document.getElementById('cooperationForm');
    const submitLabel = document.getElementById('cooperationSubmitLabel');
    const medicineTotalPrice = document.getElementById('medicineTotalPrice');
    const medicineTotalSummary = document.getElementById('medicineTotalSummary');
    let memberIndex = membersContainer ? membersContainer.querySelectorAll('.ga-coop-member-row').length : 0;

    function escapeAttr(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            })[char] || char;
        });
    }

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#gaCooperationTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[0, 'asc']],
            language: { url: datatableLanguageUrl }
        });
    }

    function formatCurrency(value) {
        return '$' + Number(value || 0).toLocaleString('en-US');
    }

    function updateMedicineSummary() {
        const bandageQty = Math.max(0, parseInt(document.getElementById('bandage_qty')?.value || '0', 10) || 0);
        const ifaksQty = Math.max(0, parseInt(document.getElementById('ifaks_qty')?.value || '0', 10) || 0);
        const painkillerQty = Math.max(0, parseInt(document.getElementById('painkiller_qty')?.value || '0', 10) || 0);
        const items = [];
        let total = 0;

        if (bandageQty > 0) {
            total += bandageQty * Number(pricePerPcs.bandage || 0);
            items.push('Bandage ' + bandageQty);
        }
        if (ifaksQty > 0) {
            total += ifaksQty * Number(pricePerPcs.ifaks || 0);
            items.push('Ifaks ' + ifaksQty);
        }
        if (painkillerQty > 0) {
            total += painkillerQty * Number(pricePerPcs.painkiller || 0);
            items.push('Painkiller ' + painkillerQty);
        }

        if (medicineTotalPrice) {
            medicineTotalPrice.textContent = formatCurrency(total);
        }
        if (medicineTotalSummary) {
            medicineTotalSummary.textContent = items.length > 0
                ? 'Dihitung: ' + items.join(', ')
                : 'Isi jumlah obat yang diberikan. Nilai kosong atau 0 tidak dihitung.';
        }
    }

    function createMemberRow(data) {
        const row = document.createElement('div');
        row.className = 'ga-coop-member-row';
        row.innerHTML = `
            <input type="text" name="members[${memberIndex}][citizen_id]" placeholder="Citizen ID" autocomplete="off" autocapitalize="characters" spellcheck="false" value="${escapeAttr(data && data.citizen_id ? data.citizen_id : '')}">
            <input type="text" name="members[${memberIndex}][member_name]" placeholder="Nama anggota (opsional)" value="${escapeAttr(data && data.member_name ? data.member_name : '')}">
            <button type="button" class="btn-danger btn-sm js-remove-member" title="Hapus anggota"><?= ems_icon('trash', 'h-4 w-4') ?></button>
        `;
        memberIndex += 1;
        return row;
    }

    function ensureOneMemberRow() {
        if (!membersContainer) return;
        if (membersContainer.querySelectorAll('.ga-coop-member-row').length === 0) {
            membersContainer.appendChild(createMemberRow({ citizen_id: '', member_name: '' }));
        }
    }

    function resetFormState() {
        if (!form) return;
        form.reset();
        document.getElementById('cooperationAction').value = 'create_cooperation';
        document.getElementById('cooperationId').value = '0';
        document.querySelectorAll('input[name="claim_scope"]').forEach(function(radio) {
            radio.checked = radio.value === 'per_person';
        });
        if (submitLabel) {
            submitLabel.textContent = 'Simpan Kerjasama';
        }
        if (membersContainer) {
            membersContainer.innerHTML = '';
        }
        memberIndex = 0;
        ensureOneMemberRow();
        updateMedicineSummary();
    }

    if (addMemberBtn) {
        addMemberBtn.addEventListener('click', function() {
            if (!membersContainer) return;
            membersContainer.appendChild(createMemberRow({ citizen_id: '', member_name: '' }));
        });
    }

    document.addEventListener('click', function(event) {
        if (event.target.closest('.js-remove-member')) {
            const row = event.target.closest('.ga-coop-member-row');
            if (row) {
                row.remove();
                ensureOneMemberRow();
            }
        }
    });

    document.querySelectorAll('.js-edit-cooperation').forEach(function(button) {
        button.addEventListener('click', function() {
            const members = JSON.parse(button.dataset.members || '[]');

            document.getElementById('cooperationAction').value = 'update_cooperation';
            document.getElementById('cooperationId').value = button.dataset.id || '0';
            document.getElementById('institutionName').value = button.dataset.institutionName || '';
            document.getElementById('periodType').value = button.dataset.periodType || 'daily';
            document.querySelectorAll('input[name="claim_scope"]').forEach(function(radio) {
                radio.checked = radio.value === (button.dataset.claimScope || 'per_person');
            });
            document.getElementById('cooperationNotes').value = button.dataset.notes || '';
            document.getElementById('bandage_qty').value = button.dataset.bandageQty || '0';
            document.getElementById('ifaks_qty').value = button.dataset.ifaksQty || '0';
            document.getElementById('painkiller_qty').value = button.dataset.painkillerQty || '0';

            if (membersContainer) {
                membersContainer.innerHTML = '';
                memberIndex = 0;
                if (Array.isArray(members) && members.length > 0) {
                    members.forEach(function(member) {
                        membersContainer.appendChild(createMemberRow(member));
                    });
                } else {
                    ensureOneMemberRow();
                }
            }

            if (submitLabel) {
                submitLabel.textContent = 'Simpan Perubahan';
            }

            updateMedicineSummary();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    document.querySelectorAll('.js-delete-visit').forEach(function(formEl) {
        formEl.addEventListener('submit', function(event) {
            const message = formEl.dataset.confirm || 'Yakin ingin menghapus data ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    if (resetButton) {
        resetButton.addEventListener('click', resetFormState);
    }

    ['bandage_qty', 'ifaks_qty', 'painkiller_qty'].forEach(function(fieldId) {
        const input = document.getElementById(fieldId);
        if (input) {
            input.addEventListener('input', updateMedicineSummary);
        }
    });

    ensureOneMemberRow();
    updateMedicineSummary();
});
</script>

<style>
.ga-coop-page-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.ga-visit-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

.ga-coop-medicine-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.ga-coop-claim-scope {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 0.25rem;
}

.ga-coop-scope-option {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.7rem 0.9rem;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
}

.ga-coop-medicine-card {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    padding: 0.85rem 0.9rem;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
}

.ga-coop-medicine-title {
    font-weight: 700;
    color: #0f172a;
}

.ga-coop-medicine-price {
    font-size: 0.78rem;
    color: #475569;
}

.ga-coop-regulation-box {
    margin-top: 0.75rem;
    padding: 0.85rem 0.95rem;
    border-radius: 12px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
}

.ga-coop-regulation-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.3rem;
}

.ga-coop-member-row {
    display: grid;
    grid-template-columns: minmax(0, 170px) minmax(0, 1fr) auto;
    gap: 0.5rem;
    align-items: center;
}

@media (max-width: 1100px) {
    .ga-visit-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {
    .ga-coop-medicine-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1200px) {
    .ga-coop-medicine-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .ga-visit-stats-grid,
    .ga-coop-member-row {
        grid-template-columns: 1fr;
    }

    .ga-coop-page-head {
        display: flex;
        flex-direction: column;
    }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
