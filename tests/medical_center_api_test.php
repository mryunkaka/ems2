<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/medical_records_api.php';
require_once __DIR__ . '/../config/duty_hours_api.php';

$person = ems_medical_center_person(['name' => 'RH - Dokter Contoh', 'citizen_id' => 'CID-1', 'staff_id' => 'STAFF-1']);
if ($person['citizen_id'] !== 'CID-1' || $person['staff_id'] !== 'STAFF-1') {
    throw new RuntimeException('Medical Center citizen ID and staff ID must both be retained.');
}
if (ems_duty_hours_name_key('RH - Dokter Contoh') !== ems_duty_hours_name_key('Dokter Contoh, S. Ked')) {
    throw new RuntimeException('Duty report name variants from the example did not normalize to the same key.');
}

$record = ems_medical_center_normalize_record([
    'id' => 436,
    'hospital' => 'roxwood',
    'nama_pasien' => 'Pasien Uji',
    'members' => [
        ['role' => 'Dokter DPJP', 'user' => ['full_name' => 'dr. Dokter Uji', 'staff_id' => 'D-1']],
        ['role' => 'Asisten Operasi', 'user' => ['full_name' => 'Asisten Uji', 'staff_id' => 'A-1']],
    ],
    'medical_details' => [
        'pasien' => ['jenis_kelamin' => 'Laki-Laki'],
        'tindakan' => ['nama_tindakan' => 'Operasi Uji'],
    ],
]);

$dpjp = json_decode($record['remote_dpjp_json'], true, 512, JSON_THROW_ON_ERROR);
$assistants = json_decode($record['remote_assistants_json'], true, 512, JSON_THROW_ON_ERROR);
if ($record['patient_gender'] !== 'Laki-laki' || $record['jenis_operasi'] !== 'Operasi Uji' || $dpjp['name'] !== 'dr. Dokter Uji' || ($assistants['member_1']['remote_name'] ?? '') !== 'Asisten Uji') {
    throw new RuntimeException('Medical Center nested patient or team fields did not normalize correctly.');
}

$legacyTeamRecord = ems_medical_center_normalize_record([
    'id' => 437,
    'poin' => ['dpjp' => 80],
    'creator' => ['id' => 12, 'name' => 'RH - Dokter Uji', 'staff_id' => 'DOC-12'],
    'members' => [
        ['id' => 12, 'name' => 'RH - Dokter Uji', 'staff_id' => 'DOC-12'],
        ['id' => 13, 'name' => 'RH - Asisten Uji', 'staff_id' => 'AST-13'],
    ],
]);
$legacyDpjp = json_decode($legacyTeamRecord['remote_dpjp_json'], true, 512, JSON_THROW_ON_ERROR);
$legacyAssistants = json_decode($legacyTeamRecord['remote_assistants_json'], true, 512, JSON_THROW_ON_ERROR);
if ($legacyDpjp['staff_id'] !== 'DOC-12' || ($legacyAssistants['member_1']['remote_staff_id'] ?? '') !== 'AST-13') {
    throw new RuntimeException('Legacy GET member IDs did not resolve the DPJP and assistant.');
}

echo "Medical Center API normalization passed.\n";
