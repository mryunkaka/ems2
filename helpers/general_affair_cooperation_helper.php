<?php

function gaCooperationTablesReady(PDO $pdo): bool
{
    return ems_table_exists($pdo, 'general_affair_cooperations')
        && ems_table_exists($pdo, 'general_affair_cooperation_members')
        && ems_table_exists($pdo, 'general_affair_cooperation_packages');
}

function gaCooperationSalesColumnsReady(PDO $pdo): bool
{
    $required = [
        'original_price',
        'cooperation_discount_amount',
        'cooperation_id',
        'cooperation_member_id',
        'cooperation_period_type',
        'cooperation_period_key',
        'cooperation_claimed_free',
    ];

    foreach ($required as $column) {
        if (!ems_column_exists($pdo, 'sales', $column)) {
            return false;
        }
    }

    return true;
}

function gaCooperationPeriodOptions(): array
{
    return [
        'daily' => 'Per Hari',
        'weekly' => 'Per Minggu',
        'monthly' => 'Per Bulan',
    ];
}

function gaCooperationPeriodLabel(string $periodType): string
{
    $options = gaCooperationPeriodOptions();
    return $options[$periodType] ?? ucfirst($periodType);
}

function gaCooperationBuildPeriodMeta(string $periodType, ?DateTimeInterface $baseDate = null): array
{
    $base = $baseDate ? DateTimeImmutable::createFromInterface($baseDate) : new DateTimeImmutable('now');

    switch ($periodType) {
        case 'weekly':
            $start = $base->modify('monday this week')->setTime(0, 0, 0);
            $end = $start->modify('+6 days')->setTime(23, 59, 59);
            $key = $start->format('o-\WW');
            $label = $start->format('d M Y') . ' - ' . $end->format('d M Y');
            break;
        case 'monthly':
            $start = $base->modify('first day of this month')->setTime(0, 0, 0);
            $end = $base->modify('last day of this month')->setTime(23, 59, 59);
            $key = $start->format('Y-m');
            $label = $start->format('F Y');
            break;
        case 'daily':
        default:
            $start = $base->setTime(0, 0, 0);
            $end = $base->setTime(23, 59, 59);
            $key = $start->format('Y-m-d');
            $label = $start->format('d M Y');
            break;
    }

    return [
        'type' => $periodType,
        'key' => $key,
        'label' => $label,
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
    ];
}

function gaCooperationNormalizeMembersInput(array $rows): array
{
    $members = [];

    foreach ($rows as $row) {
        $citizenId = ems_normalize_citizen_id($row['citizen_id'] ?? '');
        $memberName = trim((string)($row['member_name'] ?? ''));

        if ($citizenId === '' && $memberName === '') {
            continue;
        }

        if (!ems_looks_like_citizen_id($citizenId)) {
            throw new InvalidArgumentException('Citizen ID anggota kerja sama tidak valid.');
        }

        $members[$citizenId] = [
            'citizen_id' => $citizenId,
            'member_name' => $memberName !== '' ? $memberName : null,
        ];
    }

    return array_values($members);
}

