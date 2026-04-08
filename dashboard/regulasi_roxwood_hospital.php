<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');
ems_require_not_trainee_html('Regulasi Roxwood Hospital');

$pageTitle = 'Regulasi Roxwood Hospital';
$currentUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$currentHospitalName = ems_unit_hospital_name($currentUnit);
$packagesHasUnitCode = ems_column_exists($pdo, 'packages', 'unit_code');
$medicalHasCashAmount = ems_column_exists($pdo, 'medical_regulations', 'cash_amount');
$medicalHasCashMaxAmount = ems_column_exists($pdo, 'medical_regulations', 'cash_max_amount');
$medicalHasBillingAmount = ems_column_exists($pdo, 'medical_regulations', 'billing_amount');
$medicalHasBillingMaxAmount = ems_column_exists($pdo, 'medical_regulations', 'billing_max_amount');

function regulationMoney(int $amount): string
{
    return '$' . number_format($amount, 0, '.', '.');
}

function regulationPriceLabel(array $row): string
{
    $type = strtoupper((string) ($row['price_type'] ?? 'FIXED'));
    $min = (int) ($row['price_min'] ?? 0);
    $max = (int) ($row['price_max'] ?? 0);
    $cash = (int) ($row['cash_amount'] ?? 0);
    $cashMax = (int) ($row['cash_max_amount'] ?? 0);
    $billing = (int) ($row['billing_amount'] ?? 0);
    $billingMax = (int) ($row['billing_max_amount'] ?? 0);
    $hasSplitAmounts = $cash > 0 || $billing > 0 || $cashMax > 0 || $billingMax > 0;

    if ($hasSplitAmounts) {
        $totalMin = $cash + $billing;
        $totalMax = $cashMax + $billingMax;

        if ($type === 'RANGE' && $totalMax > 0) {
            return regulationMoney($totalMin) . ' - ' . regulationMoney($totalMax);
        }

        return regulationMoney($totalMin);
    }

    if ($type === 'RANGE' && $max > $min) {
        return regulationMoney($min) . ' - ' . regulationMoney($max);
    }

    return regulationMoney($min);
}

function regulationItemLabel(array $row): string
{
    $code = strtoupper((string) ($row['code'] ?? ''));
    $name = trim((string) ($row['name'] ?? ''));

    return match ($code) {
        'PP_RS' => 'Dalam Rumah Sakit',
        'PP_PALETO' => 'Area Paleto',
        'PP_GUNUNG' => 'Area Gunung / Laut',
        'PP_PERANG' => 'Zona Perang',
        'PP_UFC' => 'Zona UFC',
        'TR_RS' => 'Dalam Rumah Sakit',
        'TR_LUAR' => 'Di luar Rumah Sakit',
        'SK_KES' => 'Surat Keterangan Kesehatan',
        'SK_PSI' => 'Surat Keterangan Psikologi',
        'PEMAKAMAN' => 'Pemakaman',
        'KREMASI' => 'Kremasi',
        'RI_REG' => 'Reguler',
        'RI_VIP' => 'VIP',
        'OP_PL_CASH' => 'Cash',
        'OP_PL_BILL' => 'Billing',
        'BLEEDING_OBAT' => 'Penanganan obat / pcs',
        'BLEEDING_PELURU' => 'Luka tembak / obat',
        default => $name !== '' ? $name : $code,
    };
}

function regulationSplitLabel(array $row): string
{
    $cash = (int) ($row['cash_amount'] ?? 0);
    $cashMax = (int) ($row['cash_max_amount'] ?? 0);
    $billing = (int) ($row['billing_amount'] ?? 0);
    $billingMax = (int) ($row['billing_max_amount'] ?? 0);
    $parts = [];

    if ($cash > 0 || $cashMax > 0) {
        $parts[] = $cashMax > 0
            ? 'Cash ' . regulationMoney($cash) . ' - ' . regulationMoney($cashMax)
            : 'Cash ' . regulationMoney($cash);
    }
    if ($billing > 0 || $billingMax > 0) {
        $parts[] = $billingMax > 0
            ? 'Billing ' . regulationMoney($billing) . ' - ' . regulationMoney($billingMax)
            : 'Billing ' . regulationMoney($billing);
    }

    return implode(' + ', $parts);
}

