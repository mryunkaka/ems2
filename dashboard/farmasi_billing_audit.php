<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
if (!isset($_GET['range'])) {
    $_GET['range'] = 'week3';
}
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
$userRole = $user['role'] ?? '';

if (ems_is_staff_role($userRole)) {
    $_SESSION['flash_errors'][] = 'Halaman audit billing farmasi hanya bisa diakses selain role Staff.';
    header('Location: index.php');
    exit;
}

$pageTitle = 'Audit Billing Farmasi';
$currentUnit = ems_effective_unit($pdo, $user);
$currentHospitalName = ems_unit_hospital_name($currentUnit);
$salesHasUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
$billingTableReady = ems_table_exists($pdo, 'farmasi_hospital_billing_entries');
$reviewTableReady = ems_table_exists($pdo, 'farmasi_audit_reviews');
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function farmasiAuditMoney(int $amount): string
{
    return '$' . number_format($amount, 0, '.', '.');
}

function farmasiAuditPercent(float $value): string
{
    return number_format($value, 1) . '%';
}

function farmasiAuditStatusMeta(string $status): array
{
    return match ($status) {
        'confirmed_violation' => ['label' => 'Pelanggaran Terkonfirmasi', 'class' => 'badge-danger'],
        'manual_entry_after_service' => ['label' => 'Input Setelah Pelayanan', 'class' => 'badge-warning'],
        'system_gap' => ['label' => 'Celah Sistem', 'class' => 'badge-warning'],
        'clarified' => ['label' => 'Sudah Dijelaskan', 'class' => 'badge-info'],
        'cleared' => ['label' => 'Bersih', 'class' => 'badge-success'],
        default => ['label' => 'Menunggu Review', 'class' => 'badge-secondary'],
    };
}

function farmasiAuditLevelMeta(int $score, int $diffLt10, int $batchEvents, int $maxRowsSameSecond): array
{
    if ($diffLt10 >= 3 || $batchEvents >= 1 || $maxRowsSameSecond >= 10 || $score >= 80) {
        return ['label' => 'Sangat Janggal', 'class' => 'badge-danger', 'rank' => 3];
    }

    if ($diffLt10 >= 1 || $score >= 35) {
        return ['label' => 'Perlu Verifikasi', 'class' => 'badge-warning', 'rank' => 2];
    }

    return ['label' => 'Perlu Monitor', 'class' => 'badge-info', 'rank' => 1];
}

function farmasiAuditReviewOptions(): array
{
    return [
        ['value' => 'pending', 'label' => 'Menunggu Review'],
        ['value' => 'clarified', 'label' => 'Sudah Dijelaskan'],
        ['value' => 'manual_entry_after_service', 'label' => 'Input Setelah Selesai Menjual'],
        ['value' => 'system_gap', 'label' => 'Celah Sistem'],
        ['value' => 'confirmed_violation', 'label' => 'Pelanggaran Terkonfirmasi'],
        ['value' => 'cleared', 'label' => 'Bersih'],
    ];
}

function farmasiAuditRedirect(array $params = []): void
{
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: farmasi_billing_audit.php' . $query);
    exit;
}

function farmasiAuditDetailReasonLabel(?string $reason): string
{
    $reason = trim((string)$reason);
    return $reason !== '' ? $reason : '-';
}

$today = date('Y-m-d');
$rangeFrom = date('Y-m-d', strtotime($rangeStart));
$rangeTo = date('Y-m-d', strtotime($rangeEnd));
$focusDate = trim((string)($_GET['focus_date'] ?? ''));
$focusMedicId = (int)($_GET['focus_medic'] ?? 0);
if ($focusDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusDate)) {
    $focusDate = '';
}
$focusDateInRange = ($focusDate !== '' && $focusDate >= $rangeFrom && $focusDate <= $rangeTo);
$billingInputDate = $focusDateInRange ? $focusDate : $rangeTo;
$rangeEntryLabel = 'Pemasukan Rumah Sakit ' . $rangeFrom . ' s.d. ' . $rangeTo;

