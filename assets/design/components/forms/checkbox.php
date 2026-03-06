<?php
/** @var string $label */
/** @var string $name */
/** @var string $id */
/** @var string $value */
/** @var bool $checked */
/** @var string $class */
/** @var string $attrs */

$label = $label ?? '';
$name = $name ?? '';
$id = $id ?? ($name ?: '');
$value = $value ?? '1';
$checked = (bool)($checked ?? false);
$class = $class ?? '';
$attrs = $attrs ?? '';
?>

<label class="inline-flex items-center gap-2 text-sm text-slate-700">
    <input
        type="checkbox"
        <?= $name ? 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
        <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
        value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
        <?= $checked ? 'checked' : '' ?>
        class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/40 <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
        <?= $attrs ?>
    />
    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</label>

