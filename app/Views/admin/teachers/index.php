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
            <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
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
                                <?php /* Archiving a teacher had a route, a service with every guard
                                        it needed and no way to reach it from anywhere in the
                                        interface. What people did instead was archive the user
                                        account, which is a different thing — it stops them signing
                                        in and leaves the staff record, the schedules and the
                                        timetable exactly where they were. */ ?>
                                <?php if ((string) $teacher['status'] === 'archived'): ?>
                                    <button class="btn btn-ghost btn-sm text-success"
                                            data-restore="<?= e($teacher['teacher_id']) ?>"
                                            data-name="<?= e($teacher['last_name'] . ', ' . $teacher['first_name']) ?>"
                                            title="Restore this teacher"><i class="fa-solid fa-rotate-left"></i></button>
                                <?php else: ?>
                                    <button class="btn btn-ghost btn-sm text-danger"
                                            data-archive="<?= e($teacher['teacher_id']) ?>"
                                            data-name="<?= e($teacher['last_name'] . ', ' . $teacher['first_name']) ?>"
                                            data-schedules="<?= e($teacher['schedule_count']) ?>"
                                            title="Archive this teacher"><i class="fa-solid fa-box-archive"></i></button>
                                <?php endif; ?>
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

document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-archive]');

    if (!button) return;

    const LS = window.LSIAMS;
    const schedules = Number(button.dataset.schedules || 0);

    const result = await LS.modal.confirm({
        title: 'Archive ' + button.dataset.name + '?',
        message: 'They stop appearing in enrolment lists, pickers and search, and their account is '
               + 'disabled so they cannot sign in.'
               + (schedules > 0
                    ? ' Their ' + schedules + ' active schedule(s) are archived too — attendance already '
                      + 'recorded against them is kept, permanently.'
                    : '')
               + ' A teacher with a session open right now cannot be archived; close it first.',
        confirmLabel: 'Archive teacher',
        requirePassword: true,
    });

    if (!result) return;

    try {
        const response = await LS.http.post('/admin/teachers/' + button.dataset.archive + '/archive', {
            confirm_password: result.password,
        });

        LS.toast.success(response.message);
        setTimeout(() => window.location.reload(), 700);
    } catch (error) {
        LS.toast.fromError(error);
    }
});

document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-restore]');

    if (!button) return;

    const LS = window.LSIAMS;

    const confirmed = await LS.modal.confirm({
        title: 'Restore ' + button.dataset.name + '?',
        message: 'They become assignable again and their account is re-enabled. Schedules archived '
               + 'when they left are NOT reinstated — somebody is likely teaching those periods now, '
               + 'so the timetable is rebuilt deliberately rather than silently.',
        confirmLabel: 'Restore teacher',
    });

    if (!confirmed) return;

    try {
        const response = await LS.http.post('/admin/teachers/' + button.dataset.restore + '/restore', {});
        LS.toast.success(response.message);
        setTimeout(() => window.location.reload(), 700);
    } catch (error) {
        LS.toast.fromError(error);
    }
});
</script>
<?php $__view->stop(); ?>
