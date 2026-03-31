<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Secretary'], '/dashboard/index.php');

$pageTitle = 'Rekap Surat Rahasia';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryConfidentialStatusMeta(string $status): array
{
    return match ($status) {
        'sealed' => ['label' => 'SEALED', 'class' => 'badge-danger'],
        'distributed' => ['label' => 'DISTRIBUTED', 'class' => 'badge-success'],
        'archived' => ['label' => 'ARCHIVED', 'class' => 'badge-muted'],
        default => ['label' => strtoupper($status), 'class' => 'badge-warning'],
    };
}

function secretaryConfidentialLevelMeta(string $level): array
{
    return match ($level) {
        'top_secret' => ['label' => 'TOP SECRET', 'class' => 'badge-danger'],
        'secret' => ['label' => 'SECRET', 'class' => 'badge-warning'],
        default => ['label' => 'CONFIDENTIAL', 'class' => 'badge-muted'],
    };
}

function secretaryConfidentialTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$table] = (bool) $stmt->fetchColumn();

    return $cache[$table];
}

function secretaryConfidentialGroupAttachments(array $rows, string $foreignKey): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $key = (int) ($row[$foreignKey] ?? 0);
        if ($key <= 0) {
            continue;
        }

        $grouped[$key][] = $row;
    }

    return $grouped;
}

$summary = ['logged' => 0, 'sealed' => 0, 'distributed' => 0, 'archived' => 0];
$rows = [];
$attachmentsMap = [];
$hasAttachmentTable = false;

