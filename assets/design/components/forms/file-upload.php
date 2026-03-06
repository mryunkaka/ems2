<?php
/** @var string $label */
/** @var string $name */
/** @var string $id */
/** @var bool $required */
/** @var string $accept */
/** @var string $help */
/** @var string $class */
/** @var string $attrs */

$label = $label ?? '';
$name = $name ?? '';
$id = $id ?? ($name ?: '');
$required = (bool)($required ?? false);
$accept = $accept ?? '';
$help = $help ?? '';
$class = $class ?? '';
$attrs = $attrs ?? '';
?>

<label class="form-label" <?= $id ? 'for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    <?php if ($required): ?><span class="required">*</span><?php endif; ?>
</label>
<input
    type="file"
    <?= $name ? 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    <?= $required ? 'required' : '' ?>
    <?= $accept !== '' ? 'accept="' . htmlspecialchars($accept, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-sky-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-sky-900 hover:file:bg-sky-100 <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
    <?= $attrs ?>
/>
<?php if ($help !== ''): ?>
    <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($help, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

