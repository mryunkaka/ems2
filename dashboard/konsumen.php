<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// Block access for users on cuti
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Data Konsumen';

ems_require_not_trainee_html('Konsumen');

// ===============================
// ROLE USER
// ===============================
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$userDivision = ems_normalize_division($_SESSION['user_rh']['division'] ?? '');
$canManualFarmasi = in_array($userDivision, ['Executive', 'General Affair'], true);
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$salesHasUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
$packagesHasUnitCode = ems_column_exists($pdo, 'packages', 'unit_code');
$userRhHasUnitCode = ems_column_exists($pdo, 'user_rh', 'unit_code');

$flashMessages = $_SESSION['flash_messages'] ?? [];
$flashErrors = $_SESSION['flash_errors'] ?? [];
$flashWarnings = $_SESSION['flash_warnings'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors'], $_SESSION['flash_warnings']);

// ===============================
// FILTER INPUT
// ===============================
$q         = trim($_GET['q'] ?? '');
$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

$sales = [];
$packages = [];
$paketAB = [];
$bandagePackages = [];
$ifaksPackages = [];
$painkillerPackages = [];
$packagesById = [];
$comboPackageModeLabel = 'Paket Combo';

if ($canManualFarmasi) {
    $stmtPackages = $pdo->prepare("SELECT * FROM packages" . ($packagesHasUnitCode ? " WHERE COALESCE(unit_code, 'roxwood') = :unit_code" : "") . " ORDER BY name ASC");
    $stmtPackages->execute($packagesHasUnitCode ? [':unit_code' => $effectiveUnit] : []);
    $packages = $stmtPackages->fetchAll(PDO::FETCH_ASSOC);

    foreach ($packages as $p) {
        $id = (int)($p['id'] ?? 0);
        $name = strtoupper((string)($p['name'] ?? ''));

        if ($id <= 0) {
            continue;
        }

        $packagesById[$id] = [
            'name' => (string)($p['name'] ?? ''),
            'price' => (int)($p['price'] ?? 0),
            'bandage' => (int)($p['bandage_qty'] ?? 0),
            'ifaks' => (int)($p['ifaks_qty'] ?? 0),
            'painkiller' => (int)($p['painkiller_qty'] ?? 0),
        ];

        if (preg_match('/^PAKET\s+[A-Z]+(?:\s|$)/', $name)) {
            $paketAB[] = $p;
        } elseif ((int)$p['bandage_qty'] > 0 && (int)$p['ifaks_qty'] === 0 && (int)$p['painkiller_qty'] === 0) {
            $bandagePackages[] = $p;
        } elseif ((int)$p['ifaks_qty'] > 0 && (int)$p['bandage_qty'] === 0 && (int)$p['painkiller_qty'] === 0) {
            $ifaksPackages[] = $p;
        } elseif ((int)$p['painkiller_qty'] > 0 && (int)$p['bandage_qty'] === 0 && (int)$p['ifaks_qty'] === 0) {
            $painkillerPackages[] = $p;
        }
    }

    $comboNames = array_values(array_unique(array_filter(array_map(static function ($package) {
        return trim((string)($package['name'] ?? ''));
    }, $paketAB))));

    if ($comboNames !== []) {
        $comboPackageModeLabel = count($comboNames) === 1 ? $comboNames[0] : ('Paket ' . implode(' / ', array_map(static function ($name) {
            if (preg_match('/^paket\s+(.+)$/iu', $name, $matches)) {
                return trim((string)($matches[1] ?? ''));
            }

            return trim((string)$name);
        }, $comboNames)));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_manual_farmasi') {
    $redirectQuery = [];
    if ($q !== '') {
        $redirectQuery['q'] = $q;
    }
    if ($startDate !== '') {
        $redirectQuery['start_date'] = $startDate;
    }
    if ($endDate !== '') {
        $redirectQuery['end_date'] = $endDate;
    }
    if (!empty($_GET['range'])) {
        $redirectQuery['range'] = (string)$_GET['range'];
    }
    $redirectTo = 'konsumen.php' . ($redirectQuery ? ('?' . http_build_query($redirectQuery)) : '');

    if (!$canManualFarmasi) {
        $_SESSION['flash_errors'][] = 'Akses input manual rekap farmasi hanya untuk division Executive dan General Affair.';
        header('Location: ' . $redirectTo);
        exit;
    }

    $postedToken = trim((string)($_POST['manual_farmasi_token'] ?? ''));
    $sessionToken = trim((string)($_SESSION['manual_farmasi_token'] ?? ''));

    if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $_SESSION['flash_errors'][] = 'Permintaan input manual tidak valid atau sudah diproses.';
        header('Location: ' . $redirectTo);
        exit;
    }

    unset($_SESSION['manual_farmasi_token']);

    $manualErrors = [];
    $transactionDate = trim((string)($_POST['transaction_date'] ?? ''));
    $rawConsumerName = trim((string)($_POST['consumer_name'] ?? ''));
    $consumerName = ems_normalize_citizen_id($rawConsumerName);
    $manualMedicUserId = (int)($_POST['manual_medic_user_id'] ?? 0);
    $manualMedicName = trim((string)($_POST['manual_medic_name'] ?? ''));
    $manualMedicPosition = trim((string)($_POST['manual_medic_position'] ?? ''));
    $packageMainRaw = trim((string)($_POST['package_main'] ?? ''));
    $isCustomPackage = $packageMainRaw === 'custom';
    $pkgMainId = $isCustomPackage ? 0 : (int)$packageMainRaw;
    $pkgBandageId = (int)($_POST['package_bandage'] ?? 0);
    $pkgIfaksId = (int)($_POST['package_ifaks'] ?? 0);
    $pkgPainId = (int)($_POST['package_painkiller'] ?? 0);

    if ($transactionDate === '') {
        $manualErrors[] = 'Tanggal transaksi wajib diisi.';
    } else {
        $dateObj = DateTime::createFromFormat('Y-m-d', $transactionDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $transactionDate) {
            $manualErrors[] = 'Format tanggal transaksi tidak valid.';
        }
    }

    if ($consumerName === '') {
        $manualErrors[] = ems_consumer_identifier_label() . ' wajib diisi.';
    } elseif (!ems_looks_like_citizen_id($rawConsumerName)) {
        $manualErrors[] = ems_consumer_identifier_label() . ' tidak valid. Gunakan format Citizen ID, bukan nama konsumen.';
    }

    if ($manualMedicUserId <= 0 || $manualMedicName === '') {
        $manualErrors[] = 'Nama medis wajib dipilih dari autocomplete.';
    }

    $selectedIds = [];
    if ($pkgMainId > 0) {
        $selectedIds[] = $pkgMainId;
    }
    if ($pkgBandageId > 0) {
        $selectedIds[] = $pkgBandageId;
    }
    if ($pkgIfaksId > 0) {
        $selectedIds[] = $pkgIfaksId;
    }
    if ($pkgPainId > 0) {
        $selectedIds[] = $pkgPainId;
    }

    if ($selectedIds === []) {
        $manualErrors[] = 'Pilih minimal satu paket.';
    }

    $manualMedic = null;
    if ($manualErrors === []) {
        $manualMedicSql = "
            SELECT id, full_name, position
            FROM user_rh
            WHERE id = :id
              AND is_active = 1
        ";
        if ($userRhHasUnitCode) {
            $manualMedicSql .= " AND COALESCE(unit_code, 'roxwood') = :unit_code";
        }
        $manualMedicSql .= " LIMIT 1";

        $stmtMedic = $pdo->prepare($manualMedicSql);
        $manualMedicParams = [':id' => $manualMedicUserId];
        if ($userRhHasUnitCode) {
            $manualMedicParams[':unit_code'] = $effectiveUnit;
        }
        $stmtMedic->execute($manualMedicParams);
        $manualMedic = $stmtMedic->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$manualMedic) {
            $manualErrors[] = 'Data medis tidak ditemukan atau tidak aktif.';
        } elseif (strcasecmp(trim((string)$manualMedic['full_name']), $manualMedicName) !== 0) {
            $manualErrors[] = 'Nama medis tidak sesuai dengan data yang dipilih.';
        }
    }

    $packagesSelected = [];
    if ($manualErrors === []) {
        $selectedIds = array_values(array_unique(array_filter($selectedIds, static fn($id) => (int)$id > 0)));
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmtPackage = $pdo->prepare("SELECT * FROM packages WHERE id IN ($placeholders)" . ($packagesHasUnitCode ? " AND COALESCE(unit_code, 'roxwood') = ?" : ""));
        $packageParams = $selectedIds;
        if ($packagesHasUnitCode) {
            $packageParams[] = $effectiveUnit;
        }
        $stmtPackage->execute($packageParams);

        foreach ($stmtPackage->fetchAll(PDO::FETCH_ASSOC) as $packageRow) {
            $packagesSelected[(int)$packageRow['id']] = $packageRow;
        }

        foreach ($selectedIds as $selectedId) {
            if (!isset($packagesSelected[$selectedId])) {
                $manualErrors[] = 'Ada paket yang tidak ditemukan di database.';
                break;
            }
        }
    }

    if ($manualErrors === []) {
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM sales
            WHERE DATE(created_at) = :transaction_date
              AND UPPER(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(TRIM(consumer_name), ' ', ''),
                                '-',
                                ''
                            ),
                            '.',
                            ''
                        ),
                        '/',
                        ''
                    )
                ) = :consumer_name
              " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
        ");
        $checkParams = [
            ':transaction_date' => $transactionDate,
            ':consumer_name' => $consumerName,
        ];
        if ($salesHasUnitCode) {
            $checkParams[':unit_code'] = $effectiveUnit;
        }
        $stmtCheck->execute($checkParams);

        if ((int)$stmtCheck->fetchColumn() > 0) {
            $manualErrors[] = ems_consumer_identifier_label() . ' ' . $consumerName . ' sudah punya transaksi pada tanggal ' . $transactionDate . '.';
        }
    }

    if ($manualErrors !== []) {
        $_SESSION['flash_errors'] = array_merge($_SESSION['flash_errors'] ?? [], $manualErrors);
        header('Location: ' . $redirectTo);
        exit;
    }

    $createdAt = $transactionDate . ' ' . date('H:i:s');
    $actorName = trim((string)($_SESSION['user_rh']['name'] ?? $_SESSION['user_rh']['full_name'] ?? 'System'));
    $medicPositionLabel = ems_position_label($manualMedicPosition !== '' ? $manualMedicPosition : ($manualMedic['position'] ?? ''));
    $note = 'Input manual rekap farmasi dari halaman konsumen oleh ' . ($actorName !== '' ? $actorName : 'System');

    try {
        $pdo->beginTransaction();

        $stmtInsert = $pdo->prepare("
            INSERT INTO sales
            (
                consumer_name,
                medic_name,
                medic_user_id,
                medic_jabatan,
                " . ($salesHasUnitCode ? "unit_code," : "") . "
                package_id,
                package_name,
                price,
                qty_bandage,
                qty_ifaks,
                qty_painkiller,
                keterangan,
                created_at,
                tx_hash
            )
            VALUES
            (
                :consumer_name,
                :medic_name,
                :medic_user_id,
                :medic_jabatan,
                " . ($salesHasUnitCode ? ":unit_code," : "") . "
                :package_id,
                :package_name,
                :price,
                :qty_bandage,
                :qty_ifaks,
                :qty_painkiller,
                :keterangan,
                :created_at,
                :tx_hash
            )
        ");

        if ($isCustomPackage) {
            $customPrice = 0;
            $customBandage = 0;
            $customIfaks = 0;
            $customPain = 0;
            $customPackageId = (int)($selectedIds[0] ?? 0);

            foreach ($selectedIds as $selectedId) {
                $selectedPackage = $packagesSelected[$selectedId];
                $customPrice += (int)$selectedPackage['price'];
                $customBandage += (int)$selectedPackage['bandage_qty'];
                $customIfaks += (int)$selectedPackage['ifaks_qty'];
                $customPain += (int)$selectedPackage['painkiller_qty'];
            }

            $stmtInsert->execute([
                ':consumer_name' => $consumerName,
                ':medic_name' => (string)$manualMedic['full_name'],
                ':medic_user_id' => (int)$manualMedic['id'],
                ':medic_jabatan' => $medicPositionLabel,
                ...($salesHasUnitCode ? [':unit_code' => $effectiveUnit] : []),
                ':package_id' => $customPackageId,
                ':package_name' => 'Paket Custom',
                ':price' => $customPrice,
                ':qty_bandage' => $customBandage,
                ':qty_ifaks' => $customIfaks,
                ':qty_painkiller' => $customPain,
                ':keterangan' => $note,
                ':created_at' => $createdAt,
                ':tx_hash' => hash('sha256', $postedToken . '|manual-custom|' . $manualMedic['id']),
            ]);
        } else {
            foreach ($selectedIds as $selectedId) {
                $selectedPackage = $packagesSelected[$selectedId];

                $stmtInsert->execute([
                    ':consumer_name' => $consumerName,
                    ':medic_name' => (string)$manualMedic['full_name'],
                    ':medic_user_id' => (int)$manualMedic['id'],
                    ':medic_jabatan' => $medicPositionLabel,
                    ...($salesHasUnitCode ? [':unit_code' => $effectiveUnit] : []),
                    ':package_id' => (int)$selectedPackage['id'],
                    ':package_name' => (string)$selectedPackage['name'],
                    ':price' => (int)$selectedPackage['price'],
                    ':qty_bandage' => (int)$selectedPackage['bandage_qty'],
                    ':qty_ifaks' => (int)$selectedPackage['ifaks_qty'],
                    ':qty_painkiller' => (int)$selectedPackage['painkiller_qty'],
                    ':keterangan' => $note,
                    ':created_at' => $createdAt,
                    ':tx_hash' => hash('sha256', $postedToken . '|manual|' . $selectedId . '|' . $manualMedic['id']),
                ]);
            }
        }

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Input manual rekap farmasi berhasil disimpan.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['flash_errors'][] = 'Gagal menyimpan input manual rekap farmasi.';
        error_log('[manual_farmasi_konsumen] ' . $e->getMessage());
    }

    header('Location: ' . $redirectTo);
    exit;
}

