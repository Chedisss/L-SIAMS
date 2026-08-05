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

    <div class="card">
        <div class="card__header"><h2 class="card__title">Weekly schedule</h2></div>
        <div class="card__body--flush">
            <?php if ($schedules === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon' => 'fa-calendar-days', 'title' => 'No classes scheduled',
                    'text' => 'This section has no schedule yet, so no attendance can be taken for it.',
                ]); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th></tr></thead>
                        <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td><span class="badge badge-neutral"><?= e(substr((string) $schedule['day_of_week'], 0, 3)) ?></span></td>
                                <td class="nowrap mono text-sm">
                                    <?= e(substr((string) $schedule['start_time'], 0, 5)) ?>–<?= e(substr((string) $schedule['end_time'], 0, 5)) ?>
                                </td>
                                <td class="cell-primary"><?= e($schedule['subject_code']) ?></td>
                                <td class="text-sm"><?= e($schedule['teacher_name']) ?></td>
                                <td class="text-sm"><?= e($schedule['room_number']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $__view->stop(); ?>
