<?php
/** @var string $label */
/** @var string $name */
/** @var string $id */
/** @var array $options */
/** @var string $value */
/** @var bool $required */
/** @var string $class */
/** @var string $attrs */

$label = $label ?? '';
$name = $name ?? '';
$id = $id ?? ($name ?: '');
$options = $options ?? [];
$value = $value ?? '';
$required = (bool)($required ?? false);
$class = $class ?? '';
$attrs = $attrs ?? '';
?>

<label class="form-label" <?= $id ? 'for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    <?php if ($required): ?><span class="required">*</span><?php endif; ?>
</label>
<select
    <?= $name ? 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    <?= $required ? 'required' : '' ?>
    class="input <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
    <?= $attrs ?>>
    <?php foreach ($options as $optValue => $optLabel): ?>
        <option value="<?= htmlspecialchars((string)$optValue, ENT_QUOTES, 'UTF-8') ?>" <?= ((string)$optValue === (string)$value) ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$optLabel, ENT_QUOTES, 'UTF-8') ?>
        </option>
    <?php endforeach; ?>
</select>