// ===============================
// QUERY DATA KONSUMEN (DINAMIS)
// ===============================
if ($q !== '' || ($startDate && $endDate)) {

    $sql = "
        SELECT
            s.created_at,
            s.consumer_name,
            s.medic_name,
            s.medic_jabatan,
            s.qty_bandage,
            s.qty_ifaks,
            s.qty_painkiller,
            (s.qty_bandage + s.qty_ifaks + s.qty_painkiller) AS total_item,
            s.price,
            s.identity_id,
            im.citizen_id
        FROM sales s
        LEFT JOIN identity_master im ON im.id = s.identity_id
        WHERE 1=1
          AND s.unit_code = :unit_code
    ";

    $params = [
        ':unit_code' => $effectiveUnit,
    ];

    // FILTER KEYWORD
    if ($q !== '') {
        $sql .= "
            AND (
                s.consumer_name LIKE :q
                OR im.citizen_id LIKE :q
                OR s.medic_name LIKE :q
            )
        ";
        $params[':q'] = "%$q%";
    }

    // FILTER TANGGAL
    if ($startDate && $endDate) {
        $sql .= " AND DATE(s.created_at) BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date']   = $endDate;
    }

    // ⬇️ SORT & LIMIT
    $sql .= " ORDER BY s.created_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($canManualFarmasi && empty($_SESSION['manual_farmasi_token'])) {
    $_SESSION['manual_farmasi_token'] = bin2hex(random_bytes(32));
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">

        <h1 class="page-title">Data Konsumen</h1>

	        <p class="page-subtitle">Menampilkan seluruh data transaksi konsumen <?= htmlspecialchars(ems_unit_label($effectiveUnit)) ?>. Data lama yang masih tersimpan sebagai nama tetap ditampilkan apa adanya.</p>

        <?php foreach ($flashMessages as $message): ?>
            <div class="alert alert-success mb-4"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($flashWarnings as $warning): ?>
            <div class="alert alert-warning mb-4"><?= htmlspecialchars((string)$warning) ?></div>
        <?php endforeach; ?>
        <?php foreach ($flashErrors as $error): ?>
            <div class="alert alert-error mb-4"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header-actions card-section">
                <div class="card-header-actions-title">
                    Daftar Transaksi Konsumen
                </div>
                <?php if ($userRole !== 'staff' || $canManualFarmasi): ?>
                    <div class="card-header-actions-right">
                        <?php if ($canManualFarmasi): ?>
                            <button type="button" class="btn btn-primary" onclick="openManualFarmasiModal()">
                                <?= ems_icon('plus', 'h-4 w-4') ?>
                                <span>Input Farmasi Manual</span>
                            </button>
                        <?php endif; ?>
                        <?php if ($userRole !== 'staff'): ?>
                            <button type="button" class="btn btn-success" onclick="openImportModal()">
                                <?= ems_icon('arrow-up-tray', 'h-4 w-4') ?>
                                <span>Import Excel</span>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="search-panel">
                <form method="get" class="search-form search-form-inline">

                    <input type="hidden" name="start_date" id="startDate">
                    <input type="hidden" name="end_date" id="endDate">

                    <!-- CUSTOM DATE (HIDDEN DEFAULT) -->
                    <div class="search-field search-field-date hidden" id="customDateWrapper">
                        <input type="date" id="customStart">
                    </div>

                    <div class="search-field search-field-date hidden" id="customDateWrapperEnd">
                        <input type="date" id="customEnd">
                    </div>

                    <!-- RENTANG -->
                    <div class="search-field search-field-range">
                        <select name="range" id="rangeSelect">
                            <option value="this_week">Minggu Ini</option>
                            <option value="last_week">Minggu Lalu</option>
                            <option value="2_weeks">2 Minggu Lalu</option>
                            <option value="3_weeks">3 Minggu Lalu</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>

                    <!-- KEYWORD -->
                    <div class="search-field search-field-keyword">
                        <input type="text"
                            name="q"
                            placeholder="Cari Citizen ID Konsumen / Nama Medis / Data Nama Lama"
                            value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                            autocomplete="off">
                    </div>

                    <!-- ACTION -->
                    <div class="search-actions">
                        <button type="submit" class="btn btn-primary">
                            Cari
                        </button>

                        <?php if (!empty($_GET['q']) || !empty($_GET['range'])): ?>
                            <a href="konsumen.php" class="btn btn-secondary">
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>

                </form>
            </div>

            <div class="table-wrapper">
                <table id="konsumenTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tanggal</th>
                            <th><?= htmlspecialchars(ems_consumer_identifier_label()) ?></th>
                            <th>Data Nama Lama</th>
                            <th>Nama Medis</th>
                            <th>Jabatan</th>
                            <th>Bandage</th>
                            <th>IFAK</th>
                            <th>Obat</th>
                            <th>Total Item</th>
                            <th>Total Harga</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($sales)): ?>
                            <?php foreach ($sales as $i => $row): ?>
                                <?php
                                $consumerIdentifier = ems_consumer_identifier_value($row['consumer_name'] ?? '', $row['citizen_id'] ?? '');
                                $legacyConsumerName = ems_consumer_legacy_name_value($row['consumer_name'] ?? '', $row['citizen_id'] ?? '');
                                ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
                                    <td>
                                        <?php if ($consumerIdentifier !== ''): ?>
                                            <?php if ((int)$row['identity_id'] > 0): ?>
                                                <a href="#"
                                                    class="identity-link"
                                                    data-identity-id="<?= (int)$row['identity_id'] ?>">
                                                    <?= htmlspecialchars($consumerIdentifier) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($consumerIdentifier) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="muted-placeholder">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($legacyConsumerName) ?></td>
                                    <td><?= htmlspecialchars($row['medic_name']) ?></td>
                                    <td><?= htmlspecialchars($row['medic_jabatan']) ?></td>
                                    <td><?= (int)$row['qty_bandage'] ?></td>
                                    <td><?= (int)$row['qty_ifaks'] ?></td>
                                    <td><?= (int)$row['qty_painkiller'] ?></td>
                                    <td><?= (int)$row['total_item'] ?></td>
                                    <td><?= dollar((int)$row['price']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th class="table-align-right">TOTAL</th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- ================================================
     MODAL IDENTITY
     ================================================ -->
<div id="identityModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Data Konsumen</div>
            <button type="button" class="modal-close-btn" onclick="closeIdentityModal()" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <div id="identityContent" class="modal-content">
            <p class="muted-placeholder">Memuat data...</p>
        </div>

        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary" onclick="closeIdentityModal()">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php if ($canManualFarmasi): ?>
    <div id="manualFarmasiModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell max-w-2xl">
            <div class="modal-head">
                <div class="modal-title">Input Rekap Farmasi Manual</div>
                <button type="button" class="modal-close-btn" onclick="closeManualFarmasiModal()" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <form method="post" id="manualFarmasiForm" class="modal-content">
                <input type="hidden" name="action" value="add_manual_farmasi">
                <input type="hidden" name="manual_farmasi_token" value="<?= htmlspecialchars((string)($_SESSION['manual_farmasi_token'] ?? '')) ?>">
                <input type="hidden" name="manual_medic_user_id" id="manualMedicUserId" value="">
                <input type="hidden" name="manual_medic_position" id="manualMedicPosition" value="">

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="manualTransactionDate">Tanggal Transaksi</label>
                        <input type="date" name="transaction_date" id="manualTransactionDate" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label for="manualConsumerName"><?= htmlspecialchars(ems_consumer_identifier_label()) ?></label>
                        <input type="text"
                            name="consumer_name"
                            id="manualConsumerName"
                            placeholder="RH39IQLC"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            style="text-transform: uppercase;"
                            required>
                        <small>Gunakan Citizen ID konsumen, bukan nama.</small>
                    </div>
                    <div class="md:col-span-2">
                        <label for="manualMedicName">Nama Medis</label>
                        <div class="relative">
                            <input type="text"
                                name="manual_medic_name"
                                id="manualMedicName"
                                placeholder="Ketik nama medis..."
                                autocomplete="off"
                                required>
                            <div id="manualMedicSuggestions" class="ems-suggestion-box"></div>
                        </div>
                        <small>Pilih nama medis dari hasil autocomplete.</small>
                    </div>
                    <div class="md:col-span-2">
                        <label for="manualPkgMain">Pilihan Paket</label>
                        <select name="package_main" id="manualPkgMain">
                            <option value="">-- Tidak Pakai Paket --</option>
                            <?php foreach ($paketAB as $pkg): ?>
                                <option value="<?= (int)$pkg['id'] ?>">
                                    <?= htmlspecialchars((string)$pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                </option>
                            <?php endforeach; ?>
                            <option value="custom">Paket Custom</option>
                        </select>
                        <small>Pilih paket combo <?= htmlspecialchars($comboPackageModeLabel) ?> atau gunakan Paket Custom untuk memilih item satu per satu.</small>
                    </div>
                </div>

                <div class="grid gap-4 mt-4 md:grid-cols-3 hidden" id="manualCustomPackageRow">
                    <div>
                        <label for="manualPkgBandage">Paket Bandage</label>
                        <select name="package_bandage" id="manualPkgBandage">
                            <option value="">-- Tidak pilih paket Bandage --</option>
                            <?php foreach ($bandagePackages as $pkg): ?>
                                <option value="<?= (int)$pkg['id'] ?>">
                                    <?= htmlspecialchars((string)$pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="manualPkgIfaks">Paket IFAKS</label>
                        <select name="package_ifaks" id="manualPkgIfaks">
                            <option value="">-- Tidak pilih paket IFAKS --</option>
                            <?php foreach ($ifaksPackages as $pkg): ?>
                                <option value="<?= (int)$pkg['id'] ?>">
                                    <?= htmlspecialchars((string)$pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="manualPkgPainkiller">Paket Painkiller</label>
                        <select name="package_painkiller" id="manualPkgPainkiller">
                            <option value="">-- Tidak pilih paket Painkiller --</option>
                            <?php foreach ($painkillerPackages as $pkg): ?>
                                <option value="<?= (int)$pkg['id'] ?>">
                                    <?= htmlspecialchars((string)$pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 mt-4">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Ringkasan Paket</div>
                    <div class="grid gap-3 mt-3 md:grid-cols-4">
                        <div>
                            <div class="meta-text-xs">Bandage</div>
                            <div class="font-semibold text-slate-900"><span id="manualTotalBandage">0</span> pcs</div>
                        </div>
                        <div>
                            <div class="meta-text-xs">IFAKS</div>
                            <div class="font-semibold text-slate-900"><span id="manualTotalIfaks">0</span> pcs</div>
                        </div>
                        <div>
                            <div class="meta-text-xs">Painkiller</div>
                            <div class="font-semibold text-slate-900"><span id="manualTotalPainkiller">0</span> pcs</div>
                        </div>
                        <div>
                            <div class="meta-text-xs">Total Harga</div>
                            <div class="font-semibold text-slate-900"><span id="manualTotalPrice">$0</span></div>
                        </div>
                    </div>
                </div>

                <div class="modal-foot px-0 pb-0">
                    <div class="modal-actions justify-end">
                        <button type="button" class="btn-secondary" onclick="closeManualFarmasiModal()">Batal</button>
                        <button type="submit" class="btn-success">Simpan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- =========================
MODAL IMPORT KONSUMEN (EMS)
========================= -->
<div id="importModal" class="ems-modal-overlay hidden">
    <div class="ems-modal-card modal-frame-md">

        <h4 class="inline-flex items-center gap-2">
            <?= ems_icon('arrow-up-tray', 'h-5 w-5') ?>
            <span>Import Data Transaksi</span>
        </h4>

        <form id="importForm" enctype="multipart/form-data">

            <div class="ems-form-group">
                <label>Nama Medis Yang Input</label>
                <input type="text"
                    id="medicNameInput"
                    name="medic_name"
                    placeholder="Ketik nama medis..."
                    autocomplete="off"
                    required>

                <div id="medicSuggestions" class="ems-suggestion-box"></div>
            </div>

            <div class="ems-form-group">
                <label>Tanggal Transaksi</label>
                <input type="date"
                    name="transaction_date"
                    id="transactionDate"
                    required>
            </div>

            <div class="ems-form-group">
                <label>File Excel (.xlsx / .xls)</label>
                <input type="file"
                    name="excel_file"
                    id="excelFile"
                    accept=".xlsx,.xls"
                    required>
            </div>

            <!-- PROGRESS -->
            <div id="importProgress" class="ems-import-progress hidden">
                <div class="ems-spinner"></div>
                <p>Mengupload dan memproses data...</p>
            </div>

            <div class="modal-actions">
                <button type="button" class="ems-btn-cancel" onclick="closeImportModal()">
                    Batal
                </button>
                <button type="submit" class="ems-btn-confirm" id="importBtn">
                    Import Data
                </button>
            </div>

        </form>
    </div>
</div>

<script>
    document.getElementById('rangeSelect')?.addEventListener('change', function() {
        const range = this.value;

        const startHidden = document.getElementById('startDate');
        const endHidden = document.getElementById('endDate');

        const customStart = document.getElementById('customStart');
        const customEnd = document.getElementById('customEnd');

        const wrapStart = document.getElementById('customDateWrapper');
        const wrapEnd = document.getElementById('customDateWrapperEnd');

        const today = new Date();
        let start, end;

        function format(d) {
            return d.toISOString().slice(0, 10);
        }

        // RESET
        wrapStart.style.display = 'none';
        wrapEnd.style.display = 'none';

        if (range === 'custom') {
            startHidden.value = '';
            endHidden.value = '';

            wrapStart.style.display = 'block';
            wrapEnd.style.display = 'block';

            customStart.focus();
            return;
        }

        if (range === 'this_week') {
            const day = today.getDay() || 7;
            start = new Date(today);
            start.setDate(today.getDate() - day + 1);
            end = new Date(start);
            end.setDate(start.getDate() + 6);
        }

        if (range === 'last_week') {
            const day = today.getDay() || 7;
            end = new Date(today);
            end.setDate(today.getDate() - day);
            start = new Date(end);
            start.setDate(end.getDate() - 6);
        }

        if (range === '2_weeks') {
            start = new Date(today);
            start.setDate(today.getDate() - 14);
            end = today;
        }

        if (range === '3_weeks') {
            start = new Date(today);
            start.setDate(today.getDate() - 21);
            end = today;
        }

        if (start && end) {
            startHidden.value = format(start);
            endHidden.value = format(end);
        }
    });

    // COPY VALUE CUSTOM → HIDDEN
    document.getElementById('customStart')?.addEventListener('change', function() {
        document.getElementById('startDate').value = this.value;
    });

    document.getElementById('customEnd')?.addEventListener('change', function() {
        document.getElementById('endDate').value = this.value;
    });
</script>

<?php if ($canManualFarmasi): ?>
<script>
    const MANUAL_PACKAGES = <?= json_encode($packagesById, JSON_UNESCAPED_UNICODE) ?>;

    function openManualFarmasiModal() {
        const modal = document.getElementById('manualFarmasiModal');
        if (!modal) return;

        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
        updateManualPackageSummary();
    }

    function closeManualFarmasiModal() {
        const modal = document.getElementById('manualFarmasiModal');
        const form = document.getElementById('manualFarmasiForm');
        if (!modal || !form) return;

        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
        form.reset();
        document.getElementById('manualMedicUserId').value = '';
        document.getElementById('manualMedicPosition').value = '';
        document.getElementById('manualMedicSuggestions').style.display = 'none';
        document.getElementById('manualTransactionDate').value = new Date().toISOString().split('T')[0];
        toggleManualPackageMode();
        updateManualPackageSummary();
    }

    function formatManualDollar(amount) {
        return '$' + Number(amount || 0).toLocaleString('id-ID');
    }

    function toggleManualPackageMode() {
        const mainEl = document.getElementById('manualPkgMain');
        const customRow = document.getElementById('manualCustomPackageRow');
        if (!mainEl || !customRow) return;

        const isCustom = mainEl.value === 'custom';
        customRow.classList.toggle('hidden', !isCustom);

        if (!isCustom) {
            ['manualPkgBandage', 'manualPkgIfaks', 'manualPkgPainkiller'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) {
                    el.value = '';
                }
            });
        }
    }

    function getManualSelectedPackageIds() {
        const mainEl = document.getElementById('manualPkgMain');
        if (!mainEl) return [];

        if (mainEl.value === 'custom') {
            return ['manualPkgBandage', 'manualPkgIfaks', 'manualPkgPainkiller']
                .map(function(id) {
                    const el = document.getElementById(id);
                    return el ? parseInt(el.value || '0', 10) : 0;
                })
                .filter(Boolean);
        }

        return [parseInt(mainEl.value || '0', 10)].filter(Boolean);
    }

    function updateManualPackageSummary() {
        toggleManualPackageMode();

        let totalBandage = 0;
        let totalIfaks = 0;
        let totalPainkiller = 0;
        let totalPrice = 0;

        getManualSelectedPackageIds().forEach(function(id) {
            const pkg = MANUAL_PACKAGES[id];
            if (!pkg) return;

            totalBandage += parseInt(pkg.bandage || 0, 10);
            totalIfaks += parseInt(pkg.ifaks || 0, 10);
            totalPainkiller += parseInt(pkg.painkiller || 0, 10);
            totalPrice += parseInt(pkg.price || 0, 10);
        });

        document.getElementById('manualTotalBandage').textContent = totalBandage;
        document.getElementById('manualTotalIfaks').textContent = totalIfaks;
        document.getElementById('manualTotalPainkiller').textContent = totalPainkiller;
        document.getElementById('manualTotalPrice').textContent = formatManualDollar(totalPrice);
    }

    ['manualPkgMain', 'manualPkgBandage', 'manualPkgIfaks', 'manualPkgPainkiller'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', updateManualPackageSummary);
        }
    });

    let manualMedicSearchTimeout;
    const manualMedicInput = document.getElementById('manualMedicName');
    const manualMedicSuggestions = document.getElementById('manualMedicSuggestions');
    const manualMedicUserId = document.getElementById('manualMedicUserId');
    const manualMedicPosition = document.getElementById('manualMedicPosition');

    manualMedicInput.addEventListener('input', function() {
        clearTimeout(manualMedicSearchTimeout);
        manualMedicUserId.value = '';
        manualMedicPosition.value = '';

        const query = this.value.trim();
        if (query.length < 2) {
            manualMedicSuggestions.style.display = 'none';
            return;
        }

        manualMedicSearchTimeout = setTimeout(async () => {
            try {
                const res = await fetch(`/actions/search_medic.php?q=${encodeURIComponent(query)}`);
                const data = await res.json();

                if (data.success && Array.isArray(data.medics) && data.medics.length > 0) {
                    manualMedicSuggestions.innerHTML = data.medics.map(function(m) {
                        const safeName = String(m.full_name || '').replace(/'/g, "\\'");
                        const safePosition = String(m.position || '').replace(/'/g, "\\'");
                        return `
                            <div class="medic-suggestion-item" onclick="selectManualMedic(${parseInt(m.id || 0, 10)}, '${safeName}', '${safePosition}')">
                                <strong>${String(m.full_name || '')}</strong>
                                <div class="meta-text-xs">${String(m.position || '-')}</div>
                            </div>
                        `;
                    }).join('');
                    manualMedicSuggestions.style.display = 'block';
                } else {
                    manualMedicSuggestions.style.display = 'none';
                }
            } catch (error) {
                console.error('Error searching medic:', error);
                manualMedicSuggestions.style.display = 'none';
            }
        }, 250);
    });

    function selectManualMedic(id, name, position) {
        manualMedicInput.value = name;
        manualMedicUserId.value = id;
        manualMedicPosition.value = position;
        manualMedicSuggestions.style.display = 'none';
    }

    document.getElementById('manualFarmasiForm').addEventListener('submit', function(e) {
        const citizenIdInput = document.getElementById('manualConsumerName');
        if (citizenIdInput) {
            citizenIdInput.value = String(citizenIdInput.value || '').toUpperCase().replace(/[^A-Z0-9]+/g, '');
        }

        if (!manualMedicUserId.value) {
            e.preventDefault();
            alert('Pilih nama medis dari autocomplete terlebih dahulu.');
            return;
        }

        if (getManualSelectedPackageIds().length === 0) {
            e.preventDefault();
            alert('Pilih minimal satu paket.');
        }
    });

    document.addEventListener('click', function(e) {
        const manualModal = document.getElementById('manualFarmasiModal');
        if (manualModal && e.target === manualModal) {
            closeManualFarmasiModal();
        }

        if (e.target !== manualMedicInput && !manualMedicSuggestions.contains(e.target)) {
            manualMedicSuggestions.style.display = 'none';
        }
    });
</script>
<?php endif; ?>

<script>
    // ================================================
    // IMPORT MODAL HANDLERS
    // ================================================
    function openImportModal() {
        document.getElementById('importModal').style.display = 'flex';
        document.getElementById('transactionDate').value = new Date().toISOString().split('T')[0];
    }

    function closeImportModal() {
        document.getElementById('importModal').style.display = 'none';
        document.getElementById('importForm').reset();
        document.getElementById('importProgress').style.display = 'none';
        document.getElementById('medicSuggestions').style.display = 'none';
    }

    // ================================================
    // MEDIC NAME AUTOCOMPLETE
    // ================================================
    let medicSearchTimeout;
    const medicInput = document.getElementById('medicNameInput');
    const medicSuggestions = document.getElementById('medicSuggestions');

    medicInput.addEventListener('input', function() {
        clearTimeout(medicSearchTimeout);
        const query = this.value.trim();

        if (query.length < 2) {
            medicSuggestions.style.display = 'none';
            return;
        }

        medicSearchTimeout = setTimeout(async () => {
            try {
                const res = await fetch(`/actions/search_medic.php?q=${encodeURIComponent(query)}`);
                const data = await res.json();

                if (data.success && data.medics.length > 0) {
                    medicSuggestions.innerHTML = data.medics.map(m => `
                        <div class="medic-suggestion-item" onclick="selectMedic('${m.full_name}', '${m.position}')">
                            <strong>${m.full_name}</strong>
                            <div class="meta-text-xs">${m.position}</div>
                        </div>
                    `).join('');
                    medicSuggestions.style.display = 'block';
                } else {
                    medicSuggestions.style.display = 'none';
                }
            } catch (e) {
                console.error('Error searching medic:', e);
            }
        }, 300);
    });

    function selectMedic(name, position) {
        medicInput.value = name;
        medicInput.dataset.position = position;
        medicSuggestions.style.display = 'none';
    }

    // Close suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target !== medicInput && !medicSuggestions.contains(e.target)) {
            medicSuggestions.style.display = 'none';
        }
    });

    // ================================================
    // IMPORT FORM SUBMISSION
    // ================================================
    document.getElementById('importForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const medicName = medicInput.value.trim();
        const medicPosition = medicInput.dataset.position || '';

        if (!medicName) {
            alert('Nama medis harus diisi!');
            return;
        }

        formData.append('medic_position', medicPosition);

        // Show loading
        document.getElementById('importProgress').style.display = 'block';
        document.getElementById('importBtn').disabled = true;

        try {
            const res = await fetch('/actions/import_sales_excel.php', {
                method: 'POST',
                body: formData
            });

            const result = await res.json();

            if (result.success) {
                alert(`Berhasil import ${result.imported} transaksi!`);
                closeImportModal();
                location.reload();
            } else {
                alert('Error: ' + (result.message || 'Import gagal'));
            }
        } catch (error) {
            console.error('Import error:', error);
            alert('Terjadi kesalahan saat import data');
        } finally {
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importBtn').disabled = false;
        }
    });
