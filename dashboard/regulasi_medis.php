<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// Block access for users on cuti
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

/* ===============================
   ROLE GUARD (NON-STAFF)
   =============================== */
$userRole = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($userRole === 'staff') {
    http_response_code(403);
    die('Akses ditolak');
}

$pageTitle = 'Regulasi Medis';
$hasCashAmount = ems_column_exists($pdo, 'medical_regulations', 'cash_amount');
$hasCashMaxAmount = ems_column_exists($pdo, 'medical_regulations', 'cash_max_amount');
$hasBillingAmount = ems_column_exists($pdo, 'medical_regulations', 'billing_amount');
$hasBillingMaxAmount = ems_column_exists($pdo, 'medical_regulations', 'billing_max_amount');

function medicalRegulationPartLabel(string $label, int $min, int $max): ?string
{
    if ($min <= 0 && $max <= 0) {
        return null;
    }

    if ($max > 0) {
        return $label . ' $' . number_format($min) . ' - $' . number_format($max);
    }

    return $label . ' $' . number_format($min);
}

function medicalRegulationDisplayPrice(array $row): string
{
    $priceType = strtoupper((string) ($row['price_type'] ?? 'FIXED'));
    $priceMin = (int) ($row['price_min'] ?? 0);
    $priceMax = (int) ($row['price_max'] ?? 0);
    $cashAmount = (int) ($row['cash_amount'] ?? 0);
    $cashMaxAmount = (int) ($row['cash_max_amount'] ?? 0);
    $billingAmount = (int) ($row['billing_amount'] ?? 0);
    $billingMaxAmount = (int) ($row['billing_max_amount'] ?? 0);

    $parts = array_values(array_filter([
        medicalRegulationPartLabel('Cash', $cashAmount, $cashMaxAmount),
        medicalRegulationPartLabel('Billing', $billingAmount, $billingMaxAmount),
    ]));

    if ($parts !== []) {
        return implode(' + ', $parts);
    }

    if ($priceType === 'RANGE') {
        return '$' . number_format($priceMin) . ' - $' . number_format($priceMax);
    }

    return '$' . number_format($priceMin);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_regulation') {
            $cashAmount = $hasCashAmount ? max(0, (int) ($_POST['cash_amount'] ?? 0)) : 0;
            $cashMaxAmount = $hasCashMaxAmount ? max(0, (int) ($_POST['cash_max_amount'] ?? 0)) : 0;
            $billingAmount = $hasBillingAmount ? max(0, (int) ($_POST['billing_amount'] ?? 0)) : 0;
            $billingMaxAmount = $hasBillingMaxAmount ? max(0, (int) ($_POST['billing_max_amount'] ?? 0)) : 0;
            $legacyCashAmount = max(0, (int) ($_POST['cash_amount'] ?? 0));
            $legacyCashMaxAmount = max(0, (int) ($_POST['cash_max_amount'] ?? 0));
            $legacyBillingAmount = max(0, (int) ($_POST['billing_amount'] ?? 0));
            $legacyBillingMaxAmount = max(0, (int) ($_POST['billing_max_amount'] ?? 0));
            $effectiveCashAmount = $hasCashAmount ? $cashAmount : $legacyCashAmount;
            $effectiveCashMaxAmount = $hasCashMaxAmount ? $cashMaxAmount : $legacyCashMaxAmount;
            $effectiveBillingAmount = $hasBillingAmount ? $billingAmount : $legacyBillingAmount;
            $effectiveBillingMaxAmount = $hasBillingMaxAmount ? $billingMaxAmount : $legacyBillingMaxAmount;
            $paymentType = 'CASH';
            $priceType = ($effectiveCashMaxAmount > 0 || $effectiveBillingMaxAmount > 0) ? 'RANGE' : 'FIXED';
            $priceMin = $effectiveCashAmount + $effectiveBillingAmount;
            $priceMax = 0;

            if ($priceType === 'RANGE') {
                if ($effectiveCashAmount > 0 && $effectiveCashMaxAmount === 0) {
                    $effectiveCashMaxAmount = $effectiveCashAmount;
                    if ($hasCashMaxAmount) {
                        $cashMaxAmount = $effectiveCashMaxAmount;
                    }
                }
                if ($effectiveBillingAmount > 0 && $effectiveBillingMaxAmount === 0) {
                    $effectiveBillingMaxAmount = $effectiveBillingAmount;
                    if ($hasBillingMaxAmount) {
                        $billingMaxAmount = $effectiveBillingMaxAmount;
                    }
                }
                if ($effectiveCashMaxAmount > 0 && $effectiveCashMaxAmount < $effectiveCashAmount) {
                    throw new RuntimeException('Harga max cash tidak boleh lebih kecil dari harga min cash.');
                }
                if ($effectiveBillingMaxAmount > 0 && $effectiveBillingMaxAmount < $effectiveBillingAmount) {
                    throw new RuntimeException('Harga max billing tidak boleh lebih kecil dari harga min billing.');
                }
                $priceMax = $effectiveCashMaxAmount + $effectiveBillingMaxAmount;
            } else {
                $cashMaxAmount = 0;
                $billingMaxAmount = 0;
                $effectiveCashMaxAmount = 0;
                $effectiveBillingMaxAmount = 0;
            }

            $paymentType = ($effectiveBillingAmount > 0 || $effectiveBillingMaxAmount > 0) ? 'BILLING' : 'CASH';

            $stmt = $pdo->prepare("
                UPDATE medical_regulations
                SET
                    category = ?,
                    name = ?,
                    location = ?,
                    price_type = ?,
                    price_min = ?,
                    price_max = ?,
                    " . ($hasCashAmount ? "cash_amount = ?," : "") . "
                    " . ($hasCashMaxAmount ? "cash_max_amount = ?," : "") . "
                    " . ($hasBillingAmount ? "billing_amount = ?," : "") . "
                    " . ($hasBillingMaxAmount ? "billing_max_amount = ?," : "") . "
                    payment_type = ?,
                    duration_minutes = ?,
                    notes = ?,
                    is_active = ?
                WHERE id = ?
            ");

            $stmt->execute([
                trim((string) ($_POST['category'] ?? '')),
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['location'] ?? '')) !== '' ? trim((string) $_POST['location']) : null,
                $priceType,
                $priceMin,
                $priceMax,
                ...($hasCashAmount ? [$cashAmount] : []),
                ...($hasCashMaxAmount ? [$cashMaxAmount] : []),
                ...($hasBillingAmount ? [$billingAmount] : []),
                ...($hasBillingMaxAmount ? [$billingMaxAmount] : []),
                $paymentType,
                trim((string) ($_POST['duration_minutes'] ?? '')) !== '' ? (int) $_POST['duration_minutes'] : null,
                trim((string) ($_POST['notes'] ?? '')) !== '' ? trim((string) $_POST['notes']) : null,
                isset($_POST['is_active']) ? 1 : 0,
                (int) ($_POST['id'] ?? 0),
            ]);

            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'create_regulation') {
            $category = trim((string) ($_POST['category'] ?? ''));
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $cashAmount = $hasCashAmount ? max(0, (int) ($_POST['cash_amount'] ?? 0)) : 0;
            $cashMaxAmount = $hasCashMaxAmount ? max(0, (int) ($_POST['cash_max_amount'] ?? 0)) : 0;
            $billingAmount = $hasBillingAmount ? max(0, (int) ($_POST['billing_amount'] ?? 0)) : 0;
            $billingMaxAmount = $hasBillingMaxAmount ? max(0, (int) ($_POST['billing_max_amount'] ?? 0)) : 0;
            $legacyCashAmount = max(0, (int) ($_POST['cash_amount'] ?? 0));
            $legacyCashMaxAmount = max(0, (int) ($_POST['cash_max_amount'] ?? 0));
            $legacyBillingAmount = max(0, (int) ($_POST['billing_amount'] ?? 0));
            $legacyBillingMaxAmount = max(0, (int) ($_POST['billing_max_amount'] ?? 0));
            $effectiveCashAmount = $hasCashAmount ? $cashAmount : $legacyCashAmount;
            $effectiveCashMaxAmount = $hasCashMaxAmount ? $cashMaxAmount : $legacyCashMaxAmount;
            $effectiveBillingAmount = $hasBillingAmount ? $billingAmount : $legacyBillingAmount;
            $effectiveBillingMaxAmount = $hasBillingMaxAmount ? $billingMaxAmount : $legacyBillingMaxAmount;
            $paymentType = 'CASH';
            $priceType = ($effectiveCashMaxAmount > 0 || $effectiveBillingMaxAmount > 0) ? 'RANGE' : 'FIXED';
            $priceMin = $effectiveCashAmount + $effectiveBillingAmount;
            $priceMax = 0;
            $durationMinutes = trim((string) ($_POST['duration_minutes'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($category === '' || $code === '' || $name === '') {
                throw new RuntimeException('Kategori, kode, dan nama wajib diisi.');
            }

            if (!preg_match('/^[A-Z0-9_]+$/', $code)) {
                throw new RuntimeException('Kode regulasi hanya boleh berisi huruf kapital, angka, dan underscore.');
            }

            if ($priceType === 'RANGE') {
                if ($effectiveCashAmount > 0 && $effectiveCashMaxAmount === 0) {
                    $effectiveCashMaxAmount = $effectiveCashAmount;
                    if ($hasCashMaxAmount) {
                        $cashMaxAmount = $effectiveCashMaxAmount;
                    }
                }
                if ($effectiveBillingAmount > 0 && $effectiveBillingMaxAmount === 0) {
                    $effectiveBillingMaxAmount = $effectiveBillingAmount;
                    if ($hasBillingMaxAmount) {
                        $billingMaxAmount = $effectiveBillingMaxAmount;
                    }
                }
                if ($effectiveCashMaxAmount > 0 && $effectiveCashMaxAmount < $effectiveCashAmount) {
                    throw new RuntimeException('Harga max cash tidak boleh lebih kecil dari harga min cash.');
                }
                if ($effectiveBillingMaxAmount > 0 && $effectiveBillingMaxAmount < $effectiveBillingAmount) {
                    throw new RuntimeException('Harga max billing tidak boleh lebih kecil dari harga min billing.');
                }
                $priceMax = $effectiveCashMaxAmount + $effectiveBillingMaxAmount;
            } else {
                $cashMaxAmount = 0;
                $billingMaxAmount = 0;
                $effectiveCashMaxAmount = 0;
                $effectiveBillingMaxAmount = 0;
            }

            $paymentType = ($effectiveBillingAmount > 0 || $effectiveBillingMaxAmount > 0) ? 'BILLING' : 'CASH';

            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM medical_regulations WHERE code = ?");
            $existsStmt->execute([$code]);
            if ((int) $existsStmt->fetchColumn() > 0) {
                throw new RuntimeException('Kode regulasi sudah digunakan.');
            }

            $insertColumns = [
                'category',
                'code',
                'name',
                'location',
                'price_type',
                'price_min',
                'price_max',
            ];
            $insertValues = [
                $category,
                $code,
                $name,
                $location !== '' ? $location : null,
                $priceType,
                $priceMin,
                $priceMax,
            ];

            if ($hasCashAmount) {
                $insertColumns[] = 'cash_amount';
                $insertValues[] = $cashAmount;
            }
            if ($hasCashMaxAmount) {
                $insertColumns[] = 'cash_max_amount';
                $insertValues[] = $cashMaxAmount;
            }
            if ($hasBillingAmount) {
                $insertColumns[] = 'billing_amount';
                $insertValues[] = $billingAmount;
            }
            if ($hasBillingMaxAmount) {
                $insertColumns[] = 'billing_max_amount';
                $insertValues[] = $billingMaxAmount;
            }

            $insertColumns = array_merge($insertColumns, [
                'payment_type',
                'duration_minutes',
                'notes',
                'is_active',
            ]);
            $insertValues = array_merge($insertValues, [
                $paymentType,
                $durationMinutes !== '' ? (int) $durationMinutes : null,
                $notes !== '' ? $notes : null,
                $isActive,
            ]);

            $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
            $stmt = $pdo->prepare("
                INSERT INTO medical_regulations (" . implode(', ', $insertColumns) . ")
                VALUES (" . $placeholders . ")
            ");

            $stmt->execute($insertValues);

            echo json_encode([
                'success' => true,
                'item' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'category' => $category,
                    'code' => $code,
                    'name' => $name,
                    'location' => $location,
                    'price_type' => $priceType,
                    'price_min' => $priceMin,
                    'price_max' => $priceMax,
                    'cash_amount' => $effectiveCashAmount,
                    'cash_max_amount' => $effectiveCashMaxAmount,
                    'billing_amount' => $effectiveBillingAmount,
                    'billing_max_amount' => $effectiveBillingMaxAmount,
                    'payment_type' => $paymentType,
                    'duration_minutes' => $durationMinutes,
                    'notes' => $notes,
                    'is_active' => $isActive,
                ],
            ]);
            exit;
        }

        throw new RuntimeException('Aksi tidak valid.');
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
        exit;
    }
}

/* ===============================
   LOAD MEDICAL REGULATIONS
   =============================== */
$regs = $pdo->query("
    SELECT
        id, category, code, name, location,
        price_type, price_min, price_max,
        " . ($hasCashAmount ? "cash_amount," : "0 AS cash_amount,") . "
        " . ($hasCashMaxAmount ? "cash_max_amount," : "0 AS cash_max_amount,") . "
        " . ($hasBillingAmount ? "billing_amount," : "0 AS billing_amount,") . "
        " . ($hasBillingMaxAmount ? "billing_max_amount," : "0 AS billing_max_amount,") . "
        payment_type, duration_minutes,
        notes, is_active
    FROM medical_regulations
    ORDER BY category, code
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Regulasi Medis</h1>
        <p class="page-subtitle">Manajemen regulasi layanan medis</p>

        <div id="regAlert"></div>

        <div class="card">
            <div class="card-header card-header-actions card-header-flex">
                <div class="card-header-actions-title">
                    <?= ems_icon('document-text', 'h-5 w-5') ?> Regulasi Medis
                </div>
                <button type="button" id="openAddRegModal" class="btn-success">
                    <?= ems_icon('plus', 'h-4 w-4') ?> <span>Tambah Regulasi Baru</span>
                </button>
            </div>

            <div class="table-wrapper">
                <table id="regTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Harga</th>
                            <th>Status</th>
                            <th width="80">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regs as $r): ?>
                            <tr
                                data-id="<?= $r['id'] ?>"
                                data-category="<?= htmlspecialchars($r['category'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                                data-location="<?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES) ?>"
                                data-price_type="<?= $r['price_type'] ?>"
                                data-min="<?= $r['price_min'] ?>"
                                data-max="<?= $r['price_max'] ?>"
                                data-cash="<?= (int) ($r['cash_amount'] ?? 0) ?>"
                                data-cash-max="<?= (int) ($r['cash_max_amount'] ?? 0) ?>"
                                data-billing="<?= (int) ($r['billing_amount'] ?? 0) ?>"
                                data-billing-max="<?= (int) ($r['billing_max_amount'] ?? 0) ?>"
                                data-payment="<?= $r['payment_type'] ?>"
                                data-duration="<?= $r['duration_minutes'] ?>"
                                data-notes="<?= htmlspecialchars($r['notes'] ?? '', ENT_QUOTES) ?>"
                                data-active="<?= $r['is_active'] ?>">
                                <td><?= htmlspecialchars($r['category']) ?></td>
                                <td><?= htmlspecialchars($r['code']) ?></td>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td><?= htmlspecialchars(medicalRegulationDisplayPrice($r)) ?></td>
                                <td><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
                                <td>
                                    <button type="button" class="btn-secondary action-icon-btn btn-edit-reg" title="Ubah regulasi medis" aria-label="Ubah regulasi medis"><?= ems_icon('pencil-square', 'h-4 w-4') ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- ===============================
     MODAL EDIT REGULATION
     =============================== -->
