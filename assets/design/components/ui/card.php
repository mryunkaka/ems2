<?php
/** @var string $class */
/** @var string|null $title */
/** @var callable|null $actions */
/** @var callable|null $body */
/** @var callable|null $footer */

$class = $class ?? '';
?>

<div class="card <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (!empty($title) || is_callable($actions)): ?>
        <div class="card-header-between">
            <div class="flex items-center gap-2">
                <?php if (!empty($title)): ?>
                    <h3 class="text-base font-semibold text-text"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3>
                <?php endif; ?>
            </div>
            <?php if (is_callable($actions)): ?>
                <div class="card-header-actions-right">
                    <?php $actions(); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (is_callable($body)): ?>
        <div class="space-y-4">
            <?php $body(); ?>
        </div>
    <?php endif; ?>

    <?php if (is_callable($footer)): ?>
        <div class="mt-4 border-t border-slate-200 pt-3">
            <?php $footer(); ?>
        </div>
    <?php endif; ?>
</div>