$redirectParams = [
    'range' => $range,
    'from' => $rangeFrom,
    'to' => $rangeTo,
];
if ($focusDate !== '') {
    $redirectParams['focus_date'] = $focusDate;
}
if ($focusMedicId > 0) {
    $redirectParams['focus_medic'] = $focusMedicId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hospital_billing_entry'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!$billingTableReady) {
        $_SESSION['flash_errors'][] = 'Tabel audit pemasukan rumah sakit belum tersedia. Jalankan SQL `docs/sql/38_2026-05-16_farmasi_billing_audit.sql`.';
        farmasiAuditRedirect($redirectParams);
    }

    $hospitalBillingAmountRaw = trim((string)($_POST['hospital_billing_amount'] ?? ''));
    $hospitalBillingAmount = (int)preg_replace('/\D+/', '', $hospitalBillingAmountRaw);
    $sourceReference = trim((string)($_POST['source_reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($hospitalBillingAmount <= 0) {
        $_SESSION['flash_errors'][] = 'Total pemasukan rumah sakit wajib lebih dari 0.';
        farmasiAuditRedirect($redirectParams);
    }

    $stmtExistingRange = $pdo->prepare("
        SELECT id
        FROM farmasi_hospital_billing_entries
        WHERE unit_code = ?
          AND service_category = 'range_total'
          AND billing_date = ?
          AND service_label = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtExistingRange->execute([$currentUnit, $rangeTo, $rangeEntryLabel]);
    $existingRangeEntryId = (int)($stmtExistingRange->fetchColumn() ?: 0);

    if ($existingRangeEntryId > 0) {
        $stmt = $pdo->prepare("
            UPDATE farmasi_hospital_billing_entries
            SET
                hospital_billing_amount = ?,
                expected_pharmacy_amount = NULL,
                source_reference = ?,
                notes = ?,
                updated_by = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $hospitalBillingAmount,
            $sourceReference !== '' ? $sourceReference : null,
            $notes !== '' ? $notes : null,
            (int)($user['id'] ?? 0) ?: null,
            $existingRangeEntryId,
        ]);
        $_SESSION['flash_messages'][] = 'Pemasukan rumah sakit untuk rentang audit ini berhasil diperbarui.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO farmasi_hospital_billing_entries
            (
                unit_code,
                billing_date,
                service_category,
                service_label,
                hospital_billing_amount,
                expected_pharmacy_amount,
                source_reference,
                notes,
                created_by,
                updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $currentUnit,
            $rangeTo,
            'range_total',
            $rangeEntryLabel,
            $hospitalBillingAmount,
            null,
            $sourceReference !== '' ? $sourceReference : null,
            $notes !== '' ? $notes : null,
            (int)($user['id'] ?? 0) ?: null,
            (int)($user['id'] ?? 0) ?: null,
        ]);
        $_SESSION['flash_messages'][] = 'Pemasukan rumah sakit untuk rentang audit ini berhasil ditambahkan.';
    }

    farmasiAuditRedirect($redirectParams);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_farmasi_audit_review'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!$reviewTableReady) {
        $_SESSION['flash_errors'][] = 'Tabel review audit farmasi belum tersedia. Jalankan SQL `docs/sql/38_2026-05-16_farmasi_billing_audit.sql`.';
        farmasiAuditRedirect($redirectParams);
    }

    $reviewDate = trim((string)($_POST['review_date'] ?? ''));
    $reviewMedicId = (int)($_POST['medic_user_id'] ?? 0);
    $reviewStatus = trim((string)($_POST['status'] ?? 'pending'));
    $questionPrompt = trim((string)($_POST['question_prompt'] ?? ''));
    $medicStatement = trim((string)($_POST['medic_statement'] ?? ''));
    $reviewerNotes = trim((string)($_POST['reviewer_notes'] ?? ''));
    $anomalyScoreSnapshot = (int)($_POST['anomaly_score_snapshot'] ?? 0);
    $suspiciousTxCount = (int)($_POST['suspicious_tx_count'] ?? 0);
    $sameSecondBatchCount = (int)($_POST['same_second_batch_count'] ?? 0);
    $estimatedLossAmount = (int)($_POST['estimated_loss_amount'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewDate) || $reviewMedicId <= 0) {
        $_SESSION['flash_errors'][] = 'Target review audit tidak valid.';
        farmasiAuditRedirect($redirectParams);
    }

    $allowedReviewStatuses = array_column(farmasiAuditReviewOptions(), 'value');
    if (!in_array($reviewStatus, $allowedReviewStatuses, true)) {
        $_SESSION['flash_errors'][] = 'Status review audit tidak valid.';
        farmasiAuditRedirect($redirectParams);
    }

    $stmt = $pdo->prepare("
        INSERT INTO farmasi_audit_reviews
        (
            unit_code,
            review_date,
            medic_user_id,
            anomaly_score_snapshot,
            suspicious_tx_count,
            same_second_batch_count,
            estimated_loss_amount,
            status,
            question_prompt,
            medic_statement,
            reviewer_notes,
            reviewed_by,
            reviewed_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            anomaly_score_snapshot = VALUES(anomaly_score_snapshot),
            suspicious_tx_count = VALUES(suspicious_tx_count),
            same_second_batch_count = VALUES(same_second_batch_count),
            estimated_loss_amount = VALUES(estimated_loss_amount),
            status = VALUES(status),
            question_prompt = VALUES(question_prompt),
            medic_statement = VALUES(medic_statement),
            reviewer_notes = VALUES(reviewer_notes),
            reviewed_by = VALUES(reviewed_by),
            reviewed_at = NOW()
    ");
    $stmt->execute([
        $currentUnit,
        $reviewDate,
        $reviewMedicId,
        max(0, $anomalyScoreSnapshot),
        max(0, $suspiciousTxCount),
        max(0, $sameSecondBatchCount),
        max(0, $estimatedLossAmount),
        $reviewStatus,
        $questionPrompt !== '' ? $questionPrompt : null,
        $medicStatement !== '' ? $medicStatement : null,
        $reviewerNotes !== '' ? $reviewerNotes : null,
        (int)($user['id'] ?? 0) ?: null,
    ]);

    $_SESSION['flash_messages'][] = 'Review audit farmasi berhasil disimpan.';
    farmasiAuditRedirect($redirectParams);
}

$hospitalEntries = [];
$hospitalDailyMap = [];
$rangeBillingEntry = null;

if ($billingTableReady) {
    $stmtRangeEntry = $pdo->prepare("
        SELECT *
        FROM farmasi_hospital_billing_entries
        WHERE unit_code = ?
          AND service_category = 'range_total'
          AND billing_date = ?
          AND service_label = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtRangeEntry->execute([$currentUnit, $rangeTo, $rangeEntryLabel]);
    $rangeBillingEntry = $stmtRangeEntry->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtEntries = $pdo->prepare("
        SELECT *
        FROM farmasi_hospital_billing_entries
        WHERE unit_code = ?
          AND service_category = 'range_total'
          AND billing_date = ?
          AND service_label = ?
        ORDER BY id DESC
    ");
    $stmtEntries->execute([$currentUnit, $rangeTo, $rangeEntryLabel]);
    $hospitalEntries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$salesDailyMap = [];
$salesDailySql = "
    SELECT
        DATE(created_at) AS audit_date,
        COUNT(*) AS tx_count,
        COUNT(DISTINCT medic_user_id) AS active_medics,
        COALESCE(SUM(price), 0) AS farmasi_total
    FROM sales
    WHERE created_at BETWEEN ? AND ?
";
$salesDailyParams = [$rangeFrom . ' 00:00:00', $rangeTo . ' 23:59:59'];
if ($salesHasUnitCode) {
    $salesDailySql .= " AND COALESCE(unit_code, 'roxwood') = ?";
    $salesDailyParams[] = $currentUnit;
}
$salesDailySql .= " GROUP BY DATE(created_at)";
$stmtSalesDaily = $pdo->prepare($salesDailySql);
$stmtSalesDaily->execute($salesDailyParams);
foreach ($stmtSalesDaily->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $salesDailyMap[(string)$row['audit_date']] = [
        'tx_count' => (int)($row['tx_count'] ?? 0),
        'active_medics' => (int)($row['active_medics'] ?? 0),
        'farmasi_total' => (int)($row['farmasi_total'] ?? 0),
    ];
}

$suspiciousRows = [];
$suspiciousByDateScore = [];
$reviewMap = [];

if ($reviewTableReady) {
    $stmtReviewMap = $pdo->prepare("
        SELECT *
        FROM farmasi_audit_reviews
        WHERE unit_code = ?
          AND review_date BETWEEN ? AND ?
    ");
    $stmtReviewMap->execute([$currentUnit, $rangeFrom, $rangeTo]);
    foreach ($stmtReviewMap->fetchAll(PDO::FETCH_ASSOC) ?: [] as $reviewRow) {
        $reviewMap[(string)$reviewRow['review_date'] . ':' . (int)($reviewRow['medic_user_id'] ?? 0)] = $reviewRow;
    }
}

$orderedWhere = "s.created_at BETWEEN ? AND ?";
$orderedParams = [$rangeFrom . ' 00:00:00', $rangeTo . ' 23:59:59'];
if ($salesHasUnitCode) {
    $orderedWhere .= " AND COALESCE(s.unit_code, 'roxwood') = ?";
    $orderedParams[] = $currentUnit;
}

$batchWhere = "created_at BETWEEN ? AND ?";
$batchParams = [$rangeFrom . ' 00:00:00', $rangeTo . ' 23:59:59'];
if ($salesHasUnitCode) {
    $batchWhere .= " AND COALESCE(unit_code, 'roxwood') = ?";
    $batchParams[] = $currentUnit;
}

$suspiciousSql = "
    WITH ordered AS (
        SELECT
            s.id,
            s.medic_user_id,
            s.medic_name,
            s.consumer_name,
            s.package_name,
            s.price,
            s.created_at,
            DATE(s.created_at) AS audit_date,
            LAG(s.consumer_name) OVER (PARTITION BY s.medic_user_id ORDER BY s.created_at, s.id) AS prev_consumer,
            LAG(s.package_name) OVER (PARTITION BY s.medic_user_id ORDER BY s.created_at, s.id) AS prev_package,
            TIMESTAMPDIFF(
                SECOND,
                LAG(s.created_at) OVER (PARTITION BY s.medic_user_id ORDER BY s.created_at, s.id),
                s.created_at
            ) AS diff_sec
        FROM sales s
        WHERE {$orderedWhere}
    ),
    batch_base AS (
        SELECT
            DATE(created_at) AS audit_date,
            medic_user_id,
            created_at,
            COUNT(*) AS row_count,
            COUNT(DISTINCT consumer_name) AS consumer_count
        FROM sales
        WHERE {$batchWhere}
        GROUP BY DATE(created_at), medic_user_id, created_at
    ),
    batch_events AS (
        SELECT
            audit_date,
            medic_user_id,
            COUNT(*) AS batch_event_count,
            SUM(row_count) AS batch_row_count,
            MAX(row_count) AS max_rows_same_second
        FROM batch_base
        WHERE row_count >= 3
          AND consumer_count >= 2
        GROUP BY audit_date, medic_user_id
    )
    SELECT
        o.audit_date,
        o.medic_user_id,
        MAX(o.medic_name) AS medic_name,
        COUNT(*) AS tx_count,
        COALESCE(SUM(o.price), 0) AS farmasi_amount,
        SUM(CASE WHEN o.diff_sec = 0 AND o.consumer_name = o.prev_consumer THEN 1 ELSE 0 END) AS same_consumer_same_second,
        SUM(CASE WHEN o.diff_sec < 10 AND o.prev_consumer IS NOT NULL AND o.consumer_name <> o.prev_consumer THEN 1 ELSE 0 END) AS diff_consumer_lt10,
        SUM(CASE WHEN o.diff_sec BETWEEN 10 AND 59 AND o.prev_consumer IS NOT NULL AND o.consumer_name <> o.prev_consumer THEN 1 ELSE 0 END) AS diff_consumer_10_59,
        SUM(CASE WHEN o.diff_sec BETWEEN 60 AND 300 AND o.prev_consumer IS NOT NULL AND o.consumer_name <> o.prev_consumer THEN 1 ELSE 0 END) AS diff_consumer_1_5m,
        SUM(CASE WHEN o.diff_sec BETWEEN 60 AND 300 AND o.prev_consumer IS NOT NULL AND o.consumer_name <> o.prev_consumer AND o.package_name = o.prev_package THEN 1 ELSE 0 END) AS same_package_1_5m,
        COALESCE(MAX(b.batch_event_count), 0) AS batch_event_count,
        COALESCE(MAX(b.batch_row_count), 0) AS batch_row_count,
        COALESCE(MAX(b.max_rows_same_second), 0) AS max_rows_same_second
    FROM ordered o
    LEFT JOIN batch_events b
      ON b.audit_date = o.audit_date
     AND b.medic_user_id = o.medic_user_id
    WHERE o.medic_user_id IS NOT NULL
    GROUP BY o.audit_date, o.medic_user_id
";
$stmtSuspicious = $pdo->prepare($suspiciousSql);
$stmtSuspicious->execute(array_merge($orderedParams, $batchParams));

foreach ($stmtSuspicious->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $diffLt10 = (int)($row['diff_consumer_lt10'] ?? 0);
    $diff10To59 = (int)($row['diff_consumer_10_59'] ?? 0);
    $samePackage1To5 = (int)($row['same_package_1_5m'] ?? 0);
    $batchEvents = (int)($row['batch_event_count'] ?? 0);
    $batchRows = (int)($row['batch_row_count'] ?? 0);
    $maxRowsSameSecond = (int)($row['max_rows_same_second'] ?? 0);

    $score =
        ($diffLt10 * 12) +
        ($diff10To59 * 5) +
        ($samePackage1To5 * 3) +
        ($batchEvents * 18) +
        ($maxRowsSameSecond * 2);

    if ($score <= 0) {
        continue;
    }

    $auditDate = (string)($row['audit_date'] ?? '');
    $suspiciousByDateScore[$auditDate] = ($suspiciousByDateScore[$auditDate] ?? 0) + $score;

    $suspiciousRows[] = [
        'audit_date' => $auditDate,
        'medic_user_id' => (int)($row['medic_user_id'] ?? 0),
        'medic_name' => (string)($row['medic_name'] ?? ''),
        'tx_count' => (int)($row['tx_count'] ?? 0),
        'farmasi_amount' => (int)($row['farmasi_amount'] ?? 0),
        'same_consumer_same_second' => (int)($row['same_consumer_same_second'] ?? 0),
        'diff_consumer_lt10' => $diffLt10,
        'diff_consumer_10_59' => $diff10To59,
        'diff_consumer_1_5m' => (int)($row['diff_consumer_1_5m'] ?? 0),
        'same_package_1_5m' => $samePackage1To5,
        'batch_event_count' => $batchEvents,
        'batch_row_count' => $batchRows,
        'max_rows_same_second' => $maxRowsSameSecond,
        'anomaly_score' => $score,
    ];
}

foreach ($suspiciousRows as &$row) {
    $auditDate = $row['audit_date'];
    $row['daily_sales_total'] = (int)($salesDailyMap[$auditDate]['farmasi_total'] ?? 0);
    $row['daily_gap_amount'] = 0;
    $row['reference_amount'] = 0;

    $levelMeta = farmasiAuditLevelMeta(
        $row['anomaly_score'],
        $row['diff_consumer_lt10'],
        $row['batch_event_count'],
        $row['max_rows_same_second']
    );
    $row['level_meta'] = $levelMeta;

    $reviewKey = $auditDate . ':' . $row['medic_user_id'];
    $row['review'] = $reviewMap[$reviewKey] ?? null;
}
unset($row);

usort($suspiciousRows, static function (array $left, array $right): int {
    $levelCompare = (int)($right['level_meta']['rank'] ?? 0) <=> (int)($left['level_meta']['rank'] ?? 0);
    if ($levelCompare !== 0) {
        return $levelCompare;
    }

    $scoreCompare = (int)($right['anomaly_score'] ?? 0) <=> (int)($left['anomaly_score'] ?? 0);
    if ($scoreCompare !== 0) {
        return $scoreCompare;
    }

    $lossCompare = (int)($right['estimated_loss_amount'] ?? 0) <=> (int)($left['estimated_loss_amount'] ?? 0);
    if ($lossCompare !== 0) {
        return $lossCompare;
    }

    return strcmp((string)($right['audit_date'] ?? ''), (string)($left['audit_date'] ?? ''));
});

$dailyRows = [];
$allDates = array_values(array_unique(array_merge(array_keys($salesDailyMap), array_keys($hospitalDailyMap))));
rsort($allDates);
foreach ($allDates as $auditDate) {
    $salesDay = $salesDailyMap[$auditDate] ?? ['farmasi_total' => 0, 'tx_count' => 0, 'active_medics' => 0];
    $hospitalDay = $hospitalDailyMap[$auditDate] ?? ['hospital_billing_total' => 0, 'expected_pharmacy_total' => 0, 'entry_count' => 0];
    $referenceAmount = (int)($hospitalDay['expected_pharmacy_total'] ?? 0);
    if ($referenceAmount <= 0) {
        $referenceAmount = (int)($hospitalDay['hospital_billing_total'] ?? 0);
    }

    $farmasiTotal = (int)($salesDay['farmasi_total'] ?? 0);
    $gapAmount = $referenceAmount > 0 ? ($referenceAmount - $farmasiTotal) : 0;
    $ratio = $referenceAmount > 0 ? (($farmasiTotal / $referenceAmount) * 100) : null;

    $dailyRows[] = [
        'audit_date' => $auditDate,
        'farmasi_total' => $farmasiTotal,
        'hospital_billing_total' => (int)($hospitalDay['hospital_billing_total'] ?? 0),
        'expected_pharmacy_total' => (int)($hospitalDay['expected_pharmacy_total'] ?? 0),
        'reference_amount' => $referenceAmount,
        'gap_amount' => $gapAmount,
        'ratio' => $ratio,
        'entry_count' => (int)($hospitalDay['entry_count'] ?? 0),
        'tx_count' => (int)($salesDay['tx_count'] ?? 0),
        'active_medics' => (int)($salesDay['active_medics'] ?? 0),
        'suspicious_score_total' => (int)($suspiciousByDateScore[$auditDate] ?? 0),
    ];
}

$focusDetailRows = [];
if ($focusDate !== '' && $focusMedicId > 0) {
    $detailOrderedWhere = "s.medic_user_id = ? AND DATE(s.created_at) = ?";
    $detailParams = [$focusMedicId, $focusDate];
    if ($salesHasUnitCode) {
        $detailOrderedWhere .= " AND COALESCE(s.unit_code, 'roxwood') = ?";
        $detailParams[] = $currentUnit;
    }

    $detailSql = "
        WITH ordered AS (
            SELECT
                s.id,
                s.medic_name,
                s.consumer_name,
                s.package_name,
                s.price,
                s.created_at,
                LAG(s.consumer_name) OVER (PARTITION BY s.medic_user_id ORDER BY s.created_at, s.id) AS prev_consumer,
                LAG(s.package_name) OVER (PARTITION BY s.medic_user_id ORDER BY s.created_at, s.id) AS prev_package,
                LAG(s.created_at) OVER (PARTITION BY s.medic_user_id ORDER BY s.created_at, s.id) AS prev_created_at,
                TIMESTAMPDIFF(
                    SECOND,
                    LAG(s.created_at) OVER (PARTITION BY s.medic_user_id ORDER BY s.created_at, s.id),
                    s.created_at
                ) AS diff_sec
            FROM sales s
            WHERE {$detailOrderedWhere}
        )
        SELECT
            *,
            CASE
                WHEN diff_sec = 0 AND prev_consumer IS NOT NULL AND consumer_name = prev_consumer THEN 'Same consumer same-second'
                WHEN diff_sec = 0 AND prev_consumer IS NOT NULL AND consumer_name <> prev_consumer THEN 'Beda konsumen di detik yang sama'
                WHEN diff_sec < 10 AND prev_consumer IS NOT NULL AND consumer_name <> prev_consumer THEN 'Beda konsumen di bawah 10 detik'
                WHEN diff_sec BETWEEN 10 AND 59 AND prev_consumer IS NOT NULL AND consumer_name <> prev_consumer THEN 'Beda konsumen 10-59 detik'
                WHEN diff_sec BETWEEN 60 AND 300 AND prev_consumer IS NOT NULL AND consumer_name <> prev_consumer AND package_name = prev_package THEN 'Beda konsumen 1-5 menit dengan paket sama'
                ELSE NULL
            END AS suspicious_reason
        FROM ordered
        WHERE
            (diff_sec = 0 AND prev_consumer IS NOT NULL)
            OR
            (diff_sec < 10 AND prev_consumer IS NOT NULL AND consumer_name <> prev_consumer)
            OR
            (diff_sec BETWEEN 10 AND 59 AND prev_consumer IS NOT NULL AND consumer_name <> prev_consumer)
            OR
            (diff_sec BETWEEN 60 AND 300 AND prev_consumer IS NOT NULL AND consumer_name <> prev_consumer AND package_name = prev_package)
        ORDER BY created_at DESC, id DESC
        LIMIT 300
    ";
    $stmtDetail = $pdo->prepare($detailSql);
    $stmtDetail->execute($detailParams);
    $focusDetailRows = $stmtDetail->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$summaryHospitalBilling = 0;
$summaryReferenceAmount = 0;
$summaryFarmasiRevenue = 0;
foreach ($dailyRows as $row) {
    $summaryFarmasiRevenue += (int)$row['farmasi_total'];
}
$summaryHospitalBilling = (int)($rangeBillingEntry['hospital_billing_amount'] ?? 0);
$summaryReferenceAmount = $summaryHospitalBilling;
$summaryGapAmount = max($summaryHospitalBilling - $summaryFarmasiRevenue, 0);
$summaryRatio = $summaryReferenceAmount > 0 ? (($summaryFarmasiRevenue / $summaryReferenceAmount) * 100) : null;

$totalSuspiciousScore = 0;
foreach ($suspiciousRows as $row) {
    $totalSuspiciousScore += (int)($row['anomaly_score'] ?? 0);
}

foreach ($suspiciousRows as &$row) {
    $row['reference_amount'] = $summaryReferenceAmount;
    $row['daily_gap_amount'] = $summaryGapAmount;
    $row['estimated_loss_amount'] = ($summaryGapAmount > 0 && $totalSuspiciousScore > 0)
        ? (int)round($summaryGapAmount * ((int)$row['anomaly_score'] / $totalSuspiciousScore))
        : 0;
}
unset($row);

$focusRow = null;
if ($focusDate !== '' && $focusMedicId > 0) {
    foreach ($suspiciousRows as $row) {
        if ($row['audit_date'] === $focusDate && (int)$row['medic_user_id'] === $focusMedicId) {
            $focusRow = $row;
            break;
        }
    }
}

if (($_GET['ajax'] ?? '') === 'review_detail') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$focusRow) {
        echo json_encode([
            'ok' => false,
            'message' => 'Detail audit tidak ditemukan untuk medis dan tanggal tersebut.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $focusReview = $focusRow['review'] ?? [];
    echo json_encode([
        'ok' => true,
        'focus' => [
            'audit_date' => (string)$focusRow['audit_date'],
            'audit_date_label' => date('d M Y', strtotime((string)$focusRow['audit_date'])),
            'medic_user_id' => (int)$focusRow['medic_user_id'],
            'medic_name' => (string)$focusRow['medic_name'],
            'level_label' => (string)($focusRow['level_meta']['label'] ?? '-'),
            'level_class' => (string)($focusRow['level_meta']['class'] ?? 'badge-secondary'),
            'anomaly_score' => (int)$focusRow['anomaly_score'],
            'estimated_loss_amount' => (int)($focusRow['estimated_loss_amount'] ?? 0),
            'estimated_loss_label' => farmasiAuditMoney((int)($focusRow['estimated_loss_amount'] ?? 0)),
            'batch_event_count' => (int)$focusRow['batch_event_count'],
            'same_package_1_5m' => (int)$focusRow['same_package_1_5m'],
            'diff_consumer_lt10' => (int)$focusRow['diff_consumer_lt10'],
            'diff_consumer_10_59' => (int)$focusRow['diff_consumer_10_59'],
        ],
        'review' => [
            'status' => (string)($focusReview['status'] ?? 'pending'),
            'question_prompt' => (string)($focusReview['question_prompt'] ?? ''),
            'medic_statement' => (string)($focusReview['medic_statement'] ?? ''),
            'reviewer_notes' => (string)($focusReview['reviewer_notes'] ?? ''),
        ],
        'details' => array_map(static function (array $detail): array {
            return [
                'created_at_label' => date('d M Y H:i', strtotime((string)$detail['created_at'])),
                'prev_created_at_label' => !empty($detail['prev_created_at']) ? date('H:i:s', strtotime((string)$detail['prev_created_at'])) : '-',
                'consumer_name' => (string)($detail['consumer_name'] ?? '-'),
                'prev_consumer' => (string)($detail['prev_consumer'] ?? '-'),
                'medic_name' => (string)($detail['medic_name'] ?? '-'),
                'package_name' => (string)($detail['package_name'] ?? '-'),
                'prev_package' => (string)($detail['prev_package'] ?? '-'),
                'price_label' => farmasiAuditMoney((int)($detail['price'] ?? 0)),
                'diff_sec' => (int)($detail['diff_sec'] ?? 0),
                'reason_label' => farmasiAuditDetailReasonLabel((string)($detail['suspicious_reason'] ?? '')),
            ];
        }, $focusDetailRows),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$topMedicMap = [];
foreach ($suspiciousRows as $row) {
    $medicId = (int)$row['medic_user_id'];
    if (!isset($topMedicMap[$medicId])) {
        $topMedicMap[$medicId] = [
            'medic_user_id' => $medicId,
            'medic_name' => (string)$row['medic_name'],
            'audit_days' => 0,
            'anomaly_score' => 0,
            'estimated_loss_amount' => 0,
            'dates' => [],
        ];
    }

    $topMedicMap[$medicId]['audit_days']++;
    $topMedicMap[$medicId]['anomaly_score'] += (int)$row['anomaly_score'];
    $topMedicMap[$medicId]['estimated_loss_amount'] += (int)$row['estimated_loss_amount'];
    $topMedicMap[$medicId]['dates'][] = (string)$row['audit_date'];
}

$topMedics = array_values($topMedicMap);
usort($topMedics, static function (array $left, array $right): int {
    $scoreCompare = (int)$right['anomaly_score'] <=> (int)$left['anomaly_score'];
    if ($scoreCompare !== 0) {
        return $scoreCompare;
    }

    return (int)$right['estimated_loss_amount'] <=> (int)$left['estimated_loss_amount'];
});

?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <div class="card">
            <div class="card-header-between">
                <div>
                    <h1 class="page-title">Audit Billing Farmasi</h1>
                    <p class="page-subtitle">Cross-check pemasukan rumah sakit terhadap transaksi farmasi penuh, ranking anomali medis, dan tindak lanjut investigasi per tanggal.</p>
                </div>
                <div class="badge-info"><?= htmlspecialchars($currentHospitalName) ?></div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success mb-4"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if (!$billingTableReady || !$reviewTableReady): ?>
            <div class="alert alert-warning mb-4">
                Jalankan SQL [`docs/sql/38_2026-05-16_farmasi_billing_audit.sql`](/d:/Project/Web/ems2/docs/sql/38_2026-05-16_farmasi_billing_audit.sql:1) agar form pemasukan rumah sakit dan review investigasi dapat dipakai penuh.
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <?= ems_icon('funnel', 'h-5 w-5') ?>
                <span>Filter Audit</span>
            </div>
            <form method="get" id="filterForm" class="filter-bar">
                <div class="filter-group">
                    <label for="rangeSelect">Rentang</label>
                    <select name="range" id="rangeSelect" class="form-control">
                        <option value="week1" <?= $range === 'week1' ? 'selected' : '' ?>>3 Minggu Lalu</option>
                        <option value="week2" <?= $range === 'week2' ? 'selected' : '' ?>>2 Minggu Lalu</option>
                        <option value="week3" <?= $range === 'week3' ? 'selected' : '' ?>>Minggu Lalu</option>
                        <option value="week4" <?= $range === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                        <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
                    </select>
                </div>
                <div class="filter-group filter-custom">
                    <label for="from">Tanggal Awal</label>
                    <input type="date" id="from" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                </div>
                <div class="filter-group filter-custom">
                    <label for="to">Tanggal Akhir</label>
                    <input type="date" id="to" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                </div>
                <div class="filter-group filter-action-end">
                    <button type="submit" class="btn-primary">Terapkan Audit</button>
                </div>
            </form>
        </div>

        <div class="grid gap-4 md:grid-cols-4 mb-4">
            <div class="card">
                <div class="meta-text">Pemasukan Rumah Sakit</div>
                <div class="text-2xl font-bold text-slate-900 mt-2"><?= farmasiAuditMoney($summaryHospitalBilling) ?></div>
                <div class="helper-note mt-2">Nilai final untuk rentang audit `<?= htmlspecialchars($rangeFrom, ENT_QUOTES, 'UTF-8') ?> s.d. <?= htmlspecialchars($rangeTo, ENT_QUOTES, 'UTF-8') ?>`.</div>
            </div>
            <div class="card">
                <div class="meta-text">Pembanding Audit</div>
                <div class="text-2xl font-bold text-slate-900 mt-2"><?= farmasiAuditMoney($summaryReferenceAmount) ?></div>
                <div class="helper-note mt-2">Menggunakan pemasukan rumah sakit sebagai acuan pembanding.</div>
            </div>
            <div class="card">
                <div class="meta-text">Realisasi Farmasi</div>
                <div class="text-2xl font-bold text-slate-900 mt-2"><?= farmasiAuditMoney($summaryFarmasiRevenue) ?></div>
                <div class="helper-note mt-2">
                    <?= $summaryRatio !== null ? 'Rasio ' . farmasiAuditPercent($summaryRatio) . ' terhadap pemasukan rumah sakit pada rentang ini.' : 'Belum ada input pemasukan rumah sakit untuk rentang ini.' ?>
                </div>
            </div>
            <div class="card">
                <div class="meta-text">Potensi Gap Audit</div>
                <div class="text-2xl font-bold text-red-600 mt-2"><?= farmasiAuditMoney($summaryGapAmount) ?></div>
                <div class="helper-note mt-2"><?= count($suspiciousRows) ?> medic-date janggal dalam periode ini.</div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[1.05fr_1.6fr]">
            <div class="space-y-4">
                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('banknotes', 'h-5 w-5') ?>
                        <span>Input Pemasukan Rumah Sakit</span>
                    </div>
                    <div class="helper-note mb-4">Masukkan hasil akhir pemasukan rumah sakit untuk rentang audit aktif `<?= htmlspecialchars($rangeFrom, ENT_QUOTES, 'UTF-8') ?> s.d. <?= htmlspecialchars($rangeTo, ENT_QUOTES, 'UTF-8') ?>`. Jika sudah ada, form ini akan memperbarui nilai yang lama.</div>

                    <form method="post" class="space-y-4">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="save_hospital_billing_entry" value="1">

                        <div class="form-group">
                            <label>Rentang Audit Aktif</label>
                            <input type="text" value="<?= htmlspecialchars($rangeFrom . ' s.d. ' . $rangeTo, ENT_QUOTES, 'UTF-8') ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label for="hospital_billing_amount">Total Pemasukan Rumah Sakit</label>
                            <input type="text" id="hospital_billing_amount" name="hospital_billing_amount" inputmode="numeric" value="<?= htmlspecialchars($rangeBillingEntry ? number_format((int)$rangeBillingEntry['hospital_billing_amount'], 0, '', '.') : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Contoh: 2.000.000" <?= $billingTableReady ? '' : 'disabled' ?> required>
                        </div>

                        <div class="form-group">
                            <label for="source_reference">Sumber / Referensi</label>
                            <input type="text" id="source_reference" name="source_reference" value="<?= htmlspecialchars((string)($rangeBillingEntry['source_reference'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Contoh: rumah sakit" <?= $billingTableReady ? '' : 'disabled' ?>>
                        </div>

                        <div class="form-group">
                            <label for="notes">Catatan Manual</label>
                            <textarea id="notes" name="notes" rows="3" placeholder="Contoh: billing mingguan rumah sakit" <?= $billingTableReady ? '' : 'disabled' ?>><?= htmlspecialchars((string)($rangeBillingEntry['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <button type="submit" class="btn-primary" <?= $billingTableReady ? '' : 'disabled' ?>><?= $rangeBillingEntry ? 'Perbarui Pemasukan Rumah Sakit' : 'Simpan Pemasukan Rumah Sakit' ?></button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('exclamation-triangle', 'h-5 w-5') ?>
                        <span>Top Medis Janggal</span>
                    </div>
                    <div class="table-wrapper">
                        <table class="table-custom" data-auto-datatable="true" data-dt-page-length="10" data-dt-order="[[2,&quot;desc&quot;]]">
                            <thead>
                                <tr>
                                    <th>Medis</th>
                                    <th>Hari Janggal</th>
                                    <th>Skor</th>
                                    <th>Estimasi Kerugian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($topMedics, 0, 12) as $medic): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$medic['medic_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text"><?= htmlspecialchars(implode(', ', array_slice(array_unique($medic['dates']), 0, 3)), ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td><?= (int)$medic['audit_days'] ?></td>
                                        <td><span class="badge-danger"><?= (int)$medic['anomaly_score'] ?></span></td>
                                        <td><?= farmasiAuditMoney((int)$medic['estimated_loss_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($topMedics === []): ?>
                                    <tr><td colspan="4" class="text-center text-slate-500">Belum ada medis yang terdeteksi janggal pada rentang ini.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('calculator', 'h-5 w-5') ?>
                        <span>Rumus Audit</span>
                    </div>
                    <div class="space-y-2 text-sm text-slate-700">
                        <div>`Skor = (<10 detik x 12) + (10-59 detik x 5) + (1-5 menit paket sama x 3) + (batch same-second x 18) + (max rows same-second x 2)`</div>
                        <div>`Estimasi kerugian medis = gap harian positif x (skor medis / total skor janggal hari itu)`</div>
                        <div>Same-consumer same-second tidak langsung dianggap curang karena bisa berasal dari satu submit multi-paket, tetapi tetap ditampilkan untuk verifikasi manual.</div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('calendar-days', 'h-5 w-5') ?>
                        <span>Ringkasan Farmasi per Tanggal</span>
                    </div>
                    <div class="table-wrapper">
                        <table class="table-custom" data-auto-datatable="true" data-dt-page-length="10" data-dt-order="[[0,&quot;desc&quot;]]">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Realisasi Farmasi</th>
                                    <th>Kontribusi Rentang</th>
                                    <th>Medis Aktif</th>
                                    <th>Skor Janggal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dailyRows as $row): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars(date('d M Y', strtotime((string)$row['audit_date'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text"><?= (int)$row['tx_count'] ?> transaksi, <?= (int)$row['active_medics'] ?> medis</div>
                                        </td>
                                        <td>
                                            <?= farmasiAuditMoney((int)$row['farmasi_total']) ?>
                                            <div class="meta-text"><?= $summaryFarmasiRevenue > 0 ? farmasiAuditPercent((((int)$row['farmasi_total']) / $summaryFarmasiRevenue) * 100) : '-' ?></div>
                                        </td>
                                        <td>
                                            <?= $summaryFarmasiRevenue > 0 ? farmasiAuditPercent((((int)$row['farmasi_total']) / $summaryFarmasiRevenue) * 100) : '-' ?>
                                        </td>
                                        <td>
                                            <span class="badge-secondary"><?= (int)$row['active_medics'] ?></span>
                                        </td>
                                        <td>
                                            <?php if ((int)$row['suspicious_score_total'] > 0): ?>
                                                <span class="badge-warning"><?= (int)$row['suspicious_score_total'] ?></span>
                                            <?php else: ?>
                                                <span class="badge-secondary">0</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($dailyRows === []): ?>
                                    <tr><td colspan="5" class="text-center text-slate-500">Belum ada data farmasi pada rentang audit ini.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                        <span>Ranking Audit Medis per Tanggal</span>
                    </div>
                    <div class="table-wrapper">
                        <table class="table-custom" data-auto-datatable="true" data-dt-page-length="10" data-dt-order="[[2,&quot;desc&quot;],[4,&quot;desc&quot;],[0,&quot;desc&quot;]]">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Medis</th>
                                    <th>Level</th>
                                    <th>Pola</th>
                                    <th>Kerugian Estimasi</th>
                                    <th>Tindak Lanjut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suspiciousRows as $row): ?>
                                    <?php $reviewMeta = farmasiAuditStatusMeta((string)(($row['review']['status'] ?? 'pending'))); ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d M Y', strtotime((string)$row['audit_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$row['medic_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text"><?= (int)$row['tx_count'] ?> transaksi, omzet <?= farmasiAuditMoney((int)$row['farmasi_amount']) ?></div>
                                        </td>
                                        <td data-order="<?= (int)($row['level_meta']['rank'] ?? 0) ?>">
                                            <span class="<?= htmlspecialchars((string)$row['level_meta']['class'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string)$row['level_meta']['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <div class="meta-text mt-1">Skor <?= (int)$row['anomaly_score'] ?></div>
                                        </td>
                                        <td class="text-sm leading-5 text-slate-700">
                                            <div>&lt;10 dtk beda konsumen: <strong><?= (int)$row['diff_consumer_lt10'] ?></strong></div>
                                            <div>10-59 dtk beda konsumen: <strong><?= (int)$row['diff_consumer_10_59'] ?></strong></div>
                                            <div>1-5 mnt paket sama: <strong><?= (int)$row['same_package_1_5m'] ?></strong></div>
                                            <div>Batch same-second: <strong><?= (int)$row['batch_event_count'] ?></strong> event / <?= (int)$row['batch_row_count'] ?> row</div>
                                        </td>
                                        <td>
                                            <strong class="text-red-600"><?= farmasiAuditMoney((int)$row['estimated_loss_amount']) ?></strong>
                                            <div class="meta-text">Gap hari itu <?= farmasiAuditMoney((int)$row['daily_gap_amount']) ?></div>
                                        </td>
                                        <td>
                                            <span class="<?= htmlspecialchars($reviewMeta['class'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($reviewMeta['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <div class="mt-2">
                                                <button
                                                    type="button"
                                                    class="btn-secondary btn-sm open-audit-review-modal"
                                                    data-review-date="<?= htmlspecialchars((string)$row['audit_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-review-medic="<?= (int)$row['medic_user_id'] ?>">
                                                    Review Detail
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($suspiciousRows === []): ?>
                                    <tr><td colspan="6" class="text-center text-slate-500">Belum ada pola transaksi janggal terdeteksi pada rentang audit ini.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('archive-box', 'h-5 w-5') ?>
                        <span>Pemasukan Rumah Sakit Rentang Ini</span>
                    </div>
                    <div class="table-wrapper">
                        <table class="table-custom" data-auto-datatable="true" data-dt-page-length="10" data-dt-order="[[0,&quot;desc&quot;]]">
                            <thead>
                                <tr>
                                    <th>Rentang</th>
                                    <th>Label</th>
                                    <th>Pemasukan RS</th>
                                    <th>Sumber</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hospitalEntries as $entry): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($rangeFrom . ' s.d. ' . $rangeTo, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$entry['service_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text"><?= htmlspecialchars((string)($entry['notes'] ?? 'Pemasukan rumah sakit final per rentang audit'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td><?= farmasiAuditMoney((int)$entry['hospital_billing_amount']) ?></td>
                                        <td><?= htmlspecialchars((string)($entry['source_reference'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($hospitalEntries === []): ?>
                                    <tr><td colspan="4" class="text-center text-slate-500">Belum ada pemasukan rumah sakit final untuk rentang ini.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</section>

<div id="farmasiAuditReviewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell" style="max-width: 1280px; width: min(96vw, 1280px);">
        <div class="modal-head">
            <div>
                <div id="farmasiAuditReviewModalTitle" class="modal-title">Investigasi Detail</div>
                <div id="farmasiAuditReviewModalMeta" class="meta-text mt-1">Hanya transaksi yang masuk pola janggal</div>
            </div>
            <button type="button" class="modal-close-btn" data-close-audit-review-modal aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <div class="modal-content">
            <div class="grid gap-4 md:grid-cols-4 mb-4">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="meta-text">Level Audit</div>
                    <div class="mt-2">
                        <span id="farmasiAuditReviewLevel" class="badge-secondary">-</span>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="meta-text">Skor Audit</div>
                    <div id="farmasiAuditReviewScore" class="text-xl font-bold text-slate-900 mt-2">0</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="meta-text">Estimasi Kerugian</div>
                    <div id="farmasiAuditReviewLoss" class="text-xl font-bold text-red-600 mt-2">$0</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="meta-text">Batch Same-Second</div>
                    <div id="farmasiAuditReviewBatch" class="text-xl font-bold text-slate-900 mt-2">0 event</div>
                </div>
            </div>

            <form method="post" id="farmasiAuditReviewForm" class="space-y-4 mb-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="save_farmasi_audit_review" value="1">
                <input type="hidden" name="review_date" id="farmasiAuditReviewDate" value="">
                <input type="hidden" name="medic_user_id" id="farmasiAuditReviewMedicId" value="">
                <input type="hidden" name="anomaly_score_snapshot" id="farmasiAuditReviewScoreInput" value="0">
                <input type="hidden" name="suspicious_tx_count" id="farmasiAuditReviewSuspiciousCount" value="0">
                <input type="hidden" name="same_second_batch_count" id="farmasiAuditReviewBatchCountInput" value="0">
                <input type="hidden" name="estimated_loss_amount" id="farmasiAuditReviewLossInput" value="0">

                <div class="form-group">
                    <label for="farmasiAuditReviewStatus">Status Review</label>
                    <select id="farmasiAuditReviewStatus" name="status" <?= $reviewTableReady ? '' : 'disabled' ?>>
                        <?php foreach (farmasiAuditReviewOptions() as $option): ?>
                            <option value="<?= htmlspecialchars($option['value'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="farmasiAuditReviewQuestion">Pertanyaan ke Medis</label>
                    <textarea id="farmasiAuditReviewQuestion" name="question_prompt" rows="3" placeholder="Tanyakan apakah ada kesalahan input, catatan manual yang baru dimasukkan setelah pelayanan, atau alasan batch input." <?= $reviewTableReady ? '' : 'disabled' ?>></textarea>
                </div>

                <div class="form-group">
                    <label for="farmasiAuditReviewStatement">Pernyataan Medis</label>
                    <textarea id="farmasiAuditReviewStatement" name="medic_statement" rows="4" placeholder="Isi jawaban / klarifikasi dari yang bersangkutan." <?= $reviewTableReady ? '' : 'disabled' ?>></textarea>
                </div>

                <div class="form-group">
                    <label for="farmasiAuditReviewNotes">Catatan Verifikator</label>
                    <textarea id="farmasiAuditReviewNotes" name="reviewer_notes" rows="4" placeholder="Tuliskan hasil cek manual, cross-check billing, dan penilaian akhir apakah ini celah sistem, keterlambatan input, atau pelanggaran." <?= $reviewTableReady ? '' : 'disabled' ?>></textarea>
                </div>

                <button type="submit" class="btn-primary" <?= $reviewTableReady ? '' : 'disabled' ?>>Simpan Review Investigasi</button>
            </form>

            <div class="table-wrapper">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Konsumen</th>
                            <th>Medis</th>
                            <th>Paket</th>
                            <th>Harga</th>
                            <th>Selisih</th>
                            <th>Pola Janggal</th>
                        </tr>
                    </thead>
                    <tbody id="farmasiAuditReviewDetailBody">
                        <tr><td colspan="7" class="text-center text-slate-500">Pilih review detail untuk melihat transaksi janggal.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary" data-close-audit-review-modal>Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var rangeSelect = document.getElementById('rangeSelect');
    var customFields = document.querySelectorAll('.filter-custom');
    var rupiahInput = document.getElementById('hospital_billing_amount');
    var auditReviewModal = document.getElementById('farmasiAuditReviewModal');
    var auditReviewOpenButtons = document.querySelectorAll('.open-audit-review-modal');
    var auditReviewCloseButtons = document.querySelectorAll('[data-close-audit-review-modal]');
    var auditReviewTitle = document.getElementById('farmasiAuditReviewModalTitle');
    var auditReviewMeta = document.getElementById('farmasiAuditReviewModalMeta');
    var auditReviewLevel = document.getElementById('farmasiAuditReviewLevel');
    var auditReviewScore = document.getElementById('farmasiAuditReviewScore');
    var auditReviewLoss = document.getElementById('farmasiAuditReviewLoss');
    var auditReviewBatch = document.getElementById('farmasiAuditReviewBatch');
    var auditReviewDateInput = document.getElementById('farmasiAuditReviewDate');
    var auditReviewMedicIdInput = document.getElementById('farmasiAuditReviewMedicId');
    var auditReviewScoreInput = document.getElementById('farmasiAuditReviewScoreInput');
    var auditReviewSuspiciousCount = document.getElementById('farmasiAuditReviewSuspiciousCount');
    var auditReviewBatchCountInput = document.getElementById('farmasiAuditReviewBatchCountInput');
    var auditReviewLossInput = document.getElementById('farmasiAuditReviewLossInput');
    var auditReviewStatus = document.getElementById('farmasiAuditReviewStatus');
    var auditReviewQuestion = document.getElementById('farmasiAuditReviewQuestion');
    var auditReviewStatement = document.getElementById('farmasiAuditReviewStatement');
    var auditReviewNotes = document.getElementById('farmasiAuditReviewNotes');
    var auditReviewDetailBody = document.getElementById('farmasiAuditReviewDetailBody');
    var auditDetailEndpointBase = <?= json_encode('farmasi_billing_audit.php?' . http_build_query([
        'ajax' => 'review_detail',
        'range' => $range,
        'from' => $rangeFrom,
        'to' => $rangeTo,
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function formatRupiah(value) {
        var digits = String(value || '').replace(/\D+/g, '');
        if (!digits) {
            return '';
        }

        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function syncCustomFields() {
        var isCustom = rangeSelect && rangeSelect.value === 'custom';
        customFields.forEach(function (field) {
            field.style.display = isCustom ? '' : 'none';
        });
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openAuditReviewModal() {
        if (!auditReviewModal) return;
        auditReviewModal.classList.remove('hidden');
        auditReviewModal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }

    function closeAuditReviewModal() {
        if (!auditReviewModal) return;
        auditReviewModal.classList.add('hidden');
        auditReviewModal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    function setAuditReviewLoadingState() {
        if (auditReviewTitle) auditReviewTitle.textContent = 'Investigasi Detail';
        if (auditReviewMeta) auditReviewMeta.textContent = 'Memuat transaksi janggal...';
        if (auditReviewLevel) {
            auditReviewLevel.className = 'badge-secondary';
            auditReviewLevel.textContent = '-';
        }
        if (auditReviewScore) auditReviewScore.textContent = '0';
        if (auditReviewLoss) auditReviewLoss.textContent = '$0';
        if (auditReviewBatch) auditReviewBatch.textContent = '0 event';
        if (auditReviewDateInput) auditReviewDateInput.value = '';
        if (auditReviewMedicIdInput) auditReviewMedicIdInput.value = '';
        if (auditReviewScoreInput) auditReviewScoreInput.value = '0';
        if (auditReviewSuspiciousCount) auditReviewSuspiciousCount.value = '0';
        if (auditReviewBatchCountInput) auditReviewBatchCountInput.value = '0';
        if (auditReviewLossInput) auditReviewLossInput.value = '0';
        if (auditReviewStatus) auditReviewStatus.value = 'pending';
        if (auditReviewQuestion) auditReviewQuestion.value = '';
        if (auditReviewStatement) auditReviewStatement.value = '';
        if (auditReviewNotes) auditReviewNotes.value = '';
        if (auditReviewDetailBody) {
            auditReviewDetailBody.innerHTML = '<tr><td colspan="7" class="text-center text-slate-500">Memuat transaksi janggal...</td></tr>';
        }
    }

    function populateAuditReviewModal(payload) {
        var focus = payload.focus || {};
        var review = payload.review || {};
        var details = Array.isArray(payload.details) ? payload.details : [];

        if (auditReviewTitle) {
            auditReviewTitle.textContent = 'Investigasi Detail: ' + (focus.medic_name || '-');
        }
        if (auditReviewMeta) {
            auditReviewMeta.textContent = (focus.audit_date_label || '-') + ' • Hanya transaksi yang masuk pola janggal';
        }
        if (auditReviewLevel) {
            auditReviewLevel.className = focus.level_class || 'badge-secondary';
            auditReviewLevel.textContent = focus.level_label || '-';
        }
        if (auditReviewScore) auditReviewScore.textContent = String(focus.anomaly_score || 0);
        if (auditReviewLoss) auditReviewLoss.textContent = focus.estimated_loss_label || '$0';
        if (auditReviewBatch) auditReviewBatch.textContent = String(focus.batch_event_count || 0) + ' event';
        if (auditReviewDateInput) auditReviewDateInput.value = focus.audit_date || '';
        if (auditReviewMedicIdInput) auditReviewMedicIdInput.value = String(focus.medic_user_id || 0);
        if (auditReviewScoreInput) auditReviewScoreInput.value = String(focus.anomaly_score || 0);
        if (auditReviewSuspiciousCount) {
            auditReviewSuspiciousCount.value = String((focus.diff_consumer_lt10 || 0) + (focus.diff_consumer_10_59 || 0) + (focus.same_package_1_5m || 0));
        }
        if (auditReviewBatchCountInput) auditReviewBatchCountInput.value = String(focus.batch_event_count || 0);
        if (auditReviewLossInput) auditReviewLossInput.value = String(focus.estimated_loss_amount || 0);
        if (auditReviewStatus) auditReviewStatus.value = review.status || 'pending';
        if (auditReviewQuestion) auditReviewQuestion.value = review.question_prompt || '';
        if (auditReviewStatement) auditReviewStatement.value = review.medic_statement || '';
        if (auditReviewNotes) auditReviewNotes.value = review.reviewer_notes || '';

        if (auditReviewDetailBody) {
            if (!details.length) {
                auditReviewDetailBody.innerHTML = '<tr><td colspan="7" class="text-center text-slate-500">Belum ada transaksi janggal untuk medis dan tanggal yang dipilih.</td></tr>';
                return;
            }

            auditReviewDetailBody.innerHTML = details.map(function (detail) {
                return '<tr>'
                    + '<td><strong>' + escapeHtml(detail.created_at_label || '-') + '</strong><div class="meta-text">Sebelumnya ' + escapeHtml(detail.prev_created_at_label || '-') + '</div></td>'
                    + '<td><strong>' + escapeHtml(detail.consumer_name || '-') + '</strong><div class="meta-text">Sebelumnya ' + escapeHtml(detail.prev_consumer || '-') + '</div></td>'
                    + '<td>' + escapeHtml(detail.medic_name || '-') + '</td>'
                    + '<td><strong>' + escapeHtml(detail.package_name || '-') + '</strong><div class="meta-text">Sebelumnya ' + escapeHtml(detail.prev_package || '-') + '</div></td>'
                    + '<td>' + escapeHtml(detail.price_label || '$0') + '</td>'
                    + '<td>' + escapeHtml(String(detail.diff_sec || 0)) + ' detik</td>'
                    + '<td>' + escapeHtml(detail.reason_label || '-') + '</td>'
                    + '</tr>';
            }).join('');
        }
    }

    function loadAuditReviewDetail(reviewDate, reviewMedicId) {
        if (!auditReviewModal) return;

        setAuditReviewLoadingState();
        openAuditReviewModal();

        var url = auditDetailEndpointBase + '&focus_date=' + encodeURIComponent(reviewDate) + '&focus_medic=' + encodeURIComponent(reviewMedicId);
        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.ok !== true) {
                    throw new Error(payload && payload.message ? payload.message : 'Gagal memuat detail audit.');
                }

                populateAuditReviewModal(payload);
            })
            .catch(function (error) {
                if (auditReviewMeta) auditReviewMeta.textContent = 'Gagal memuat detail audit';
                if (auditReviewDetailBody) {
                    auditReviewDetailBody.innerHTML = '<tr><td colspan="7" class="text-center text-red-600">' + escapeHtml(error.message || 'Gagal memuat detail audit.') + '</td></tr>';
                }
            });
    }

    if (rangeSelect) {
        syncCustomFields();
        rangeSelect.addEventListener('change', syncCustomFields);
    }

    if (rupiahInput) {
        rupiahInput.addEventListener('input', function () {
            rupiahInput.value = formatRupiah(rupiahInput.value);
        });

        if (rupiahInput.value) {
            rupiahInput.value = formatRupiah(rupiahInput.value);
        }
    }

    auditReviewOpenButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            loadAuditReviewDetail(button.dataset.reviewDate || '', button.dataset.reviewMedic || '0');
        });
    });

    auditReviewCloseButtons.forEach(function (button) {
        button.addEventListener('click', closeAuditReviewModal);
    });

    if (auditReviewModal) {
        auditReviewModal.addEventListener('click', function (event) {
            if (event.target === auditReviewModal) {
                closeAuditReviewModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && auditReviewModal && !auditReviewModal.classList.contains('hidden')) {
            closeAuditReviewModal();
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
