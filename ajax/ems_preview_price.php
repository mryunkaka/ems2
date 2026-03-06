<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

$serviceType   = $_POST['service_type'] ?? '';
$serviceDetail = $_POST['service_detail'] ?? '';
$operasiTingkat = $_POST['operasi_tingkat'] ?? null;
$qty = (int)($_POST['qty'] ?? 1);
$isGunshot = ($_POST['is_gunshot'] ?? '0') === '1';
$meds = $_POST['meds'] ?? [];

$total = 0;
$price = 0;

try {
    switch ($serviceType) {

        case 'Pingsan':
            $map = [
                'RS' => 'PP_RS',
                'Paleto' => 'PP_PALETO',
                'Gunung/Laut' => 'PP_GUNUNG',
                'Zona Perang' => 'PP_PERANG',
                'UFC' => 'PP_UFC',
            ];

            $price = safeRegulation($pdo, $map[$serviceDetail]);

            $medPrice = safeRegulation(
                $pdo,
                $isGunshot ? 'BLEEDING_PELURU' : 'BLEEDING_OBAT'
            );

            $medicineCount = is_array($meds) ? count($meds) : 0;

            $total = $price + ($medicineCount * $medPrice);
            break;

        case 'Treatment':
            $price = safeRegulation($pdo, $serviceDetail === 'RS' ? 'TR_RS' : 'TR_LUAR');

            $medPrice = safeRegulation(
                $pdo,
                $isGunshot ? 'BLEEDING_PELURU' : 'BLEEDING_OBAT'
            );

            $medicineCount = is_array($meds) ? count($meds) : 0;

            $total = $price + ($medicineCount * $medPrice);
            break;

        case 'Surat':
            $price = safeRegulation($pdo, $serviceDetail === 'Kesehatan' ? 'SK_KES' : 'SK_PSI');
            $total = $price;
            break;

        case 'Operasi':
            if (!$operasiTingkat) {
                $errors[] = 'Tingkat operasi wajib dipilih';
                break;
            }

            // Ambil regulasi dasar
            $code = $serviceDetail === 'Besar' ? 'OP_BESAR' : 'OP_KECIL';

            $stmt = $pdo->prepare("
                SELECT price_min, price_max
                FROM medical_regulations
                WHERE code = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$code]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reg) {
                $errors[] = 'Regulasi operasi tidak ditemukan';
                break;
            }

            $min = (int)$reg['price_min'];
            $max = (int)$reg['price_max'];

            // Bagi range jadi 3 bagian
            $step = floor(($max - $min) / 3);

            switch ($operasiTingkat) {
                case 'Ringan':
                    $price = random_int($min, $min + $step);
                    break;

                case 'Sedang':
                    $price = random_int(
                        $min + $step + 1,
                        $min + ($step * 2)
                    );
                    break;

                case 'Berat':
                    $price = random_int(
                        $min + ($step * 2) + 1,
                        $max
                    );
                    break;

                default:
                    $errors[] = 'Tingkat operasi tidak valid';
                    break;
            }

            $total = $price;
            break;


        case 'Rawat Inap':
            $perHari = safeRegulation($pdo, $serviceDetail === 'Reguler' ? 'RI_REG' : 'RI_VIP');
            $total = $perHari * max($qty, 1);
            break;

        case 'Kematian':
            $price = safeRegulation($pdo, $serviceDetail === 'Pemakaman' ? 'PEMAKAMAN' : 'KREMASI');
            $total = $price;
            break;
    }

    echo json_encode([
        'success' => true,
        'total' => $total,
        'breakdown' => [
            'base_price' => $price ?? 0,
            'medicine' => [
                'count' => count($meds),
                'per_item' => isset($medPrice) ? $medPrice : 0,
                'type' => $isGunshot ? 'PELURU' : 'NORMAL',
                'subtotal' => isset($medPrice) ? count($meds) * $medPrice : 0
            ]
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
