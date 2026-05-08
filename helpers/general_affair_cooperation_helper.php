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
