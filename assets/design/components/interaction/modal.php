<?php
require_once __DIR__ . '/../../ui/icon.php';

/** @var string $id */
/** @var string|null $title */
/** @var string $size */
/** @var callable|null $body */
/** @var callable|null $actions */
/** @var string $class */

$id    = $id ?? '';
$title = $title ?? null;
$size  = $size ?? 'md'; // sm|md|lg
$class = $class ?? '';

$frameClass = match ($size) {
    'sm' => 'max-w-lg',
    'lg' => 'max-w-4xl',
    default => 'max-w-2xl',
};
?>

<div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" class="modal-overlay hidden <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <div class="modal-card <?= $frameClass ?>">
        <?php if ($title !== null): ?>
            <div class="modal-header flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-semibold text-text"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3>
                </div>
                <button type="button" class="btn-secondary btn-compact btn-cancel" aria-label="Tutup">
                    <?= ems_icon('x-mark', 'h-4 w-4') ?>
                </button>
            </div>
        <?php endif; ?>

        <div class="modal-body">
            <?php if (is_callable($body)) $body(); ?>
        </div>

        <?php if (is_callable($actions)): ?>
            <div class="modal-actions mt-4">
                <?php $actions(); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

