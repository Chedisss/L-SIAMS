<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

/* The sections this teacher takes, for the links under the grid. Keyed by id
   so a teacher who takes the same section for two subjects is listed once. */
$sections = [];

foreach ($schedules as $schedule) {
    $sections[(int) $schedule['section_id']] = (string) $schedule['section_code'];
}

asort($sections);
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
    <?php /* The same grid the section pages use — rows of time, columns of day,
            with break, lunch and home time named rather than left as holes.
            This replaced a column of stacked cards per day, which was accurate
            and hard to read across: the question a teacher actually asks of
            their own timetable is "what am I doing at ten", and that is a row,
            not a column. */ ?>
    <?php $__view->include('partials.week-grid', [
        'week'       => $week,
        'title'      => 'Weekly schedule',
        'emptyTitle' => 'No classes assigned yet',
        'emptyText'  => 'Once the administration assigns you subjects and sections, your timetable appears here.',
    ]); ?>

    <?php /* The grid names the section but cannot link it — a downloadable
            picture of a table has no links in it, and the cells are already
            carrying two lines. The rosters stay reachable underneath. */ ?>
    <?php if ($sections !== []): ?>
        <div class="card">
            <div class="card__header"><h2 class="card__title">Your sections</h2></div>
            <div class="card__body">
                <div class="flex flex-wrap gap-1">
                    <?php foreach ($sections as $sectionId => $label): ?>
                        <a class="badge badge-primary" href="/teacher/sections/<?= e($sectionId) ?>"
                           title="See who is in this section"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
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

<?php if ($view !== 'daily' && ($week['rows'] ?? []) !== []): ?>
<?php
/* Handed to the download as data rather than scraped back out of the DOM, so
   the picture is of the timetable rather than of however the table happened to
   be laid out at the window width somebody had open. */
$__timetable = [
    'heading'    => $teacherName,
    'subheading' => '',
    'caption'    => 'Teaching Schedule',
    'filename'   => 'schedule-' . $teacherName,
    'school'     => App\Services\SettingsService::schoolName(),
    'days'       => $week['days'],
    'rows'       => $week['rows'],
];
?>
<script type="application/json" id="week-data"><?= json_encode($__timetable, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php
$__view->stop();
$__view->start('scripts');
?>
<?php $__view->include('partials.week-grid-script'); ?>
<?php $__view->stop(); ?>
