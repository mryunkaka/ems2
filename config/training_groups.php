<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

function ems_training_groups_tables_ready(PDO $pdo): bool
{
    return ems_table_exists($pdo, 'training_groups') && ems_table_exists($pdo, 'training_group_members');
}

function ems_training_availability_tables_ready(PDO $pdo): bool
{
    return ems_table_exists($pdo, 'training_user_availability') && ems_table_exists($pdo, 'training_user_availability_sessions');
}

function ems_training_member_positions(): array
{
    return ['trainee', 'paramedic', 'co_asst'];
}

function ems_training_is_manager_role(string $role): bool
{
    return strcasecmp(trim($role), 'Staff') !== 0;
}

function ems_training_medical_tool_catalog(): array
{
    return [
        ['name' => 'Stetoskop', 'philosophy' => 'Melambangkan ketelitian dalam mendengar, memahami, dan merespons kebutuhan tim dengan presisi.'],
        ['name' => 'Defibrilator', 'philosophy' => 'Melambangkan keberanian mengambil tindakan cepat saat tim menghadapi tekanan tinggi.'],
        ['name' => 'Laringoskop', 'philosophy' => 'Melambangkan kemampuan membuka jalan saat situasi rumit dan keputusan harus jelas.'],
        ['name' => 'Ventilator', 'philosophy' => 'Melambangkan dukungan berkelanjutan agar anggota tim tetap stabil dan produktif.'],
        ['name' => 'Infus', 'philosophy' => 'Melambangkan konsistensi suplai energi, disiplin, dan ritme kerja yang terjaga.'],
        ['name' => 'USG', 'philosophy' => 'Melambangkan ketajaman membaca situasi sebelum menentukan langkah bersama.'],
        ['name' => 'Otoskop', 'philosophy' => 'Melambangkan perhatian pada detail kecil yang sering menentukan kualitas hasil besar.'],
        ['name' => 'Nebulizer', 'philosophy' => 'Melambangkan kemampuan menenangkan keadaan dan memulihkan fokus tim.'],
        ['name' => 'Forceps', 'philosophy' => 'Melambangkan ketepatan tangan dan koordinasi saat menangani pekerjaan sensitif.'],
        ['name' => 'Syringe Pump', 'philosophy' => 'Melambangkan kontrol yang presisi, stabil, dan tidak tergesa-gesa dalam eksekusi.'],
        ['name' => 'Pulse Oximeter', 'philosophy' => 'Melambangkan kebiasaan memantau kondisi tim secara real-time dan objektif.'],
        ['name' => 'Ambu Bag', 'philosophy' => 'Melambangkan kesiapan menjadi penopang utama ketika tim butuh dorongan cepat.'],
    ];
}

function ems_training_group_fallback_identities(array $draftGroups): array
{
    $catalog = ems_training_medical_tool_catalog();
    $identities = [];

    foreach ($draftGroups as $index => $group) {
        $tool = $catalog[$index % count($catalog)];
        $mentorNames = array_column((array)($group['mentors'] ?? []), 'full_name');
        $identities[] = [
            'name' => 'Kelompok ' . $tool['name'],
            'philosophy' => $tool['philosophy'],
            'mentor_summary' => $mentorNames !== [] ? ('Mentor: ' . implode(', ', $mentorNames)) : 'Mentor akan mengikuti pembagian online yang tersedia.',
        ];
    }

    return $identities;
}