</script>

<script>
    // ================================================
    // IMAGE LIGHTBOX (ZOOM KTP)
    // ================================================
    function createLightbox() {
        if (document.getElementById('imageLightbox')) return;

        const lightbox = document.createElement('div');
        lightbox.id = 'imageLightbox';
        lightbox.className = 'image-lightbox';
        lightbox.innerHTML = `
        <div class="lightbox-content">
            <button class="lightbox-close" onclick="closeLightbox()">×</button>
            <img class="lightbox-image" src="" alt="KTP Preview">
            <div class="lightbox-caption"></div>
        </div>
    `;
        document.body.appendChild(lightbox);
    }

    function openLightbox(imageSrc, caption = '') {
        createLightbox();
        const lightbox = document.getElementById('imageLightbox');
        const lightboxImage = lightbox.querySelector('.lightbox-image');
        const lightboxCaption = lightbox.querySelector('.lightbox-caption');
        lightboxImage.src = imageSrc;
        lightboxCaption.textContent = caption;
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const lightbox = document.getElementById('imageLightbox');
        if (lightbox) {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('identity-photo')) {
            e.preventDefault();
            openLightbox(e.target.src, e.target.alt || 'Foto Identitas');
        }
        if (e.target.id === 'imageLightbox') {
            closeLightbox();
        }
        if (e.target.classList.contains('lightbox-image')) {
            e.stopPropagation();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLightbox();
        }
    });

    // ================================================
    // MODAL IDENTITY HANDLER
    // ================================================
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.identity-link');
        if (!link) return;
        e.preventDefault();
        openIdentityModal(link.dataset.identityId);
    });

    function openIdentityModal(identityId) {
        const modal = document.getElementById('identityModal');
        const content = document.getElementById('identityContent');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
        content.innerHTML = '<p class="muted-placeholder">Memuat data...</p>';

        fetch('/ajax/get_identity_detail.php?id=' + encodeURIComponent(identityId))
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(err => {
                console.error('Error loading identity:', err);
                content.innerHTML = '<p class="text-sm text-red-500">Gagal memuat data.</p>';
            });
    }

    function closeIdentityModal() {
        const modal = document.getElementById('identityModal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('click', function(e) {
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeIdentityModal();
            closeImportModal();
            <?php if ($canManualFarmasi): ?>
            closeManualFarmasiModal();
            <?php endif; ?>
        }
    });
