<?php
/**
 * @var string                       $title
 * @var list<array{0:string,1:string}> $breadcrumbs  [[label, href], …]
 * @var string                       $actions      pre-rendered HTML
 * @var string                       $subtitle
 */
?>
<div class="page-header">
    <div>
        <?php if (!empty($breadcrumbs)): ?>
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <?php if ($index > 0): ?><span class="breadcrumb__sep">/</span><?php endif; ?>
                    <?php if (!empty($crumb[1])): ?>
                        <a href="<?= e($crumb[1]) ?>"><?= e($crumb[0]) ?></a>
                    <?php else: ?>
                        <span><?= e($crumb[0]) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <h1><?= e($title) ?></h1>

        <?php if (!empty($subtitle)): ?>
            <p class="text-muted text-sm mb-0"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($actions)): ?>
        <div class="page-header__actions"><?= $actions ?></div>
    <?php endif; ?>
</div>
