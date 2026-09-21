<?php

if (!function_exists('ems_medical_center_api_url')) {
    function ems_medical_center_api_url(): string
    {
        $url = rtrim((string) ems_env(
            'MEDICAL_CENTER_API_URL',
            'https://medicalcenterime.my.id/api/rekam-medis?hospital=roxwood'
        ), '&?');
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (
            ($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'medicalcenterime.my.id'
            || ($parts['path'] ?? '') !== '/api/rekam-medis'
            || ($query['hospital'] ?? '') !== 'roxwood'
        ) {
            throw new RuntimeException('Medical Center API URL wajib memakai endpoint GET Medical Center hospital=roxwood.');
        }
        return $url;
    }
}

if (!function_exists('ems_medical_center_api_key')) {
    function ems_medical_center_api_key(): string
    {
        return trim((string) ems_env('MEDICAL_CENTER_API_KEY', ''));
    }
}

if (!function_exists('ems_medical_center_cutoff')) {
    function ems_medical_center_cutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-21 00:00:00', new DateTimeZone('Asia/Jakarta'));
    }
}

if (!function_exists('ems_medical_center_parse_datetime')) {
    function ems_medical_center_parse_datetime(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('ems_medical_center_datetime_for_db')) {
    function ems_medical_center_datetime_for_db(mixed $value): ?string
    {
        $date = ems_medical_center_parse_datetime($value);
        return $date?->format('Y-m-d H:i:s');
    }
}

if (!function_exists('ems_medical_center_allowed_media_url')) {
    function ems_medical_center_allowed_media_url(mixed $value): bool
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && in_array($host, ['medicalcenterime.my.id'], true);
    }
}

if (!function_exists('ems_medical_center_photo_url')) {
    function ems_medical_center_photo_url(mixed $photo): string
    {
        $url = is_array($photo)
            ? trim((string) ($photo['url'] ?? ($photo['photo_url'] ?? ($photo['image_url'] ?? ($photo['path'] ?? ($photo['src'] ?? ''))))))
            : trim((string) ($photo ?? ''));

        return ems_medical_center_allowed_media_url($url) ? $url : '';
    }
}

if (!function_exists('ems_medical_center_request_json')) {
    function ems_medical_center_request_json(string $url): array
    {
        $apiKey = ems_medical_center_api_key();
        if ($apiKey === '') {
            throw new RuntimeException('Medical Center API key belum dikonfigurasi server.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Tidak dapat menyiapkan koneksi Medical Center API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(15, (int) ems_env('MEDICAL_CENTER_API_TIMEOUT', 45)),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                sprintf('%s: %s', 'X-API-Key', $apiKey),
            ],
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            throw new RuntimeException('GET Medical Center API gagal: koneksi atau timeout.');
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('GET Medical Center API gagal dengan HTTP ' . $status . '.');
        }

        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Response GET Medical Center API bukan JSON valid.');
        }

        if (!is_array($payload) || ($payload['success'] ?? false) !== true || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new RuntimeException('Format response GET Medical Center API tidak sesuai kontrak.');
        }

        return $payload;
    }
}

if (!function_exists('ems_medical_center_api_fetch_all')) {
    function ems_medical_center_api_fetch_all(): array
    {
        $baseUrl = ems_medical_center_api_url();
        $records = [];
        $seenIds = [];
        $page = 1;
        $lastPage = 1;
        $pages = 0;
        $maxPages = max(1, (int) ems_env('MEDICAL_CENTER_API_MAX_PAGES', 100));

        do {
            $url = $baseUrl;
            if ($page > 1) {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;
            }

            $response = ems_medical_center_request_json($url);
            $pages++;
            $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];
            $lastPage = max(1, (int) ($meta['last_page'] ?? $page));

            foreach ($response['data'] as $record) {
                if (!is_array($record)) {
                    continue;
                }

                if (strtolower(trim((string) ($record['hospital'] ?? ''))) !== 'roxwood') {
                    continue;
                }

                $remoteId = trim((string) ($record['id'] ?? ''));
                if ($remoteId === '' || isset($seenIds[$remoteId])) {
                    continue;
                }

                $seenIds[$remoteId] = true;
                $records[] = $record;
            }

            $page++;
            if ($pages >= $maxPages && $page <= $lastPage) {
                throw new RuntimeException('Pagination GET Medical Center API melebihi batas aman.');
            }
        } while ($page <= $lastPage);

        return [
            'records' => $records,
            'pages' => $pages,
            'last_page' => $lastPage,
        ];
    }
}

