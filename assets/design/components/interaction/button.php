<?php
/** @var string $label */
/** @var string $variant */
/** @var string $type */
/** @var string $class */
/** @var string $attrs */
/** @var string|null $icon */

require_once __DIR__ . '/../../ui/icon.php';

$label   = $label ?? '';
$variant = $variant ?? 'primary'; // primary|secondary|success|warning|danger|link
$type    = $type ?? 'button';
$class   = $class ?? '';
$attrs   = $attrs ?? '';
$icon    = $icon ?? null;

$variantClass = match ($variant) {
    'secondary' => 'btn-secondary',
    'success' => 'btn-success',
    'warning' => 'btn-warning',
    'danger' => 'btn-danger',
    'link' => 'btn-link',
    default => 'btn-primary',
};
?>

<button type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
    class="<?= $variantClass ?> <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
    <?= $attrs ?>>
    <?php if ($icon): ?>
        <?= ems_icon($icon, 'h-5 w-5') ?>
    <?php endif; ?>
    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
</button>