try {
    $hasAttachmentTable = secretaryConfidentialTableExists($pdo, 'secretary_confidential_letter_attachments');
    $summary['logged'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'logged'")->fetchColumn();
    $summary['sealed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'sealed'")->fetchColumn();
    $summary['distributed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'distributed'")->fetchColumn();
    $summary['archived'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'archived'")->fetchColumn();

    $rows = $pdo->query("
        SELECT *
        FROM secretary_confidential_letters
        ORDER BY letter_date DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($hasAttachmentTable && !empty($rows)) {
        $letterIds = array_values(array_filter(array_map(static fn($row) => (int) ($row['id'] ?? 0), $rows)));
        if (!empty($letterIds)) {
            $placeholders = implode(',', array_fill(0, count($letterIds), '?'));
            $stmt = $pdo->prepare("
                SELECT *
                FROM secretary_confidential_letter_attachments
                WHERE letter_id IN ($placeholders)
                ORDER BY letter_id ASC, sort_order ASC, id ASC
            ");
            $stmt->execute($letterIds);
            $attachmentsMap = secretaryConfidentialGroupAttachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'letter_id');
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Tabel Secretary belum siap. Jalankan SQL `docs/sql/07_2026-03-11_secretary_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Register surat rahasia, arah surat, level kerahasiaan, dan status distribusinya.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php if (!$hasAttachmentTable): ?>
            <div class="alert alert-warning">Fitur lampiran surat rahasia memerlukan SQL <code>docs/sql/15_2026-03-31_secretary_attachments.sql</code>.</div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Baru Tercatat', 'value' => $summary['logged'], 'icon' => 'document-text', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Sealed', 'value' => $summary['sealed'], 'icon' => 'lock-closed', 'tone' => 'danger']);
            ems_component('ui/statistic-card', ['label' => 'Distributed', 'value' => $summary['distributed'], 'icon' => 'paper-airplane', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Archived', 'value' => $summary['archived'], 'icon' => 'inbox', 'tone' => 'muted']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Surat Rahasia</div>
                <p class="meta-text mb-4">Register surat masuk/keluar yang butuh kendali distribusi dan kerahasiaan.</p>

                <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_confidential_letter">
                    <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">

                    <label>Nomor Surat</label>
                    <div class="flex gap-2">
                        <input type="text" name="register_code" id="addConfidentialCode" maxlength="100" placeholder="Otomatis muncul setelah field wajib lengkap">
                        <button type="button" class="btn-secondary whitespace-nowrap" id="addConfidentialCodeAutoBtn">Auto</button>
                    </div>
                    <div class="meta-text-xs mt-1">Nomor surat otomatis bisa diedit manual.</div>

                    <div class="row-form-2">
                        <div>
                            <label>Nomor Referensi</label>
                            <input type="text" name="reference_number" required>
                        </div>
                        <div>
                            <label>Arah Surat</label>
                            <select name="letter_direction" id="addConfidentialDirection">
                                <option value="incoming">Incoming</option>
                                <option value="outgoing">Outgoing</option>
                            </select>
                        </div>
                    </div>

                    <label>Subjek Surat</label>
                    <input type="text" name="subject" required>

                    <label>Pengirim / Penerima Utama</label>
                    <input type="text" name="counterparty_name" id="addConfidentialCounterpartyName" required>

                    <div class="row-form-2">
                        <div>
                            <label>Level Kerahasiaan</label>
                            <select name="confidentiality_level">
                                <option value="confidential">Confidential</option>
                                <option value="secret">Secret</option>
                                <option value="top_secret">Top Secret</option>
                            </select>
                        </div>
                        <div>
                            <label>Tanggal Surat</label>
                            <input type="date" name="letter_date" id="addConfidentialDate" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="logged">Logged</option>
                        <option value="sealed">Sealed</option>
                        <option value="distributed">Distributed</option>
                        <option value="archived">Archived</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="doc-upload-wrapper m-0">
                        <div class="doc-upload-header">
                            <label class="text-sm font-semibold text-slate-900">Lampiran Surat Rahasia</label>
                            <span class="badge-muted-mini">Opsional, bisa beberapa file</span>
                        </div>
                        <div class="doc-upload-input">
                            <label for="confidentialAttachments" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih lampiran</strong>
                                    <small>JPG / PNG, multi file</small>
                                </span>
                            </label>
                            <input type="file" id="confidentialAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                            <div class="file-selected-name" data-for="confidentialAttachments"></div>
                            <div id="confidentialAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                        </div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('inbox', 'h-4 w-4') ?>
                            <span>Simpan Register</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Surat Rahasia</div>
                <p class="meta-text mb-4">Pantau surat rahasia berdasarkan nomor referensi, level, dan status distribusi.</p>

                <div class="table-wrapper">
                    <table id="secretaryConfidentialTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Nomor Surat</th>
                                <th>Referensi</th>
                                <th>Subjek</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Lampiran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $statusMeta = secretaryConfidentialStatusMeta((string) $row['status']); ?>
                                <?php $levelMeta = secretaryConfidentialLevelMeta((string) $row['confidentiality_level']); ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['register_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['reference_number'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(strtoupper((string) $row['letter_direction']), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['counterparty_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><span class="<?= htmlspecialchars($levelMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($levelMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <?php $attachments = $attachmentsMap[(int) $row['id']] ?? []; ?>
                                        <?php if (!empty($attachments)): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <a href="#"
                                                        class="doc-badge btn-preview-doc"
                                                        data-src="/<?= htmlspecialchars(ltrim((string) $attachment['file_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-title="<?= htmlspecialchars((string) ($attachment['file_name'] ?: ('Lampiran ' . $row['register_code'])), ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= ems_icon('paper-clip', 'h-4 w-4') ?>
                                                        <span><?= htmlspecialchars((string) ($attachment['file_name'] ?: 'Lampiran'), ENT_QUOTES, 'UTF-8') ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="meta-text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <form method="POST" action="secretary_action.php" class="inline-flex gap-2 items-center">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="update_confidential_status">
                                                <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">
                                                <input type="hidden" name="letter_id" value="<?= (int) $row['id'] ?>">
                                                <select name="status">
                                                    <?php foreach (['logged', 'sealed', 'distributed', 'archived'] as $status): ?>
                                                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn-secondary btn-sm">Status</button>
                                            </form>

                                            <form method="POST" action="secretary_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus permanen dokumen surat rahasia ini?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_confidential_letter">
                                                <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">
                                                <input type="hidden" name="letter_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm">
                                                    <?= ems_icon('trash', 'h-4 w-4') ?>
                                                    <span>Hapus Permanen</span>
                                                </button>
                                            </form>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const generateCodeUrl = '<?= htmlspecialchars(ems_url('/ajax/generate_surat_code.php'), ENT_QUOTES, 'UTF-8') ?>';

    function debounce(fn, delay) {
        let timer = null;
        return function () {
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(null, args);
            }, delay);
        };
    }

    function setupAutoCode(options) {
        const codeInput = document.getElementById(options.codeInputId);
        const autoButton = document.getElementById(options.autoButtonId);
        const requiredInputs = options.requiredInputIds.map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);
        const watchedInputs = options.watchedInputIds.map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);

        if (!codeInput || !requiredInputs.length) {
            return;
        }

        codeInput.dataset.autoMode = 'true';
        codeInput.dataset.generatedCode = codeInput.value || '';

        async function refreshCode(forceAuto) {
            const requiredReady = requiredInputs.every(function (input) {
                return String(input.value || '').trim() !== '';
            });

            if (!requiredReady) {
                if (forceAuto) {
                    codeInput.value = '';
                    codeInput.dataset.generatedCode = '';
                }
                return;
            }

            const url = new URL(generateCodeUrl, window.location.origin);
            url.searchParams.set('type', options.type);

            const dateInput = document.getElementById(options.dateInputId);
            if (dateInput) {
                url.searchParams.set('date', (dateInput.value || '').trim());
            }

            const counterpartyInput = document.getElementById(options.counterpartyInputId);
            if (counterpartyInput) {
                url.searchParams.set('counterparty_name', (counterpartyInput.value || '').trim());
            }

            const directionInput = document.getElementById(options.directionInputId);
            if (directionInput) {
                url.searchParams.set('letter_direction', (directionInput.value || '').trim());
            }

            const response = await fetch(url.toString(), { credentials: 'same-origin' });
            const payload = await response.json();
            if (!payload.success) {
                return;
            }

            const currentValue = codeInput.value.trim();
            const previousGenerated = codeInput.dataset.generatedCode || '';
            const shouldApply = forceAuto || codeInput.dataset.autoMode === 'true' || currentValue === '' || currentValue === previousGenerated;

            codeInput.dataset.generatedCode = payload.code || '';
            if (shouldApply) {
                codeInput.value = payload.code || '';
                codeInput.dataset.autoMode = 'true';
            }
        }

        const debouncedRefresh = debounce(function () {
            refreshCode(false).catch(function () {});
        }, 250);

        watchedInputs.forEach(function (input) {
            input.addEventListener('input', debouncedRefresh);
            input.addEventListener('change', debouncedRefresh);
        });

        codeInput.addEventListener('input', function () {
            const currentValue = codeInput.value.trim();
            codeInput.dataset.autoMode = (currentValue === '' || currentValue === (codeInput.dataset.generatedCode || '')) ? 'true' : 'false';
        });

        if (autoButton) {
            autoButton.addEventListener('click', function () {
                codeInput.dataset.autoMode = 'true';
                refreshCode(true).catch(function () {});
            });
        }

        refreshCode(false).catch(function () {});
    }

    function setupMultiImagePreview(inputId, previewId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
        if (!input || !preview || !nameBox) {
            return;
        }

        let objectUrls = [];

        function clearPreview() {
            objectUrls.forEach(function (url) {
                try { URL.revokeObjectURL(url); } catch (_) {}
            });
            objectUrls = [];
            preview.innerHTML = '';
            nameBox.textContent = '';
            nameBox.classList.add('hidden');
        }

        input.addEventListener('change', function () {
            clearPreview();

            const files = Array.from(this.files || []);
            if (!files.length) {
                return;
            }

            nameBox.textContent = files.length + ' file dipilih';
            nameBox.classList.remove('hidden');

            files.forEach(function (file) {
                if (!String(file.type || '').startsWith('image/')) {
                    return;
                }

                const url = URL.createObjectURL(file);
                objectUrls.push(url);

                const item = document.createElement('div');
                item.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-2';
                item.innerHTML = `
                    <img src="${url}" class="identity-photo h-28 w-full rounded-xl object-cover cursor-zoom-in" alt="Preview lampiran">
                    <div class="mt-2 truncate text-xs text-slate-600">${file.name}</div>
                `;
                preview.appendChild(item);
            });
        });
    }

    if (window.jQuery && $.fn.DataTable) {
        $('#secretaryConfidentialTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }

    setupAutoCode({
        type: 'confidential',
        codeInputId: 'addConfidentialCode',
        autoButtonId: 'addConfidentialCodeAutoBtn',
        dateInputId: 'addConfidentialDate',
        counterpartyInputId: 'addConfidentialCounterpartyName',
        directionInputId: 'addConfidentialDirection',
        requiredInputIds: ['addConfidentialDate', 'addConfidentialCounterpartyName'],
        watchedInputIds: ['addConfidentialDate', 'addConfidentialCounterpartyName', 'addConfidentialDirection']
    });

    setupMultiImagePreview('confidentialAttachments', 'confidentialAttachmentsPreview');

    document.querySelectorAll('.js-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus data ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
