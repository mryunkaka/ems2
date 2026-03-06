<?php
/** @var string $label */
/** @var string $class */

$label = $label ?? 'Memuat...';
$class = $class ?? '';
?>

<div class="inline-flex items-center gap-2 text-sm text-slate-600 <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-primary"></span>
    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</div>

