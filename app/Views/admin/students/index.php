<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Students',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Students', null]],
    'actions'     => '<a class="btn btn-secondary" href="/admin/students/import"><i class="fa-solid fa-file-import"></i> Import</a>'
        . '<div class="dropdown"><button class="btn btn-secondary" data-dropdown="export-menu"><i class="fa-solid fa-download"></i> Export</button>'
        . '<div class="dropdown__menu" id="export-menu">'
        . '<a class="dropdown__item" href="#" data-export="csv">CSV</a>'
        . '<a class="dropdown__item" href="#" data-export="xlsx">Excel</a>'
        . '<a class="dropdown__item" href="#" data-export="pdf">PDF</a></div></div>'
        . '<a class="btn btn-primary" href="/admin/students/create"><i class="fa-solid fa-plus"></i> Add Student</a>',
]); ?>

<form class="filter-bar" id="student-filters" data-no-submit>
    <div class="form-group form-group--wide">
        <label for="filter-search">Search</label>
        <input type="search" id="filter-search" name="search" value="<?= e($filters['search']) ?>"
               placeholder="Student number, name, section or card UID">
    </div>

    <div class="form-group">
        <label for="filter-grade">Grade level</label>
        <select id="filter-grade" name="grade_level_id">
            <option value="">All grades</option>
            <?php foreach ($gradeLevels as $grade): ?>
                <option value="<?= e($grade['grade_level_id']) ?>"
                    <?= (int) ($filters['grade_level_id'] ?? 0) === (int) $grade['grade_level_id'] ? 'selected' : '' ?>>
                    <?= e($grade['grade_level_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="filter-section">Section</label>
        <select id="filter-section" name="section_id">
            <option value="">All sections</option>
            <?php foreach ($sections as $section): ?>
                <option value="<?= e($section['section_id']) ?>"
                        data-grade="<?= e($section['grade_level_id']) ?>"
                    <?= (int) ($filters['section_id'] ?? 0) === (int) $section['section_id'] ? 'selected' : '' ?>>
                    <?= e($section['section_code']) ?> — <?= e($section['section_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status">
            <option value="">All</option>
            <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'transferred' => 'Transferred', 'archived' => 'Archived'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="filter-rfid">RFID card</label>
        <select id="filter-rfid" name="rfid_status">
            <option value="">Any</option>
            <option value="assigned" <?= $filters['rfid_status'] === 'assigned' ? 'selected' : '' ?>>Has a card</option>
            <option value="missing"  <?= $filters['rfid_status'] === 'missing' ? 'selected' : '' ?>>No card</option>
        </select>
    </div>

    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" id="reset-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Student directory</h2>
        <div class="flex gap-1 items-center">
            <span class="text-sm text-muted hidden" id="selection-count"></span>
            <button class="btn btn-danger btn-sm hidden" id="bulk-archive">
                <i class="fa-solid fa-box-archive"></i> Archive selected
            </button>
        </div>
    </div>

    <div class="card__body--flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th style="width:34px"><input type="checkbox" id="select-all" aria-label="Select all"></th>
                    <th><a href="#" data-sort="student_number">Student No.</a></th>
                    <th><a href="#" data-sort="last_name">Name</a></th>
                    <th><a href="#" data-sort="grade">Grade</a></th>
                    <th><a href="#" data-sort="section">Section</a></th>
                    <th>RFID UID</th>
                    <th class="numeric">Attendance</th>
                    <th>Guardian</th>
                    <th><a href="#" data-sort="status">Status</a></th>
                    <th style="width:120px"></th>
                </tr>
                </thead>
                <tbody id="students-body">
                <?php if ($students === []): ?>
                    <tr>
                        <td colspan="10">
                            <?php $__view->include('partials.empty-state', [
                                'icon'   => 'fa-user-graduate',
                                'title'  => 'No students found',
                                'text'   => 'Adjust the filters, or register your first student to get started.',
                                'action' => '<a class="btn btn-primary" href="/admin/students/create">'
                                    . '<i class="fa-solid fa-plus"></i> Add Student</a>',
                            ]); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <?php $percentage = $student['attendance_percentage']; ?>
                        <tr>
                            <td><input type="checkbox" class="row-select" value="<?= e($student['student_id']) ?>" aria-label="Select student"></td>
                            <td class="mono text-sm"><?= e($student['student_number']) ?></td>
                            <td>
                                <div class="cell-person">
                                    <?php if (!empty($student['photo_path'])): ?>
                                        <img class="avatar avatar--sm" src="/uploads/<?= e($student['photo_path']) ?>" alt="">
                                    <?php else: ?>
                                        <span class="avatar avatar--sm"><?= e(initials($student['first_name'] . ' ' . $student['last_name'])) ?></span>
                                    <?php endif; ?>
                                    <span class="cell-stack">
                                        <span class="cell-primary"><?= e($student['last_name']) ?>, <?= e($student['first_name']) ?></span>
                                        <span class="cell-muted"><?= e($student['gender']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><?= e($student['grade_level_code']) ?></td>
                            <td><span class="badge badge-primary"><?= e($student['section_code']) ?></span></td>
                            <td>
                                <?php if (!empty($student['card_uid'])): ?>
                                    <span class="mono text-xs"><?= e($student['card_uid']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-warning">No card</span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric">
                                <?php if ($percentage === null): ?>
                                    <span class="text-subtle">—</span>
                                <?php else: ?>
                                    <span class="fw-600 <?= $percentage < 80 ? 'text-danger' : ($percentage < 90 ? 'text-warning' : 'text-success') ?>">
                                        <?= e($percentage) ?>%
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-stack">
                                <span class="text-sm"><?= e($student['guardian_name']) ?></span>
                                <span class="cell-muted"><?= e($student['guardian_contact']) ?></span>
                            </td>
                            <td><span class="badge <?= e(status_badge($student['status'])) ?>"><?= e(ucfirst($student['status'])) ?></span></td>
                            <td class="nowrap">
                                <a class="btn btn-ghost btn-sm" href="/admin/students/<?= e($student['student_id']) ?>" title="View">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                                <a class="btn btn-ghost btn-sm" href="/admin/students/<?= e($student['student_id']) ?>/edit" title="Edit">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                <?php if ($student['deleted_at'] === null): ?>
                                    <button class="btn btn-ghost btn-sm text-danger" title="Archive"
                                            data-archive="<?= e($student['student_id']) ?>"
                                            data-name="<?= e($student['last_name'] . ', ' . $student['first_name']) ?>">
                                        <i class="fa-solid fa-box-archive"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-ghost btn-sm" title="Restore" data-restore="<?= e($student['student_id']) ?>">
                                        <i class="fa-solid fa-rotate"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php $__view->include('partials.pagination', ['pagination' => $pagination, 'target' => 'studentTable']); ?>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;
    const form = document.getElementById('student-filters');

    function filters() {
        return {
            search:         document.getElementById('filter-search').value,
            grade_level_id: document.getElementById('filter-grade').value,
            section_id:     document.getElementById('filter-section').value,
            status:         document.getElementById('filter-status').value,
            rfid_status:    document.getElementById('filter-rfid').value,
        };
    }

    window.studentTable = LS.table({
        endpoint: '/admin/students',
        body: document.getElementById('students-body'),
        filters: filters,
        perPage: <?= e($pagination['per_page']) ?>,
        sort: '<?= e($sort) ?>',
        direction: '<?= e($direction) ?>',
        render: () => window.location.reload(),
    });

    // Section options are filtered client-side by the chosen grade, which is a
    // convenience — the server re-validates the pairing on every write.
    const gradeSelect   = document.getElementById('filter-grade');
    const sectionSelect = document.getElementById('filter-section');

    function filterSections() {
        const grade = gradeSelect.value;

        Array.from(sectionSelect.options).forEach((option) => {
            if (!option.value) return;
            const matches = !grade || option.dataset.grade === grade;
            option.hidden = !matches;
            if (!matches && option.selected) sectionSelect.value = '';
        });
    }

    gradeSelect.addEventListener('change', () => { filterSections(); apply(); });
    filterSections();

    const apply = LS.util.debounce(() => {
        const params = new URLSearchParams(
            Object.entries(filters()).filter(([, v]) => v !== '')
        );
        window.location.search = params.toString();
    }, 450);

    document.getElementById('filter-search').addEventListener('input', apply);
    ['filter-section', 'filter-status', 'filter-rfid'].forEach((id) => {
        document.getElementById(id).addEventListener('change', apply);
    });

    document.getElementById('reset-filters').addEventListener('click', () => {
        window.location.search = '';
    });

    document.querySelectorAll('[data-sort]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const url = new URL(window.location.href);
            const column = link.dataset.sort;
            const current = url.searchParams.get('sort');
            const direction = current === column && url.searchParams.get('direction') === 'ASC' ? 'DESC' : 'ASC';
            url.searchParams.set('sort', column);
            url.searchParams.set('direction', direction);
            window.location.href = url.toString();
        });
    });

    document.querySelectorAll('[data-export]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const params = new URLSearchParams(filters());
            params.set('format', link.dataset.export);
            window.location.href = '/admin/students/export?' + params.toString();
        });
    });

    /* ---- selection + bulk archive --------------------------------------- */

    const selectAll = document.getElementById('select-all');
    const countEl   = document.getElementById('selection-count');
    const bulkBtn   = document.getElementById('bulk-archive');

    function selected() {
        return Array.from(document.querySelectorAll('.row-select:checked')).map((box) => box.value);
    }

    function refreshSelection() {
        const count = selected().length;
        countEl.textContent = count + ' selected';
        countEl.classList.toggle('hidden', count === 0);
        bulkBtn.classList.toggle('hidden', count === 0);
    }

    selectAll.addEventListener('change', () => {
        document.querySelectorAll('.row-select').forEach((box) => { box.checked = selectAll.checked; });
        refreshSelection();
    });

    document.addEventListener('change', (event) => {
        if (event.target.classList.contains('row-select')) refreshSelection();
    });

    bulkBtn.addEventListener('click', async () => {
        const ids = selected();

        // Bulk archiving is destructive enough to demand re-authentication
        // (Part 2 business rules).
        const result = await LS.modal.confirm({
            title: 'Archive ' + ids.length + ' student(s)?',
            message: 'Archived students keep all their attendance history and can be restored later. '
                   + 'Their RFID cards are deactivated immediately.',
            confirmLabel: 'Archive',
            danger: true,
            requirePassword: true,
        });

        if (!result) return;

        try {
            const response = await LS.http.post('/admin/students/bulk-archive', {
                student_ids: ids,
                confirm_password: result.password,
                reason: 'Bulk archive from the student directory',
            });

            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            LS.toast.fromError(error);
        }
    });

    /* ---- row actions ---------------------------------------------------- */

    document.addEventListener('click', async (event) => {
        const archive = event.target.closest('[data-archive]');

        if (archive) {
            const result = await LS.modal.confirm({
                title: 'Archive student?',
                message: archive.dataset.name + ' will be archived. Their attendance history is retained '
                       + 'and their RFID card is deactivated immediately.',
                confirmLabel: 'Archive',
                danger: true,
            });

            if (!result) return;

            try {
                const response = await LS.http.post('/admin/students/' + archive.dataset.archive + '/archive', {});
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }

        const restore = event.target.closest('[data-restore]');

        if (restore) {
            try {
                const response = await LS.http.post('/admin/students/' + restore.dataset.restore + '/restore', {});
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });
})();
</script>
<?php $__view->stop(); ?>
