<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/medical_records_api.php';

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

echo "Medical Center API normalization passed.\n";
