<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/medical_records_api.php';

$lockPath = __DIR__ . '/../storage/cache/medical_center_get.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0750, true);
}

$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Medical Center GET pull sudah berjalan.\n");
    exit(1);
}

$startedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$status = 'synced';
$errorMessage = null;
$pagesFetched = 0;
$recordsReceived = 0;
$recordsStored = 0;
$recordsSkipped = 0;
$recordsDeleted = 0;

function medicalCenterRemoteRecordIds(array $records): array
{
    $ids = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $remoteId = trim((string) ($record['id'] ?? ''));
        if ($remoteId !== '') {
            $ids[$remoteId] = true;
        }
    }
    return $ids;
}

function deleteMissingMedicalCenterMirrors(PDO $pdo, array $remoteIds, DateTimeImmutable $cutoff): int
{
    $stmt = $pdo->prepare(
        "SELECT id, remote_record_id
         FROM medical_records
         WHERE source_provider = 'medical_center'
           AND source_hospital = 'roxwood'
           AND remote_event_at >= ?"
    );
    $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);
    $missing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $remoteId = trim((string) ($row['remote_record_id'] ?? ''));
        if ($remoteId !== '' && !isset($remoteIds[$remoteId])) {
            $missing[] = ['id' => (int) $row['id'], 'remote_id' => $remoteId];
        }
    }

    if ($missing === []) {
        return 0;
    }

    $deleteIntegration = $pdo->prepare(
        'DELETE FROM medical_record_integrations
         WHERE provider = ? AND hospital_code = ? AND remote_record_id = ?'
    );
    $deleteMirror = $pdo->prepare(
        'DELETE FROM medical_records
         WHERE id = ? AND source_provider = ? AND source_hospital = ? AND remote_record_id = ?'
    );
    foreach ($missing as $row) {
        $deleteIntegration->execute(['medical_center', 'roxwood', $row['remote_id']]);
        $deleteMirror->execute([$row['id'], 'medical_center', 'roxwood', $row['remote_id']]);
    }

    return count($missing);
}

