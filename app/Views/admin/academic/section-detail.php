<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => $section['section_code'] . ' — ' . $section['section_name'],
    'subtitle'    => $section['grade_level_code'] . ($section['strand'] ? ' · ' . $section['strand'] : '')
        . ' · Adviser: ' . ($section['adviser_name'] ?? 'unassigned'),
    'breadcrumbs' => [['Dashboard', '/admin'], ['Sections', '/admin/sections'], [$section['section_code'], null]],
]); ?>

<div class="grid grid--4 mb-3">
    <?php foreach ([
        ['Enrolled',   $section['enrolled_count'] . ' / ' . $section['capacity'], 'fa-people-group', ''],
        ['With cards', $section['students_with_cards'] . ' / ' . $section['active_students'], 'fa-id-card', 'info'],
        ['Attendance', ($section['attendance_percentage_30d'] ?? 0) . '%', 'fa-percent', 'success'],
        ['Schedules',  count($schedules), 'fa-calendar-days', 'warning'],
    ] as [$label, $value, $icon, $tone]): ?>
        <div class="stat">
            <span class="stat__icon <?= $tone ? 'stat__icon--' . e($tone) : '' ?>"><i class="fa-solid <?= e($icon) ?>"></i></span>
            <div><div class="stat__label"><?= e($label) ?></div><div class="stat__value" style="font-size:21px"><?= e($value) ?></div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Roster</h2>
            <span class="text-sm text-muted"><?= e(count($roster)) ?> student(s)</span>
        </div>
        <div class="card__body--flush">
            <?php if ($roster === []): ?>
                <?php $__view->include('partials.empty-state', ['icon' => 'fa-user-graduate', 'title' => 'No students enrolled']); ?>
            <?php else: ?>
                <div class="table-wrap" style="max-height:520px;overflow-y:auto">
                    <table class="data">
                        <thead><tr><th>Student No.</th><th>Name</th><th>Card</th><th class="numeric">Attendance</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($roster as $student): ?>
                            <tr>
                                <td class="mono text-sm"><?= e($student['student_number']) ?></td>
                                <td>
                                    <a href="/admin/students/<?= e($student['student_id']) ?>">
                                        <?= e($student['last_name']) ?>, <?= e($student['first_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($student['card_uid']): ?>
                                        <span class="mono text-xs"><?= e($student['card_uid']) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">No card</span>
                                    <?php endif; ?>
                                </td>
                                <td class="numeric">
                                    <?php if ($student['attendance_percentage'] === null): ?>
                                        <span class="text-subtle">—</span>
                                    <?php else: ?>
                                        <span class="<?= $student['attendance_percentage'] < 80 ? 'text-danger fw-600' : '' ?>">
                                            <?= e($student['attendance_percentage']) ?>%
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= e(status_badge($student['status'])) ?>"><?= e(ucfirst((string) $student['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php /* Full width, on its own row.
        Sharing the two-column grid with the roster left five day columns in
        half a page: Thursday and Friday were off the right-hand edge and the
        band labels, centred across the whole week, were centred somewhere
        nobody could see. A timetable is a wide thing and has to be given the
        width. */ ?>
<div class="grid">
    <?php /* The week as a grid rather than a list.
            Nobody reads a timetable by scanning forty rows for the ones that
            say Wednesday — they look at a column. The list this replaced was
            correct and unusable for the thing it was for. */ ?>
    <?php $__view->include('partials.week-grid', [
        'week'       => $week,
        'title'      => 'Weekly schedule',
        'emptyTitle' => 'No classes scheduled',
        'emptyText'  => 'This section has no schedule yet, so no attendance can be taken for it.',
    ]); ?>
</div>

<?php if (($week['rows'] ?? []) !== []): ?>
<?php
/* Handed to the download as data rather than scraped back out of the DOM:
   the picture should be of the timetable, not of however the table happens to
   be laid out at the window width somebody had open. */
$__timetable = [
    'heading'    => trim((string) $section['section_code'] . ' — ' . (string) $section['section_name']),
    'subheading' => (string) ($section['grade_level_name'] ?? ''),
    'caption'    => 'Class Schedule',
    'filename'   => (string) $section['section_code'],
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
