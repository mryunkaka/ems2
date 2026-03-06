<?php
require_once __DIR__ . '/../../ui/icon.php';

/** @var string $label */
/** @var string|int|float $value */
/** @var string $icon */
/** @var string $tone */
/** @var string $class */

$label = $label ?? '';
$value = $value ?? '-';
$icon  = $icon ?? 'chart-bar';
$tone  = $tone ?? 'primary'; // primary|success|warning|danger|muted
$class = $class ?? '';

$toneMap = [
    'primary' => 'bg-primary/10 text-primary',
    'success' => 'bg-emerald-500/10 text-emerald-700',
    'warning' => 'bg-amber-500/10 text-amber-700',
    'danger'  => 'bg-rose-500/10 text-rose-700',
    'muted'   => 'bg-slate-500/10 text-slate-700',
];
$toneClass = $toneMap[$tone] ?? $toneMap['primary'];
?>

<div class="card <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="mt-2 text-2xl font-semibold text-text"><?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="inline-flex h-11 w-11 items-center justify-center rounded-2xl <?= $toneClass ?>">
            <?= ems_icon($icon, 'h-6 w-6') ?>
        </div>
    </div>
</div>

