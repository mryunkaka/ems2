<?php
require 'config/database.php';

echo "<h2>Hapus data yang salah dan backfill ulang untuk request_id 9</h2>";
echo "<pre>";

// Hapus data yang sudah di-insert
$pdo->exec("DELETE FROM position_promotion_request_operations WHERE request_id = 9");
echo "Data yang lama dihapus\n\n";

// Backfill dengan data dummy untuk paramedic → co_asst
// Syarat: 2x minor + 1x mayor sebagai assistant
$dummyData = [
    [
        'medical_record_id' => null,
        'patient_name' => 'Dummy Patient 1 (Minor)',
        'operasi_type' => 'minor',
        'dpjp_name' => 'dr. Dummy DPJP 1',
        'operation_role' => 'assistant'
    ],
    [
        'medical_record_id' => null,
        'patient_name' => 'Dummy Patient 2 (Minor)',
        'operasi_type' => 'minor',
        'dpjp_name' => 'dr. Dummy DPJP 2',
        'operation_role' => 'assistant'
    ],
    [
        'medical_record_id' => 2, // Gunakan real medical record untuk mayor
        'patient_name' => 'Emysyu Takahashi',
        'operasi_type' => 'major',
        'dpjp_name' => 'Chocho Xavier',
        'operation_role' => 'assistant'
    ]
];

echo "Records yang akan di-insert:\n";
foreach ($dummyData as $rec) {
    print_r($rec);
    echo "---\n";
}

// Insert ke position_promotion_request_operations
$sortOrder = 1;
foreach ($dummyData as $rec) {
    $insertStmt = $pdo->prepare("
        INSERT INTO position_promotion_request_operations
            (request_id, sort_order, medical_record_id, patient_name, procedure_name, dpjp, operation_role, operation_level)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        9,
        $sortOrder++,
        $rec['medical_record_id'],
        $rec['patient_name'],
        'Operasi ' . ($rec['operasi_type'] === 'major' ? 'Mayor' : 'Minor'),
        $rec['dpjp_name'],
        $rec['operation_role'],
        $rec['operasi_type']
    ]);
}

echo "\nBackfill selesai untuk request_id 9\n";
echo "</pre>";
