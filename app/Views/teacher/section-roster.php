<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$without = count(array_filter($students, static fn (array $s): bool => $s['card_uid'] === null));
?>

<?php $__view->include('partials.page-header', [
    'title'       => $section['section_code'] . ' — ' . $section['section_name'],
    'subtitle'    => $section['grade_level_name'] . ' · ' . $section['subject_codes']
                     . ' · ' . count($students) . ' active student' . (count($students) === 1 ? '' : 's'),
    'breadcrumbs' => [['Dashboard', '/teacher'], ['My Sections', '/teacher/sections'], [$section['section_code'], null]],
    'actions'     => '<a class="btn btn-secondary" href="/teacher/sections"><i class="fa-solid fa-arrow-left"></i> All sections</a>'
        . '<a class="btn btn-primary" href="/teacher/attendance?section_id=' . (int) $section['section_id']
        . '"><i class="fa-solid fa-clipboard-user"></i> Attendance for this section</a>',
]); ?>

<?php if ($without > 0): ?>
    <div class="alert alert-warning">
        <span class="alert__icon"><i class="fa-solid fa-id-card"></i></span>
        <div class="alert__body">
            <strong><?= e($without) ?> of these students hold no active card.</strong>
            The terminal has nothing to read for them, so they cannot tap in or out and will be
            recorded absent every meeting until a card is issued. They are marked below.
        </div>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
    <div class="alert__body">
        The attendance figures are for <strong>your</strong> classes with this section only. If a
        colleague also teaches them, their record is separate and is not mixed in here.
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Roster</h2>
        <input type="search" id="roster-filter" class="input-sm"
               placeholder="Filter by name or number…" aria-label="Filter the roster">
    </div>

    <div class="card__body--flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Card</th>
                    <th class="numeric">Meetings</th>
                    <th class="numeric">Attended</th>
                    <th style="width:170px">Rate</th>
                    <th>Guardian</th>
                </tr>
                </thead>
                <tbody id="roster-body">
                <?php if ($students === []): ?>
                    <tr><td colspan="6">
                        <?php $__view->include('partials.empty-state', [
                            'icon'  => 'fa-user-graduate',
                            'title' => 'No active students in this section',
                            'text'  => 'Students appear here once they are enrolled into the section.',
                        ]); ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <?php
                        $rate = $student['attendance_rate'];

                        // Banded rather than a single colour: the number alone
                        // makes a teacher work out which figures need them.
                        $tone = $rate === null ? 'neutral'
                            : ($rate >= 90 ? 'success' : ($rate >= 75 ? 'primary' : ($rate >= 50 ? 'warning' : 'danger')));
                        ?>
                        <tr data-search="<?= e(strtolower(
                            $student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['student_number']
                        )) ?>">
                            <td class="cell-stack">
                                <span class="cell-primary">
                                    <?= e($student['last_name']) ?>, <?= e($student['first_name']) ?>
                                    <?= $student['suffix'] ? e($student['suffix']) : '' ?>
                                </span>
                                <span class="cell-muted mono"><?= e($student['student_number']) ?></span>
                            </td>
                            <td>
                                <?php if ($student['card_uid'] === null): ?>
                                    <span class="badge badge-warning" title="Cannot be marked present until a card is issued">
                                        <i class="fa-solid fa-triangle-exclamation"></i> no card
                                    </span>
                                <?php else: ?>
                                    <span class="mono text-xs text-muted"><?= e($student['card_uid']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric text-sm"><?= e($student['meetings']) ?></td>
                            <td class="numeric text-sm"><?= e($student['attended']) ?></td>
                            <td>
                                <?php if ($rate === null): ?>
                                    <span class="text-subtle text-xs">not met yet</span>
                                <?php else: ?>
                                    <div class="flex gap-1" style="align-items:center">
                                        <div class="meter" style="flex:1">
                                            <span class="meter__fill meter__fill--<?= e($tone) ?>"
                                                  style="width:<?= e($rate) ?>%"></span>
                                        </div>
                                        <span class="text-xs text-muted" style="min-width:34px;text-align:right">
                                            <?= e($rate) ?>%
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm">
                                <?= e($student['guardian_name']) ?>
                                <div class="text-xs text-muted mono"><?= e($student['guardian_contact']) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // Filtering in the page rather than on the server: a section is at most a
    // few dozen rows and they are all already here, so a round trip would only
    // make it slower.
    const filter = document.getElementById('roster-filter');

    if (filter === null) return;

    filter.addEventListener('input', function () {
        const needle = this.value.trim().toLowerCase();

        document.querySelectorAll('#roster-body tr[data-search]').forEach((row) => {
            row.hidden = needle !== '' && !row.dataset.search.includes(needle);
        });
    });
})();
</script>
<?php $__view->stop(); ?>
