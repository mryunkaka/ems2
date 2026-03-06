<?php
require_once __DIR__ . '/../../ui/icon.php';

/** @var string $title */
/** @var string $message */
/** @var string $icon */
/** @var string $class */

$title = $title ?? 'Belum ada data';
$message = $message ?? 'Data akan muncul di sini setelah tersedia.';
$icon = $icon ?? 'document-text';
$class = $class ?? '';
?>

<div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-50 text-slate-600">
        <?= ems_icon($icon, 'h-6 w-6') ?>
    </div>
    <div class="mt-3 text-sm font-semibold text-text"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
</div>

