<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

/** Group the flat schedule list by day so the weekly grid can render columns. */
$byDay = array_fill_keys($days, []);

foreach ($schedules as $schedule) {
    $byDay[$schedule['day_of_week']][] = $schedule;
}

$weekdays = array_slice($days, 0, 5);
$weekend  = array_filter(array_slice($days, 5), static fn (string $d): bool => $byDay[$d] !== []);
$columns  = array_merge($weekdays, $weekend);
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'My Schedule',
    'subtitle'    => 'Every class you are assigned to teach. Schedules are set by the administration — this is a read-only view.',
    'breadcrumbs' => [['Dashboard', '/teacher'], ['My Schedule', null]],
    'actions'     => '<div class="btn-group">'
        . '<a class="btn ' . ($view === 'weekly' ? 'btn-primary' : 'btn-secondary') . '" href="/teacher/schedule?view=weekly">Week</a>'
        . '<a class="btn ' . ($view === 'daily' ? 'btn-primary' : 'btn-secondary') . '" href="/teacher/schedule?view=daily">Today</a>'
        . '</div>',
]); ?>

<?php if ($schedules === []): ?>
    <div class="card">
        <div class="card__body--flush">
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-calendar-xmark',
                'title' => $view === 'daily' ? 'Nothing scheduled today' : 'No classes assigned yet',
                'text'  => $view === 'daily'
                    ? 'You have no classes on ' . $today . '. Switch to the week view to see the rest of your timetable.'
                    : 'Once the administration assigns you subjects and sections, your timetable appears here.',
            ]); ?>
        </div>
    </div>
<?php elseif ($view === 'daily'): ?>
    <div class="card">
        <div class="card__header">
            <h2 class="card__title"><?= e($today) ?></h2>
            <span class="text-xs text-muted"><?= e(count($schedules)) ?> class(es)</span>
        </div>
        <div class="card__body--flush">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Time</th><th>Subject</th><th>Section</th><th>Room</th>
                        <th>Late after</th><th>Time-in closes</th><th>Time-out opens</th><th>Minimum stay</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td class="nowrap fw-600"><?= e(format_time($schedule['start_time'])) ?> – <?= e(format_time($schedule['end_time'])) ?></td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($schedule['subject_name']) ?></span>
                                <span class="cell-muted mono"><?= e($schedule['subject_code']) ?></span>
                            </td>
                            <td>
                                <?php /* Straight to the roster: the question that follows "when do I
                                         teach this" is almost always "who is in it". */ ?>
                                <a class="badge badge-primary" href="/teacher/sections/<?= e($schedule['section_id']) ?>"
                                   title="See who is in this section">
                                    <?= e($schedule['section_code']) ?>
                                </a>
                            </td>
                            <td class="text-sm"><?= e($schedule['room_number']) ?><span class="text-muted"> · <?= e($schedule['building']) ?></span></td>
                            <td class="text-sm"><?= e(format_time($schedule['start_time'])) ?> + <?= e($schedule['late_threshold_minutes']) ?>m</td>
                            <td class="text-sm"><?= e(format_time($schedule['time_in_window_close'])) ?></td>
                            <td class="text-sm"><?= e(format_time($schedule['time_out_window_open'])) ?></td>
                            <td class="text-sm"><?= e($schedule['minimum_dwell_minutes']) ?> min</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card__body--flush">
            <div class="table-wrap">
                <div class="timetable" style="grid-template-columns:repeat(<?= e(count($columns)) ?>, minmax(190px, 1fr))">
                    <?php foreach ($columns as $day): ?>
                        <div class="timetable__col">
                            <div class="timetable__head <?= $day === $today ? 'is-today' : '' ?>">
                                <?= e($day) ?>
                                <span class="text-xs text-muted">(<?= e(count($byDay[$day])) ?>)</span>
                            </div>

                            <?php if ($byDay[$day] === []): ?>
                                <div class="timetable__free">No classes</div>
                            <?php else: ?>
                                <?php foreach ($byDay[$day] as $schedule): ?>
                                    <div class="timetable__slot">
                                        <div class="timetable__time">
                                            <?= e(format_time($schedule['start_time'])) ?> – <?= e(format_time($schedule['end_time'])) ?>
                                        </div>
                                        <div class="timetable__subject"><?= e($schedule['subject_code']) ?></div>
                                        <div class="timetable__meta">
                                            <?php /* The weekly grid is the default view, so the roster
                                                     has to be reachable from here and not only from the
                                                     daily table. */ ?>
                                            <a href="/teacher/sections/<?= e($schedule['section_id']) ?>"
                                               title="See who is in this section"><?= e($schedule['section_code']) ?></a>
                                            · Room <?= e($schedule['room_number']) ?>
                                        </div>
                                        <div class="timetable__meta text-xs">
                                            Late after <?= e($schedule['late_threshold_minutes']) ?> min ·
                                            stay <?= e($schedule['minimum_dwell_minutes']) ?> min
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__header"><h2 class="card__title">How these windows are used</h2></div>
    <div class="card__body">
        <p class="text-sm text-muted" style="max-width:72ch">
            When you open a session on the classroom terminal, these four values are copied into it
            and stay fixed for that session. A student tapping in after the late threshold is recorded
            <strong>Late</strong>; after the time-in window closes they are refused. Tapping out before
            the time-out window opens, or before the minimum stay has elapsed, is recorded as
            <strong>Left Early</strong>. Changing a schedule later never reinterprets sessions that
            have already run.
        </p>
    </div>
</div>

<?php $__view->stop(); ?>
