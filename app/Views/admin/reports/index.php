<?php
/** @var App\Core\View $__view */

use App\Core\Auth;

$__view->extend('layouts.app');
$__view->start('content');

$isTeacher = Auth::isTeacher();
$base      = $isTeacher ? '/teacher' : '/admin';

/**
 * Which filter controls each report type actually uses. The server ignores
 * anything irrelevant, but hiding the unused fields stops an administrator
 * from believing a filter applied when it did not.
 */
$fieldsByType = [
    'daily'               => ['date', 'section_id', 'grade_level_id', 'subject_id', 'teacher_id', 'final_status'],
    'weekly'              => ['range', 'section_id', 'grade_level_id', 'subject_id', 'teacher_id'],
    'monthly'             => ['range', 'section_id', 'grade_level_id', 'subject_id', 'teacher_id'],
    'student'             => ['range', 'student_id'],
    'teacher'             => ['range', 'teacher_id'],
    'subject'             => ['range', 'subject_id', 'grade_level_id'],
    'section_daily'       => ['date', 'section_id'],
    'section_summary'     => ['range', 'section_id'],
    'section_comparison'  => ['range', 'grade_level_id'],
    'adviser'             => ['range', 'teacher_id'],
    'section_roster_rfid' => ['section_id'],
    'chronic_absence'     => ['range', 'grade_level_id', 'threshold'],
    'device_uptime'       => ['days', 'classroom_id'],
    'audit'               => ['range'],
    'security'            => ['range'],
];
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Reports',
    'subtitle'    => 'Preview a report on screen, then export it as PDF, Excel or CSV. Exports carry every row; the preview is capped so the browser stays responsive.',
    'breadcrumbs' => [['Dashboard', $base], ['Reports', null]],
]); ?>