try {
    if (!ems_table_exists($pdo, 'medical_record_integrations') || !ems_table_exists($pdo, 'medical_record_api_sync_runs')) {
        throw new RuntimeException('Tabel cache Medical Center belum tersedia. Jalankan docs/sql/78_2026-09-21_medical_center_get_cache.sql.');
    }

    $result = ems_medical_center_api_fetch_all();
    $pagesFetched = (int) ($result['pages'] ?? 0);
    $remoteRecords = $result['records'] ?? [];
    $recordsReceived = count($remoteRecords);
    $cutoff = ems_medical_center_cutoff();
    $recordsDeleted = deleteMissingMedicalCenterMirrors($pdo, medicalCenterRemoteRecordIds($remoteRecords), $cutoff);
    $now = $startedAt->format('Y-m-d H:i:s');

    $find = $pdo->prepare(
        'SELECT id FROM medical_record_integrations WHERE provider = ? AND hospital_code = ? AND remote_record_id = ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO medical_record_integrations
            (provider, hospital_code, remote_record_id, remote_event_at, remote_created_at, remote_updated_at,
             patient_name, record_payload_json, payload_hash, sync_state, last_pulled_at, last_error)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE medical_record_integrations
         SET remote_event_at = ?, remote_created_at = ?, remote_updated_at = ?, patient_name = ?,
             record_payload_json = ?, payload_hash = ?, sync_state = ?, last_pulled_at = ?, last_error = ?
         WHERE id = ?'
    );
    $mirrorColumns = [
        'source_provider', 'source_hospital', 'remote_record_id', 'remote_record_url', 'remote_event_at',
        'remote_created_at', 'remote_updated_at', 'remote_sync_state', 'remote_last_pulled_at', 'remote_last_error',
        'remote_payload_json', 'remote_medical_details_json', 'remote_team_json', 'remote_dpjp_json', 'remote_assistants_json', 'remote_photos_json',
        'record_code', 'patient_name', 'patient_citizen_id', 'patient_occupation', 'patient_dob', 'patient_phone',
        'patient_gender', 'patient_blood_type', 'remote_record_number', 'patient_address', 'patient_status', 'remote_location', 'remote_diagnosis',
        'remote_anamnesis', 'remote_past_medical_history', 'remote_family_history', 'remote_allergy_history',
        'remote_medication_history', 'remote_general_condition', 'remote_gcs', 'remote_blood_pressure', 'remote_pulse',
        'remote_respiratory_rate', 'remote_temperature', 'remote_oxygen_saturation', 'remote_operation_name',
        'remote_operation_start_at', 'remote_operation_end_at', 'remote_operation_steps', 'remote_operation_result',
        'remote_anesthesia_type', 'remote_anesthesia_officer', 'remote_preop_medications', 'remote_postop_medications',
        'remote_laboratory_result', 'remote_radiology_result', 'remote_postop_advice', 'remote_aldrete',
        'medical_result_html', 'doctor_id', 'assistant_id', 'operasi_type', 'jenis_operasi', 'created_by', 'created_at', 'updated_at',
    ];
    $mirrorFind = $pdo->prepare(
        'SELECT id FROM medical_records
         WHERE source_provider = ? AND source_hospital = ? AND remote_record_id = ?
         LIMIT 1'
    );
    $mirrorUpdateColumns = array_values(array_filter($mirrorColumns, static fn (string $column): bool => !in_array($column, [
        'source_provider', 'source_hospital', 'remote_record_id', 'created_at',
    ], true)));
    $mirrorUpdate = $pdo->prepare(
        'UPDATE medical_records SET ' . implode(', ', array_map(
            static fn (string $column): string => "`{$column}` = ?",
            $mirrorUpdateColumns
        )) . ' WHERE id = ?'
    );
    $mirrorInsert = $pdo->prepare(
        'INSERT INTO medical_records (`' . implode('`,`', $mirrorColumns) . '`)
         VALUES (' . implode(',', array_fill(0, count($mirrorColumns), '?')) . ')'
    );

    foreach ($remoteRecords as $record) {
        $remoteId = trim((string) ($record['id'] ?? ''));
        if ($remoteId === '') {
            $recordsSkipped++;
            continue;
        }

        $eventAt = ems_medical_center_parse_datetime($record['tanggal_waktu'] ?? null);
        if ($eventAt !== null && $eventAt < $cutoff) {
            $recordsSkipped++;
            continue;
        }

        $recordStatus = $eventAt === null ? 'needs_review' : 'synced';
        $mapped = ems_medical_center_normalize_record($record, $pdo);
        $payloadJson = $mapped['remote_payload_json'];
        $payloadHash = hash('sha256', $payloadJson);
        $remoteEventAt = $eventAt?->format('Y-m-d H:i:s');
        $remoteCreatedAt = ems_medical_center_datetime_for_db($record['created_at'] ?? null);
        $remoteUpdatedAt = ems_medical_center_datetime_for_db($record['updated_at'] ?? null);
        $patientName = $mapped['patient_name'] !== '' ? $mapped['patient_name'] : null;
        $mapped['remote_sync_state'] = $recordStatus;
        $mapped['remote_last_pulled_at'] = $now;
        $mapped['remote_last_error'] = $eventAt === null ? 'tanggal_waktu tidak valid atau kosong.' : null;
        $mapped['remote_event_at'] = $remoteEventAt;
        $mapped['remote_created_at'] = $remoteCreatedAt;
        $mapped['remote_updated_at'] = $remoteUpdatedAt;
        $mapped['medical_result_html'] = $mapped['medical_result_html'] !== '' ? $mapped['medical_result_html'] : '<p>-</p>';
        $mapped['created_at'] = $remoteEventAt ?: $now;
        $mapped['updated_at'] = $now;

        $find->execute(['medical_center', 'roxwood', $remoteId]);
        $integrationId = (int) ($find->fetchColumn() ?: 0);
        if ($integrationId > 0) {
            $update->execute([
                $remoteEventAt,
                $remoteCreatedAt,
                $remoteUpdatedAt,
                $patientName,
                $payloadJson,
                $payloadHash,
                $recordStatus,
                $now,
                $eventAt === null ? 'tanggal_waktu tidak valid atau kosong.' : null,
                $integrationId,
            ]);
        } else {
            $insert->execute([
                'medical_center',
                'roxwood',
                $remoteId,
                $remoteEventAt,
                $remoteCreatedAt,
                $remoteUpdatedAt,
                $patientName,
                $payloadJson,
                $payloadHash,
                $recordStatus,
                $now,
                $eventAt === null ? 'tanggal_waktu tidak valid atau kosong.' : null,
            ]);
        }

        $mirrorValues = [];
        foreach ($mirrorColumns as $column) {
            $mirrorValues[] = match ($column) {
                'source_provider' => $mapped['source_provider'],
                'source_hospital' => $mapped['source_hospital'],
                'remote_record_id' => $mapped['remote_record_id'],
                default => $mapped[$column] ?? null,
            };
        }
        $mirrorFind->execute(['medical_center', 'roxwood', $remoteId]);
        $mirrorId = (int) ($mirrorFind->fetchColumn() ?: 0);
        if ($mirrorId > 0) {
            $mirrorUpdateValues = [];
            foreach ($mirrorUpdateColumns as $column) {
                $mirrorUpdateValues[] = $mirrorValues[array_search($column, $mirrorColumns, true)];
            }
            $mirrorUpdate->execute([...$mirrorUpdateValues, $mirrorId]);
        } else {
            $mirrorInsert->execute($mirrorValues);
            $mirrorId = (int) $pdo->lastInsertId();
        }

        if ($mirrorId > 0 && isset($mapped['remote_local_assistant_ids']) && is_array($mapped['remote_local_assistant_ids'])) {
            ems_save_medical_record_assistants($pdo, $mirrorId, $mapped['remote_local_assistant_ids']);
        }

        if ($mirrorId > 0) {
            ems_auto_create_disciplinary_reduction_requests_for_medical_record($pdo, $mirrorId);
        }

        $recordsStored++;
        if ($recordStatus === 'needs_review') {
            $status = 'needs_review';
        }
    }
} catch (Throwable $e) {
    $status = 'failed';
    $errorMessage = $e->getMessage();

    try {
        if (ems_table_exists($pdo, 'medical_record_integrations')) {
            $staleStmt = $pdo->prepare(
                "UPDATE medical_record_integrations
                 SET sync_state = 'stale', last_error = ?
                 WHERE provider = 'medical_center' AND hospital_code = 'roxwood'
                   AND sync_state IN ('synced', 'needs_review')"
            );
            $staleStmt->execute([$errorMessage]);
        }
        if (ems_table_exists($pdo, 'medical_records')) {
            $mirrorStaleStmt = $pdo->prepare(
                "UPDATE medical_records
                 SET remote_sync_state = 'stale', remote_last_error = ?
                 WHERE source_provider = 'medical_center' AND source_hospital = 'roxwood'"
            );
            $mirrorStaleStmt->execute([$errorMessage]);
        }
    } catch (Throwable) {
        // Preserve original pull error; cache remains available even if status update fails.
    }
}

$finishedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
try {
    if (ems_table_exists($pdo, 'medical_record_api_sync_runs')) {
        $stmt = $pdo->prepare(
            'INSERT INTO medical_record_api_sync_runs
                (provider, hospital_code, status, pages_fetched, records_received, records_stored, records_skipped,
                 error_message, started_at, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'medical_center',
            'roxwood',
            $status,
            $pagesFetched,
            $recordsReceived,
            $recordsStored,
            $recordsSkipped,
            $errorMessage,
            $startedAt->format('Y-m-d H:i:s'),
            $finishedAt->format('Y-m-d H:i:s'),
        ]);
    }
} catch (Throwable $e) {
    $status = 'failed';
    $errorMessage ??= 'Gagal mencatat status GET pull.';
}

flock($lock, LOCK_UN);
fclose($lock);

$output = sprintf(
    "Medical Center GET pull: %s; pages=%d; received=%d; stored=%d; deleted=%d; skipped=%d%s\n",
    $status,
    $pagesFetched,
    $recordsReceived,
    $recordsStored,
    $recordsDeleted,
    $recordsSkipped,
    $errorMessage !== null ? '; error=' . $errorMessage : ''
);

fwrite($status === 'failed' ? STDERR : STDOUT, $output);
exit($status === 'failed' ? 1 : 0);
