<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php
$archived      = $archived      ?? false;
$archivedCount = $archivedCount ?? 0;
?>

<?php $__view->include('partials.page-header', [
    'title'       => $archived ? 'Archived Subjects' : 'Subjects',
    'subtitle'    => $archived
        ? 'Archived subjects are hidden from every dropdown and cannot be scheduled. Attendance already recorded against them is untouched and still resolves.'
        : 'Every subject belongs to exactly one department — this is what makes the cross-department assignment rule enforceable.',
    'breadcrumbs' => $archived
        ? [['Dashboard', '/admin'], ['Academic Setup', null], ['Subjects', '/admin/subjects'], ['Archived', null]]
        : [['Dashboard', '/admin'], ['Academic Setup', null], ['Subjects', null]],
    'actions'     => $archived
        ? '<a class="btn btn-secondary" href="/admin/subjects"><i class="fa-solid fa-arrow-left"></i> Back to Subjects</a>'
        : '<button class="btn btn-primary" data-modal-open="subject-modal"><i class="fa-solid fa-plus"></i> Add Subject</button>',
]); ?>

<?php if (!$archived && $archivedCount > 0): ?>
    <div class="mb-2">
        <a class="btn btn-ghost btn-sm" href="/admin/subjects?view=archived">
            <i class="fa-solid fa-box-archive"></i>
            View <?= e($archivedCount) ?> archived subject<?= $archivedCount === 1 ? '' : 's' ?>
        </a>
    </div>
<?php endif; ?>

