<?php
/** @var string $id */
/** @var string $variant */

$id = $id ?? 'consumerNotice';
$variant = $variant ?? 'danger';
?>

<div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" class="notice-box consumer-merge-notice notice-<?= htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') ?>">
    <div class="consumer-merge-notice__header">
        <div class="consumer-merge-notice__icon">
            <?= ems_icon('exclamation-triangle', 'h-5 w-5') ?>
        </div>
        <div class="consumer-merge-notice__intro">
            <div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>Title" class="consumer-merge-notice__title"></div>
            <div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>Text" class="consumer-merge-notice__text"></div>
        </div>
    </div>

    <div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>Body" class="consumer-merge-notice__body"></div>
    <div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>Actions" class="consumer-merge-notice__actions"></div>
    <div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>Foot" class="consumer-merge-notice__foot"></div>
</div>