function ems_training_generate_group_identities(PDO $pdo, array $draftGroups, ?int $createdBy = null): array
{
    $fallback = ems_training_group_fallback_identities($draftGroups);
    $settings = ems_ai_get_settings($pdo);

    if (empty($settings['is_enabled']) || trim((string)($settings['gemini_api_key'] ?? '')) === '') {
        return $fallback;
    }

    try {
        $catalogNames = array_map(static fn(array $item): string => $item['name'], ems_training_medical_tool_catalog());
        $payload = [];

        foreach ($draftGroups as $index => $group) {
            $payload[] = [
                'group_number' => $index + 1,
                'batch' => (int)($group['batch'] ?? 0),
                'trainees' => array_column((array)($group['trainees'] ?? []), 'full_name'),
                'mentors' => array_column((array)($group['mentors'] ?? []), 'full_name'),
            ];
        }

        $response = ems_gemini_generate_content(
            $pdo,
            $settings,
            [
                [
                    'role' => 'user',
                    'parts' => [[
                        'text' => "Buat JSON valid dengan format {\"groups\":[{\"group_number\":1,\"name\":\"Kelompok ...\",\"philosophy\":\"...\",\"mentor_summary\":\"...\"}]}. "
                            . "Gunakan nama alat medis yang konkret dan berbeda untuk setiap kelompok. "
                            . "Filosofi harus singkat, relevan dengan alat medis, dan terkait dinamika kelompok trainee serta mentor. "
                            . "Pilihan alat medis wajib dari daftar ini: " . implode(', ', $catalogNames) . ". "
                            . "Data kelompok: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ],
            ],
            (string)($settings['default_model'] ?? 'gemini-2.5-flash'),
            'training_group_identity_generator',
            $createdBy
        );

        $decoded = json_decode((string)($response['text'] ?? ''), true);
        $groups = $decoded['groups'] ?? null;
        if (!is_array($groups) || count($groups) !== count($draftGroups)) {
            return $fallback;
        }

        $result = [];
        foreach ($groups as $index => $group) {
            $result[] = [
                'name' => trim((string)($group['name'] ?? '')) !== '' ? trim((string)$group['name']) : $fallback[$index]['name'],
                'philosophy' => trim((string)($group['philosophy'] ?? '')) !== '' ? trim((string)$group['philosophy']) : $fallback[$index]['philosophy'],
                'mentor_summary' => trim((string)($group['mentor_summary'] ?? '')) !== '' ? trim((string)$group['mentor_summary']) : $fallback[$index]['mentor_summary'],
            ];
        }

        return $result;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function ems_training_fetch_registered_batch_member_count(PDO $pdo, string $unitCode, int $batch): int
{
    $positions = ems_training_member_positions();
    $placeholders = implode(',', array_fill(0, count($positions), '?'));
    $params = array_merge([$unitCode, $batch], $positions);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM user_rh
        WHERE is_active = 1
          AND COALESCE(unit_code, 'roxwood') = ?
          AND batch = ?
          AND position IN ($placeholders)
    ");
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function ems_training_fetch_batch_members(PDO $pdo, string $unitCode, int $batch): array
{
    $positions = ems_training_member_positions();
    $placeholders = implode(',', array_fill(0, count($positions), '?'));
    $params = array_merge([$unitCode, $batch], $positions);

    $availabilityJoin = ems_training_availability_tables_ready($pdo)
        ? "LEFT JOIN training_user_availability tua ON tua.user_id = ur.id"
        : "";

    $availabilitySelect = ems_training_availability_tables_ready($pdo)
        ? "COALESCE(tua.status, 'offline') AS availability_status"
        : "'offline' AS availability_status";

    $stmt = $pdo->prepare("
        SELECT
            ur.id,
            ur.full_name,
            ur.batch,
            ur.position,
            ur.role,
            ur.division,
            ur.jenis_kelamin,
            {$availabilitySelect}
        FROM user_rh ur
        {$availabilityJoin}
        WHERE ur.is_active = 1
          AND ur.role = 'Staff'
          AND COALESCE(ur.unit_code, 'roxwood') = ?
          AND ur.batch = ?
          AND ur.position IN ($placeholders)
        ORDER BY ur.full_name ASC
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_training_fetch_online_trainees(PDO $pdo, string $unitCode, int $batch): array
{
    if (!ems_training_availability_tables_ready($pdo)) {
        return [];
    }

    $positions = ems_training_member_positions();
    $placeholders = implode(',', array_fill(0, count($positions), '?'));
    $params = array_merge([$batch, $unitCode], $positions);

    $stmt = $pdo->prepare("
        SELECT ur.id, ur.full_name, ur.batch, ur.position, ur.role, ur.division, ur.jenis_kelamin
        FROM user_rh ur
        JOIN training_user_availability tua ON tua.user_id = ur.id
        WHERE tua.status = 'online'
          AND ur.is_active = 1
          AND ur.role = 'Staff'
          AND ur.batch = ?
          AND COALESCE(ur.unit_code, 'roxwood') = ?
          AND ur.position IN ($placeholders)
        ORDER BY ur.full_name ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_training_fetch_online_managers(PDO $pdo, string $unitCode): array
{
    if (!ems_training_availability_tables_ready($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT ur.id, ur.full_name, ur.batch, ur.position, ur.role, ur.division, ur.jenis_kelamin
        FROM user_rh ur
        JOIN training_user_availability tua ON tua.user_id = ur.id
        WHERE tua.status = 'online'
          AND ur.is_active = 1
          AND ur.role <> 'Staff'
          AND COALESCE(ur.unit_code, 'roxwood') = ?
        ORDER BY ur.full_name ASC
    ");
    $stmt->execute([$unitCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_training_member_is_female(array $member): bool
{
    return trim((string)($member['jenis_kelamin'] ?? '')) === 'Perempuan';
}

function ems_training_group_female_count(array $group): int
{
    $count = 0;
    foreach ((array)($group['trainees'] ?? []) as $member) {
        if (ems_training_member_is_female($member)) {
            $count++;
        }
    }
    return $count;
}

function ems_training_pick_group_index_for_member(array $groups, bool $femaleOnlyPriority = false): int
{
    $bestIndex = 0;
    $bestFemaleCount = null;
    $bestTotalCount = null;

    foreach ($groups as $index => $group) {
        $femaleCount = ems_training_group_female_count($group);
        $totalCount = count((array)($group['trainees'] ?? []));

        if ($bestFemaleCount === null || $bestTotalCount === null) {
            $bestIndex = $index;
            $bestFemaleCount = $femaleCount;
            $bestTotalCount = $totalCount;
            continue;
        }

        if ($femaleOnlyPriority) {
            if ($femaleCount < $bestFemaleCount || ($femaleCount === $bestFemaleCount && $totalCount < $bestTotalCount)) {
                $bestIndex = $index;
                $bestFemaleCount = $femaleCount;
                $bestTotalCount = $totalCount;
            }
            continue;
        }

        if ($totalCount < $bestTotalCount || ($totalCount === $bestTotalCount && $femaleCount < $bestFemaleCount)) {
            $bestIndex = $index;
            $bestFemaleCount = $femaleCount;
            $bestTotalCount = $totalCount;
        }
    }

    return $bestIndex;
}

function ems_training_fetch_active_member_ids(PDO $pdo, string $unitCode, ?string $memberRole = null): array
{
    $sql = "
        SELECT DISTINCT tgm.user_id
        FROM training_group_members tgm
        JOIN training_groups tg ON tg.id = tgm.group_id
        WHERE tg.unit_code = ?
          AND tg.status = 'active'
          AND tgm.is_active = 1
    ";
    $params = [$unitCode];

    if ($memberRole !== null) {
        $sql .= " AND tgm.member_role = ?";
        $params[] = $memberRole;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function ems_training_fetch_active_groups(PDO $pdo, string $unitCode, ?int $batch = null): array
{
    $sql = "
        SELECT tg.*
        FROM training_groups tg
        WHERE tg.unit_code = ?
          AND tg.status = 'active'
    ";
    $params = [$unitCode];

    if ($batch !== null) {
        $sql .= " AND tg.batch = ?";
        $params[] = $batch;
    }

    $sql .= " ORDER BY tg.batch ASC, tg.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_training_attach_group_members(PDO $pdo, array $groups): array
{
    if ($groups === []) {
        return [];
    }

    $groupIds = array_map(static fn(array $group): int => (int)$group['id'], $groups);
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            tgm.group_id,
            tgm.member_role,
            tgm.assignment_source,
            ur.id AS user_id,
            ur.full_name,
            ur.role,
            ur.position,
            ur.batch,
            ur.division,
            ur.jenis_kelamin
        FROM training_group_members tgm
        JOIN user_rh ur ON ur.id = tgm.user_id
        WHERE tgm.group_id IN ($placeholders)
          AND tgm.is_active = 1
        ORDER BY tgm.member_role DESC, ur.full_name ASC
    ");
    $stmt->execute($groupIds);
    $memberRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $groupMap = [];
    foreach ($groups as $group) {
        $group['trainees'] = [];
        $group['mentors'] = [];
        $groupMap[(int)$group['id']] = $group;
    }

    foreach ($memberRows as $member) {
        $groupId = (int)$member['group_id'];
        if (!isset($groupMap[$groupId])) {
            continue;
        }

        if (($member['member_role'] ?? '') === 'mentor') {
            $groupMap[$groupId]['mentors'][] = $member;
        } else {
            $groupMap[$groupId]['trainees'][] = $member;
        }
    }

    return array_values($groupMap);
}

function ems_training_insert_group_member(PDO $pdo, int $groupId, int $userId, string $memberRole, string $assignmentSource = 'generated'): void
{
    $check = $pdo->prepare("
        SELECT id
        FROM training_group_members
        WHERE group_id = ?
          AND user_id = ?
          AND member_role = ?
          AND is_active = 1
        LIMIT 1
    ");
    $check->execute([$groupId, $userId, $memberRole]);
    if ($check->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO training_group_members
        (
            group_id,
            user_id,
            member_role,
            assignment_source,
            is_active,
            assigned_at
        ) VALUES (?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$groupId, $userId, $memberRole, $assignmentSource]);
}

function ems_training_generate_groups(PDO $pdo, string $unitCode, int $batch, int $groupSize, array $preferredManagerIds = [], ?int $generatedBy = null): array
{
    if ($groupSize < 1) {
        throw new RuntimeException('Jumlah trainee per kelompok minimal 1.');
    }

    $trainees = ems_training_fetch_online_trainees($pdo, $unitCode, $batch);
    if ($trainees === []) {
        throw new RuntimeException('Tidak ada trainee online pada batch yang dipilih.');
    }

    $managers = ems_training_fetch_online_managers($pdo, $unitCode);
    $preferredManagerIds = array_values(array_unique(array_map('intval', $preferredManagerIds)));

    shuffle($trainees);
    shuffle($managers);

    usort($managers, static function (array $left, array $right) use ($preferredManagerIds): int {
        $leftPreferred = in_array((int)$left['id'], $preferredManagerIds, true);
        $rightPreferred = in_array((int)$right['id'], $preferredManagerIds, true);
        return (int)$rightPreferred <=> (int)$leftPreferred;
    });

    $groupCount = (int)ceil(count($trainees) / max(1, $groupSize));
    $draftGroups = [];
    for ($i = 0; $i < $groupCount; $i++) {
        $draftGroups[$i] = [
            'batch' => $batch,
            'group_code' => sprintf('B%02d-G%02d', $batch, $i + 1),
            'target_member_count' => $groupSize,
            'trainees' => [],
            'mentors' => [],
        ];
    }

    $femaleMembers = [];
    $otherMembers = [];
    foreach ($trainees as $trainee) {
        if (ems_training_member_is_female($trainee)) {
            $femaleMembers[] = $trainee;
        } else {
            $otherMembers[] = $trainee;
        }
    }

    foreach ($femaleMembers as $member) {
        $groupIndex = ems_training_pick_group_index_for_member($draftGroups, true);
        $draftGroups[$groupIndex]['trainees'][] = $member;
    }

    foreach ($otherMembers as $member) {
        $groupIndex = ems_training_pick_group_index_for_member($draftGroups, false);
        $draftGroups[$groupIndex]['trainees'][] = $member;
    }

    foreach ($draftGroups as $index => $_group) {
        if (isset($managers[$index])) {
            $draftGroups[$index]['mentors'][] = $managers[$index];
        }
    }

    if (count($managers) > $groupCount) {
        for ($managerIndex = $groupCount; $managerIndex < count($managers); $managerIndex++) {
            $draftGroups[$managerIndex % $groupCount]['mentors'][] = $managers[$managerIndex];
        }
    }

    $identities = ems_training_generate_group_identities($pdo, $draftGroups, $generatedBy);

    $pdo->beginTransaction();
    try {
        $stmtCloseMembers = $pdo->prepare("
            UPDATE training_group_members tgm
            JOIN training_groups tg ON tg.id = tgm.group_id
            SET tgm.is_active = 0,
                tgm.unassigned_at = NOW()
            WHERE tg.unit_code = ?
              AND tg.batch = ?
              AND tg.status = 'active'
              AND tgm.is_active = 1
        ");
        $stmtCloseMembers->execute([$unitCode, $batch]);

        $stmtCloseGroups = $pdo->prepare("
            UPDATE training_groups
            SET status = 'closed',
                updated_at = NOW()
            WHERE unit_code = ?
              AND batch = ?
              AND status = 'active'
        ");
        $stmtCloseGroups->execute([$unitCode, $batch]);

        $stmtInsertGroup = $pdo->prepare("
            INSERT INTO training_groups
            (
                unit_code,
                batch,
                group_code,
                group_name,
                group_philosophy,
                mentor_summary,
                target_member_count,
                generated_by,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        foreach ($draftGroups as $index => $group) {
            $identity = $identities[$index] ?? ems_training_group_fallback_identities([$group])[0];
            $stmtInsertGroup->execute([
                $unitCode,
                $batch,
                $group['group_code'],
                $identity['name'],
                $identity['philosophy'],
                $identity['mentor_summary'],
                $groupSize,
                $generatedBy,
            ]);
            $groupId = (int)$pdo->lastInsertId();

            foreach ($group['trainees'] as $trainee) {
                ems_training_insert_group_member($pdo, $groupId, (int)$trainee['id'], 'trainee', 'generated');
            }

            foreach ($group['mentors'] as $mentor) {
                ems_training_insert_group_member($pdo, $groupId, (int)$mentor['id'], 'mentor', in_array((int)$mentor['id'], $preferredManagerIds, true) ? 'manual_manager' : 'generated');
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'group_count' => $groupCount,
        'trainee_count' => count($trainees),
        'manager_count' => count($managers),
    ];
}

function ems_training_auto_fill_groups(PDO $pdo, string $unitCode, ?int $batch = null): array
{
    if (!ems_training_groups_tables_ready($pdo)) {
        return ['trainees_assigned' => 0, 'mentors_assigned' => 0];
    }

    $groups = ems_training_attach_group_members($pdo, ems_training_fetch_active_groups($pdo, $unitCode, $batch));
    if ($groups === []) {
        return ['trainees_assigned' => 0, 'mentors_assigned' => 0];
    }

    $assignedTraineeIds = ems_training_fetch_active_member_ids($pdo, $unitCode, 'trainee');
    $assignedMentorIds = ems_training_fetch_active_member_ids($pdo, $unitCode, 'mentor');

    $traineesAssigned = 0;
    $mentorsAssigned = 0;

    $groupIndexesByBatch = [];
    foreach ($groups as $index => $group) {
        $groupIndexesByBatch[(int)$group['batch']][] = $index;
    }

    foreach (array_keys($groupIndexesByBatch) as $groupBatch) {
        $onlineTrainees = array_values(array_filter(
            ems_training_fetch_online_trainees($pdo, $unitCode, (int)$groupBatch),
            static fn(array $row): bool => !in_array((int)$row['id'], $assignedTraineeIds, true)
        ));

        foreach ($onlineTrainees as $trainee) {
            usort($groupIndexesByBatch[$groupBatch], static function (int $left, int $right) use (&$groups, $trainee): int {
                $leftFemale = ems_training_group_female_count($groups[$left]);
                $rightFemale = ems_training_group_female_count($groups[$right]);
                $leftCount = count($groups[$left]['trainees']);
                $rightCount = count($groups[$right]['trainees']);

                if (ems_training_member_is_female($trainee)) {
                    if ($leftFemale !== $rightFemale) {
                        return $leftFemale <=> $rightFemale;
                    }

                    return $leftCount <=> $rightCount;
                }

                if ($leftCount !== $rightCount) {
                    return $leftCount <=> $rightCount;
                }

                return $leftFemale <=> $rightFemale;
            });

            $assigned = false;
            foreach ($groupIndexesByBatch[$groupBatch] as $groupIndex) {
                $currentCount = count($groups[$groupIndex]['trainees']);
                $targetCount = (int)($groups[$groupIndex]['target_member_count'] ?? 0);
                if ($currentCount >= $targetCount) {
                    continue;
                }

                ems_training_insert_group_member($pdo, (int)$groups[$groupIndex]['id'], (int)$trainee['id'], 'trainee', 'auto_online_fill');
                $groups[$groupIndex]['trainees'][] = $trainee;
                $assignedTraineeIds[] = (int)$trainee['id'];
                $traineesAssigned++;
                $assigned = true;
                break;
            }

            if (!$assigned) {
                break;
            }
        }
    }

    $onlineManagers = array_values(array_filter(
        ems_training_fetch_online_managers($pdo, $unitCode),
        static fn(array $row): bool => !in_array((int)$row['id'], $assignedMentorIds, true)
    ));

    foreach ($onlineManagers as $manager) {
        usort($groups, static function (array $left, array $right): int {
            return count($left['mentors']) <=> count($right['mentors']);
        });

        foreach ($groups as &$group) {
            if (count($group['mentors']) >= 2) {
                continue;
            }

            ems_training_insert_group_member($pdo, (int)$group['id'], (int)$manager['id'], 'mentor', 'auto_online_fill');
            $group['mentors'][] = $manager;
            $assignedMentorIds[] = (int)$manager['id'];
            $mentorsAssigned++;
            break;
        }
        unset($group);
    }

    return [
        'trainees_assigned' => $traineesAssigned,
        'mentors_assigned' => $mentorsAssigned,
    ];
}

function ems_training_fetch_user_active_assignments(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || !ems_training_groups_tables_ready($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            tg.id AS group_id,
            tg.batch,
            tg.group_code,
            tg.group_name,
            tg.group_philosophy,
            tgm.member_role
        FROM training_group_members tgm
        JOIN training_groups tg ON tg.id = tgm.group_id
        WHERE tgm.user_id = ?
          AND tgm.is_active = 1
          AND tg.status = 'active'
        ORDER BY tg.batch ASC, tg.id ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_training_close_active_groups(PDO $pdo, string $unitCode, int $batch): int
{
    if (!ems_training_groups_tables_ready($pdo)) {
        return 0;
    }

    $pdo->beginTransaction();
    try {
        $stmtMembers = $pdo->prepare("
            UPDATE training_group_members tgm
            JOIN training_groups tg ON tg.id = tgm.group_id
            SET
                tgm.is_active = 0,
                tgm.unassigned_at = NOW()
            WHERE tg.unit_code = ?
              AND tg.batch = ?
              AND tg.status = 'active'
              AND tgm.is_active = 1
        ");
        $stmtMembers->execute([$unitCode, $batch]);

        $stmtGroups = $pdo->prepare("
            UPDATE training_groups
            SET
                status = 'closed',
                updated_at = NOW()
            WHERE unit_code = ?
              AND batch = ?
              AND status = 'active'
        ");
        $stmtGroups->execute([$unitCode, $batch]);
        $affected = (int)$stmtGroups->rowCount();

        $pdo->commit();
        return $affected;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
