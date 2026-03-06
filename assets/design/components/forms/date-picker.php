<?php
/** @var string $label */
/** @var string $name */
/** @var string $id */
/** @var string $value */
/** @var bool $required */
/** @var string $class */
/** @var string $attrs */

$label = $label ?? '';
$name = $name ?? '';
$id = $id ?? ($name ?: '');
$value = $value ?? '';
$required = (bool)($required ?? false);
$class = $class ?? '';
$attrs = $attrs ?? '';
?>

<label class="form-label" <?= $id ? 'for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    <?php if ($required): ?><span class="required">*</span><?php endif; ?>
</label>
<input
    type="date"
    <?= $name ? 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
    <?= $required ? 'required' : '' ?>
    class="input <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
    <?= $attrs ?>
/>