<form class="filter-bar" data-no-submit>
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
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>" placeholder="Subject code or name">
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="clear-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__body--flush">
        <?php if ($subjects === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'   => 'fa-book',
                'title'  => 'No subjects found',
                'text'   => 'Add subjects before building schedules — a schedule needs a subject offered to the section\'s grade level.',
                'action' => '<button class="btn btn-primary" data-modal-open="subject-modal"><i class="fa-solid fa-plus"></i> Add Subject</button>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Offered to</th>
                        <th class="numeric">Teachers</th><th>Status</th><th style="width:88px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($subjects as $subject): ?>
                        <tr>
                            <td><span class="badge badge-primary mono"><?= e($subject['subject_code']) ?></span></td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($subject['subject_name']) ?></span>
                                <?php if ($subject['description']): ?>
                                    <span class="cell-muted"><?= e($subject['description']) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php /* "Values Education Department" in a column headed Department
                                    spends eighteen characters restating the heading. */ ?>
                            <td class="text-sm"><?= e(preg_replace('/\s+Department$/', '', (string) $subject['department_name'])) ?></td>
                            <td class="text-sm nowrap">
                                <?php $span = grade_span($subject['grade_levels'] ?? null, $subject['grade_level_numbers'] ?? null); ?>
                                <?= $span === '' ? '<span class="text-warning">none</span>' : e($span) ?>
                            </td>
                            <td class="numeric">
                                <?php /* The count was the whole answer, so "3" meant going to
                                        Teachers, filtering, and reading down a list to find out
                                        who. It is now the way to ask. */ ?>
                                <?php if ((int) $subject['teacher_count'] > 0): ?>
                                    <button class="btn-link" data-teachers="<?= e($subject['subject_id']) ?>"
                                            data-name="<?= e($subject['subject_code']) ?> · <?= e($subject['subject_name']) ?>"
                                            title="See who teaches this">
                                        <?= e($subject['teacher_count']) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= e(status_badge($subject['status'])) ?>"><?= e(ucfirst((string) $subject['status'])) ?></span></td>
                            <td class="nowrap">
                                <button class="btn btn-ghost btn-sm" title="Edit"
                                        data-edit='<?= json_attr([
                                            'subject_id'    => (int) $subject['subject_id'],
                                            'subject_code'  => $subject['subject_code'],
                                            'subject_name'  => $subject['subject_name'],
                                            'description'   => $subject['description'],
                                            'department_id' => (int) $subject['department_id'],
                                            'status'        => $subject['status'],
                                        ]) ?>'><i class="fa-solid fa-pen"></i></button>
                                <?php if ($archived): ?>
                                    <button class="btn btn-ghost btn-sm" data-restore="<?= e($subject['subject_id']) ?>"
                                            data-name="<?= e($subject['subject_code']) ?>" title="Restore">
                                        <i class="fa-solid fa-rotate-left"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-ghost btn-sm text-danger" data-archive="<?= e($subject['subject_id']) ?>"
                                            data-name="<?= e($subject['subject_code']) ?>" title="Archive">
                                        <i class="fa-solid fa-box-archive"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-backdrop" id="teachers-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title" id="teachers-modal-title">Teachers</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body" id="teachers-modal-body"></div>
        <div class="modal__footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Close</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="subject-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title" id="subject-modal-title">Add Subject</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form id="subject-form">
            <div class="modal__body">
                <input type="hidden" name="subject_id" id="sub-id">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="sub-code" class="required">Subject code</label>
                        <input type="text" id="sub-code" name="subject_code" required maxlength="30"
                               placeholder="ENG12" data-uppercase style="text-transform:uppercase;font-family:var(--mono)">
                        <span class="field-help">Can be changed later. Nothing is keyed on it, so
                            correcting a code renames it everywhere it appears.</span>
                    </div>
                    <div class="form-group">
                        <label for="sub-department" class="required">Department</label>
                        <select id="sub-department" name="department_id" required>
                            <option value="">Select…</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e($department['department_id']) ?>"><?= e($department['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-help">Only teachers in this department may be assigned this subject.</span>
                    </div>
                    <div class="form-group form-group--full">
                        <label for="sub-name" class="required">Subject name</label>
                        <input type="text" id="sub-name" name="subject_name" required maxlength="150"
                               placeholder="English for Academic and Professional Purposes">
                    </div>
                    <div class="form-group form-group--full">
                        <label for="sub-description">Description</label>
                        <textarea id="sub-description" name="description" maxlength="500"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="sub-status">Status</label>
                        <select id="sub-status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="required">Offered to grade levels</label>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.3rem">
                            <?php foreach ($gradeLevels as $grade): ?>
                                <label class="checkbox">
                                    <input type="checkbox" name="grade_level_ids[]" value="<?= e($grade['grade_level_id']) ?>">
                                    <span><?= e($grade['grade_level_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="field-help">A schedule is refused if the subject is not offered to the section's grade level.</span>
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save subject</button>
            </div>
        </form>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
function applySubjectFilters() {
    const params = new URLSearchParams();
    const department = document.getElementById('f-department').value;
    const search = document.getElementById('f-search').value;
    if (department) params.set('department_id', department);
    if (search) params.set('search', search);
    window.location.search = params.toString();
}

(function () {
    const LS = window.LSIAMS;
    const form = document.getElementById('subject-form');

    document.getElementById('f-search').addEventListener('input', LS.util.debounce(applySubjectFilters, 500));

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        const data = LS.util.formData(form);
        const id = data.subject_id;
        delete data.subject_id;

        if (!data.grade_level_ids || data.grade_level_ids.length === 0) {
            LS.toast.warning('Select at least one grade level this subject is offered to.');
            LS.util.setBusy(button, false);
            return;
        }

        try {
            const response = id
                ? await LS.http.put('/admin/subjects/' + id, data)
                : await LS.http.post('/admin/subjects', data);
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    /* ---- archive and restore ------------------------------------------------ */

    document.addEventListener('click', async (event) => {
        const teachers = event.target.closest('[data-teachers]');

        if (teachers) {
            document.getElementById('teachers-modal-title').textContent = teachers.dataset.name;
            const body = document.getElementById('teachers-modal-body');
            body.innerHTML = '<div class="skeleton skeleton--row"></div>';
            LS.modal.open('teachers-modal');

            try {
                const response = await LS.http.get('/admin/subjects/' + teachers.dataset.teachers + '/teachers');
                const rows = response.data.teachers || [];

                if (rows.length === 0) {
                    body.innerHTML = '<p class="text-muted">Nobody is assigned to teach this subject yet.</p>';
                    return;
                }

                body.innerHTML =
                    '<table class="data"><thead><tr>'
                    + '<th>Teacher</th><th>Employee no.</th><th>Department</th>'
                    + '<th class="numeric">Classes</th><th>Status</th>'
                    + '</tr></thead><tbody>'
                    + rows.map((t) =>
                        '<tr><td class="cell-stack"><span class="cell-primary">'
                        + LS.util.escape(t.last_name + ', ' + t.first_name) + '</span>'
                        /* The interesting half: qualified through their own department is
                           unremarkable, qualified across one was somebody's decision. */
                        + (Number(t.is_exception)
                            ? '<span class="cell-muted">Cross-department exception</span>' : '')
                        + '</td>'
                        + '<td class="mono text-sm">' + LS.util.escape(t.employee_number || '—') + '</td>'
                        + '<td class="text-sm">' + LS.util.escape(t.department_name || '—') + '</td>'
                        + '<td class="numeric">' + Number(t.schedule_count || 0) + '</td>'
                        + '<td><span class="badge badge-' + (t.status === 'active' ? 'success' : 'neutral')
                        + '">' + LS.util.escape(t.status) + '</span></td></tr>').join('')
                    + '</tbody></table>';
            } catch (error) {
                body.innerHTML = '<div class="alert alert-danger">Could not load the teachers for this subject.</div>';
            }

            return;
        }

        const archive = event.target.closest('[data-archive]');

        if (archive) {
            /* The impact is fetched and shown before the decision, the same way
               a department archive works. Active schedules are refused by the
               server outright rather than confirmed: ScheduleService does not
               check a subject's status, so an archived subject on a live
               schedule would go on opening classes every day. */
            try {
                const detail = await LS.http.get('/admin/subjects/' + archive.dataset.archive + '/impact');
                const impact = detail.data.impact;

                let message = archive.dataset.name + ' will be archived and hidden from every dropdown.';

                if (impact.teachers.length > 0) {
                    message += '\n\nIt is removed from ' + impact.teachers.length
                        + ' teacher(s) qualified to teach it.';
                }

                if (impact.attendance_records > 0) {
                    message += '\n\n' + impact.attendance_records
                        + ' attendance record(s) reference it. Those are untouched and keep resolving —'
                        + ' archiving a subject never alters attendance.';
                }

                const result = await LS.modal.confirm({
                    title: 'Archive subject?',
                    message: message,
                    confirmLabel: 'Archive',
                    danger: true,
                });

                if (!result) return;

                const body = new FormData();
                body.append('_csrf', LS.config.csrfToken);
                body.append('confirmed', '1');

                const response = await LS.http.post(
                    '/admin/subjects/' + archive.dataset.archive + '/archive', body);
                LS.toast.success(response.message);
                window.setTimeout(() => window.location.reload(), 1200);
            } catch (error) {
                LS.toast.fromError(error);
            }

            return;
        }

        const restore = event.target.closest('[data-restore]');

        if (restore) {
            try {
                const body = new FormData();
                body.append('_csrf', LS.config.csrfToken);

                const response = await LS.http.post(
                    '/admin/subjects/' + restore.dataset.restore + '/restore', body);
                LS.toast.success(response.message);
                window.setTimeout(() => window.location.reload(), 1200);
            } catch (error) {
                LS.toast.fromError(error);
            }

            return;
        }

        const edit = event.target.closest('[data-edit]');
        if (!edit) return;

        const data = JSON.parse(edit.dataset.edit);
        document.getElementById('subject-modal-title').textContent = 'Edit Subject';

        Object.entries(data).forEach(([key, value]) => {
            const field = form.elements[key];
            if (field && field.type !== 'checkbox') field.value = value === null ? '' : value;
        });


        // Load the grade levels this subject is currently offered to.
        try {
            const response = await LS.http.get('/admin/subjects/' + data.subject_id);
            const ids = response.data.grade_level_ids || [];

            form.querySelectorAll('[name="grade_level_ids[]"]').forEach((box) => {
                box.checked = ids.includes(parseInt(box.value, 10));
            });

        } catch (error) { /* leave unchecked */ }

        LS.modal.open('subject-modal');
    });

    document.getElementById('subject-modal').addEventListener('modal:close', () => {
        setTimeout(() => {
            form.reset();
            document.getElementById('sub-id').value = '';
            document.getElementById('subject-modal-title').textContent = 'Add Subject';
        }, 200);
    });
})();

// The filter controls announce changes rather than calling this directly:
// an inline onchange= attribute cannot be authorised by a CSP nonce.
document.addEventListener('ls:filter-change', applySubjectFilters);
</script>
<?php $__view->stop(); ?>
