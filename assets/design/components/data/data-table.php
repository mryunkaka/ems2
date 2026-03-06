<?php
/** @var string $id */
/** @var string $class */
/** @var callable|null $head */
/** @var callable|null $body */
/** @var callable|null $toolbar */

$id = $id ?? '';
$class = $class ?? '';
?>

<div class="card">
    <?php if (is_callable($toolbar)): ?>
        <div class="card-toolbar">
            <?php $toolbar(); ?>
        </div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
            class="table-custom <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
            <?php if (is_callable($head)): ?>
                <thead><?php $head(); ?></thead>
            <?php endif; ?>
            <tbody>
                <?php if (is_callable($body)) $body(); ?>
            </tbody>
        </table>
    </div>
</div>

