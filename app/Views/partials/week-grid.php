<?php
/**
 * A week as a grid: rows of time, columns of day.
 *
 * Shared by the section page and both teacher schedule pages so that a
 * timetable is the same object wherever it is read. A teacher who has learned
 * to find Wednesday's third period on one screen should not have to learn it
 * again on another.
 *
 * @var App\Core\View                                         $__view
 * @var array{days:list<string>,rows:list<array<string,mixed>>} $week
 * @var string                                                $emptyTitle
 * @var string                                                $emptyText
 * @var string                                                $title
 * @var bool                                                  $downloadable
 */
$title        = $title        ?? 'Weekly schedule';
$emptyTitle   = $emptyTitle   ?? 'No classes scheduled';
$emptyText    = $emptyText    ?? 'Nothing is on the timetable yet.';
$downloadable = $downloadable ?? true;
$hasRows      = ($week['rows'] ?? []) !== [];
?>
<div class="card" id="week-card">
    <div class="card__header">
        <h2 class="card__title"><?= e($title) ?></h2>
        <?php if ($hasRows && $downloadable): ?>
            <button class="btn btn-secondary btn-sm" id="week-download">
                <i class="fa-solid fa-image"></i> Download as image
            </button>
        <?php endif; ?>
    </div>
    <div class="card__body--flush">
        <?php if (!$hasRows): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-calendar-days',
                'title' => $emptyTitle,
                'text'  => $emptyText,
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data timetable" id="week-grid">
                    <thead>
                        <tr>
                            <th style="width:104px">Time</th>
                            <th class="numeric" style="width:74px">Duration</th>
                            <?php foreach ($week['days'] as $day): ?>
                                <th><?= e($day) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($week['rows'] as $row): ?>
                        <?php if ($row['type'] === 'band'): ?>
                            <?php /* Break, lunch and home time are not schedules — nothing
                                    is taught in them, so no session can open — but leaving
                                    the gap blank makes the timetable look broken. The times
                                    are the school's own; only the label is inferred. */ ?>
                            <tr class="timetable__band">
                                <td class="mono text-sm nowrap">
                                    <?= e($row['start']) ?><?= $row['end'] ? '–' . e($row['end']) : '' ?>
                                </td>
                                <td class="numeric text-sm"><?= $row['duration'] ? e($row['duration']) : '' ?></td>
                                <td colspan="<?= e(count($week['days'])) ?>"><?= e($row['label']) ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="mono text-sm nowrap"><?= e($row['start']) ?>–<?= e($row['end']) ?></td>
                                <td class="numeric text-sm"><?= e($row['duration']) ?></td>
                                <?php foreach ($week['days'] as $day): ?>
                                    <?php $cell = $row['cells'][$day] ?? null; ?>
                                    <td class="timetable__cell">
                                        <?php if ($cell === null): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <span class="cell-primary"><?= e($cell['primary']) ?></span>
                                            <span class="cell-muted"><?= e($cell['secondary']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
