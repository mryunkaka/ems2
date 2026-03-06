<?php
/** @var string $class */
/** @var callable|null $body */

$class = $class ?? '';
?>

<div class="content <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (is_callable($body)) $body(); ?>
</div>

