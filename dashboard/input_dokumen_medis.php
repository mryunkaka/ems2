<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
$userRole = strtolower(trim((string)($user['role'] ?? '')));

if (ems_is_staff_role($userRole)) {
    $_SESSION['flash_errors'][] = 'Akses ditolak.';
    header('Location: /dashboard/index.php');
    exit;
}

function inputDokumenHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

function inputDokumenNormalizeDocName(?string $name): string
{
    return trim(preg_replace('/\s+/', ' ', (string)$name));
}

$pageTitle = 'Input Dokumen Medis';

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

$hasDivisionColumn = inputDokumenHasColumn($pdo, 'division');
$divisionSelect = $hasDivisionColumn ? 'division,' : "NULL AS division,";

$stmtMedics = $pdo->query("
    SELECT
        id,
        full_name,
        position,
        role,
        {$divisionSelect}
        batch,
        citizen_id,
        file_ktp,
        file_sim,
        file_kta,
        file_skb,
        sertifikat_heli,
        sertifikat_operasi,
        dokumen_lainnya
    FROM user_rh
    WHERE is_active = 1
    ORDER BY full_name ASC
");
$activeMedics = $stmtMedics ? $stmtMedics->fetchAll(PDO::FETCH_ASSOC) : [];

$selectedMedicId = (int)($_GET['medic_id'] ?? 0);
$selectedMedic = null;
foreach ($activeMedics as $medicRow) {
    if ((int)($medicRow['id'] ?? 0) === $selectedMedicId) {
        $selectedMedic = $medicRow;
        break;
    }
}

$otherDocSuggestions = [];
$stmtOtherDocSuggestions = $pdo->query("SELECT dokumen_lainnya FROM user_rh WHERE dokumen_lainnya IS NOT NULL AND dokumen_lainnya <> ''");
$otherDocRows = $stmtOtherDocSuggestions ? $stmtOtherDocSuggestions->fetchAll(PDO::FETCH_ASSOC) : [];
foreach ($otherDocRows as $otherDocRow) {
    $parsedDocs = ensureAcademyDocIds(parseAcademyDocs($otherDocRow['dokumen_lainnya'] ?? ''));
    foreach ($parsedDocs as $parsedDoc) {
        $docName = inputDokumenNormalizeDocName($parsedDoc['name'] ?? '');
        if ($docName === '') {
            continue;
        }

        $lookupKey = strtolower($docName);
        if (!isset($otherDocSuggestions[$lookupKey])) {
            $otherDocSuggestions[$lookupKey] = $docName;
        }
    }
}
natcasesort($otherDocSuggestions);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title">Input Dokumen Medis</h1>
        <p class="page-subtitle">Manager dapat mengunggah dokumen untuk medis aktif tanpa login ke akun medis tersebut.</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card card-section">
            <div class="card-header">Pilih Medis Aktif</div>
            <div class="card-body">
                <div class="medic-picker">
                    <label for="medicSearchInput">Cari Nama Medis</label>
                    <div class="medic-search-shell">
                        <input type="text" id="medicSearchInput" class="form-control" placeholder="Ketik nama, jabatan, batch, atau citizen ID..." autocomplete="off" value="<?= htmlspecialchars($selectedMedic['full_name'] ?? '') ?>">
                        <button type="button" id="btnClearMedicSearch" class="btn-secondary button-compact">Clear</button>
                    </div>
                    <input type="hidden" id="selectedMedicId" value="<?= (int)($selectedMedic['id'] ?? 0) ?>">
                    <div id="medicSearchResults" class="medic-search-results hidden"></div>
                </div>

                <div id="selectedMedicSummary" class="medic-summary <?= $selectedMedic ? '' : 'hidden' ?>">
                    <div class="medic-summary-head">
                        <strong id="selectedMedicName"><?= htmlspecialchars($selectedMedic['full_name'] ?? '-') ?></strong>
                        <span id="selectedMedicRole" class="badge-muted-mini"><?= htmlspecialchars(ems_role_label($selectedMedic['role'] ?? '')) ?></span>
                    </div>
                    <div class="medic-summary-meta">
                        <span id="selectedMedicPosition"><?= htmlspecialchars(ems_position_label($selectedMedic['position'] ?? '')) ?></span>
                        <span id="selectedMedicDivision"><?= htmlspecialchars(ems_normalize_division($selectedMedic['division'] ?? '') ?: '-') ?></span>
                        <span id="selectedMedicBatch"><?= !empty($selectedMedic['batch']) ? 'Batch ' . (int)$selectedMedic['batch'] : 'Tanpa Batch' ?></span>
                        <span id="selectedMedicCitizenId"><?= htmlspecialchars($selectedMedic['citizen_id'] ?? 'Citizen ID belum ada') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header">Status Dokumen Medis Terpilih</div>
            <div class="card-body">
                <div id="selectedMedicDocsPanel" class="<?= $selectedMedic ? '' : 'hidden' ?>">
                    <div class="doc-status-grid">
                        <div class="doc-status-card">
                            <div class="doc-status-card-title">Sudah Upload</div>
                            <div id="selectedMedicUploadedDocs" class="doc-status-list"></div>
                        </div>
                    </div>
                </div>
                <div id="selectedMedicDocsEmpty" class="empty-state <?= $selectedMedic ? 'hidden' : '' ?>">
                    Pilih medis dari autocomplete untuk melihat file yang sudah ada dan yang masih kosong.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Form Upload Dokumen</div>
            <div class="card-body">
                <form method="POST" action="input_dokumen_medis_action.php" enctype="multipart/form-data" class="form" id="managerDocUploadForm">
                    <input type="hidden" name="medic_user_id" id="medicUserIdField" value="<?= (int)($selectedMedic['id'] ?? 0) ?>">

                    <div class="info-box">
                        <span class="info-icon"><?= ems_icon('information-circle', 'h-5 w-5') ?></span>
                        <span>Dokumen akan disimpan ke profil medis yang dipilih dan masuk ke folder storage medis tersebut.</span>
                    </div>

                    <?php
                    function renderManagerDocInput(string $label, string $name): void
                    {
                        ?>
                        <div class="doc-upload-wrapper">
                            <div class="doc-upload-header">
                                <label class="doc-label"><?= htmlspecialchars($label) ?></label>
                                <span class="badge-muted-mini">PNG / JPG</span>
                            </div>
                            <div class="doc-upload-input">
                                <label for="<?= htmlspecialchars($name) ?>" class="file-upload-label">
                                    <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                    <span class="file-text">
                                        <strong>Pilih file</strong>
                                        <small>Upload akan menggantikan file lama jika ada</small>
                                    </span>
                                </label>
                                <input type="file" id="<?= htmlspecialchars($name) ?>" name="<?= htmlspecialchars($name) ?>" accept="image/png,image/jpeg" class="hidden">
                                <div class="file-selected-name" data-for="<?= htmlspecialchars($name) ?>"></div>
                            </div>
                        </div>
                        <?php
                    }

                    renderManagerDocInput('Upload KTP', 'file_ktp');
                    renderManagerDocInput('Upload SKB', 'file_skb');
                    renderManagerDocInput('Upload SIM', 'file_sim');
                    renderManagerDocInput('Upload KTA', 'file_kta');
                    renderManagerDocInput('Sertifikat Heli', 'sertifikat_heli');
                    renderManagerDocInput('Sertifikat Operasi', 'sertifikat_operasi');
                    ?>

                    <div class="doc-upload-wrapper doc-upload-dashed">
                        <div class="doc-upload-header doc-upload-header-stack">
                            <label class="doc-label">File Lainnya</label>
                            <small class="text-muted doc-upload-meta">Nama dokumen bisa dipilih dari saran yang sudah ada atau tetap diketik manual jika ingin nama baru.</small>
                        </div>

                        <datalist id="managerOtherDocSuggestions">
                            <?php foreach ($otherDocSuggestions as $suggestedDocName): ?>
                                <option value="<?= htmlspecialchars($suggestedDocName) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <div id="managerOtherDocsContainer" class="academy-list">
                            <div class="academy-doc-row" data-row="other-doc">
                                <div class="row-form-2 academy-grid">
                                    <div>
                                        <label>Nama File Lainnya</label>
                                        <input type="text" name="academy_doc_name[]" list="managerOtherDocSuggestions" autocomplete="off" placeholder="Contoh: Surat Kontrak Kerja atau Academy">
                                    </div>
                                    <div>
                                        <label>File</label>
                                        <div class="doc-upload-input doc-upload-input-reset">
                                            <label for="manager_other_doc_0" class="file-upload-label">
                                                <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                                <span class="file-text">
                                                    <strong>Pilih file</strong>
                                                    <small>PNG atau JPG</small>
                                                </span>
                                            </label>
                                            <input type="file" id="manager_other_doc_0" name="academy_doc_file[]" accept="image/png,image/jpeg" class="hidden">
                                            <div class="file-selected-name" data-for="manager_other_doc_0"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="action-row-end">
                            <button type="button" id="btnAddManagerOtherDoc" class="btn-secondary button-compact">
                                <?= ems_icon('plus', 'h-4 w-4') ?> Tambah File Lainnya
                            </button>
                        </div>
                    </div>

                    <div class="form-submit-wrapper">
                        <button type="submit" class="btn-primary btn-submit">
                            <?= ems_icon('arrow-up-tray', 'h-5 w-5') ?>
                            <span>Upload Dokumen Medis</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<script>
    window.activeMedicsIndex = <?= json_encode(array_map(static function (array $medic): array {
        $otherDocs = ensureAcademyDocIds(parseAcademyDocs($medic['dokumen_lainnya'] ?? ''));
        $uploadedDocs = [];
        $missingDocs = [];

        $standardDocs = [
            'Upload KTP' => $medic['file_ktp'] ?? '',
            'Upload SIM' => $medic['file_sim'] ?? '',
            'Upload KTA' => $medic['file_kta'] ?? '',
            'Upload SKB' => $medic['file_skb'] ?? '',
            'Sertifikat Heli' => $medic['sertifikat_heli'] ?? '',
            'Sertifikat Operasi' => $medic['sertifikat_operasi'] ?? '',
        ];

        foreach ($standardDocs as $label => $path) {
            if (!empty($path)) {
                $uploadedDocs[] = [
                    'label' => $label,
                    'path' => (string)$path,
                ];
            } else {
                $missingDocs[] = $label;
            }
        }

        if ($otherDocs !== []) {
            foreach ($otherDocs as $otherDoc) {
                $uploadedDocs[] = [
                    'label' => (string)($otherDoc['name'] ?? 'File Lainnya'),
                    'path' => (string)($otherDoc['path'] ?? ''),
                ];
            }
        } else {
            $missingDocs[] = 'File Lainnya';
        }

        return [
            'id' => (int)($medic['id'] ?? 0),
            'full_name' => (string)($medic['full_name'] ?? ''),
            'position_label' => ems_position_label($medic['position'] ?? ''),
            'role_label' => ems_role_label($medic['role'] ?? ''),
            'division_label' => ems_normalize_division($medic['division'] ?? '') ?: '-',
            'batch_label' => !empty($medic['batch']) ? 'Batch ' . (int)$medic['batch'] : 'Tanpa Batch',
            'citizen_id' => (string)($medic['citizen_id'] ?? ''),
            'uploaded_docs' => $uploadedDocs,
            'missing_docs' => $missingDocs,
            'search_blob' => strtolower(trim(implode(' ', array_filter([
                (string)($medic['full_name'] ?? ''),
                ems_position_label($medic['position'] ?? ''),
                ems_role_label($medic['role'] ?? ''),
                ems_normalize_division($medic['division'] ?? '') ?: '',
                !empty($medic['batch']) ? 'batch ' . (int)$medic['batch'] : '',
                (string)($medic['citizen_id'] ?? ''),
            ])))),
        ];
    }, $activeMedics), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
    document.addEventListener('change', function(e) {
        const input = e.target;
        if (!input || input.tagName !== 'INPUT' || input.type !== 'file') return;
        const nameDisplay = document.querySelector('.file-selected-name[data-for="' + input.id + '"]');
        if (!nameDisplay) return;
        if (input.files && input.files.length > 0) {
            const fileName = input.files[0].name;
            const fileSize = (input.files[0].size / 1024).toFixed(1);
            nameDisplay.innerHTML = `<span class="selected-file-info"><strong>${fileName}</strong><small>${fileSize} KB</small></span>`;
            nameDisplay.style.display = 'flex';
        } else {
            nameDisplay.style.display = 'none';
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const medics = window.activeMedicsIndex || [];
        const searchInput = document.getElementById('medicSearchInput');
        const hiddenInput = document.getElementById('medicUserIdField');
        const selectedMedicId = document.getElementById('selectedMedicId');
        const resultsBox = document.getElementById('medicSearchResults');
        const summaryBox = document.getElementById('selectedMedicSummary');
        const clearButton = document.getElementById('btnClearMedicSearch');
        const nameEl = document.getElementById('selectedMedicName');
        const roleEl = document.getElementById('selectedMedicRole');
        const positionEl = document.getElementById('selectedMedicPosition');
        const divisionEl = document.getElementById('selectedMedicDivision');
        const batchEl = document.getElementById('selectedMedicBatch');
        const citizenIdEl = document.getElementById('selectedMedicCitizenId');
        const docsPanel = document.getElementById('selectedMedicDocsPanel');
        const docsEmpty = document.getElementById('selectedMedicDocsEmpty');
        const uploadedDocsEl = document.getElementById('selectedMedicUploadedDocs');
        const form = document.getElementById('managerDocUploadForm');

        function hideResults() {
            resultsBox.innerHTML = '';
            resultsBox.classList.add('hidden');
        }

        function renderSummary(medic) {
            if (!medic) {
                summaryBox.classList.add('hidden');
                nameEl.textContent = '-';
                roleEl.textContent = '-';
                positionEl.textContent = '-';
                divisionEl.textContent = '-';
                batchEl.textContent = '-';
                citizenIdEl.textContent = '-';
                uploadedDocsEl.innerHTML = '';
                docsPanel.classList.add('hidden');
                docsEmpty.classList.remove('hidden');
                return;
            }

            summaryBox.classList.remove('hidden');
            nameEl.textContent = medic.full_name || '-';
            roleEl.textContent = medic.role_label || '-';
            positionEl.textContent = medic.position_label || '-';
            divisionEl.textContent = medic.division_label || '-';
            batchEl.textContent = medic.batch_label || 'Tanpa Batch';
            citizenIdEl.textContent = medic.citizen_id || 'Citizen ID belum ada';

            uploadedDocsEl.innerHTML = '';

            (medic.uploaded_docs || []).forEach(function(doc) {
                const anchor = document.createElement('a');
                anchor.href = '#';
                anchor.className = 'doc-badge btn-preview-doc';
                anchor.dataset.src = '/' + String(doc.path || '').replace(/^\/+/, '');
                anchor.dataset.title = doc.label || 'Dokumen';
                anchor.textContent = doc.label || 'Dokumen';
                uploadedDocsEl.appendChild(anchor);
            });

            docsPanel.classList.remove('hidden');
            docsEmpty.classList.add('hidden');
        }

        function chooseMedic(medic) {
            if (!medic) return;
            searchInput.value = medic.full_name || '';
            hiddenInput.value = String(medic.id || 0);
            if (selectedMedicId) selectedMedicId.value = String(medic.id || 0);
            renderSummary(medic);
            hideResults();
        }

        function refreshResults() {
            const keyword = (searchInput.value || '').toLowerCase().trim();
            if (keyword === '') {
                hideResults();
                return;
            }

            const terms = keyword.split(/\s+/).filter(Boolean);
            const matches = medics.filter(function(medic) {
                return terms.every(function(term) {
                    return (medic.search_blob || '').includes(term);
                });
            }).slice(0, 8);

            if (!matches.length) {
                resultsBox.innerHTML = '<div class="medic-result-empty">Tidak ada medis aktif yang cocok.</div>';
                resultsBox.classList.remove('hidden');
                return;
            }

            resultsBox.innerHTML = '';
            matches.forEach(function(medic) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'medic-result-item';
                button.innerHTML = `<strong>${medic.full_name}</strong><span>${medic.position_label} | ${medic.batch_label} | ${medic.citizen_id || 'Citizen ID belum ada'}</span>`;
                button.addEventListener('click', function() {
                    chooseMedic(medic);
                });
                resultsBox.appendChild(button);
            });
            resultsBox.classList.remove('hidden');
        }

        searchInput?.addEventListener('input', function() {
            hiddenInput.value = '';
            if (selectedMedicId) selectedMedicId.value = '';
            renderSummary(null);
            refreshResults();
        });
        searchInput?.addEventListener('focus', refreshResults);

        document.addEventListener('click', function(e) {
            if (e.target === searchInput || e.target.closest('#medicSearchResults')) return;
            hideResults();
        });

        clearButton?.addEventListener('click', function() {
            searchInput.value = '';
            hiddenInput.value = '';
            if (selectedMedicId) selectedMedicId.value = '';
            renderSummary(null);
            hideResults();
            searchInput.focus();
        });

        const initialMedic = medics.find(function(medic) {
            return String(medic.id || 0) === String(hiddenInput.value || '');
        });
        renderSummary(initialMedic || null);

        form?.addEventListener('submit', function(e) {
            if (!hiddenInput.value) {
                e.preventDefault();
                alert('Pilih medis aktif terlebih dahulu dari hasil autocomplete.');
                searchInput.focus();
            }
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('btnAddManagerOtherDoc');
        const container = document.getElementById('managerOtherDocsContainer');
        if (!btn || !container) return;

        let newIndex = 1;
        btn.addEventListener('click', function() {
            const id = 'manager_other_doc_' + newIndex++;
            const row = document.createElement('div');
            row.className = 'academy-doc-row';
            row.setAttribute('data-row', 'other-doc');
            row.innerHTML = `
                <div class="row-form-2 academy-grid">
                    <div>
                        <label>Nama File Lainnya</label>
                        <input type="text" name="academy_doc_name[]" list="managerOtherDocSuggestions" autocomplete="off" placeholder="Contoh: Surat Kontrak Kerja atau Academy">
                    </div>
                    <div>
                        <label>File</label>
                        <div class="doc-upload-input doc-upload-input-reset">
                            <label for="${id}" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih file</strong>
                                    <small>PNG atau JPG</small>
                                </span>
                            </label>
                            <input type="file" id="${id}" name="academy_doc_file[]" accept="image/png,image/jpeg" class="hidden">
                            <div class="file-selected-name" data-for="${id}"></div>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(row);
        });
    });
</script>

<style>
    .medic-picker { display: grid; gap: 0.75rem; }
    .medic-search-shell { display: flex; gap: 0.75rem; align-items: center; }
    .medic-search-shell .form-control { flex: 1; }
    .medic-search-results { border: 1px solid rgba(148, 163, 184, 0.3); border-radius: 1rem; background: #fff; box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08); max-height: 320px; overflow-y: auto; padding: 0.5rem; }
    .medic-result-item { width: 100%; display: grid; gap: 0.2rem; text-align: left; border: 0; background: transparent; border-radius: 0.85rem; padding: 0.8rem 0.9rem; color: #1e293b; }
    .medic-result-item:hover { background: #eff6ff; }
    .medic-result-item span, .medic-result-empty { color: #64748b; font-size: 0.92rem; }
    .medic-result-empty { padding: 0.8rem 0.9rem; }
    .medic-summary { margin-top: 1rem; border: 1px solid rgba(14, 165, 233, 0.15); border-radius: 1rem; background: linear-gradient(180deg, rgba(240, 249, 255, 0.85), rgba(248, 250, 252, 0.95)); padding: 1rem 1.1rem; display: grid; gap: 0.35rem; }
    .medic-summary-head, .medic-summary-meta { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .doc-status-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .doc-status-card { border: 1px solid rgba(148, 163, 184, 0.22); border-radius: 1rem; padding: 1rem; background: #fff; }
    .doc-status-card-title { font-weight: 700; color: #0f172a; margin-bottom: 0.8rem; }
    .doc-status-list { display: flex; flex-wrap: wrap; gap: 0.65rem; min-height: 2rem; }
    .empty-state { color: #64748b; }
    @media (max-width: 768px) { .medic-search-shell { flex-direction: column; align-items: stretch; } }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>

