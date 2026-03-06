<?php
require_once __DIR__ . '/../../ui/icon.php';

/** @var string $title */
/** @var string $message */
/** @var string $class */

$title = $title ?? 'Terjadi kesalahan';
$message = $message ?? 'Silakan muat ulang halaman atau coba lagi.';
$class = $class ?? '';
?>

<div class="rounded-2xl border border-rose-200 bg-rose-50 p-6 <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <div class="flex items-start gap-3">
        <div class="mt-0.5 text-rose-700"><?= ems_icon('exclamation-triangle', 'h-5 w-5') ?></div>
        <div>
            <div class="text-sm font-semibold text-rose-900"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="mt-1 text-sm text-rose-800"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>
</div>

