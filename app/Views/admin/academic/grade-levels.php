<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Grade Levels',
    'subtitle'    => 'Every comparison in the system uses the numeric level, never the code — "G10" sorts before "G7" as a string.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Academic Setup', null], ['Grade Levels', null]],
    'actions'     => '<button class="btn btn-primary" data-modal-open="grade-modal"><i class="fa-solid fa-plus"></i> Add Grade Level</button>',
]); ?>

<div class="card">
    <div class="card__body--flush">
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Code</th><th>Name</th><th class="numeric">Numeric level</th><th>Track</th>
                    <th class="numeric">Sections</th><th class="numeric">Students</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($gradeLevels as $grade): ?>
                    <tr>
                        <td><span class="badge badge-primary mono"><?= e($grade['grade_level_code']) ?></span></td>
                        <td class="cell-primary"><?= e($grade['grade_level_name']) ?></td>
                        <td class="numeric fw-600"><?= e($grade['numeric_level']) ?></td>
                        <td><?= $grade['track'] ? '<span class="badge badge-neutral">' . e($grade['track']) . '</span>' : '<span class="text-subtle">—</span>' ?></td>
                        <td class="numeric"><?= e($grade['section_count']) ?></td>
                        <td class="numeric"><?= e($grade['student_count']) ?></td>
                        <td><span class="badge <?= e(status_badge($grade['status'])) ?>"><?= e(ucfirst((string) $grade['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card__footer text-sm text-muted">
        Grade levels are never deleted, only deactivated — historical attendance references them permanently.
    </div>
</div>

<div class="modal-backdrop" id="grade-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Add Grade Level</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form method="post" data-ajax action="/admin/grade-levels" data-reload="true">
            <div class="modal__body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="g-code" class="required">Code</label>
                        <input type="text" id="g-code" name="grade_level_code" required maxlength="10"
                               placeholder="G7" data-uppercase style="text-transform:uppercase">
                    </div>
                    <div class="form-group">
                        <label for="g-numeric" class="required">Numeric level</label>
                        <input type="number" id="g-numeric" name="numeric_level" required min="1" max="20" placeholder="7">
                        <span class="field-help">Used for all sorting and range comparisons.</span>
                    </div>
                    <div class="form-group form-group--full">
                        <label for="g-name" class="required">Name</label>
                        <input type="text" id="g-name" name="grade_level_name" required maxlength="50" placeholder="Grade 7">
                    </div>
                    <div class="form-group form-group--full">
                        <label for="g-track">Track <span class="label__hint">senior high only</span></label>
                        <select id="g-track" name="track">
                            <option value="">None</option>
                            <option>Academic</option><option>TVL</option><option>Sports</option><option>Arts and Design</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php $__view->stop(); ?>