if (!function_exists('ems_medical_center_remote_detail_url')) {
    function ems_medical_center_remote_detail_url(mixed $remoteId): string
    {
        $remoteId = trim((string) $remoteId);
        if ($remoteId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $remoteId)) {
            return '';
        }

        return 'https://medicalcenterime.my.id/staff/operations/' . rawurlencode($remoteId);
    }
}

if (!function_exists('ems_medical_center_decode_array')) {
    function ems_medical_center_decode_array(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }
}

if (!function_exists('ems_medical_center_nested_value')) {
    function ems_medical_center_nested_value(array $record, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $parts = is_array($path) ? $path : explode('.', (string) $path);
            $value = $record;
            $found = true;

            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    $found = false;
                    break;
                }
                $value = $value[$part];
            }

            if ($found && $value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('ems_medical_center_person')) {
    function ems_medical_center_person(mixed $value): array
    {
        if (is_array($value)) {
            $name = trim((string) ($value['name'] ?? ($value['full_name'] ?? ($value['nama'] ?? ''))));
            $citizenId = trim((string) ($value['citizen_id'] ?? ($value['staff_id'] ?? '')));
            $remoteId = $value['id'] ?? null;
        } else {
            $text = trim((string) ($value ?? ''));
            $citizenId = '';
            $name = $text;
            if (preg_match('/^(.*)\\s*\\(([^()]+)\\)\\s*$/', $text, $matches)) {
                $name = trim($matches[1]);
                $citizenId = trim($matches[2]);
            }
            $remoteId = null;
        }

        return [
            'name' => $name,
            'citizen_id' => $citizenId,
            'remote_id' => $remoteId,
        ];
    }
}

