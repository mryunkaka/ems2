<?php
/** @var string $label */
/** @var string $tone */
/** @var string $class */

$label = $label ?? '';
$tone  = $tone ?? 'muted'; // success|danger|muted
$class = $class ?? '';

$toneClass = match ($tone) {
    'success' => 'badge-success',
    'danger' => 'badge-danger',
    default => 'badge-muted-mini',
};
?>

<span class="<?= $toneClass ?> <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
</span>

