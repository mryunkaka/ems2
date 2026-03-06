<?php
/** @var string $label */
/** @var string $name */
/** @var string $id */
/** @var string $type */
/** @var string $value */
/** @var bool $required */
/** @var string $placeholder */
/** @var string $class */
/** @var string $attrs */

$label = $label ?? '';
$name = $name ?? '';
$id = $id ?? ($name ?: '');
$type = $type ?? 'text';
$value = $value ?? '';
$required = (bool)($required ?? false);
$placeholder = $placeholder ?? '';
$class = $class ?? '';
$attrs = $attrs ?? '';
?>

<label class="form-label" <?= $id ? 'for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    <?php if ($required): ?><span class="required">*</span><?php endif; ?>
</label>
<input
    type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
    <?= $name ? 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
    <?= $required ? 'required' : '' ?>
    <?= $placeholder !== '' ? 'placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    class="input <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
    <?= $attrs ?>
/>