if (!function_exists('ems_medical_center_local_staff')) {
    function ems_medical_center_local_staff(PDO $pdo, mixed $value): ?array
    {
        $person = ems_medical_center_person($value);
        if ($person['citizen_id'] === '') {
            return null;
        }

        $sql = 'SELECT id, full_name, citizen_id, position FROM user_rh WHERE citizen_id = ?';
        $params = [$person['citizen_id']];
        if (ems_column_exists($pdo, 'user_rh', 'unit_code')) {
            $sql .= " AND unit_code = 'roxwood'";
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        return $staff ?: null;
    }
}

if (!function_exists('ems_medical_center_scalar_text')) {
    function ems_medical_center_scalar_text(mixed $value, string $fallback = ''): string
    {
        if (is_array($value) || is_object($value)) {
            return $fallback;
        }

        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('ems_medical_center_list_text')) {
    function ems_medical_center_list_text(mixed $value): string
    {
        if (!is_array($value)) {
            return ems_medical_center_scalar_text($value);
        }

        $items = [];
        array_walk_recursive($value, static function (mixed $item) use (&$items): void {
            $text = ems_medical_center_scalar_text($item);
            if ($text !== '') {
                $items[] = $text;
            }
        });

        return implode("\n", array_values(array_unique($items)));
    }
}

if (!function_exists('ems_medical_center_value_text')) {
    function ems_medical_center_value_text(mixed $value): string
    {
        if (!is_array($value)) {
            return ems_medical_center_scalar_text($value);
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $label = is_string($key) ? ucwords(str_replace(['_', '-'], ' ', $key)) : '';
            if (is_array($item)) {
                $text = ems_medical_center_value_text($item);
            } else {
                $text = ems_medical_center_scalar_text($item);
            }
            if ($text !== '') {
                $lines[] = ($label !== '' ? $label . ': ' : '') . $text;
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('ems_medical_center_date_for_db')) {
    function ems_medical_center_date_for_db(mixed $value): ?string
    {
        $value = ems_medical_center_scalar_text($value);
        if ($value === '' || $value === '-') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('Asia/Jakarta'));
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return ems_medical_center_datetime_for_db($value) !== null
            ? ems_medical_center_parse_datetime($value)?->format('Y-m-d')
            : null;
    }
}

if (!function_exists('ems_medical_center_aldrete_text')) {
    function ems_medical_center_aldrete_text(array $anesthesia): string
    {
        $fields = [
            'Kesadaran' => 'score_kesadaran',
            'Motorik' => 'score_motorik',
            'Mual/Muntah' => 'score_mual',
            'Tekanan Darah' => 'score_td',
            'Pernapasan' => 'score_pernapasan',
            'Warna Kulit' => 'score_warna_kulit',
        ];
        $lines = [];
        foreach ($fields as $label => $key) {
            $lines[] = $label . ': ' . ems_medical_center_scalar_text($anesthesia[$key] ?? '', '-');
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('ems_medical_center_build_result_html')) {
    function ems_medical_center_build_result_html(array $mapped): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(
            ems_medical_center_scalar_text($value, '-'),
            ENT_QUOTES,
            'UTF-8'
        );
        $paragraph = static fn (string $label, mixed $value): string => '<p><strong>' . $escape($label) . ':</strong> ' . nl2br($escape($value)) . '</p>';
        $html = '<h1>REKAM MEDIS MEDICAL CENTER</h1>';
        $html .= '<h2>ANAMNESIS &amp; RIWAYAT KESEHATAN</h2>';
        $html .= $paragraph('Anamnesis / Keluhan Utama', $mapped['remote_anamnesis']);
        $html .= $paragraph('Diagnosis Utama', $mapped['remote_diagnosis']);
        $html .= $paragraph('Riwayat Penyakit Dahulu', $mapped['remote_past_medical_history']);
        $html .= $paragraph('Riwayat Penyakit Keluarga', $mapped['remote_family_history']);
        $html .= $paragraph('Riwayat Alergi', $mapped['remote_allergy_history']);
        $html .= $paragraph('Riwayat Pengobatan', $mapped['remote_medication_history']);
        $html .= '<h2>PEMERIKSAAN FISIK &amp; TANDA VITAL</h2>';
        foreach ([
            'Keadaan Umum' => 'remote_general_condition',
            'Kesadaran / GCS' => 'remote_gcs',
            'Tekanan Darah' => 'remote_blood_pressure',
            'Nadi' => 'remote_pulse',
            'Respirasi (RR)' => 'remote_respiratory_rate',
            'Suhu' => 'remote_temperature',
            'Saturasi O2' => 'remote_oxygen_saturation',
        ] as $label => $key) {
            $html .= $paragraph($label, $mapped[$key]);
        }
        $html .= '<h2>TINDAKAN / OPERASI</h2>';
        $html .= $paragraph('Nama Tindakan', $mapped['remote_operation_name']);
        $html .= $paragraph('Waktu Mulai', $mapped['remote_operation_start_at']);
        $html .= $paragraph('Waktu Selesai', $mapped['remote_operation_end_at']);
        $html .= $paragraph('Langkah-Langkah Tindakan', $mapped['remote_operation_steps']);
        $html .= $paragraph('Hasil Operasi', $mapped['remote_operation_result']);
        $html .= '<h2>ANESTESI, OBAT, DAN PEMULIHAN</h2>';
        $html .= $paragraph('Jenis Anestesi', $mapped['remote_anesthesia_type']);
        $html .= $paragraph('Petugas Anestesi', $mapped['remote_anesthesia_officer']);
        $html .= $paragraph('Obat Pra-Operasi', $mapped['remote_preop_medications']);
        $html .= $paragraph('Obat Pasca-Operasi (Antidote/Anti Mual/Analgesik)', $mapped['remote_postop_medications']);
        $html .= $paragraph('Aldrete Score', $mapped['remote_aldrete']);
        $html .= '<h2>PENUNJANG DAN SARAN</h2>';
        $html .= $paragraph('Hasil Laboratorium', $mapped['remote_laboratory_result']);
        $html .= $paragraph('Hasil Radiologi / X-Ray', $mapped['remote_radiology_result']);
        $html .= $paragraph('Obat-Obatan Post Operasi', $mapped['remote_supporting_medications']);
        $html .= $paragraph('Saran dan Anjuran Dokter', $mapped['remote_postop_advice']);

        return $html;
    }
}

if (!function_exists('ems_medical_center_normalize_record')) {
    function ems_medical_center_normalize_record(array $record, ?PDO $pdo = null): array
    {
        $details = ems_medical_center_decode_array($record['medical_details'] ?? []);
        $patient = ems_medical_center_decode_array($details['pasien'] ?? ($details['patient'] ?? []));
        $operation = ems_medical_center_decode_array($details['tindakan'] ?? ($details['operasi'] ?? ($details['operation'] ?? [])));
        $anesthesia = ems_medical_center_decode_array($details['anestesi'] ?? ($details['anesthesia'] ?? []));
        $vitals = ems_medical_center_decode_array($details['ttv'] ?? ($details['tanda_vital'] ?? ($details['vital_signs'] ?? [])));
        $history = ems_medical_center_decode_array($details['anamnesis'] ?? ($details['riwayat'] ?? ($details['history'] ?? [])));
        $supporting = ems_medical_center_decode_array($details['penunjang'] ?? ($details['supporting'] ?? []));
        $supportingMedications = ems_medical_center_list_text($details['obat_obatan'] ?? '');
        $team = $record['members'] ?? ($details['tim'] ?? ($details['members'] ?? ($details['team'] ?? [])));
        $remoteDpjp = $record['dpjp'] ?? ($details['dpjp'] ?? null);
        $teamDetails = ems_medical_center_decode_array($details['tim'] ?? []);
        $remoteAssistants = [];
        foreach (['asisten_1', 'asisten_2', 'asisten_3', 'asisten_4', 'asisten_5'] as $assistantKey) {
            $assistantValue = $teamDetails[$assistantKey] ?? null;
            if ($assistantValue !== null && $assistantValue !== '') {
                $remoteAssistants[$assistantKey] = $assistantValue;
            }
        }
        $photos = $record["photos"] ?? ($details["photos"] ?? []);
        $localDpjp = $pdo !== null ? ems_medical_center_local_staff($pdo, $remoteDpjp) : null;
        $firstResponder = $teamDetails['first_responder'] ?? null;
        $localFirstResponder = $pdo !== null ? ems_medical_center_local_staff($pdo, $firstResponder) : null;
        $teamLocalStaff = [];
        $resolvedTeam = [];
        foreach (is_array($team) ? $team : [] as $teamMember) {
            $person = ems_medical_center_person($teamMember);
            $localStaff = $pdo !== null ? ems_medical_center_local_staff($pdo, $teamMember) : null;
            $teamLocalStaff[] = [$person, $localStaff];
            $resolvedMember = is_array($teamMember) ? $teamMember : ['remote_name' => $person['name']];
            if ($localStaff !== null) {
                $resolvedMember = array_merge($resolvedMember, [
                    'local_id' => (int) $localStaff['id'],
                    'local_name' => (string) $localStaff['full_name'],
                    'local_citizen_id' => (string) $localStaff['citizen_id'],
                ]);
            } else {
                $resolvedMember = array_merge($resolvedMember, ['local_id' => null, 'local_name' => '', 'unmapped' => true]);
            }
            $resolvedTeam[] = $resolvedMember;
        }

        $resolvedDpjp = is_array($remoteDpjp) ? $remoteDpjp : ems_medical_center_person($remoteDpjp);
        if ($localDpjp !== null) {
            $resolvedDpjp = array_merge($resolvedDpjp, [
                'local_id' => (int) $localDpjp['id'],
                'local_name' => (string) $localDpjp['full_name'],
                'local_citizen_id' => (string) $localDpjp['citizen_id'],
            ]);
        }

        $resolvedAssistants = [];
        $localAssistantIds = [];
        foreach ($remoteAssistants as $assistantKey => $assistantValue) {
            $remotePerson = ems_medical_center_person($assistantValue);
            $localStaff = $pdo !== null ? ems_medical_center_local_staff($pdo, $assistantValue) : null;
            if ($localStaff === null && $remotePerson['name'] !== '') {
                foreach ($teamLocalStaff as [$teamPerson, $candidate]) {
                    if ($candidate !== null && strcasecmp($teamPerson['name'], $remotePerson['name']) === 0) {
                        $localStaff = $candidate;
                        break;
                    }
                }
            }
            $resolvedValue = is_array($assistantValue) ? $assistantValue : ['remote_name' => $remotePerson['name']];
            if ($localStaff !== null) {
                $resolvedValue = array_merge($resolvedValue, [
                    'local_id' => (int) $localStaff['id'],
                    'local_name' => (string) $localStaff['full_name'],
                    'local_citizen_id' => (string) $localStaff['citizen_id'],
                ]);
                $localAssistantIds[] = (int) $localStaff['id'];
            } else {
                $resolvedValue = array_merge($resolvedValue, ['local_id' => null, 'local_name' => '', 'unmapped' => true]);
            }
            $resolvedAssistants[$assistantKey] = $resolvedValue;
        }
        $localAssistantIds = array_values(array_unique(array_filter($localAssistantIds, static fn (int $id): bool => $id > 0)));

        $get = static function (array $paths, mixed $fallback = '') use ($record, $details, $patient, $operation, $anesthesia, $vitals, $history, $supporting): mixed {
            foreach ([$record, $details, $patient, $operation, $anesthesia, $vitals, $history, $supporting] as $source) {
                $value = ems_medical_center_nested_value($source, $paths);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
            return $fallback;
        };

        $remoteId = ems_medical_center_scalar_text($record['id'] ?? '');
        $operationName = ems_medical_center_scalar_text($get([
            'tindakan_operasi', 'nama_tindakan', 'operation_name', 'procedure_name', 'jenis_operasi',
        ]));
        $steps = ems_medical_center_list_text($get([
            'langkah_tindakan', 'langkah_langkah_tindakan', 'operation_steps', 'steps',
        ]));
        $anesthesiaType = ems_medical_center_scalar_text($get([
            'jenis_anestesi', 'anesthesia_type', 'anesthetic_type',
        ]));
        $diagnosis = ems_medical_center_scalar_text($get([
            'diagnosa', 'diagnosis', 'diagnosis_utama', 'medical_diagnosis',
        ]));
        $patientName = ems_medical_center_scalar_text($get(['nama_pasien', 'patient_name']), '-');
        $dob = ems_medical_center_date_for_db($get(['dob', 'patient_dob', 'tanggal_lahir']));
        $gender = ems_medical_center_scalar_text($get(['jenis_kelamin', 'gender', 'patient_gender']));
        if (!in_array($gender, ['Laki-laki', 'Perempuan'], true)) {
            $gender = null;
        }

        $mapped = [
            'source_provider' => 'medical_center',
            'source_hospital' => 'roxwood',
            'remote_record_id' => $remoteId,
            'remote_record_url' => ems_medical_center_remote_detail_url($remoteId),
            'remote_record_number' => ems_medical_center_scalar_text($get(['no_rekam_medis', 'record_code', 'medical_record_number'])),
            'record_code' => 'MC-RM-' . $remoteId,
                        'doctor_id' => $localDpjp['id'] ?? null,
                        'assistant_id' => $localAssistantIds[0] ?? null,
                        'remote_local_assistant_ids' => $localAssistantIds,
                        'jenis_operasi' => $operationName,
            'patient_name' => $patientName,
            'patient_citizen_id' => ems_medical_center_scalar_text($get(['citizen_id', 'patient_citizen_id', 'ktp', 'patient.citizen_id'])),
            'patient_dob' => $dob,
            'patient_gender' => $gender,
            'patient_blood_type' => ems_medical_center_scalar_text($get(['gol_darah', 'golongan_darah', 'blood_type', 'patient.blood_type'])),
            'patient_occupation' => ems_medical_center_scalar_text($get(['pekerjaan', 'occupation', 'patient.occupation'])),
            'patient_phone' => ems_medical_center_scalar_text($get(['no_hp', 'phone', 'patient_phone', 'patient.phone'])),
            'patient_address' => ems_medical_center_scalar_text($get(['alamat', 'address', 'patient_address', 'patient.address'])),
            'patient_status' => ems_medical_center_scalar_text($get(['status_pasien', 'patient_status', 'status'])),
            'remote_location' => ems_medical_center_scalar_text($get(['lokasi', 'location'])),
            'remote_diagnosis' => $diagnosis,
            'remote_anamnesis' => ems_medical_center_value_text($get(['anamnesis_keluhan', 'anamnesis', 'keluhan_utama', 'complaint', 'chief_complaint'])),
            'remote_past_medical_history' => ems_medical_center_scalar_text($get(['riwayat_penyakit_dahulu', 'past_medical_history'])),
            'remote_family_history' => ems_medical_center_scalar_text($get(['riwayat_penyakit_keluarga', 'family_history'])),
            'remote_allergy_history' => ems_medical_center_scalar_text($get(['riwayat_alergi', 'allergy_history'])),
            'remote_medication_history' => ems_medical_center_scalar_text($get(['riwayat_pengobatan', 'medication_history'])),
            'remote_general_condition' => ems_medical_center_scalar_text($get(['keadaan_umum', 'general_condition'])),
            'remote_gcs' => ems_medical_center_scalar_text($get(['gcs', 'kesadaran', 'consciousness'])),
            'remote_blood_pressure' => ems_medical_center_scalar_text($get(['tekanan_darah', 'blood_pressure', 'vitals.blood_pressure'])),
            'remote_pulse' => ems_medical_center_scalar_text($get(['nadi', 'pulse', 'heart_rate', 'vitals.pulse'])),
            'remote_respiratory_rate' => ems_medical_center_scalar_text($get(['respirasi', 'respiratory_rate', 'rr', 'vitals.respiratory_rate'])),
            'remote_temperature' => ems_medical_center_scalar_text($get(['suhu', 'temperature', 'body_temperature', 'vitals.temperature'])),
            'remote_oxygen_saturation' => ems_medical_center_scalar_text($get(['saturasi', 'saturasi_o2', 'oxygen_saturation', 'spo2', 'vitals.oxygen_saturation'])),
            'remote_operation_name' => $operationName,
            'remote_operation_start_at' => ems_medical_center_scalar_text($get(['waktu_mulai', 'operation_start', 'started_at'])),
            'remote_operation_end_at' => ems_medical_center_scalar_text($get(['waktu_selesai', 'operation_end', 'finished_at'])),
            'remote_operation_steps' => $steps,
            'remote_operation_result' => ems_medical_center_scalar_text($get(['hasil_operasi', 'operation_result', 'result'])),
            'remote_anesthesia_type' => $anesthesiaType,
            'remote_anesthesia_officer' => ems_medical_center_scalar_text($get(['petugas_anestesi', 'anesthesia_officer'])) ?: ems_medical_center_scalar_text($teamDetails['anestesi'] ?? ''),
            'remote_preop_medications' => ems_medical_center_list_text($get(['pra_operasi', 'obat_pra_operasi', 'preoperative_medications', 'preop_medications'])),
            'remote_postop_medications' => ems_medical_center_list_text($get(['pasca_operasi', 'obat_pasca_operasi', 'postoperative_medications', 'postop_medications'])),
            'remote_laboratory_result' => ems_medical_center_scalar_text($get(['lab', 'hasil_laboratorium', 'laboratory_result', 'lab_result'])),
            'remote_radiology_result' => ems_medical_center_scalar_text($get(['radiologi', 'hasil_radiologi', 'radiology_result', 'xray_result'])),
            'remote_postop_advice' => ems_medical_center_scalar_text($get(['saran_anjuran', 'saran_dokter', 'doctor_advice', 'postoperative_advice'])),
            'remote_supporting_medications' => $supportingMedications,
            'remote_aldrete' => ems_medical_center_aldrete_text($anesthesia),
            'created_by' => $localFirstResponder['id'] ?? null,
            'remote_team_json' => json_encode($resolvedTeam, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'remote_dpjp_json' => json_encode($resolvedDpjp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'remote_assistants_json' => json_encode($resolvedAssistants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'remote_photos_json' => json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'remote_medical_details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'remote_payload_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        $mapped['medical_result_html'] = ems_medical_center_build_result_html($mapped);
        $mapped['operasi_type'] = 'major';

        return $mapped;
    }
}
