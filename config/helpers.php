<?php
function initialsFromName(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return mb_strtoupper(
            mb_substr($parts[0], 0, 1) .
                mb_substr($parts[1], 0, 1)
        );
    }
    return mb_strtoupper(mb_substr($parts[0], 0, 2));
}

function avatarColorFromName(string $name): string
{
    $hash = crc32(mb_strtolower(trim($name)));
    $hue  = $hash % 360;
    return "hsl($hue, 70%, 45%)";
}

function formatTanggalID($datetime)
{
    if (!$datetime) return '-';

    $bulan = [
        1 => 'Jan',
        'Feb',
        'Mar',
        'Apr',
        'Mei',
        'Jun',
        'Jul',
        'Agu',
        'Sep',
        'Okt',
        'Nov',
        'Des'
    ];

    $dt = new DateTime($datetime);

    $hari  = (int)$dt->format('j');
    $bulanTxt = $bulan[(int)$dt->format('n')];
    $tahun = $dt->format('Y');
    $jam   = $dt->format('H:i');

    return "{$hari} {$bulanTxt} {$tahun} {$jam}";
}

function safeRegulation(PDO $pdo, string $code): int
{
    $stmt = $pdo->prepare("
        SELECT price_type, price_min, price_max
        FROM medical_regulations
        WHERE code = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        throw new Exception("Regulasi tidak ditemukan: {$code}");
    }

    if ($r['price_type'] === 'RANGE') {
        return random_int((int)$r['price_min'], (int)$r['price_max']);
    }

    return (int)$r['price_min'];
}

function formatTanggalIndo($date)
{
    if (!$date) return '-';

    // Jika sudah DateTime, pakai langsung
    if ($date instanceof DateTime) {
        $d = $date;
    } else {
        $d = new DateTime($date);
    }

    $bulan = [
        1 => 'Jan',
        'Feb',
        'Mar',
        'Apr',
        'Mei',
        'Jun',
        'Jul',
        'Agu',
        'Sep',
        'Okt',
        'Nov',
        'Des'
    ];

    $hari  = $d->format('d');                  // 05
    $bulanNama = $bulan[(int)$d->format('n')]; // Jan
    $tahun = substr($d->format('Y'), 2);       // 26

    return "{$hari} {$bulanNama} {$tahun}";
}

function dollar($amount)
{
    return '$' . number_format((float)$amount, 0, ',', '.');
}
