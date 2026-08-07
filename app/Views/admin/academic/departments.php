<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Departments',
    'subtitle'    => 'Departments group subjects and the teachers qualified to teach them. A teacher may only be assigned subjects from their own department.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Academic Setup', null], ['Departments', null]],
    'actions'     => '<button class="btn btn-primary" data-modal-open="department-modal"><i class="fa-solid fa-plus"></i> Add Department</button>',
]); ?>

<div class="card">
    <div class="card__body--flush">
        <?php if ($departments === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'   => 'fa-building-columns',
                'title'  => 'No departments configured yet',
                'text'   => 'Add a department to begin assigning subjects.',
                'action' => '<button class="btn btn-primary" data-modal-open="department-modal"><i class="fa-solid fa-plus"></i> Add Department</button>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Code</th><th>Name</th><th>Head</th><th class="numeric">Subjects</th>
                        <th class="numeric">Teachers</th><th class="numeric">Schedules</th><th>Status</th><th style="width:150px"></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($departments as $department): ?>
                        <tr>
                            <td><span class="badge badge-primary mono"><?= e($department['department_code']) ?></span></td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($department['department_name']) ?></span>
                                <?php if ($department['description']): ?>
                                    <span class="cell-muted"><?= e($department['description']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm"><?= e($department['head_teacher_name'] ?? '—') ?></td>
                            <td class="numeric"><?= e($department['subject_count']) ?></td>
                            <td class="numeric"><?= e($department['teacher_count']) ?></td>
                            <td class="numeric"><?= e($department['schedule_count']) ?></td>
                            <td><span class="badge <?= e(status_badge($department['status'])) ?>"><?= e(ucfirst((string) $department['status'])) ?></span></td>
                            <td class="nowrap">
                                <button class="btn btn-ghost btn-sm" data-view="<?= e($department['department_id']) ?>" title="View"><i class="fa-solid fa-eye"></i></button>
                                <button class="btn btn-ghost btn-sm" title="Edit"
                                        data-edit='<?= json_attr([
                                            'department_id'   => (int) $department['department_id'],
                                            'department_code' => $department['department_code'],
                                            'department_name' => $department['department_name'],
                                            'description'     => $department['description'],
                                            'head_teacher_id' => $department['head_teacher_id'],
                                            'status'          => $department['status'],
                                        ]) ?>'><i class="fa-solid fa-pen"></i></button>
                                <button class="btn btn-ghost btn-sm text-danger" data-archive="<?= e($department['department_id']) ?>"
                                        data-name="<?= e($department['department_name']) ?>" title="Archive">
                                    <i class="fa-solid fa-box-archive"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-backdrop" id="department-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title" id="department-modal-title">Add Department</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <form id="department-form">
            <div class="modal__body">
                <input type="hidden" name="department_id" id="d-id">

                <div class="form-group">
                    <label for="d-code" class="required">Department code</label>
                    <input type="text" id="d-code" name="department_code" required maxlength="20"
                           placeholder="ENG" style="text-transform:uppercase;font-family:var(--mono)">
                    <span class="field-help">Immutable once created — every export and report keys on it.</span>
                </div>

                <div class="form-group">
                    <label for="d-name" class="required">Department name</label>
                    <input type="text" id="d-name" name="department_name" required maxlength="120"
                           placeholder="English Department">
                </div>

                <div class="form-group">
                    <label for="d-description">Description</label>
                    <textarea id="d-description" name="description" maxlength="500"></textarea>
                </div>

                <div class="form-group">
                    <label for="d-head">Department head</label>
                    <select id="d-head" name="head_teacher_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= e($teacher['teacher_id']) ?>">
                                <?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="d-status">Status</label>
                    <select id="d-status" name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save department</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="department-detail-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Department detail</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body" id="department-detail"></div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS   = window.LSIAMS;
    const form = document.getElementById('department-form');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        const data = LS.util.formData(form);
        const id   = data.department_id;
        delete data.department_id;

        try {
            const response = id
                ? await LS.http.put('/admin/departments/' + id, data)
                : await LS.http.post('/admin/departments', data);

            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    document.addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-edit]');

        if (edit) {
            const data = JSON.parse(edit.dataset.edit);
            document.getElementById('department-modal-title').textContent = 'Edit Department';
            document.getElementById('d-id').value = data.department_id;
            document.getElementById('d-code').value = data.department_code;
            document.getElementById('d-code').readOnly = true;  // immutable
            document.getElementById('d-name').value = data.department_name;
            document.getElementById('d-description').value = data.description || '';
            document.getElementById('d-head').value = data.head_teacher_id || '';
            document.getElementById('d-status').value = data.status;
            LS.modal.open('department-modal');
        }

        const view = event.target.closest('[data-view]');

        if (view) {
            LS.modal.open('department-detail-modal');
            const body = document.getElementById('department-detail');
            body.innerHTML = '<div class="skeleton skeleton--row"></div>';

            try {
                const response = await LS.http.get('/admin/departments/' + view.dataset.view);
                const data = response.data;

                let html = '<div class="grid grid--3 mb-3">'
                    + tile('Attendance (30d)', (data.summary.attendance_percentage || 0) + '%')
                    + tile('Records (30d)', data.summary.records_30d)
                    + tile('Active schedules', data.impact.schedules.length)
                    + '</div>';

                html += '<h4>Subjects</h4>';
                html += data.subjects.length === 0
                    ? '<p class="text-muted text-sm">No subjects in this department yet.</p>'
                    : '<table class="data"><thead><tr><th>Code</th><th>Name</th><th>Grade levels</th><th class="numeric">Teachers</th></tr></thead><tbody>'
                      + data.subjects.map((s) =>
                          '<tr><td class="mono text-sm">' + LS.util.escape(s.subject_code) + '</td>'
                          + '<td>' + LS.util.escape(s.subject_name) + '</td>'
                          + '<td class="text-sm">' + LS.util.escape(s.grade_levels || '—') + '</td>'
                          + '<td class="numeric">' + s.teacher_count + '</td></tr>').join('')
                      + '</tbody></table>';

                html += '<h4 class="mt-3">Teachers</h4>';
                html += data.impact.teachers.length === 0
                    ? '<p class="text-muted text-sm">No teachers assigned.</p>'
                    : '<ul class="text-sm">' + data.impact.teachers.map((t) =>
                        '<li>' + LS.util.escape(t.last_name + ', ' + t.first_name)
                        + ' <span class="text-muted">(' + LS.util.escape(t.employee_number) + ')</span></li>').join('')
                      + '</ul>';

                body.innerHTML = html;
            } catch (error) {
                body.innerHTML = '<div class="alert alert-danger">Could not load this department.</div>';
            }
        }

        const archive = event.target.closest('[data-archive]');

        if (archive) {
            // The server returns the full impact list first; the administrator
            // confirms only after seeing what would break (Part 13.2).
            try {
                const detail = await LS.http.get('/admin/departments/' + archive.dataset.archive);
                const impact = detail.data.impact;

                const message = archive.dataset.name + ' — this affects '
                    + impact.teachers.length + ' teacher(s), '
                    + impact.subjects.length + ' subject(s) and '
                    + impact.schedules.length + ' active schedule(s).';

                const result = await LS.modal.confirm({
                    title: 'Archive department?',
                    message: message,
                    confirmLabel: 'Archive',
                    danger: true,
                });

                if (!result) return;

                const response = await LS.http.post('/admin/departments/' + archive.dataset.archive + '/archive',
                    { confirmed: true });

                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });

    document.getElementById('department-modal').addEventListener('modal:close', () => {
        setTimeout(() => {
            form.reset();
            document.getElementById('d-id').value = '';
            document.getElementById('d-code').readOnly = false;
            document.getElementById('department-modal-title').textContent = 'Add Department';
        }, 200);
    });

    function tile(label, value) {
        return '<div style="padding:.6rem .8rem;background:var(--surface-alt);border-radius:var(--radius-sm)">'
            + '<div class="text-xs text-muted">' + label + '</div>'
            + '<div class="fw-600" style="font-size:20px">' + value + '</div></div>';
    }
})();
</script>
<?php $__view->stop(); ?>
