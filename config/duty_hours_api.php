<?php

require_once __DIR__ . '/curl_ca_bundle.php';

function ems_duty_hours_request(PDO $pdo, string $period, string $startDate = '', string $endDate = ''): array
{
    $apiKey = trim((string) ems_env('EMS_DUTY_HOURS_API_KEY', ''));
    if ($apiKey === '') {
        throw new RuntimeException('API key laporan absensi belum dikonfigurasi di server.');
    }

    $query = ['period' => $period, 'per_page' => 1000];
    if ($period === 'custom_range') {
        $query['start_date'] = $startDate;
        $query['end_date'] = $endDate;
    }

    $url = 'https://medicalcenterime.my.id/api/duty-hours?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Tidak dapat menyiapkan koneksi laporan absensi.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Accept: application/json', 'Cache-Control: no-cache'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
    ];
    $caBundle = emsFindCaBundlePath();
    if ($caBundle !== null) $options[CURLOPT_CAINFO] = $caBundle;
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Gagal mengambil data absensi dari Medical Center.' . ($error !== '' ? ' ' . $error : ''));
    }

    $payload = json_decode($body, true);
    if (!is_array($payload) || empty($payload['success']) || !is_array($payload['data'] ?? null)) {
        throw new RuntimeException('Respons laporan absensi dari Medical Center tidak valid.');
    }

    $staffStmt = $pdo->query("SELECT id, full_name, citizen_id, position FROM user_rh WHERE COALESCE(NULLIF(LOWER(TRIM(unit_code)), ''), 'roxwood') = 'roxwood' AND TRIM(COALESCE(citizen_id, '')) <> ''");
    $staff = [];
    foreach ($staffStmt->fetchAll(PDO::FETCH_ASSOC) as $person) {
        if (!ems_is_medical_position($person['position'] ?? null)) continue;
        $staff[strtoupper(trim((string) $person['citizen_id']))] = $person;
    }

    $rows = [];
    foreach ($payload['data'] as $item) {
        if (strtolower(trim((string) ($item['hospital'] ?? ''))) !== 'roxwood') {
            continue;
        }
        $citizenId = strtoupper(trim((string) ($item['citizen_id'] ?? $item['staff_id'] ?? '')));
        if ($citizenId === '' || !isset($staff[$citizenId])) {
            continue;
        }
        $rows[] = $item + ['registered_staff' => $staff[$citizenId]];
    }

    usort($rows, static fn(array $a, array $b): int => ((int) ($b['total_seconds'] ?? 0)) <=> ((int) ($a['total_seconds'] ?? 0)));
    return ['period' => $payload['period'] ?? $period, 'date_range' => $payload['date_range'] ?? [], 'summary' => $payload['summary'] ?? [], 'rows' => $rows];
}

function ems_duty_hours_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
