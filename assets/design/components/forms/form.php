<?php
/** @var string $class */
/** @var string $method */
/** @var string $action */
/** @var string $attrs */
/** @var callable|null $body */

$class = $class ?? 'form';
$method = $method ?? 'post';
$action = $action ?? '';
$attrs = $attrs ?? '';
?>

<form method="<?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?>"
    <?= $action !== '' ? 'action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
    class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
    <?= $attrs ?>>
    <?php if (is_callable($body)) $body(); ?>
</form>