<div class="grid" style="grid-template-columns:340px 1fr;align-items:start">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Build a report</h2></div>

        <div class="card__body">
            <form id="report-form">
                <div class="form-group">
                    <label for="r-type" class="required">Report type</label>
                    <select id="r-type" name="type" required>
                        <?php foreach ($types as $value => $label): ?>
                            <?php if ($isTeacher && in_array($value, ['audit', 'security', 'device_uptime'], true)) { continue; } ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="date">
                    <label for="r-date">Date</label>
                    <input type="date" id="r-date" name="date" value="<?= e($defaultTo) ?>">
                </div>

                <div class="form-grid" data-field="range">
                    <div class="form-group">
                        <label for="r-from">From</label>
                        <input type="date" id="r-from" name="date_from" value="<?= e($defaultFrom) ?>">
                    </div>
                    <div class="form-group">
                        <label for="r-to">To</label>
                        <input type="date" id="r-to" name="date_to" value="<?= e($defaultTo) ?>">
                    </div>
                </div>

                <div class="form-group" data-field="grade_level_id">
                    <label for="r-grade">Grade level</label>
                    <select id="r-grade" name="grade_level_id">
                        <option value="">All grade levels</option>
                        <?php foreach ($gradeLevels as $grade): ?>
                            <option value="<?= e($grade['grade_level_id']) ?>"><?= e($grade['grade_level_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="section_id">
                    <label for="r-section">Section</label>
                    <select id="r-section" name="section_id">
                        <option value="">All sections</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= e($section['section_id']) ?>" data-grade="<?= e($section['grade_level_id']) ?>">
                                <?= e($section['section_code']) ?> — <?= e($section['section_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="subject_id">
                    <label for="r-subject">Subject</label>
                    <select id="r-subject" name="subject_id">
                        <option value="">All subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= e($subject['subject_id']) ?>">
                                <?= e($subject['subject_code']) ?> — <?= e($subject['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="teacher_id">
                    <label for="r-teacher">Teacher</label>
                    <?php if ($isTeacher): ?>
                        <input type="text" value="Your own records only" readonly>
                        <span class="field-help">A teacher report is always scoped to you, whatever the form says.</span>
                    <?php else: ?>
                        <select id="r-teacher" name="teacher_id">
                            <option value="">All teachers</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= e($teacher['teacher_id']) ?>">
                                    <?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="form-group" data-field="classroom_id">
                    <label for="r-classroom">Classroom</label>
                    <select id="r-classroom" name="classroom_id">
                        <option value="">All classrooms</option>
                        <?php foreach ($classrooms as $classroom): ?>
                            <option value="<?= e($classroom['classroom_id']) ?>">
                                <?= e($classroom['room_number']) ?><?php if (!empty($classroom['building'])): ?> — <?= e($classroom['building']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="student_id">
                    <label for="r-student-search">Student</label>
                    <input type="search" id="r-student-search" placeholder="Search by name or student number"
                           autocomplete="off">
                    <input type="hidden" name="student_id" id="r-student">
                    <div class="typeahead" id="r-student-results" hidden></div>
                    <span class="field-help" id="r-student-chosen">No student selected.</span>
                </div>

                <div class="form-group" data-field="final_status">
                    <label for="r-status">Final status</label>
                    <select id="r-status" name="final_status">
                        <option value="">Any status</option>
                        <?php foreach (['Present', 'Late', 'Left Early', 'Incomplete', 'Excused', 'Absent'] as $status): ?>
                            <option value="<?= e($status) ?>"><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="threshold">
                    <label for="r-threshold">Attendance threshold</label>
                    <input type="number" id="r-threshold" name="threshold" min="1" max="100" step="0.5" placeholder="80">
                    <span class="field-help">Students below this percentage are listed. Defaults to the system setting.</span>
                </div>

                <div class="form-group" data-field="days">
                    <label for="r-days">Window</label>
                    <select id="r-days" name="days">
                        <option value="7">Last 7 days</option>
                        <option value="14">Last 14 days</option>
                        <option value="30">Last 30 days</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fa-solid fa-magnifying-glass"></i> Preview
                </button>
            </form>
        </div>
    </div>

    <div>
        <div class="card" id="preview-card">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="preview-title">Preview</h2>
                    <div class="text-xs text-muted" id="preview-subtitle">Choose a report type and press Preview.</div>
                </div>
                <div class="flex gap-1" id="export-buttons">
                    <label class="checkbox" style="margin-right:.5rem">
                        <input type="checkbox" id="r-save" checked>
                        <span class="text-xs">Keep in history</span>
                    </label>
                    <button class="btn btn-secondary btn-sm" data-export="pdf"><i class="fa-solid fa-file-pdf"></i> PDF</button>
                    <button class="btn btn-secondary btn-sm" data-export="xlsx"><i class="fa-solid fa-file-excel"></i> Excel</button>
                    <button class="btn btn-secondary btn-sm" data-export="csv"><i class="fa-solid fa-file-csv"></i> CSV</button>
                </div>
            </div>

            <div class="card__body" id="preview-stats" hidden></div>

            <div class="card__body--flush">
                <div id="preview-body">
                    <?php $__view->include('partials.empty-state', [
                        'icon'  => 'fa-file-lines',
                        'title' => 'Nothing previewed yet',
                        'text'  => 'Reports are generated from live data — nothing here is cached.',
                    ]); ?>
                </div>
            </div>
        </div>

        <?php if (!$isTeacher): ?>
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title">Recently generated</h2>
                    <span class="text-xs text-muted">Report files live outside the web root and are served through an access check</span>
                </div>
                <div class="card__body--flush">
                    <?php if ($history === []): ?>
                        <?php $__view->include('partials.empty-state', [
                            'icon'  => 'fa-clock-rotate-left',
                            'title' => 'No saved reports yet',
                        ]); ?>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                <tr><th>Report</th><th>Format</th><th class="numeric">Rows</th><th>Generated by</th><th>When</th><th></th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($history as $report): ?>
                                    <tr>
                                        <td class="cell-stack">
                                            <span class="cell-primary"><?= e($report['report_name']) ?></span>
                                            <span class="cell-muted"><?= e($types[$report['report_type']] ?? $report['report_type']) ?></span>
                                        </td>
                                        <td><span class="badge badge-neutral"><?= e(strtoupper((string) $report['format'])) ?></span></td>
                                        <td class="numeric"><?= e(number_format((int) $report['row_count'])) ?></td>
                                        <td class="text-sm"><?= e($report['full_name']) ?></td>
                                        <td class="nowrap text-sm"><?= e(time_ago($report['created_at'])) ?></td>
                                        <td class="nowrap">
                                            <a class="btn btn-ghost btn-sm" href="<?= e($base) ?>/reports/<?= e($report['report_id']) ?>/download">
                                                <i class="fa-solid fa-download"></i> Download
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS   = window.LSIAMS;
    const BASE = <?= json_js($base) ?>;

    const FIELDS = <?= json_js($fieldsByType) ?>;

    const form   = document.getElementById('report-form');
    const type   = document.getElementById('r-type');
    const grade  = document.getElementById('r-grade');
    const section = document.getElementById('r-section');

    /* ---- show only the filters this report type consumes ---------------- */
    function syncFields() {
        const wanted = FIELDS[type.value] || [];

        form.querySelectorAll('[data-field]').forEach((node) => {
            const show = wanted.indexOf(node.dataset.field) !== -1;
            node.hidden = !show;

            // Hidden controls are also cleared, so a stale value from a
            // previous report type cannot silently narrow the next one.
            if (!show) {
                node.querySelectorAll('select, input').forEach((input) => {
                    if (input.type === 'date' || input.type === 'checkbox') return;
                    input.value = '';
                });
            }
        });
    }

    type.addEventListener('change', syncFields);
    syncFields();

    /* ---- section list follows the grade level --------------------------- */
    grade.addEventListener('change', function () {
        const gradeId = this.value;

        Array.from(section.options).forEach((option) => {
            if (option.value === '') return;
            option.hidden = gradeId !== '' && option.dataset.grade !== gradeId;
        });

        if (section.selectedOptions[0] && section.selectedOptions[0].hidden) section.value = '';
    });

    /* ---- student typeahead ---------------------------------------------- */
    const search  = document.getElementById('r-student-search');
    const chosen  = document.getElementById('r-student');
    const results = document.getElementById('r-student-results');
    const label   = document.getElementById('r-student-chosen');

    search.addEventListener('input', LS.util.debounce(async function () {
        const query = search.value.trim();

        if (query.length < 2) { results.hidden = true; return; }

        try {
            const response = await LS.http.get(BASE + '/reports/students/search', { q: query });
            const rows = response.data.rows || [];

            results.innerHTML = rows.length === 0
                ? '<div class="typeahead__empty">No match.</div>'
                : rows.map((row) =>
                    '<button type="button" class="typeahead__item" data-id="' + row.student_id + '">'
                    + '<span>' + LS.util.escape(row.name) + '</span>'
                    + '<span class="text-xs text-muted mono">' + LS.util.escape(row.student_number)
                    + ' · ' + LS.util.escape(row.section_code || '—') + '</span></button>').join('');

            results.hidden = false;
        } catch (error) {
            results.hidden = true;
        }
    }, 300));

    results.addEventListener('click', function (event) {
        const item = event.target.closest('[data-id]');
        if (!item) return;

        chosen.value = item.dataset.id;
        label.textContent = 'Selected: ' + item.textContent.trim();
        label.className = 'field-help text-success';
        search.value = '';
        results.hidden = true;
    });

    /* ---- preview --------------------------------------------------------- */
    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Building…');

        const body = document.getElementById('preview-body');
        body.innerHTML = '<div style="padding:1rem">'
            + '<div class="skeleton skeleton--row"></div><div class="skeleton skeleton--row"></div>'
            + '<div class="skeleton skeleton--row"></div></div>';

        const filters = LS.util.formData(form);

        try {
            const response = await LS.http.post(BASE + '/reports/preview', filters);
            render(response.data);
        } catch (error) {
            body.innerHTML = '<div class="alert alert-danger" style="margin:1rem">'
                + LS.util.escape(error.message || 'Could not build this report.') + '</div>';
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    function render(data) {
        document.getElementById('preview-title').textContent    = data.title;
        document.getElementById('preview-subtitle').textContent = data.subtitle;

        /* statistics strip */
        const stats = document.getElementById('preview-stats');
        const entries = Object.entries(data.statistics || {});

        if (entries.length === 0) {
            stats.hidden = true;
        } else {
            stats.hidden = false;
            stats.innerHTML = '<div class="grid grid--6">' + entries.map(([key, value]) =>
                '<div style="padding:.55rem .7rem;background:var(--surface-alt);border-radius:var(--radius-sm)">'
                + '<div class="text-xs text-muted">' + LS.util.escape(key.replace(/_/g, ' ')) + '</div>'
                + '<div class="fw-600" style="font-size:18px">' + LS.util.escape(String(value)) + '</div></div>').join('')
                + '</div>';
        }

        /* table */
        const body = document.getElementById('preview-body');

        if (!data.rows || data.rows.length === 0) {
            body.innerHTML = '<div class="empty-state">'
                + '<div class="empty-state__icon"><i class="fa-solid fa-inbox"></i></div>'
                + '<div class="empty-state__title">No rows matched these filters</div>'
                + '<div class="empty-state__text">The report is still exportable — it will simply be empty.</div></div>';
            return;
        }

        let html = '<div class="table-wrap" style="max-height:560px;overflow:auto"><table class="data"><thead><tr>'
            + data.headers.map((header) => '<th>' + LS.util.escape(header) + '</th>').join('')
            + '</tr></thead><tbody>'
            + data.rows.map((row) => '<tr>'
                + row.map((cell) => '<td>' + LS.util.escape(cell === null ? '—' : String(cell)) + '</td>').join('')
                + '</tr>').join('')
            + '</tbody></table></div>';

        if (data.truncated) {
            html += '<div class="alert alert-info" style="margin:1rem">'
                + '<span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>'
                + '<div class="alert__body">Showing the first ' + data.rows.length + ' of '
                + data.total_rows.toLocaleString() + ' rows. The export contains all of them.</div></div>';
        }

        body.innerHTML = html;
    }

    /* ---- export ---------------------------------------------------------- */
    document.getElementById('export-buttons').addEventListener('click', function (event) {
        const button = event.target.closest('[data-export]');
        if (!button) return;

        // Read the form now rather than reusing whatever the last preview was
        // built from. Exporting never needed a preview — the server builds the
        // report from these same filters either way — and requiring one meant
        // the buttons did nothing at all until somebody happened to press
        // Preview first. Reading live also means that changing a filter after
        // a preview exports what is on screen, not what used to be.
        const fields = Object.assign(LS.util.formData(form), {
            format: button.dataset.export,
            save:   document.getElementById('r-save').checked ? '1' : '0',
            _csrf:  LS.config.csrfToken,
        });

        // A normal form POST rather than fetch, so the browser's own download
        // handling takes over and large exports stream straight to disk.
        const post = document.createElement('form');
        post.method = 'post';
        post.action = BASE + '/reports/generate';
        post.style.display = 'none';

        Object.entries(fields).forEach(([key, value]) => {
            if (value === '' || value === null || value === undefined) return;
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = key;
            input.value = value;
            post.appendChild(input);
        });

        document.body.appendChild(post);
        post.submit();
        setTimeout(() => post.remove(), 1000);
    });
})();
</script>
<?php $__view->stop(); ?>
