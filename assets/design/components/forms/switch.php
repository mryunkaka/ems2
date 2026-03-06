<?php
/**
 * Switch Toggle (UI-only).
 * Requires Alpine.js (already local) for interaction.
 *
 * Props:
 * - name, id, checked, label
 */

/** @var string $label */
/** @var string $name */
/** @var string $id */
/** @var bool $checked */
/** @var string $attrs */

$label = $label ?? '';
$name = $name ?? '';
$id = $id ?? ($name ?: '');
$checked = (bool)($checked ?? false);
$attrs = $attrs ?? '';
?>

<div class="flex items-center justify-between gap-3">
    <label class="text-sm font-medium text-slate-700" <?= $id ? 'for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </label>
    <label class="relative inline-flex cursor-pointer items-center" x-data="{ on: <?= $checked ? 'true' : 'false' ?> }">
        <input
            type="checkbox"
            class="sr-only"
            x-model="on"
            <?= $name ? 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
            <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
            <?= $checked ? 'checked' : '' ?>
            <?= $attrs ?>
        />
        <span
            class="h-6 w-11 rounded-full border border-slate-300 bg-slate-200 transition"
            :class="on ? 'bg-emerald-500/80 border-emerald-500/30' : 'bg-slate-200 border-slate-300'"></span>
        <span
            class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition"
            :class="on ? 'translate-x-5' : 'translate-x-0'"></span>
    </label>
</div>

