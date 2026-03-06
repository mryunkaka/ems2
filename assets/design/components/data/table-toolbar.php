<?php
require_once __DIR__ . '/../../ui/icon.php';

/** @var string|null $title */
/** @var callable|null $left */
/** @var callable|null $right */

$title = $title ?? null;
?>

<div class="card-toolbar">
    <div class="toolbar-group">
        <?php if ($title !== null): ?>
            <div class="text-sm font-semibold text-text"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (is_callable($left)) $left(); ?>
    </div>
    <div class="toolbar-group">
        <?php if (is_callable($right)) $right(); ?>
    </div>
</div>

