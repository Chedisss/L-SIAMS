<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Sections',
    'subtitle'    => 'A section is a fixed cohort belonging to exactly one grade level. Every student belongs to exactly one active section.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Academic Setup', null], ['Sections', null]],
    'actions'     => '<button class="btn btn-primary" data-modal-open="section-modal"><i class="fa-solid fa-plus"></i> Add Section</button>',
]); ?>

<form class="filter-bar" data-no-submit>
    <div class="form-group">
        <label for="f-grade">Grade level</label>
        <select id="f-grade" name="grade_level_id" data-filter-input>
            <option value="">All grades</option>
            <?php foreach ($gradeLevels as $grade): ?>
                <option value="<?= e($grade['grade_level_id']) ?>" <?= (int) ($filters['grade_level_id'] ?? 0) === (int) $grade['grade_level_id'] ? 'selected' : '' ?>>
                    <?= e($grade['grade_level_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-strand">Strand</label>
        <input type="text" id="f-strand" name="strand" value="<?= e($filters['strand']) ?>" placeholder="STEM" data-filter-input>
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
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>" placeholder="Section code or name">
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="clear-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__body--flush">
        <?php if ($sections === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'   => 'fa-people-group',
                'title'  => 'No sections found',
                'text'   => 'Create a section so students can be enrolled and schedules can be built.',
                'action' => '<button class="btn btn-primary" data-modal-open="section-modal"><i class="fa-solid fa-plus"></i> Add Section</button>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Code</th><th>Name</th><th>Grade</th><th>Strand</th><th>Adviser</th>
                        <th style="width:150px">Enrolled</th><th class="numeric">Cards</th><th class="numeric">Attendance</th><th>Status</th><th style="width:110px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($sections as $section): ?>
                        <?php
                        $percent = (float) ($section['capacity_percent'] ?? 0);
                        $tone    = $percent >= 100 ? 'danger' : ($percent >= 90 ? 'warning' : '');
                        ?>
                        <tr>
                            <td><span class="badge badge-primary mono"><?= e($section['section_code']) ?></span></td>
                            <td class="cell-primary"><?= e($section['section_name']) ?></td>
                            <td><?= e($section['grade_level_code']) ?></td>
                            <td><?= $section['strand'] ? '<span class="badge badge-neutral">' . e($section['strand']) . '</span>' : '—' ?></td>
                            <td class="text-sm"><?= e($section['adviser_name'] ?? 'unassigned') ?></td>
                            <td>
                                <div class="flex justify-between text-xs mb-1">
                                    <span><?= e($section['enrolled_count']) ?> / <?= e($section['capacity']) ?></span>
                                    <span class="text-muted"><?= e($percent) ?>%</span>
                                </div>
                                <div class="progress">
                                    <div class="progress__bar <?= $tone ? 'progress__bar--' . e($tone) : '' ?>"
                                         style="width:<?= e(min(100, $percent)) ?>%"></div>
                                </div>
                            </td>
                            <td class="numeric text-sm">
                                <?= e($section['students_with_cards']) ?>/<?= e($section['active_students']) ?>
                            </td>
                            <td class="numeric">
                                <?php if ($section['attendance_percentage_30d'] === null): ?>
                                    <span class="text-subtle">—</span>
                                <?php else: ?>
                                    <span class="fw-600 <?= $section['attendance_percentage_30d'] < 80 ? 'text-danger' : 'text-success' ?>">
                                        <?= e($section['attendance_percentage_30d']) ?>%
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= e(status_badge($section['status'])) ?>"><?= e(ucfirst((string) $section['status'])) ?></span></td>
                            <td class="nowrap">
                                <a class="btn btn-ghost btn-sm" href="/admin/sections/<?= e($section['section_id']) ?>" title="View"><i class="fa-solid fa-eye"></i></a>
                                <button class="btn btn-ghost btn-sm" title="Edit"
                                        data-edit='<?= json_attr([
                                            'section_id'   => (int) $section['section_id'],
                                            'section_code' => $section['section_code'],
                                            'section_name' => $section['section_name'],
                                            'grade_level_id' => (int) $section['grade_level_id'],
                                            'adviser_id'   => $section['adviser_id'],
                                            'strand'       => $section['strand'],
                                            'capacity'     => (int) $section['capacity'],
                                            'status'       => $section['status'],
                                        ]) ?>'><i class="fa-solid fa-pen"></i></button>
                                <?php /* An archived row is reachable now that the Archived filter
                                        returns something, so it needs the way back. */ ?>
                                <?php if (($section['deleted_at'] ?? null) !== null): ?>
                                    <button class="btn btn-ghost btn-sm" data-restore="<?= e($section['section_id']) ?>"
                                            data-name="<?= e($section['section_code']) ?>" title="Restore">
                                        <i class="fa-solid fa-rotate-left"></i> Restore
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-ghost btn-sm text-danger" data-archive="<?= e($section['section_id']) ?>"
                                            data-name="<?= e($section['section_code']) ?>"
                                            data-enrolled="<?= e($section['active_students']) ?>" title="Archive">
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

<div class="modal-backdrop" id="section-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title" id="section-modal-title">Add Section</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form id="section-form">
            <div class="modal__body">
                <input type="hidden" name="section_id" id="s-id">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="s-code" class="required">Section code</label>
                        <input type="text" id="s-code" name="section_code" required maxlength="30"
                               placeholder="G12-STEM-A" data-uppercase style="text-transform:uppercase;font-family:var(--mono)">
                    </div>
                    <div class="form-group">
                        <label for="s-name" class="required">Section name</label>
                        <input type="text" id="s-name" name="section_name" required maxlength="80" placeholder="St. Ignatius">
                    </div>
                    <div class="form-group">
                        <label for="s-grade" class="required">Grade level</label>
                        <select id="s-grade" name="grade_level_id" required>
                            <option value="">Select…</option>
                            <?php foreach ($gradeLevels as $grade): ?>
                                <option value="<?= e($grade['grade_level_id']) ?>"
                                        data-level="<?= e($grade['numeric_level']) ?>">
                                    <?= e($grade['grade_level_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-help">Cannot be changed once students are enrolled.</span>
                    </div>

                    <!--
                        Strand belongs to Senior High only. Grades 7-10 follow one
                        curriculum with no track, so asking for a strand there invites
                        a value that means nothing and then shows up in every report.
                        Hidden and cleared below whenever the chosen grade is under 11.
                    -->
                    <div class="form-group" id="s-strand-group" hidden>
                        <label for="s-strand">Strand / track</label>
                        <select id="s-strand" name="strand">
                            <option value="">Select a strand…</option>
                            <optgroup label="Academic">
                                <option value="STEM">STEM — Science, Technology, Engineering and Mathematics</option>
                                <option value="ABM">ABM — Accountancy, Business and Management</option>
                                <option value="HUMSS">HUMSS — Humanities and Social Sciences</option>
                                <option value="GAS">GAS — General Academic Strand</option>
                            </optgroup>
                            <optgroup label="Technical-Vocational-Livelihood">
                                <option value="TVL-ICT">TVL-ICT — Information and Communications Technology</option>
                                <option value="TVL-HE">TVL-HE — Home Economics</option>
                                <option value="TVL-IA">TVL-IA — Industrial Arts</option>
                                <option value="TVL-AFA">TVL-AFA — Agri-Fishery Arts</option>
                            </optgroup>
                            <optgroup label="Other tracks">
                                <option value="SPORTS">Sports Track</option>
                                <option value="ARTS">Arts and Design Track</option>
                            </optgroup>
                        </select>
                        <span class="field-help">Senior High only. Grades 7–10 have no strand.</span>
                    </div>
                    <div class="form-group">
                        <label for="s-adviser">Adviser</label>
                        <select id="s-adviser" name="adviser_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= e($teacher['teacher_id']) ?>">
                                    <?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?>
                                    <?= $teacher['grade_levels'] ? '(' . e($teacher['grade_levels']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-help">Must be assigned to this section's grade level.</span>
                    </div>
                    <div class="form-group">
                        <label for="s-capacity" class="required">Capacity</label>
                        <input type="number" id="s-capacity" name="capacity" required min="1" max="200" value="45">
                    </div>
                    <div class="form-group">
                        <label for="s-classroom">Default classroom</label>
                        <select id="s-classroom" name="default_classroom_id">
                            <option value="">None</option>
                            <?php foreach ($classrooms as $room): ?>
                                <option value="<?= e($room['classroom_id']) ?>">Room <?= e($room['room_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="s-status">Status</label>
                        <select id="s-status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save section</button>
            </div>
        </form>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
function applyFilters() {
    const params = new URLSearchParams();
    ['f-grade:grade_level_id', 'f-strand:strand', 'f-status:status', 'f-search:search'].forEach((pair) => {
        const [id, name] = pair.split(':');
        const value = document.getElementById(id).value;
        if (value) params.set(name, value);
    });
    window.location.search = params.toString();
}

(function () {
    const LS = window.LSIAMS;
    const form = document.getElementById('section-form');

    document.getElementById('f-search').addEventListener('input', LS.util.debounce(applyFilters, 500));

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        const data = LS.util.formData(form);
        const id = data.section_id;
        delete data.section_id;

        try {
            const response = id
                ? await LS.http.put('/admin/sections/' + id, data)
                : await LS.http.post('/admin/sections', data);
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    /* ---- strand is Senior High only -------------------------------------- */
    const gradeSelect  = document.getElementById('s-grade');
    const strandGroup  = document.getElementById('s-strand-group');
    const strandSelect = document.getElementById('s-strand');

    function syncStrand() {
        const option = gradeSelect.selectedOptions[0];
        const level  = option ? parseInt(option.dataset.level, 10) : NaN;
        const senior = level >= 11;

        strandGroup.hidden = !senior;

        // Cleared when hidden so a strand chosen for Grade 11 cannot survive a
        // switch to Grade 8 and be saved invisibly.
        if (!senior) strandSelect.value = '';
    }

    gradeSelect.addEventListener('change', syncStrand);
    syncStrand();

    document.addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-edit]');
        if (edit) {
            const data = JSON.parse(edit.dataset.edit);
            document.getElementById('section-modal-title').textContent = 'Edit Section';
            Object.entries(data).forEach(([key, value]) => {
                const field = form.elements[key];
                if (field) field.value = value === null ? '' : value;
            });
            document.getElementById('s-code').readOnly = true;

            // After the values are in, so visibility matches the grade level
            // this section actually has.
            syncStrand();
            if (data.strand) strandSelect.value = data.strand;

            LS.modal.open('section-modal');
        }

        const restore = event.target.closest('[data-restore]');

        /* Confirmed, unlike the department restore, because this one has a
           consequence worth stating before it happens rather than only in the
           toast afterwards: the schedules do not come back with it. */
        if (restore) {
            const result = await LS.modal.confirm({
                title: 'Restore section?',
                message: restore.dataset.name + ' will come back inactive and with no timetable. '
                    + 'Its schedules stay archived — those periods may since have been given to '
                    + 'another section, so the timetable has to be rebuilt deliberately.',
                confirmLabel: 'Restore',
            });

            if (result) {
                try {
                    const response = await LS.http.post(
                        '/admin/sections/' + restore.dataset.restore + '/restore', {});
                    LS.toast.success(response.message);
                    window.setTimeout(() => window.location.reload(), 1500);
                } catch (error) {
                    LS.toast.error(error.message || 'That section could not be restored.');
                }
            }
        }

        const archive = event.target.closest('[data-archive]');
        if (archive) {
            if (parseInt(archive.dataset.enrolled, 10) > 0) {
                LS.toast.warning('Transfer or archive the ' + archive.dataset.enrolled
                    + ' remaining student(s) before archiving this section.');
                return;
            }

            const result = await LS.modal.confirm({
                title: 'Archive section?',
                message: archive.dataset.name + ' will be archived. All attendance recorded against it '
                       + 'is retained permanently and stays queryable.',
                confirmLabel: 'Archive',
                danger: true,
            });

            if (!result) return;

            try {
                const response = await LS.http.post('/admin/sections/' + archive.dataset.archive + '/archive', {});
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });

    document.getElementById('section-modal').addEventListener('modal:close', () => {
        setTimeout(() => {
            form.reset();
            document.getElementById('s-id').value = '';
            document.getElementById('s-code').readOnly = false;
            document.getElementById('section-modal-title').textContent = 'Add Section';
            syncStrand();
        }, 200);
    });
})();

// The filter controls announce changes rather than calling this directly:
// an inline onchange= attribute cannot be authorised by a CSP nonce.
document.addEventListener('ls:filter-change', applyFilters);
</script>
<?php $__view->stop(); ?>