function gaCooperationResolveMember(PDO $pdo, string $citizenId, string $unitCode): ?array
{
    $citizenId = ems_normalize_citizen_id($citizenId);
    $unitCode = ems_normalize_unit_code($unitCode);

    if ($citizenId === '' || $unitCode === '' || !gaCooperationTablesReady($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            gc.id AS cooperation_id,
            gc.institution_name,
            gc.period_type,
            gc.notes,
            gc.unit_code,
            gcm.id AS member_id,
            gcm.member_name,
            gcm.citizen_id
        FROM general_affair_cooperations gc
        INNER JOIN general_affair_cooperation_members gcm
            ON gcm.cooperation_id = gc.id
           AND gcm.is_active = 1
        WHERE gc.is_active = 1
          AND gc.unit_code = :unit_code
          AND gcm.citizen_id = :citizen_id
        ORDER BY gc.id DESC, gcm.id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':unit_code' => $unitCode,
        ':citizen_id' => $citizenId,
    ]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        return null;
    }

    $stmtPackages = $pdo->prepare("
        SELECT
            gcp.package_id,
            p.name AS package_name,
            p.price,
            p.bandage_qty,
            p.ifaks_qty,
            p.painkiller_qty
        FROM general_affair_cooperation_packages gcp
        INNER JOIN packages p ON p.id = gcp.package_id
        WHERE gcp.cooperation_id = :cooperation_id
          AND COALESCE(p.unit_code, 'roxwood') = :unit_code
        ORDER BY p.name ASC
    ");
    $stmtPackages->execute([
        ':cooperation_id' => (int)$member['cooperation_id'],
        ':unit_code' => $unitCode,
    ]);
    $packageRows = $stmtPackages->fetchAll(PDO::FETCH_ASSOC);

    $periodMeta = gaCooperationBuildPeriodMeta((string)$member['period_type']);
    $usedByPackage = [];

    if (gaCooperationSalesColumnsReady($pdo)) {
        $stmtUsage = $pdo->prepare("
            SELECT
                package_id,
                MAX(created_at) AS last_claim_at
            FROM sales
            WHERE cooperation_member_id = :member_id
              AND cooperation_claimed_free = 1
              AND cooperation_period_key = :period_key
            GROUP BY package_id
        ");
        $stmtUsage->execute([
            ':member_id' => (int)$member['member_id'],
            ':period_key' => $periodMeta['key'],
        ]);

        foreach ($stmtUsage->fetchAll(PDO::FETCH_ASSOC) as $usageRow) {
            $usedByPackage[(int)$usageRow['package_id']] = [
                'claimed' => true,
                'last_claim_at' => (string)($usageRow['last_claim_at'] ?? ''),
            ];
        }
    }

    $packages = [];
    $eligibleIds = [];
    $usedIds = [];

    foreach ($packageRows as $packageRow) {
        $packageId = (int)($packageRow['package_id'] ?? 0);
        if ($packageId <= 0) {
            continue;
        }

        $eligibleIds[] = $packageId;
        $usage = $usedByPackage[$packageId] ?? ['claimed' => false, 'last_claim_at' => ''];
        if (!empty($usage['claimed'])) {
            $usedIds[] = $packageId;
        }

        $packages[] = [
            'id' => $packageId,
            'name' => (string)($packageRow['package_name'] ?? ''),
            'price' => (int)($packageRow['price'] ?? 0),
            'bandage_qty' => (int)($packageRow['bandage_qty'] ?? 0),
            'ifaks_qty' => (int)($packageRow['ifaks_qty'] ?? 0),
            'painkiller_qty' => (int)($packageRow['painkiller_qty'] ?? 0),
            'claimed_in_period' => !empty($usage['claimed']),
            'last_claim_at' => (string)($usage['last_claim_at'] ?? ''),
        ];
    }

    return [
        'cooperation_id' => (int)$member['cooperation_id'],
        'member_id' => (int)$member['member_id'],
        'institution_name' => (string)$member['institution_name'],
        'period_type' => (string)$member['period_type'],
        'period_label' => gaCooperationPeriodLabel((string)$member['period_type']),
        'period_meta' => $periodMeta,
        'notes' => (string)($member['notes'] ?? ''),
        'member_name' => (string)($member['member_name'] ?? ''),
        'citizen_id' => (string)$member['citizen_id'],
        'unit_code' => (string)$member['unit_code'],
        'eligible_package_ids' => array_values(array_unique($eligibleIds)),
        'used_package_ids' => array_values(array_unique($usedIds)),
        'packages' => $packages,
    ];
}

function gaCooperationFindPackagePricing(array $selectedPackageIds, ?array $memberStatus): array
{
    $results = [];
    $usedIds = [];

    if (!$memberStatus) {
        foreach ($selectedPackageIds as $packageId) {
            $results[(int)$packageId] = [
                'eligible' => false,
                'claimed_free' => false,
            ];
        }

        return $results;
    }

    $eligibleMap = array_fill_keys(array_map('intval', $memberStatus['eligible_package_ids'] ?? []), true);
    $alreadyUsedMap = array_fill_keys(array_map('intval', $memberStatus['used_package_ids'] ?? []), true);

    foreach ($selectedPackageIds as $packageId) {
        $packageId = (int)$packageId;
        $eligible = isset($eligibleMap[$packageId]);
        $claimedFree = false;

        if ($eligible && !isset($alreadyUsedMap[$packageId]) && !in_array($packageId, $usedIds, true)) {
            $claimedFree = true;
            $usedIds[] = $packageId;
        }

        $results[$packageId] = [
            'eligible' => $eligible,
            'claimed_free' => $claimedFree,
        ];
    }

    return $results;
}

function gaCooperationFetchFreeClaimRows(PDO $pdo, string $unitCode, string $startDateTime, string $endDateTime, int $institutionId = 0): array
{
    if (!gaCooperationTablesReady($pdo) || !gaCooperationSalesColumnsReady($pdo)) {
        return [];
    }

    $sql = "
        SELECT
            s.id,
            s.consumer_name,
            s.medic_name,
            s.medic_jabatan,
            s.package_id,
            s.package_name,
            s.price,
            s.original_price,
            s.cooperation_discount_amount,
            s.qty_bandage,
            s.qty_ifaks,
            s.qty_painkiller,
            s.identity_id,
            s.cooperation_id,
            s.cooperation_member_id,
            s.cooperation_period_type,
            s.cooperation_period_key,
            s.cooperation_claimed_free,
            s.created_at,
            gc.institution_name,
            gc.unit_code,
            gc.period_type AS institution_period_type,
            gcm.citizen_id,
            gcm.member_name
        FROM sales s
        INNER JOIN general_affair_cooperations gc
            ON gc.id = s.cooperation_id
        LEFT JOIN general_affair_cooperation_members gcm
            ON gcm.id = s.cooperation_member_id
        WHERE gc.unit_code = :unit_code
          AND COALESCE(s.cooperation_claimed_free, 0) = 1
          AND s.created_at BETWEEN :start_date AND :end_date
    ";

    $params = [
        ':unit_code' => ems_normalize_unit_code($unitCode),
        ':start_date' => $startDateTime,
        ':end_date' => $endDateTime,
    ];

    if ($institutionId > 0) {
        $sql .= " AND gc.id = :institution_id";
        $params[':institution_id'] = $institutionId;
    }

    $sql .= "
        ORDER BY s.created_at DESC, gc.institution_name ASC, s.consumer_name ASC, s.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gaCooperationGroupFreeClaimRows(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $groupKey = implode('|', [
            (string)($row['cooperation_id'] ?? ''),
            (string)($row['cooperation_member_id'] ?? ''),
            (string)($row['consumer_name'] ?? ''),
            (string)($row['medic_name'] ?? ''),
            (string)($row['medic_jabatan'] ?? ''),
            (string)($row['created_at'] ?? ''),
        ]);

        if (!isset($grouped[$groupKey])) {
            $memberName = trim((string)($row['member_name'] ?? ''));
            $citizenId = ems_normalize_citizen_id((string)($row['citizen_id'] ?? $row['consumer_name'] ?? ''));

            $grouped[$groupKey] = [
                'group_key' => $groupKey,
                'transaction_at' => (string)($row['created_at'] ?? ''),
                'institution_name' => (string)($row['institution_name'] ?? ''),
                'cooperation_id' => (int)($row['cooperation_id'] ?? 0),
                'cooperation_member_id' => (int)($row['cooperation_member_id'] ?? 0),
                'member_name' => $memberName !== '' ? $memberName : $citizenId,
                'citizen_id' => $citizenId,
                'medic_name' => (string)($row['medic_name'] ?? ''),
                'medic_jabatan' => (string)($row['medic_jabatan'] ?? ''),
                'period_type' => (string)(($row['cooperation_period_type'] ?? '') !== ''
                    ? $row['cooperation_period_type']
                    : ($row['institution_period_type'] ?? '')),
                'period_key' => (string)($row['cooperation_period_key'] ?? ''),
                'package_names' => [],
                'package_ids' => [],
                'sale_ids' => [],
                'total_original_price' => 0,
                'total_discount_amount' => 0,
                'total_final_price' => 0,
                'total_bandage' => 0,
                'total_ifaks' => 0,
                'total_painkiller' => 0,
                'package_count' => 0,
            ];
        }

        $grouped[$groupKey]['package_names'][] = trim((string)($row['package_name'] ?? ''));
        $grouped[$groupKey]['package_ids'][] = (int)($row['package_id'] ?? 0);
        $grouped[$groupKey]['sale_ids'][] = (int)($row['id'] ?? 0);
        $grouped[$groupKey]['total_original_price'] += (int)($row['original_price'] ?? $row['price'] ?? 0);
        $grouped[$groupKey]['total_discount_amount'] += (int)($row['cooperation_discount_amount'] ?? 0);
        $grouped[$groupKey]['total_final_price'] += (int)($row['price'] ?? 0);
        $grouped[$groupKey]['total_bandage'] += (int)($row['qty_bandage'] ?? 0);
        $grouped[$groupKey]['total_ifaks'] += (int)($row['qty_ifaks'] ?? 0);
        $grouped[$groupKey]['total_painkiller'] += (int)($row['qty_painkiller'] ?? 0);
        $grouped[$groupKey]['package_count']++;
    }

    foreach ($grouped as &$item) {
        $packageNames = array_values(array_filter(array_map('trim', $item['package_names'])));
        $packageIds = array_values(array_filter(array_map('intval', $item['package_ids'])));
        $saleIds = array_values(array_filter(array_map('intval', $item['sale_ids'])));

        $item['package_names'] = array_values(array_unique($packageNames));
        $item['package_ids'] = array_values(array_unique($packageIds));
        $item['sale_ids'] = array_values(array_unique($saleIds));
        $item['package_summary'] = implode(', ', $item['package_names']);
        $item['period_label'] = gaCooperationHistoryPeriodLabel(
            (string)($item['period_type'] ?? ''),
            (string)($item['period_key'] ?? '')
        );
    }
    unset($item);

    return array_values($grouped);
}

function gaCooperationHistorySummary(array $groupedRows): array
{
    $institutionIds = [];
    $memberKeys = [];
    $packageTotal = 0;
    $discountTotal = 0;

    foreach ($groupedRows as $row) {
        $institutionId = (int)($row['cooperation_id'] ?? 0);
        $memberKey = (string)($row['cooperation_member_id'] ?? '') . '|' . (string)($row['citizen_id'] ?? '');

        if ($institutionId > 0) {
            $institutionIds[$institutionId] = true;
        }
        if (trim($memberKey, '|') !== '') {
            $memberKeys[$memberKey] = true;
        }

        $packageTotal += (int)($row['package_count'] ?? 0);
        $discountTotal += (int)($row['total_discount_amount'] ?? 0);
    }

    return [
        'transactions' => count($groupedRows),
        'institutions' => count($institutionIds),
        'members' => count($memberKeys),
        'packages' => $packageTotal,
        'discount_total' => $discountTotal,
    ];
}

function gaCooperationHistoryPeriodLabel(string $periodType, string $periodKey): string
{
    $periodType = trim($periodType);
    $periodKey = trim($periodKey);

    if ($periodType === '' || $periodKey === '') {
        return '-';
    }

    try {
        if ($periodType === 'daily') {
            $date = new DateTimeImmutable($periodKey);
            return $date->format('d M Y');
        }

        if ($periodType === 'monthly') {
            $date = new DateTimeImmutable($periodKey . '-01');
            return $date->format('F Y');
        }

        if ($periodType === 'weekly' && preg_match('/^(\d{4})-W(\d{2})$/', $periodKey, $matches)) {
            $date = (new DateTimeImmutable())->setISODate((int)$matches[1], (int)$matches[2])->setTime(0, 0, 0);
            $end = $date->modify('+6 days');
            return $date->format('d M Y') . ' - ' . $end->format('d M Y');
        }
    } catch (Throwable $e) {
        return $periodKey;
    }

    return $periodKey;
}