<div id="editRegModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Ubah Regulasi Medis</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form id="editRegForm" class="form modal-form">
            <div class="modal-content">
                <input type="hidden" name="action" value="update_regulation">
                <input type="hidden" name="id" id="regId">

                <label>Kategori</label>
                <input type="text" name="category" id="regCategory" required>

                <label>Nama</label>
                <input type="text" name="name" id="regName" required>

                <label>Lokasi</label>
                <input type="text" name="location" id="regLocation">

                <input type="hidden" name="price_type" id="regPriceType" value="FIXED">

                <input type="hidden" name="price_min" id="regMin" value="0">
                <input type="hidden" name="price_max" id="regMax" value="0">

                <div class="reg-amount-stack">
                    <div class="reg-amount-card">
                        <div class="reg-amount-title">Amount Cash</div>
                        <div class="reg-dual-amounts">
                            <div>
                                <label>Input Harga Min</label>
                                <input type="number" name="cash_amount" id="regCashAmount" min="0" value="0">
                            </div>
                            <div>
                                <label>Input Harga Max</label>
                                <input type="number" name="cash_max_amount" id="regCashMaxAmount" min="0" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="reg-amount-card">
                        <div class="reg-amount-title">Amount Billing</div>
                        <div class="reg-dual-amounts">
                            <div>
                                <label>Input Harga Min</label>
                                <input type="number" name="billing_amount" id="regBillingAmount" min="0" value="0">
                            </div>
                            <div>
                                <label>Input Harga Max</label>
                                <input type="number" name="billing_max_amount" id="regBillingMaxAmount" min="0" value="0">
                            </div>
                        </div>
                    </div>
                </div>

                <label>Durasi (menit)</label>
                <input type="number" name="duration_minutes" id="regDuration">

                <label>Catatan</label>
                <textarea name="notes" id="regNotes"></textarea>

                <label class="checkbox-label checkbox-pill">
                    <input type="checkbox" name="is_active" id="regActive"> Aktif
                </label>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="addRegModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Tambah Regulasi Medis</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form id="addRegForm" class="form modal-form">
            <div class="modal-content">
                <input type="hidden" name="action" value="create_regulation">

                <label>Kategori</label>
                <input type="text" name="category" id="newRegCategory" required>

                <label>Kode</label>
                <input type="text" name="code" id="newRegCode" required placeholder="Otomatis terisi, tetap bisa diubah manual">

                <label>Nama</label>
                <input type="text" name="name" id="newRegName" required>

                <label>Lokasi</label>
                <input type="text" name="location" id="newRegLocation">

                <input type="hidden" name="price_type" id="newRegPriceType" value="FIXED">

                <input type="hidden" name="price_min" id="newRegMin" value="0">
                <input type="hidden" name="price_max" id="newRegMax" value="0">

                <div class="reg-amount-stack">
                    <div class="reg-amount-card">
                        <div class="reg-amount-title">Amount Cash</div>
                        <div class="reg-dual-amounts">
                            <div>
                                <label>Input Harga Min</label>
                                <input type="number" name="cash_amount" id="newRegCashAmount" min="0" value="0">
                            </div>
                            <div>
                                <label>Input Harga Max</label>
                                <input type="number" name="cash_max_amount" id="newRegCashMaxAmount" min="0" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="reg-amount-card">
                        <div class="reg-amount-title">Amount Billing</div>
                        <div class="reg-dual-amounts">
                            <div>
                                <label>Input Harga Min</label>
                                <input type="number" name="billing_amount" id="newRegBillingAmount" min="0" value="0">
                            </div>
                            <div>
                                <label>Input Harga Max</label>
                                <input type="number" name="billing_max_amount" id="newRegBillingMaxAmount" min="0" value="0">
                            </div>
                        </div>
                    </div>
                </div>

                <label>Durasi (menit)</label>
                <input type="number" name="duration_minutes" id="newRegDuration">

                <label>Catatan</label>
                <textarea name="notes" id="newRegNotes"></textarea>

                <label class="checkbox-label checkbox-pill">
                    <input type="checkbox" name="is_active" id="newRegActive" checked> Aktif
                </label>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const hasDataTable = !!(window.jQuery && jQuery.fn && typeof jQuery.fn.DataTable === 'function');
        const regTable = hasDataTable
            ? jQuery('#regTable').DataTable({
                pageLength: 10,
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            })
            : null;
        const regTableBody = document.querySelector('#regTable tbody');
        const requestUrl = window.emsUrl ? window.emsUrl('/dashboard/regulasi_medis.php') : 'regulasi_medis.php';

        const editRegModal = document.getElementById('editRegModal');
        const editRegForm = document.getElementById('editRegForm');
        const addRegModal = document.getElementById('addRegModal');
        const addRegForm = document.getElementById('addRegForm');
        const openAddRegModal = document.getElementById('openAddRegModal');

        const regId = document.getElementById('regId');
        const regCategory = document.getElementById('regCategory');
        const regName = document.getElementById('regName');
        const regLocation = document.getElementById('regLocation');
        const regPriceType = document.getElementById('regPriceType');
        const regCashAmount = document.getElementById('regCashAmount');
        const regCashMaxAmount = document.getElementById('regCashMaxAmount');
        const regBillingAmount = document.getElementById('regBillingAmount');
        const regBillingMaxAmount = document.getElementById('regBillingMaxAmount');
        const regMin = document.getElementById('regMin');
        const regMax = document.getElementById('regMax');
        const regDuration = document.getElementById('regDuration');
        const regNotes = document.getElementById('regNotes');
        const regActive = document.getElementById('regActive');

        const newRegCategory = document.getElementById('newRegCategory');
        const newRegCode = document.getElementById('newRegCode');
        const newRegName = document.getElementById('newRegName');
        const newRegLocation = document.getElementById('newRegLocation');
        const newRegPriceType = document.getElementById('newRegPriceType');
        const newRegCashAmount = document.getElementById('newRegCashAmount');
        const newRegCashMaxAmount = document.getElementById('newRegCashMaxAmount');
        const newRegBillingAmount = document.getElementById('newRegBillingAmount');
        const newRegBillingMaxAmount = document.getElementById('newRegBillingMaxAmount');
        const newRegMin = document.getElementById('newRegMin');
        const newRegMax = document.getElementById('newRegMax');
        const newRegDuration = document.getElementById('newRegDuration');
        const newRegNotes = document.getElementById('newRegNotes');
        const newRegActive = document.getElementById('newRegActive');

        let activeRow = null;
        let activeRowElement = null;
        let newRegCodeManual = false;

        function openModal(modal) {
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.style.display = 'none';
            modal.classList.add('hidden');
            document.body.classList.remove('modal-open');
        }

        function formatMoney(value) {
            return '$' + Number(value || 0).toLocaleString();
        }

        function buildRangePart(label, min, max) {
            const minValue = Number(min || 0);
            const maxValue = Number(max || 0);

            if (minValue <= 0 && maxValue <= 0) return '';
            if (maxValue > 0) return `${label} ${formatMoney(minValue)} - ${formatMoney(maxValue)}`;
            return `${label} ${formatMoney(minValue)}`;
        }

        function buildPriceLabel(values) {
            const cashPart = buildRangePart('Cash', values.cashMin, values.cashMax);
            const billingPart = buildRangePart('Billing', values.billingMin, values.billingMax);
            const parts = [cashPart, billingPart].filter(Boolean);

            if (parts.length > 0) return parts.join(' + ');

            if (values.priceType === 'RANGE') {
                return `${formatMoney(values.totalMin)} - ${formatMoney(values.totalMax)}`;
            }

            return formatMoney(values.totalMin);
        }

        function slugifyCodePart(value) {
            return (value || '')
                .toString()
                .trim()
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .replace(/_+/g, '_');
        }

        function buildSuggestedCode() {
            const parts = [
                slugifyCodePart(newRegCategory.value),
                slugifyCodePart(newRegName.value),
                slugifyCodePart(newRegLocation.value),
            ].filter(Boolean);

            return parts.join('_').slice(0, 50);
        }

        function syncGeneratedCode() {
            if (newRegCodeManual) return;
            newRegCode.value = buildSuggestedCode();
        }

        function syncDerivedPrice(hiddenTypeElement, minElement, maxElement, cashMinElement, cashMaxElement, billingMinElement, billingMaxElement) {
            const cashMin = Number(cashMinElement.value || 0);
            const cashMax = Number(cashMaxElement.value || 0);
            const billingMin = Number(billingMinElement.value || 0);
            const billingMax = Number(billingMaxElement.value || 0);
            const isRange = cashMax > 0 || billingMax > 0;

            hiddenTypeElement.value = isRange ? 'RANGE' : 'FIXED';
            minElement.value = String(cashMin + billingMin);
            maxElement.value = String(isRange ? (cashMax + billingMax) : 0);
        }

        function getRowAmounts(row) {
            const legacyPayment = ((row.dataset.payment || 'CASH').toUpperCase() === 'INVOICE')
                ? 'BILLING'
                : (row.dataset.payment || 'CASH').toUpperCase();
            const legacyMin = Number(row.dataset.min || 0);
            const legacyMax = Number(row.dataset.max || 0);

            let cashMin = Number(row.dataset.cash || 0);
            let cashMax = Number(row.dataset.cashMax || 0);
            let billingMin = Number(row.dataset.billing || 0);
            let billingMax = Number(row.dataset.billingMax || 0);

            if (cashMin === 0 && billingMin === 0 && legacyMin > 0) {
                if (legacyPayment === 'BILLING') {
                    billingMin = legacyMin;
                } else {
                    cashMin = legacyMin;
                }
            }

            if (cashMax === 0 && billingMax === 0 && legacyMax > 0) {
                if (legacyPayment === 'BILLING') {
                    billingMax = legacyMax;
                } else {
                    cashMax = legacyMax;
                }
            }

            return { cashMin, cashMax, billingMin, billingMax };
        }

        function updateRenderedRow(row, values) {
            if (!row) return;

            row.dataset.category = values.category;
            row.dataset.name = values.name;
            row.dataset.location = values.location;
            row.dataset.price_type = values.priceType;
            row.dataset.min = values.totalMin;
            row.dataset.max = values.totalMax;
            row.dataset.cash = values.cashMin;
            row.dataset.cashMax = values.cashMax;
            row.dataset.billing = values.billingMin;
            row.dataset.billingMax = values.billingMax;
            row.dataset.payment = values.paymentType;
            row.dataset.duration = values.duration;
            row.dataset.notes = values.notes;
            row.dataset.active = values.isActive ? '1' : '0';

            const cells = row.cells;
            if (cells.length >= 5) {
                cells[0].textContent = values.category;
                if (cells.length >= 3) {
                    cells[2].textContent = values.name;
                }
                if (cells.length >= 4) {
                    cells[3].textContent = values.priceLabel;
                }
                if (cells.length >= 5) {
                    cells[4].textContent = values.isActive ? 'Aktif' : 'Nonaktif';
                }
            }
        }

        function createActionButtonHtml() {
            return '<button type="button" class="btn-secondary action-icon-btn btn-edit-reg" title="Ubah regulasi medis" aria-label="Ubah regulasi medis"><?= ems_icon("pencil-square", "h-4 w-4") ?></button>';
        }

        function appendRenderedRow(item, priceLabel) {
            if (hasDataTable) {
                const rowNode = regTable.row.add([
                    item.category,
                    item.code,
                    item.name,
                    priceLabel,
                    item.is_active == 1 ? 'Aktif' : 'Nonaktif',
                    createActionButtonHtml()
                ]).draw(false).node();

                return rowNode;
            }

            if (!regTableBody) return null;

            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${item.category || ''}</td>
                <td>${item.code || ''}</td>
                <td>${item.name || ''}</td>
                <td>${priceLabel}</td>
                <td>${item.is_active == 1 ? 'Aktif' : 'Nonaktif'}</td>
                <td>${createActionButtonHtml()}</td>
            `;
            regTableBody.appendChild(row);
            return row;
        }

        editRegModal.querySelectorAll('.btn-cancel, .modal-close-btn').forEach(btn => {
            btn.addEventListener('click', () => closeModal(editRegModal));
        });

        addRegModal.querySelectorAll('.btn-cancel, .modal-close-btn').forEach(btn => {
            btn.addEventListener('click', () => closeModal(addRegModal));
        });

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-edit-reg');
            if (!btn) return;

            const row = btn.closest('tr');
            activeRowElement = row;
            activeRow = hasDataTable ? regTable.row(row) : null;

            regId.value = row.dataset.id;
            regCategory.value = row.dataset.category;
            regName.value = row.dataset.name;
            regLocation.value = row.dataset.location || '';
            const amounts = getRowAmounts(row);
            regCashAmount.value = String(amounts.cashMin);
            regCashMaxAmount.value = String(amounts.cashMax);
            regBillingAmount.value = String(amounts.billingMin);
            regBillingMaxAmount.value = String(amounts.billingMax);

            regDuration.value = row.dataset.duration || '';
            regNotes.value = row.dataset.notes || '';
            regActive.checked = row.dataset.active === '1';

            syncDerivedPrice(regPriceType, regMin, regMax, regCashAmount, regCashMaxAmount, regBillingAmount, regBillingMaxAmount);
            openModal(editRegModal);
        });

        editRegForm.addEventListener('submit', function(e) {
            e.preventDefault();

            syncDerivedPrice(regPriceType, regMin, regMax, regCashAmount, regCashMaxAmount, regBillingAmount, regBillingMaxAmount);

            fetch(requestUrl, {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(r => {
                    if (!r.success && r.success !== undefined) {
                        showAlert('error', r.message || 'Gagal menyimpan data');
                        return;
                    }

                    const harga = buildPriceLabel({
                        priceType: regPriceType.value,
                        cashMin: regCashAmount.value,
                        cashMax: regCashMaxAmount.value,
                        billingMin: regBillingAmount.value,
                        billingMax: regBillingMaxAmount.value,
                        totalMin: regMin.value,
                        totalMax: regMax.value
                    });

                    const currentCode = activeRowElement?.cells?.[1]?.textContent || '';
                    const paymentType = Number(regBillingAmount.value || 0) > 0 || Number(regBillingMaxAmount.value || 0) > 0 ? 'BILLING' : 'CASH';

                    updateRenderedRow(activeRowElement, {
                        category: regCategory.value,
                        name: regName.value,
                        location: regLocation.value,
                        priceType: regPriceType.value,
                        totalMin: regMin.value || '0',
                        totalMax: regMax.value || '0',
                        cashMin: regCashAmount.value || '0',
                        cashMax: regCashMaxAmount.value || '0',
                        billingMin: regBillingAmount.value || '0',
                        billingMax: regBillingMaxAmount.value || '0',
                        paymentType,
                        duration: regDuration.value,
                        notes: regNotes.value,
                        isActive: regActive.checked,
                        priceLabel: harga
                    });

                    if (hasDataTable && activeRow) {
                        activeRow.data([
                            regCategory.value,
                            currentCode,
                            regName.value,
                            harga,
                            regActive.checked ? 'Aktif' : 'Nonaktif',
                            createActionButtonHtml()
                        ]).draw(false);
                    }

                    showAlert('success', 'Data regulasi berhasil diperbarui');
                    closeModal(editRegModal);
                })
                .catch(err => showAlert('error', 'Terjadi kesalahan: ' + err.message));
        });

        if (openAddRegModal) {
            openAddRegModal.addEventListener('click', function() {
                addRegForm.reset();
                newRegCodeManual = false;
                newRegPriceType.value = 'FIXED';
                newRegActive.checked = true;
                newRegCashAmount.value = '0';
                newRegCashMaxAmount.value = '0';
                newRegBillingAmount.value = '0';
                newRegBillingMaxAmount.value = '0';
                syncDerivedPrice(newRegPriceType, newRegMin, newRegMax, newRegCashAmount, newRegCashMaxAmount, newRegBillingAmount, newRegBillingMaxAmount);
                syncGeneratedCode();
                openModal(addRegModal);
            });
        }

        [newRegCategory, newRegName, newRegLocation].forEach(element => {
            element.addEventListener('input', syncGeneratedCode);
        });

        newRegCode.addEventListener('input', function() {
            const sanitized = (newRegCode.value || '')
                .toUpperCase()
                .replace(/[^A-Z0-9_]/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '')
                .slice(0, 50);

            newRegCode.value = sanitized;
            newRegCodeManual = sanitized !== '' && sanitized !== buildSuggestedCode();
        });

        [regCashAmount, regCashMaxAmount, regBillingAmount, regBillingMaxAmount].forEach(element => {
            element.addEventListener('input', function() {
                syncDerivedPrice(regPriceType, regMin, regMax, regCashAmount, regCashMaxAmount, regBillingAmount, regBillingMaxAmount);
            });
        });

        [newRegCashAmount, newRegCashMaxAmount, newRegBillingAmount, newRegBillingMaxAmount].forEach(element => {
            element.addEventListener('input', function() {
                syncDerivedPrice(newRegPriceType, newRegMin, newRegMax, newRegCashAmount, newRegCashMaxAmount, newRegBillingAmount, newRegBillingMaxAmount);
            });
        });

        addRegForm.addEventListener('submit', function(e) {
            e.preventDefault();

            newRegCode.value = (newRegCode.value || '').trim().toUpperCase();
            syncDerivedPrice(newRegPriceType, newRegMin, newRegMax, newRegCashAmount, newRegCashMaxAmount, newRegBillingAmount, newRegBillingMaxAmount);

            fetch(requestUrl, {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) {
                        showAlert('error', r.message || 'Gagal menambah data');
                        return;
                    }

                    const item = r.item || {};
                    const harga = buildPriceLabel({
                        priceType: item.price_type,
                        cashMin: item.cash_amount,
                        cashMax: item.cash_max_amount,
                        billingMin: item.billing_amount,
                        billingMax: item.billing_max_amount,
                        totalMin: item.price_min,
                        totalMax: item.price_max
                    });
                    const rowNode = appendRenderedRow(item, harga);

                    if (rowNode) {
                        rowNode.dataset.id = item.id;
                        rowNode.dataset.category = item.category || '';
                        rowNode.dataset.name = item.name || '';
                        rowNode.dataset.location = item.location || '';
                        rowNode.dataset.price_type = item.price_type || 'FIXED';
                        rowNode.dataset.min = item.price_min || 0;
                        rowNode.dataset.max = item.price_max || 0;
                        rowNode.dataset.cash = item.cash_amount || 0;
                        rowNode.dataset.cashMax = item.cash_max_amount || 0;
                        rowNode.dataset.billing = item.billing_amount || 0;
                        rowNode.dataset.billingMax = item.billing_max_amount || 0;
                        rowNode.dataset.payment = item.payment_type || 'CASH';
                        rowNode.dataset.duration = item.duration_minutes || '';
                        rowNode.dataset.notes = item.notes || '';
                        rowNode.dataset.active = item.is_active == 1 ? '1' : '0';
                    }

                    showAlert('success', 'Regulasi medis baru berhasil ditambahkan');
                    closeModal(addRegModal);
                })
                .catch(err => showAlert('error', 'Terjadi kesalahan: ' + err.message));
        });

        syncDerivedPrice(regPriceType, regMin, regMax, regCashAmount, regCashMaxAmount, regBillingAmount, regBillingMaxAmount);
        syncDerivedPrice(newRegPriceType, newRegMin, newRegMax, newRegCashAmount, newRegCashMaxAmount, newRegBillingAmount, newRegBillingMaxAmount);
    });

    function showAlert(type, message) {
        if (typeof window.emsToast === 'function') {
            window.emsToast(message, type === 'error' ? 'error' : 'success', {
                title: 'Regulasi Medis',
            });
        }
    }
</script>

<style>
    .reg-amount-stack {
        display: grid;
        gap: 14px;
    }

    .reg-amount-card {
        border: 1px solid #d6e2f0;
        border-radius: 18px;
        padding: 14px;
        background: #f9fcff;
    }

    .reg-amount-title {
        font-weight: 700;
        color: #24354a;
        margin-bottom: 10px;
    }

    .reg-dual-amounts {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