function regulationPackageSummary(array $row): array
{
    $items = [];
    if ((int) ($row['bandage_qty'] ?? 0) > 0) {
        $items[] = ['label' => 'Bandage', 'qty' => (int) $row['bandage_qty']];
    }
    if ((int) ($row['ifaks_qty'] ?? 0) > 0) {
        $items[] = ['label' => 'Ifak Stress', 'qty' => (int) $row['ifaks_qty']];
    }
    if ((int) ($row['painkiller_qty'] ?? 0) > 0) {
        $items[] = ['label' => 'Pain-Killer', 'qty' => (int) $row['painkiller_qty']];
    }

    return $items;
}

function regulationPackageCardTone(string $name): string
{
    $normalized = strtolower(trim($name));

    return match (true) {
        str_contains($normalized, 'paket a') => 'tone-red',
        str_contains($normalized, 'paket b') => 'tone-blue',
        str_contains($normalized, 'paket c') => 'tone-orange',
        default => 'tone-slate',
    };
}

function regulationSingleMedicineItems(array $packages): array
{
    $catalog = [
        'bandage' => ['label' => 'Bandage', 'qty_key' => 'bandage_qty'],
        'ifaks' => ['label' => 'Ifaks Stress', 'qty_key' => 'ifaks_qty'],
        'painkiller' => ['label' => 'Painkiller', 'qty_key' => 'painkiller_qty'],
    ];

    $items = [];
    foreach ($catalog as $key => $config) {
        $items[$key] = [
            'label' => $config['label'],
            'unit_price' => 0,
            '_qty' => 0,
        ];
    }

    foreach ($packages as $package) {
        $bandageQty = (int) ($package['bandage_qty'] ?? 0);
        $ifaksQty = (int) ($package['ifaks_qty'] ?? 0);
        $painkillerQty = (int) ($package['painkiller_qty'] ?? 0);
        $nonZeroCount = (int) ($bandageQty > 0) + (int) ($ifaksQty > 0) + (int) ($painkillerQty > 0);

        if ($nonZeroCount !== 1) {
            continue;
        }

        foreach ($catalog as $key => $config) {
            $qty = (int) ($package[$config['qty_key']] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unitPrice = (int) round(((int) ($package['price'] ?? 0)) / $qty);
            if ($unitPrice <= 0) {
                continue;
            }

            if ($items[$key]['unit_price'] <= 0 || $qty > $items[$key]['_qty']) {
                $items[$key]['unit_price'] = $unitPrice;
                $items[$key]['_qty'] = $qty;
            }
        }
    }

    return array_values(array_map(static function (array $item): array {
        unset($item['_qty']);
        return $item;
    }, $items));
}

$medicalRegs = $pdo->query("
    SELECT
        id, category, code, name, location,
        price_type, price_min, price_max,
        " . ($medicalHasCashAmount ? "cash_amount," : "0 AS cash_amount,") . "
        " . ($medicalHasCashMaxAmount ? "cash_max_amount," : "0 AS cash_max_amount,") . "
        " . ($medicalHasBillingAmount ? "billing_amount," : "0 AS billing_amount,") . "
        " . ($medicalHasBillingMaxAmount ? "billing_max_amount," : "0 AS billing_max_amount,") . "
        payment_type, duration_minutes,
        notes, is_active
    FROM medical_regulations
    WHERE is_active = 1
    ORDER BY category, code
")->fetchAll(PDO::FETCH_ASSOC);

$packagesStmt = $pdo->prepare("
    SELECT id, name, bandage_qty, ifaks_qty, painkiller_qty, price
    FROM packages
    " . ($packagesHasUnitCode ? "WHERE COALESCE(unit_code, 'roxwood') = :unit_code" : "") . "
    ORDER BY name ASC, id DESC
");
$packagesStmt->execute($packagesHasUnitCode ? [':unit_code' => $currentUnit] : []);
$packageRows = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

$medicalSections = [
    'pingsan' => [],
    'bleeding' => [],
    'treatment' => [],
    'surat' => [],
    'kematian' => [],
    'operasi' => [],
    'operasi_plastik' => [],
    'rawat_inap' => [],
    'lainnya' => [],
];

foreach ($medicalRegs as $row) {
    $code = strtoupper((string) ($row['code'] ?? ''));
    $category = strtoupper((string) ($row['category'] ?? ''));

    if (str_starts_with($code, 'PP_')) {
        $medicalSections['pingsan'][] = $row;
        continue;
    }
    if (str_starts_with($code, 'BLEEDING_')) {
        $medicalSections['bleeding'][] = $row;
        continue;
    }
    if ($category === 'TREATMENT') {
        $medicalSections['treatment'][] = $row;
        continue;
    }
    if ($category === 'SURAT') {
        $medicalSections['surat'][] = $row;
        continue;
    }
    if ($category === 'KEMATIAN') {
        $medicalSections['kematian'][] = $row;
        continue;
    }
    if ($category === 'OPERASI') {
        $medicalSections['operasi'][] = $row;
        continue;
    }
    if ($category === 'OPERASI_PLASTIK') {
        $medicalSections['operasi_plastik'][] = $row;
        continue;
    }
    if ($category === 'RAWAT_INAP') {
        $medicalSections['rawat_inap'][] = $row;
        continue;
    }

    $medicalSections['lainnya'][] = $row;
}

$dedupedPackages = [];
foreach ($packageRows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') {
        continue;
    }

    $key = strtolower(preg_replace('/\s+/', ' ', $name) ?: $name);
    $currentScore = (int) ($row['bandage_qty'] ?? 0) + (int) ($row['ifaks_qty'] ?? 0) + (int) ($row['painkiller_qty'] ?? 0);

    if (!isset($dedupedPackages[$key])) {
        $dedupedPackages[$key] = $row + ['_score' => $currentScore];
        continue;
    }

    $existing = $dedupedPackages[$key];
    $existingScore = (int) ($existing['_score'] ?? 0);

    if ($currentScore > $existingScore || ($currentScore === $existingScore && (int) $row['id'] > (int) ($existing['id'] ?? 0))) {
        $dedupedPackages[$key] = $row + ['_score' => $currentScore];
    }
}

$packageDisplayRows = array_values(array_map(static function (array $row): array {
    unset($row['_score']);
    return $row;
}, $dedupedPackages));

usort($packageDisplayRows, static function (array $left, array $right): int {
    $leftName = strtolower(trim((string) ($left['name'] ?? '')));
    $rightName = strtolower(trim((string) ($right['name'] ?? '')));
    $leftIsPackage = str_starts_with($leftName, 'paket');
    $rightIsPackage = str_starts_with($rightName, 'paket');

    if ($leftIsPackage !== $rightIsPackage) {
        return $leftIsPackage ? -1 : 1;
    }

    return strcmp($leftName, $rightName);
});

$bundlePackages = [];
$singlePackages = [];
foreach ($packageDisplayRows as $row) {
    $name = strtolower(trim((string) ($row['name'] ?? '')));
    if (str_starts_with($name, 'paket')) {
        $bundlePackages[] = $row;
    } else {
        $singlePackages[] = $row;
    }
}
$singleMedicineItems = regulationSingleMedicineItems($singlePackages);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell regulation-page">
        <div class="regulation-board">
            <header class="regulation-hero">
                <div class="regulation-title-wrap">
                    <div class="regulation-title-pill">REGULASI ROXWOOD HOSPITAL</div>
                    <div class="regulation-meta"><?= htmlspecialchars(strtoupper($currentHospitalName), ENT_QUOTES, 'UTF-8') ?> • VIEW MANAGER</div>
                </div>
                <div class="regulation-brand">
                    <div class="brand-mark"><?= ems_icon('building-office-2', 'h-7 w-7') ?></div>
                    <div class="brand-copy">
                        <strong>ROXWOOD</strong>
                        <span>HOSPITAL</span>
                    </div>
                </div>
            </header>

            <div class="regulation-medical-grid">
                <section class="regulation-column">
                    <?php if (!empty($medicalSections['pingsan'])): ?>
                        <article class="regulation-block">
                            <h2>Pertolongan Pertama (Pingsan)</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['pingsan'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endif; ?>

                    <?php if (!empty($medicalSections['treatment'])): ?>
                        <article class="regulation-block">
                            <h2>Perawatan Luka (Treatment)</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['treatment'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (!empty($medicalSections['bleeding'])): ?>
                                <div class="regulation-note">
                                    <strong>Tambahan penanganan:</strong>
                                    <?php foreach ($medicalSections['bleeding'] as $index => $item): ?>
                                        <span><?= $index > 0 ? ' • ' : '' ?><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>

                    <?php if (!empty($medicalSections['operasi'])): ?>
                        <article class="regulation-block">
                            <h2>Operasi</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['operasi'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endif; ?>

                    <?php if (!empty($medicalSections['rawat_inap'])): ?>
                        <article class="regulation-block">
                            <h2>Rawat Inap (per-hari)</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['rawat_inap'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <div class="regulation-note">1 hari = 15 menit</div>
                        </article>
                    <?php endif; ?>
                </section>

                <section class="regulation-column">
                    <?php if (!empty($medicalSections['surat'])): ?>
                        <article class="regulation-block">
                            <h2>Pembuatan Surat</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['surat'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endif; ?>

                    <?php if (!empty($medicalSections['kematian'])): ?>
                        <article class="regulation-block">
                            <h2>Kematian</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['kematian'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endif; ?>

                    <?php if (!empty($medicalSections['operasi_plastik'])): ?>
                        <article class="regulation-block">
                            <h2>Operasi Plastik</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['operasi_plastik'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endif; ?>

                    <?php if (!empty($medicalSections['lainnya'])): ?>
                        <article class="regulation-block">
                            <h2>Layanan Lainnya</h2>
                            <ul class="regulation-list">
                                <?php foreach ($medicalSections['lainnya'] as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars(regulationItemLabel($item), ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationPriceLabel($item), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                    <?php if (regulationSplitLabel($item) !== ''): ?>
                                        <div class="regulation-subnote">• <?= htmlspecialchars(regulationSplitLabel($item), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endif; ?>
                </section>
            </div>

            <div class="regulation-divider"></div>

            <section class="pharmacy-section">
                <div class="pharmacy-pill">REGULASI FARMASI</div>
                <div class="pharmacy-caption">1 hari hanya diperbolehkan membeli 1 paket. Paket akan mengikuti data pada tabel `packages`.</div>

                <div class="pharmacy-grid">
                    <?php foreach ($bundlePackages as $package): ?>
                        <article class="package-card <?= htmlspecialchars(regulationPackageCardTone((string) $package['name']), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="package-head">
                                <span class="package-name"><?= htmlspecialchars((string) $package['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <strong class="package-price"><?= htmlspecialchars(regulationMoney((int) ($package['price'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <ul class="package-list">
                                <?php foreach (regulationPackageSummary($package) as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong>x<?= (int) $item['qty'] ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endforeach; ?>

                    <?php if (!empty($singleMedicineItems)): ?>
                        <article class="package-card tone-slate package-card-wide">
                            <div class="package-head">
                                <span class="package-name">SATUAN / PCS</span>
                                <strong class="package-price">Obat-obatan</strong>
                            </div>
                            <ul class="package-list">
                                <?php foreach ($singleMedicineItems as $item): ?>
                                    <li>
                                        <span><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong><?= htmlspecialchars(regulationMoney((int) ($item['unit_price'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</section>

<style>
    .regulation-page {
        max-width: 1180px;
        margin: 0 auto;
    }

    .regulation-board {
        position: relative;
        overflow: hidden;
        background:
            radial-gradient(circle at bottom right, rgba(239, 68, 68, 0.08), transparent 24%),
            linear-gradient(180deg, #ffffff 0%, #fff7f7 100%);
        border: 1px solid rgba(248, 113, 113, 0.18);
        border-radius: 32px;
        padding: 30px 28px 34px;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
    }

    .regulation-board::before,
    .regulation-board::after {
        content: "";
        position: absolute;
        width: 240px;
        height: 240px;
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.18), rgba(239, 68, 68, 0));
        transform: rotate(45deg);
        pointer-events: none;
    }

    .regulation-board::before {
        top: -160px;
        left: -120px;
    }

    .regulation-board::after {
        top: -180px;
        right: -120px;
    }

    .regulation-hero {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        gap: 18px;
        align-items: flex-start;
        margin-bottom: 26px;
    }

    .regulation-title-wrap {
        flex: 1;
        min-width: 0;
    }

    .regulation-title-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 52px;
        padding: 10px 28px;
        background: #ff3131;
        border-radius: 999px;
        color: #fff;
        font-size: 1.1rem;
        font-weight: 900;
        letter-spacing: 0.04em;
        text-align: center;
        box-shadow: 0 12px 24px rgba(239, 68, 68, 0.24);
    }

    .regulation-meta {
        margin-top: 12px;
        color: #64748b;
        font-size: 0.88rem;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .regulation-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-top: 4px;
        color: #0f172a;
    }

    .brand-mark {
        display: grid;
        place-items: center;
        width: 58px;
        height: 58px;
        border-radius: 18px;
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: #fff;
        box-shadow: 0 12px 22px rgba(220, 38, 38, 0.24);
    }

    .brand-copy {
        display: flex;
        flex-direction: column;
        line-height: 1.05;
    }

    .brand-copy strong {
        font-size: 1rem;
        letter-spacing: 0.08em;
    }

    .brand-copy span {
        font-size: 0.86rem;
        letter-spacing: 0.18em;
        color: #64748b;
    }

    .regulation-medical-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 28px;
    }

    .regulation-column {
        display: grid;
        gap: 24px;
        align-content: start;
    }

    .regulation-block h2 {
        margin: 0 0 12px;
        color: #ef4444;
        font-size: 1.72rem;
        line-height: 1.08;
        font-weight: 900;
    }

    .regulation-list {
        margin: 0;
        padding-left: 1.2rem;
        display: grid;
        gap: 8px;
    }

    .regulation-list li {
        display: flex;
        align-items: baseline;
        gap: 10px;
        color: #1f2937;
        font-size: 1rem;
        line-height: 1.45;
    }

    .regulation-list li span {
        flex: 1;
        min-width: 0;
        position: relative;
        display: inline-flex;
        align-items: baseline;
        gap: 10px;
    }

    .regulation-list li span::after {
        content: "";
        flex: 1;
        border-bottom: 1px dotted rgba(15, 23, 42, 0.35);
        transform: translateY(-3px);
    }

    .regulation-list li strong {
        white-space: nowrap;
        font-size: 1.02rem;
        color: #111827;
    }

    .regulation-note {
        margin-top: 12px;
        color: #475569;
        font-size: 0.92rem;
        line-height: 1.55;
    }

    .regulation-subnote {
        margin: -4px 0 0 1.2rem;
        color: #475569;
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .regulation-divider {
        position: relative;
        z-index: 1;
        height: 1px;
        margin: 28px 0 24px;
        background: linear-gradient(90deg, transparent, rgba(15, 23, 42, 0.3), transparent);
    }

    .pharmacy-section {
        position: relative;
        z-index: 1;
    }

    .pharmacy-pill {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        border-radius: 999px;
        background: #374151;
        color: #fff;
        font-size: 1.02rem;
        font-weight: 900;
        letter-spacing: 0.06em;
    }

    .pharmacy-caption {
        margin: 14px 0 18px;
        color: #64748b;
        text-align: center;
        font-size: 0.92rem;
    }

    .pharmacy-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }

    .package-card {
        border-radius: 22px;
        padding: 16px 16px 14px;
        background: #fff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        box-shadow: 0 14px 24px rgba(15, 23, 42, 0.08);
    }

    .package-card-wide {
        grid-column: span 2;
    }

    .package-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
        padding: 10px 14px;
        border-radius: 999px;
        color: #fff;
    }

    .package-name {
        font-weight: 900;
        letter-spacing: 0.04em;
    }

    .package-price {
        white-space: nowrap;
        font-size: 0.96rem;
    }

    .package-list {
        margin: 0;
        padding-left: 1rem;
        display: grid;
        gap: 8px;
        color: #1f2937;
    }

    .package-list li {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        font-size: 0.95rem;
    }

    .tone-red .package-head {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .tone-blue .package-head {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
    }

    .tone-orange .package-head {
        background: linear-gradient(135deg, #fb923c, #f97316);
    }

    .tone-slate .package-head {
        background: linear-gradient(135deg, #475569, #334155);
    }

    @media (max-width: 1080px) {
        .regulation-hero {
            flex-direction: column;
        }

        .regulation-medical-grid,
        .pharmacy-grid {
            grid-template-columns: 1fr;
        }

        .package-card-wide {
            grid-column: auto;
        }
    }
</style>
