<?php
/**
 * @var string $icon
 * @var string $title
 * @var string $text
 * @var string $action  pre-rendered HTML
 */
?>
<div class="empty-state">
    <div class="empty-state__icon"><i class="fa-solid <?= e($icon ?? 'fa-inbox') ?>"></i></div>
    <div class="empty-state__title"><?= e($title ?? 'Nothing here yet') ?></div>
    <?php if (!empty($text)): ?>
        <p class="empty-state__text"><?= e($text) ?></p>
    <?php endif; ?>
    <?php if (!empty($action)): ?>
        <?= $action ?>
    <?php endif; ?>
</div>
