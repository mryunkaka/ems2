<?php

function ems_dispatcher_ensure_tables(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatcher_assignments (
            id INT(11) NOT NULL AUTO_INCREMENT,
            assignment_code VARCHAR(40) NOT NULL,
            status_code VARCHAR(30) NOT NULL,
            status_label_custom VARCHAR(100) NULL,
            coordinate VARCHAR(100) NULL,
            location_name VARCHAR(150) NULL,
            koordinasi_note TEXT NULL,
            note TEXT NULL,
            unit_code VARCHAR(20) NOT NULL DEFAULT 'roxwood',
            status ENUM('active','cleared') NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cleared_at DATETIME NULL,
            cleared_by INT(11) NULL,
            cleared_by_name_snapshot VARCHAR(100) NULL,
            created_by INT(11) NOT NULL,
            created_by_name_snapshot VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dispatcher_assignment_code (assignment_code),
            KEY idx_dispatcher_assignment_status (status),
            KEY idx_dispatcher_assignment_unit_status (unit_code, status),
            KEY idx_dispatcher_assignment_status_code (status_code),
            KEY idx_dispatcher_assignment_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatcher_assignment_members (
            id INT(11) NOT NULL AUTO_INCREMENT,
            assignment_id INT(11) NOT NULL,
            medic_user_id INT(11) NOT NULL,
            medic_name_snapshot VARCHAR(100) NULL,
            medic_jabatan_snapshot VARCHAR(60) NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_dam_assignment_id (assignment_id),
            KEY idx_dam_medic_user_id (medic_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatcher_supervisors (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            unit_code VARCHAR(20) NOT NULL DEFAULT 'roxwood',
            user_name_snapshot VARCHAR(100) NULL,
            activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dispatcher_supervisor_user_unit (user_id, unit_code),
            KEY idx_dispatcher_supervisor_unit (unit_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatcher_roster (
            id INT(11) NOT NULL AUTO_INCREMENT,
            medic_user_id INT(11) NOT NULL,
            unit_code VARCHAR(20) NOT NULL DEFAULT 'roxwood',
            medic_name_snapshot VARCHAR(100) NULL,
            added_by INT(11) NULL,
            added_by_name_snapshot VARCHAR(100) NULL,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dispatcher_roster_medic_unit (medic_user_id, unit_code),
            KEY idx_dispatcher_roster_unit (unit_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

/**
 * Apakah user ini sedang jadi Penanggung Jawab (PIC) dispatcher aktif untuk unit tsb.
 */
function ems_dispatcher_is_supervisor(PDO $pdo, int $userId, string $unitCode): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM dispatcher_supervisors WHERE user_id = ? AND unit_code = ? LIMIT 1");
    $stmt->execute([$userId, $unitCode]);

    return (bool)$stmt->fetchColumn();
}

/**
 * Daftar Penanggung Jawab (PIC) dispatcher yang sedang aktif untuk unit tsb.
 */
function ems_dispatcher_active_supervisors(PDO $pdo, string $unitCode): array
{
    $stmt = $pdo->prepare("
        SELECT user_id, user_name_snapshot, activated_at
        FROM dispatcher_supervisors
        WHERE unit_code = ?
        ORDER BY activated_at ASC
    ");
    $stmt->execute([$unitCode]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * ID medis yang statusnya online di sistem jaga farmasi (user_farmasi_status),
 * dipakai untuk mengecualikan mereka dari generate dispatcher & tombol Clear —
 * jaga farmasi hanya bisa dilepas lewat rekap_farmasi.php, bukan dari sini.
 */
function ems_dispatcher_farmasi_online_medic_ids(PDO $pdo, string $unitCode): array
{
    if (!ems_table_exists($pdo, 'user_farmasi_status')) {
        return [];
    }

    $hasUnitCode = ems_column_exists($pdo, 'user_rh', 'unit_code');

    $sql = "
        SELECT ufs.user_id
        FROM user_farmasi_status ufs
        JOIN user_rh ur ON ur.id = ufs.user_id
        WHERE ufs.status = 'online'
    ";
    $params = [];
    if ($hasUnitCode) {
        $sql .= " AND COALESCE(ur.unit_code, 'roxwood') = ?";
        $params[] = $unitCode;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Waktu terakhir tiap medis (dari daftar $medicIds) mendapat tugas "aktif"
 * (respon lapangan / bantu IGD / standby resepsionis). Dipakai untuk rotasi
 * fair pada Generate Dispatcher — yang paling lama/tidak pernah kebagian
 * diprioritaskan duluan, supaya tidak itu-itu saja orangnya.
 *
 * Return: [medic_user_id => 'Y-m-d H:i:s'] — medis yang belum pernah tidak muncul di array.
 */
function ems_dispatcher_last_duty_at(PDO $pdo, array $medicIds, string $unitCode): array
{
    $medicIds = array_values(array_unique(array_filter(array_map('intval', $medicIds), static fn($id) => $id > 0)));
    if (!$medicIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($medicIds), '?'));
    $stmt = $pdo->prepare("
        SELECT dam.medic_user_id, MAX(da.started_at) AS last_duty_at
        FROM dispatcher_assignment_members dam
        JOIN dispatcher_assignments da ON da.id = dam.assignment_id
        WHERE dam.medic_user_id IN ($placeholders)
          AND da.unit_code = ?
          AND da.status_code IN ('respon_lapangan', 'bantu_igd', 'standby_resepsionis')
        GROUP BY dam.medic_user_id
    ");
    $stmt->execute([...$medicIds, $unitCode]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['medic_user_id']] = (string)$row['last_duty_at'];
    }

    return $map;
}

/**
 * Urutkan daftar medic (array of ['id'=>..,'position'=>..,...]) dari yang PALING
 * LAMA/TIDAK PERNAH kebagian tugas aktif ke yang paling baru — dipakai Generate
 * Dispatcher supaya rotasinya adil (rolling), tidak orang yang itu-itu saja.
 *
 * Medic dengan last-duty yang SAMA (misalnya semua di-generate dalam batch yang
 * sama sehingga timestamp-nya identik) diacak dulu sebelum di-sort: usort() di
 * PHP 8+ stabil, jadi tanpa pengacakan ini urutan "seri" akan selalu jatuh balik
 * ke urutan roster yang konstan — akibatnya Generate ulang selalu menghasilkan
 * komposisi yang identik (bug yang dilaporkan: "balik lagi orang-orangnya yang
 * tadi"). Shuffle di sini membuat setiap kelompok yang seri tetap berotasi acak
 * tiap kali Generate dijalankan, sementara medic yang benar-benar sudah lama
 * tidak bertugas tetap diprioritaskan lebih dulu.
 */
function ems_dispatcher_sort_by_fairness(array $medics, array $lastDutyMap): array
{
    shuffle($medics);

    usort($medics, static function (array $a, array $b) use ($lastDutyMap): int {
        $aTime = $lastDutyMap[(int)$a['id']] ?? null;
        $bTime = $lastDutyMap[(int)$b['id']] ?? null;

        // Belum pernah bertugas = paling prioritas (dianggap paling "lama").
        if ($aTime === null && $bTime === null) {
            return 0;
        }
        if ($aTime === null) {
            return -1;
        }
        if ($bTime === null) {
            return 1;
        }

        return strcmp($aTime, $bTime);
    });

    return $medics;
}

/**
 * Katalog status dispatcher. 'requires_location' = wajib isi koordinat.
 * 'requires_custom_label' = wajib isi label bebas (status "Lainnya").
 */
function ems_dispatcher_status_options(): array
{
    return [
        'off_duty' => [
            'label' => '10-7 / Istirahat',
            'badge' => 'badge-secondary',
            'icon' => 'pause-circle',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'rapat' => [
            'label' => 'Rapat',
            'badge' => 'badge-info',
            'icon' => 'user-group',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'kunjungan' => [
            'label' => 'Kunjungan',
            'badge' => 'badge-info',
            'icon' => 'building-office-2',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'standby_resepsionis' => [
            'label' => 'Standby Resepsionis',
            'badge' => 'badge-info',
            'icon' => 'identification',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'bantu_igd' => [
            'label' => 'Bantu IGD',
            'badge' => 'badge-warning',
            'icon' => 'shield-exclamation',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'respon_lapangan' => [
            'label' => 'Respon Lapangan',
            'badge' => 'badge-danger',
            'icon' => 'signal',
            'requires_location' => true,
            'requires_custom_label' => false,
        ],
        'lainnya' => [
            'label' => 'Lainnya',
            'badge' => 'badge-muted',
            'icon' => 'information-circle',
            'requires_location' => false,
            'requires_custom_label' => true,
        ],
    ];
}

function ems_dispatcher_status_label(string $statusCode, ?string $customLabel = null): string
{
    $options = ems_dispatcher_status_options();

    if ($statusCode === 'lainnya' && trim((string)$customLabel) !== '') {
        return trim((string)$customLabel);
    }

    return $options[$statusCode]['label'] ?? ucfirst(str_replace('_', ' ', $statusCode));
}

function ems_dispatcher_status_badge_class(string $statusCode): string
{
    $options = ems_dispatcher_status_options();

    return $options[$statusCode]['badge'] ?? 'badge-muted';
}

function ems_dispatcher_status_icon(string $statusCode): string
{
    $options = ems_dispatcher_status_options();

    return $options[$statusCode]['icon'] ?? 'signal';
}

function ems_dispatcher_generate_code(): string
{
    return 'DSP-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function ems_dispatcher_duration_label(int $seconds): string
{
    $seconds = max(0, $seconds);

    if ($seconds < 60) {
        return $seconds . ' detik';
    }

    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . ' menit';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    return $remainingMinutes > 0
        ? ($hours . ' jam ' . $remainingMinutes . ' menit')
        : ($hours . ' jam');
}

/**
 * Susun rencana pembagian tugas otomatis (Generate Dispatcher) dari daftar medic
 * roster yang eligible (bukan sedang jaga farmasi, bukan status manual off_duty/
 * rapat/kunjungan/lainnya yang sengaja tidak ingin diganggu). Slot IGD & Resepsionis
 * ditentukan proporsional dari jumlah total eligible, sisanya dibagi ke Respon
 * Lapangan (solo senior / senior+1-2 trainee / 2 trainee kalau senior habis).
 * Rotasi memakai $lastDutyMap supaya yang paling lama/tidak pernah kebagian
 * tugas aktif diprioritaskan duluan (tidak itu-itu saja orangnya).
 *
 * $eligibleMedics: array of ['id'=>int, 'name'=>string, 'jabatan'=>string label, 'position_code'=>string]
 *
 * Return: [
 *   'standby_resepsionis' => [ [medic], ... ],            // tiap elemen = 1 slot solo
 *   'bantu_igd' => [ [medic], ... ],                       // tiap elemen = 1 slot solo
 *   'respon_lapangan' => [ [medic, medic, ...], ... ],     // tiap elemen = 1 tim (grup)
 *   'unassigned' => [ medic, ... ],                        // sisa, tetap "Tersedia"
 * ]
 */
function ems_dispatcher_build_generate_plan(array $eligibleMedics, array $lastDutyMap): array
{
    $plan = [
        'standby_resepsionis' => [],
        'bantu_igd' => [],
        'respon_lapangan' => [],
        'unassigned' => [],
    ];

    $total = count($eligibleMedics);
    if ($total === 0) {
        return $plan;
    }

    $sorted = ems_dispatcher_sort_by_fairness($eligibleMedics, $lastDutyMap);

    $seniorPositions = ['paramedic', 'co_asst', 'general_practitioner', 'specialist'];

    // Tentukan jumlah slot IGD & Resepsionis secara proporsional dari total eligible.
    if ($total <= 1) {
        $igdSlots = 0;
        $resepsionisSlots = 0;
    } elseif ($total === 2) {
        $igdSlots = 1;
        $resepsionisSlots = 0;
    } else {
        $igdSlots = max(1, (int)round($total * 0.2));
        $resepsionisSlots = max(1, (int)round($total * 0.15));
        // Sisakan minimal 1 orang untuk Respon Lapangan.
        while (($igdSlots + $resepsionisSlots) > ($total - 1)) {
            if ($resepsionisSlots > 0) {
                $resepsionisSlots--;
            } elseif ($igdSlots > 1) {
                $igdSlots--;
            } else {
                break;
            }
        }
    }

    // Isi slot Resepsionis & IGD DIDAHULUKAN dari trainee (fairness tetap dijaga di
    // dalam masing-masing kelompok), supaya senior tersisa untuk memimpin tim Respon
    // Lapangan alih-alih ikut "terserap" ke tugas stasioner duluan.
    $stationaryPool = array_merge(
        array_values(array_filter($sorted, static fn($m) => !in_array($m['position_code'], $seniorPositions, true))),
        array_values(array_filter($sorted, static fn($m) => in_array($m['position_code'], $seniorPositions, true)))
    );

    $pickedIds = [];
    for ($i = 0; $i < $resepsionisSlots && $stationaryPool; $i++) {
        $medic = array_shift($stationaryPool);
        $plan['standby_resepsionis'][] = [$medic];
        $pickedIds[(int)$medic['id']] = true;
    }
    for ($i = 0; $i < $igdSlots && $stationaryPool; $i++) {
        $medic = array_shift($stationaryPool);
        $plan['bantu_igd'][] = [$medic];
        $pickedIds[(int)$medic['id']] = true;
    }

    // Sisa pool untuk Respon Lapangan = urutan fairness semula dikurangi yang sudah kepakai.
    $pool = array_values(array_filter($sorted, static fn($m) => !isset($pickedIds[(int)$m['id']])));

    $remainingSeniors = [];
    $remainingTrainees = [];
    foreach ($pool as $medic) {
        if (in_array($medic['position_code'], $seniorPositions, true)) {
            $remainingSeniors[] = $medic;
        } else {
            $remainingTrainees[] = $medic;
        }
    }

    $teams = [];
    foreach ($remainingSeniors as $senior) {
        $teams[] = [$senior];
    }

    // Bagi trainee round-robin ke tim senior, maksimal 2 trainee per tim (1 senior + 2 trainee).
    $teamIndex = 0;
    $trainingLeftover = [];
    foreach ($remainingTrainees as $trainee) {
        $placed = false;
        $teamCount = count($teams);
        for ($attempt = 0; $attempt < $teamCount; $attempt++) {
            $idx = ($teamIndex + $attempt) % $teamCount;
            if (count($teams[$idx]) < 3) {
                $teams[$idx][] = $trainee;
                $teamIndex = $idx + 1;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $trainingLeftover[] = $trainee;
        }
    }

    // Trainee sisa tanpa senior -> pasangkan 2-2 jadi tim sendiri (butuh minimal 2 orang).
    while (count($trainingLeftover) >= 2) {
        $teams[] = [array_shift($trainingLeftover), array_shift($trainingLeftover)];
    }

    $plan['respon_lapangan'] = array_values($teams);
    $plan['unassigned'] = $trainingLeftover;

    return $plan;
}

function ems_dispatcher_datetime_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d M Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}