</script>

<script>
    // ================================================
    // DATATABLE INITIALIZATION
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        if (!window.jQuery || !jQuery.fn.DataTable) {
            console.error('jQuery atau DataTables tidak tersedia');
            return;
        }

        const table = document.getElementById('konsumenTable');
        if (!table) return;

        try {
            jQuery('#konsumenTable').DataTable({
                pageLength: 10,
                order: [
                    [1, 'desc']
                ],
                language: {
                    url: '/assets/design/js/datatables-id.json'
                },
                searching: false,
                footerCallback: function(row, data, start, end, display) {
                    const api = this.api();

                    function intVal(i) {
                        return typeof i === 'string' ?
                            i.replace(/[^\d]/g, '') * 1 :
                            typeof i === 'number' ? i : 0;
                    }

                    const totalBandage = api.column(6, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalIFAK = api.column(7, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalObat = api.column(8, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalItem = api.column(9, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalPrice = api.column(10, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);

                    function formatDollar(num) {
                        return '$' + num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    }

                    jQuery(api.column(6).footer()).html(totalBandage);
                    jQuery(api.column(7).footer()).html(totalIFAK);
                    jQuery(api.column(8).footer()).html(totalObat);
                    jQuery(api.column(9).footer()).html(totalItem);
                    jQuery(api.column(10).footer()).html(formatDollar(totalPrice));
                }
            });
        } catch (error) {
            console.error('Error initializing DataTable:', error);
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
