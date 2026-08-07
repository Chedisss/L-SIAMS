<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Teachers',
    'subtitle'    => 'A teacher may only be assigned subjects from their department and sections within their grade level.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Teachers', null]],
    'actions'     => '<a class="btn btn-primary" href="/admin/teachers/create"><i class="fa-solid fa-plus"></i> Register Teacher</a>',
]); ?>

<?php if ($withoutGradeLevel !== []): ?>
    <div class="alert alert-warning">
        <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <div class="alert__body">
            <div class="alert__title"><?= e(count($withoutGradeLevel)) ?> teacher(s) have no assigned grade level</div>
            They cannot be placed on any schedule until one is assigned:
            <?= e(implode(', ', array_map(
                static fn (array $t): string => $t['first_name'] . ' ' . $t['last_name'],
                array_slice($withoutGradeLevel, 0, 5)
            ))) ?><?= count($withoutGradeLevel) > 5 ? ' and ' . (count($withoutGradeLevel) - 5) . ' more' : '' ?>.
        </div>
    </div>
<?php endif; ?>

<form class="filter-bar" data-no-submit>
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>"
               placeholder="Employee number, name or email">
    </div>
    <div class="form-group">
        <label for="f-department">Department</label>
        <select id="f-department" name="department_id" data-filter-input>
            <option value="">All departments</option>
            <?php foreach ($departments as $department): ?>
                <option value="<?= e($department['department_id']) ?>" <?= (int) ($filters['department_id'] ?? 0) === (int) $department['department_id'] ? 'selected' : '' ?>>
                    <?= e($department['department_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-fingerprint">Fingerprint</label>
        <select id="f-fingerprint" name="fingerprint_status" data-filter-input>
            <option value="">Any</option>
            <option value="enrolled" <?= $filters['fingerprint_status'] === 'enrolled' ? 'selected' : '' ?>>Enrolled</option>
            <option value="not_enrolled" <?= $filters['fingerprint_status'] === 'not_enrolled' ? 'selected' : '' ?>>Not enrolled</option>
            <option value="disabled" <?= $filters['fingerprint_status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
        </select>
    </div>
    <div class="form-group">
        <label for="f-status">Status</label>
        <select id="f-status" name="status" data-filter-input>
            <option value="">All</option>
            <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="clear-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__body--flush">
        <?php if ($teachers === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'   => 'fa-chalkboard-user',
                'title'  => 'No teachers found',
                'text'   => 'Register a teacher to begin building schedules and taking attendance.',
                'action' => '<a class="btn btn-primary" href="/admin/teachers/create"><i class="fa-solid fa-plus"></i> Register Teacher</a>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Employee No.</th><th>Name</th><th>Department</th><th>Grade levels</th>
                        <th class="numeric">Subjects</th><th class="numeric">Sections</th><th class="numeric">Schedules</th>
                        <th>Fingerprint</th><th>Account</th><th style="width:80px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td class="mono text-sm"><?= e($teacher['employee_number']) ?></td>
                            <td>
                                <div class="cell-person">
                                    <?php if (!empty($teacher['photo_path'])): ?>
                                        <img class="avatar avatar--sm" src="/uploads/<?= e($teacher['photo_path']) ?>" alt="">
                                    <?php else: ?>
                                        <span class="avatar avatar--sm"><?= e(initials($teacher['first_name'] . ' ' . $teacher['last_name'])) ?></span>
                                    <?php endif; ?>
                                    <span class="cell-stack">
                                        <span class="cell-primary"><?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?></span>
                                        <span class="cell-muted"><?= e($teacher['email']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><span class="badge badge-primary"><?= e($teacher['department_code']) ?></span></td>
                            <td>
                                <?php if ($teacher['grade_levels']): ?>
                                    <span class="text-sm"><?= e($teacher['grade_levels']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-warning">None assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric"><?= e($teacher['subject_count']) ?></td>
                            <td class="numeric"><?= e($teacher['section_count']) ?></td>
                            <td class="numeric"><?= e($teacher['schedule_count']) ?></td>
                            <td>
                                <span class="badge <?= $teacher['fingerprint_status'] === 'enrolled' ? 'badge-success' : 'badge-warning' ?>">
                                    <?= e(ucfirst(str_replace('_', ' ', (string) $teacher['fingerprint_status']))) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($teacher['username'] === null): ?>
                                    <span class="badge badge-neutral">No account</span>
                                <?php else: ?>
                                    <span class="badge <?= e(status_badge($teacher['account_status'])) ?>"><?= e(ucfirst((string) $teacher['account_status'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn-ghost btn-sm" href="/admin/teachers/<?= e($teacher['teacher_id']) ?>" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a class="btn btn-ghost btn-sm" href="/admin/teachers/<?= e($teacher['teacher_id']) ?>/edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php $__view->include('partials.pagination', ['pagination' => $pagination, 'target' => 'teacherTable']); ?>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
function applyTeacherFilters() {
    const params = new URLSearchParams();
    ['f-search:search', 'f-department:department_id', 'f-fingerprint:fingerprint_status', 'f-status:status']
        .forEach((pair) => {
            const [id, name] = pair.split(':');
            const value = document.getElementById(id).value;
            if (value) params.set(name, value);
        });
    window.location.search = params.toString();
}

window.teacherTable = {
    load: (o) => { const p = new URLSearchParams(window.location.search); Object.entries(o).forEach(([k, v]) => p.set(k, v)); window.location.search = p.toString(); },
    page: (n) => window.teacherTable.load({ page: n }),
};

document.getElementById('f-search').addEventListener('input',
    window.LSIAMS.util.debounce(applyTeacherFilters, 500));

// The filter controls announce changes rather than calling this directly:
// an inline onchange= attribute cannot be authorised by a CSP nonce.
document.addEventListener('ls:filter-change', applyTeacherFilters);
</script>
<?php $__view->stop(); ?>
