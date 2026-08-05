<?php
/**
 * @var array<string,mixed> $pagination
 * @var string              $target  JS controller name for AJAX paging
 */

$page     = (int) ($pagination['page'] ?? 1);
$lastPage = (int) ($pagination['last_page'] ?? 1);
$total    = (int) ($pagination['total'] ?? 0);

// Windowed page list: first, last, and a few either side of the current page.
$window = [];

for ($i = 1; $i <= $lastPage; $i++) {
    if ($i === 1 || $i === $lastPage || abs($i - $page) <= 2) {
        $window[] = $i;
    }
}
?>
<div class="pagination">
    <div>
        <?php if ($total === 0): ?>
            No records
        <?php else: ?>
            Showing <strong><?= e($pagination['from'] ?? 0) ?></strong>–<strong><?= e($pagination['to'] ?? 0) ?></strong>
            of <strong><?= e(number_format($total)) ?></strong>
        <?php endif; ?>
    </div>

    <div class="flex items-center gap-1">
        <label class="text-sm text-muted" for="per-page-select" style="margin:0">Rows</label>
        <select id="per-page-select" style="width:auto;padding:.25rem .45rem;font-size:12px"
                onchange="<?= e($target ?? 'window.tableController') ?>.load({ per_page: parseInt(this.value, 10), page: 1 })">
            <?php foreach ((array) config('app.pagination.allowed', [10, 25, 50, 100]) as $option): ?>
                <option value="<?= e($option) ?>" <?= (int) ($pagination['per_page'] ?? 25) === $option ? 'selected' : '' ?>>
                    <?= e($option) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($lastPage > 1): ?>
        <div class="pagination__pages">
            <button class="pagination__link" type="button" <?= $page <= 1 ? 'disabled' : '' ?>
                    onclick="<?= e($target ?? 'window.tableController') ?>.page(<?= e($page - 1) ?>)"
                    aria-label="Previous page">
                <i class="fa-solid fa-chevron-left"></i>
            </button>

            <?php $previous = 0; ?>
            <?php foreach ($window as $number): ?>
                <?php if ($previous > 0 && $number - $previous > 1): ?>
                    <span class="text-subtle" style="padding:0 .2rem">…</span>
                <?php endif; ?>
                <button class="pagination__link <?= $number === $page ? 'active' : '' ?>" type="button"
                        onclick="<?= e($target ?? 'window.tableController') ?>.page(<?= e($number) ?>)"
                        <?= $number === $page ? 'aria-current="page"' : '' ?>>
                    <?= e($number) ?>
                </button>
                <?php $previous = $number; ?>
            <?php endforeach; ?>

            <button class="pagination__link" type="button" <?= $page >= $lastPage ? 'disabled' : '' ?>
                    onclick="<?= e($target ?? 'window.tableController') ?>.page(<?= e($page + 1) ?>)"
                    aria-label="Next page">
                <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>
    <?php endif; ?>
</div>
