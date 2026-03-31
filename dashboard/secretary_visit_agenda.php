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

$pageTitle = 'Agenda Kunjungan Divisi';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryAgendaStatusMeta(string $status): array
{
    return match ($status) {
        'ongoing' => ['label' => 'ONGOING', 'class' => 'badge-counter'],
        'completed' => ['label' => 'COMPLETED', 'class' => 'badge-success'],
        'cancelled' => ['label' => 'CANCELLED', 'class' => 'badge-danger'],
        default => ['label' => strtoupper($status), 'class' => 'badge-muted'],
    };
}

function secretaryAgendaTableExists(PDO $pdo, string $table): bool
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

function secretaryAgendaGroupAttachments(array $rows, string $foreignKey): array
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

$summary = ['total' => 0, 'scheduled' => 0, 'today' => 0, 'completed' => 0];
$agendas = [];
$attachmentsMap = [];
$hasAttachmentTable = false;

try {
    $hasAttachmentTable = secretaryAgendaTableExists($pdo, 'secretary_visit_agenda_attachments');
    $summary['total'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas")->fetchColumn();
    $summary['scheduled'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas WHERE status IN ('scheduled', 'ongoing')")->fetchColumn();
    $summary['today'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas WHERE visit_date = CURDATE()")->fetchColumn();
    $summary['completed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas WHERE status = 'completed'")->fetchColumn();

    $agendas = $pdo->query("
        SELECT
            sva.*,
            pic.full_name AS pic_name
        FROM secretary_visit_agendas sva
        INNER JOIN user_rh pic ON pic.id = sva.pic_user_id
        ORDER BY sva.visit_date DESC, sva.visit_time DESC, sva.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($hasAttachmentTable && !empty($agendas)) {
        $agendaIds = array_values(array_filter(array_map(static fn($row) => (int) ($row['id'] ?? 0), $agendas)));
        if (!empty($agendaIds)) {
            $placeholders = implode(',', array_fill(0, count($agendaIds), '?'));
            $stmt = $pdo->prepare("
                SELECT *
                FROM secretary_visit_agenda_attachments
                WHERE agenda_id IN ($placeholders)
                ORDER BY agenda_id ASC, sort_order ASC, id ASC
            ");
            $stmt->execute($agendaIds);
            $attachmentsMap = secretaryAgendaGroupAttachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'agenda_id');
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
        <p class="page-subtitle">Pendataan jadwal kunjungan divisi, tamu, lokasi, dan PIC internal.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php if (!$hasAttachmentTable): ?>
            <div class="alert alert-warning">Fitur lampiran agenda kunjungan memerlukan SQL <code>docs/sql/15_2026-03-31_secretary_attachments.sql</code>.</div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Total Agenda', 'value' => $summary['total'], 'icon' => 'calendar-days', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Agenda Aktif', 'value' => $summary['scheduled'], 'icon' => 'clock', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Agenda Hari Ini', 'value' => $summary['today'], 'icon' => 'ticket', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Kunjungan Selesai', 'value' => $summary['completed'], 'icon' => 'check-circle', 'tone' => 'muted']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Agenda Kunjungan</div>
                <p class="meta-text mb-4">Simpan jadwal kunjungan divisi dan PIC internal yang bertanggung jawab.</p>

                <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_visit_agenda">
                    <input type="hidden" name="redirect_to" value="secretary_visit_agenda.php">

                    <label>Nomor Surat</label>
                    <div class="flex gap-2">
                        <input type="text" name="agenda_code" id="addVisitAgendaCode" maxlength="100" placeholder="Otomatis muncul setelah field wajib lengkap">
                        <button type="button" class="btn-secondary whitespace-nowrap" id="addVisitAgendaCodeAutoBtn">Auto</button>
                    </div>
                    <div class="meta-text-xs mt-1">Nomor surat otomatis bisa diedit manual.</div>

                    <label>Nama Tamu / Pengunjung</label>
                    <input type="text" name="visitor_name" required>

                    <label>Instansi / Asal</label>
                    <input type="text" name="origin_name" id="addVisitAgendaOriginName">

                    <label>Tujuan Kunjungan</label>
                    <textarea name="visit_purpose" rows="3" required></textarea>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Kunjungan</label>
                            <input type="date" name="visit_date" id="addVisitAgendaDate" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Jam Kunjungan</label>
                            <input type="time" name="visit_time" required>
                        </div>
                    </div>

                    <label>Lokasi</label>
                    <input type="text" name="location" required>

                    <label>PIC Internal</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                        <input type="text" data-user-autocomplete-input placeholder="Ketik nama PIC..." required>
                        <input type="hidden" name="pic_user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="scheduled">Scheduled</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="doc-upload-wrapper m-0">
                        <div class="doc-upload-header">
                            <label class="text-sm font-semibold text-slate-900">Lampiran Agenda</label>
                            <span class="badge-muted-mini">Opsional, bisa beberapa file</span>
                        </div>
                        <div class="doc-upload-input">
                            <label for="visitAgendaAttachments" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih lampiran</strong>
                                    <small>JPG / PNG, multi file</small>
                                </span>
                            </label>
                            <input type="file" id="visitAgendaAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                            <div class="file-selected-name" data-for="visitAgendaAttachments"></div>
                            <div id="visitAgendaAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                        </div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Agenda</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Agenda Kunjungan</div>
                <p class="meta-text mb-4">Monitoring agenda kunjungan yang sedang dijadwalkan maupun sudah selesai.</p>

                <div class="table-wrapper">
                    <table id="secretaryAgendaTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Nomor Surat</th>
                                <th>Tamu</th>
                                <th>Jadwal</th>
                                <th>PIC</th>
                                <th>Status</th>
                                <th>Lampiran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agendas as $agenda): ?>
                                <?php $statusMeta = secretaryAgendaStatusMeta((string) $agenda['status']); ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $agenda['agenda_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $agenda['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($agenda['origin_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $agenda['visit_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(substr((string) $agenda['visit_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars((string) $agenda['location'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) $agenda['pic_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <?php $attachments = $attachmentsMap[(int) $agenda['id']] ?? []; ?>
                                        <?php if (!empty($attachments)): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <a href="#"
                                                        class="doc-badge btn-preview-doc"
                                                        data-src="/<?= htmlspecialchars(ltrim((string) $attachment['file_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-title="<?= htmlspecialchars((string) ($attachment['file_name'] ?: ('Lampiran ' . $agenda['agenda_code'])), ENT_QUOTES, 'UTF-8') ?>">
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
                                        <form method="POST" action="secretary_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_visit_status">
                                            <input type="hidden" name="redirect_to" value="secretary_visit_agenda.php">
                                            <input type="hidden" name="agenda_id" value="<?= (int) $agenda['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['scheduled', 'ongoing', 'completed', 'cancelled'] as $status): ?>
                                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $agenda['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8') ?></option>
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

            const institutionInput = document.getElementById(options.institutionInputId);
            if (institutionInput) {
                url.searchParams.set('institution_name', (institutionInput.value || '').trim());
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
        $('#secretaryAgendaTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }

    setupAutoCode({
        type: 'visit_agenda',
        codeInputId: 'addVisitAgendaCode',
        autoButtonId: 'addVisitAgendaCodeAutoBtn',
        dateInputId: 'addVisitAgendaDate',
        institutionInputId: 'addVisitAgendaOriginName',
        requiredInputIds: ['addVisitAgendaDate'],
        watchedInputIds: ['addVisitAgendaDate', 'addVisitAgendaOriginName']
    });

    setupMultiImagePreview('visitAgendaAttachments', 'visitAgendaAttachmentsPreview');
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
