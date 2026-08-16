<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'My Sections',
    'subtitle'    => 'The classes you take, and who is in them. A section you teach for two subjects is listed once.',
    'breadcrumbs' => [['Dashboard', '/teacher'], ['My Sections', null]],
    'actions'     => '<a class="btn btn-secondary" href="/teacher/schedule"><i class="fa-solid fa-calendar-days"></i> My schedule</a>',
]); ?>

<?php
$missing = array_sum(array_map(static fn (array $s): int => (int) $s['without_card'], $sections));
?>

<?php if ($missing > 0): ?>
    <div class="alert alert-warning">
        <span class="alert__icon"><i class="fa-solid fa-id-card"></i></span>
        <div class="alert__body">
            <strong><?= e($missing) ?> student<?= $missing === 1 ? '' : 's' ?> across your sections
            hold no active card.</strong> They cannot be marked present by the terminal at all — there is
            nothing for it to read. Ask the administration to issue their cards; the sections below show
            which students.
        </div>
    </div>
<?php endif; ?>

<?php if ($sections === []): ?>
    <div class="card">
        <div class="card__body">
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-users-rectangle',
                'title' => 'No sections assigned to you yet',
                'text'  => 'Sections appear here once a schedule is created pairing you with a subject and a section. '
                         . 'Ask the administration if you expected a class to be listed.',
            ]); ?>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid--3">
        <?php foreach ($sections as $section): ?>
            <?php $without = (int) $section['without_card']; ?>
            <a class="card card--link" href="/teacher/sections/<?= e($section['section_id']) ?>">
                <div class="card__body">
                    <div class="flex gap-1" style="justify-content:space-between;align-items:flex-start">
                        <div>
                            <h3 class="card__title" style="margin:0">
                                <?= e($section['section_code']) ?>
                            </h3>
                            <div class="text-sm text-muted"><?= e($section['section_name']) ?></div>
                        </div>
                        <span class="badge badge-primary"><?= e($section['grade_level_code']) ?></span>
                    </div>

                    <div class="text-xs text-muted mt-2">
                        <i class="fa-solid fa-book"></i>
                        <?= e($section['subject_codes']) ?>
                        <?php if ((int) $section['subject_count'] > 1): ?>
                            <span class="badge badge-neutral"><?= e($section['subject_count']) ?> subjects</span>
                        <?php endif; ?>
                    </div>

                    <div class="flex gap-2 mt-3" style="align-items:baseline">
                        <div>
                            <span style="font-size:24px;font-weight:600"><?= e($section['student_count']) ?></span>
                            <span class="text-xs text-muted">student<?= (int) $section['student_count'] === 1 ? '' : 's' ?></span>
                        </div>
                        <?php if ($without > 0): ?>
                            <span class="badge badge-warning" title="Cannot be marked present until a card is issued">
                                <?= e($without) ?> without a card
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php $__view->stop(); ?>
