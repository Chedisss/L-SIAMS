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
    'student_summary'     => ['range', 'school_year_id', 'grade_level_id', 'section_id'],
    'daily'               => ['date', 'section_id', 'grade_level_id', 'subject_id', 'teacher_id', 'final_status'],
    'weekly'              => ['range', 'section_id', 'grade_level_id', 'subject_id', 'teacher_id'],
    'monthly'             => ['range', 'section_id', 'grade_level_id', 'subject_id', 'teacher_id'],
    'student'             => ['range', 'student_id'],
    'teacher'             => ['range', 'teacher_id'],
    // Naming a subject turns this into that subject day by day, so the date
    // range and the section both narrow it further.
    'subject'             => ['range', 'subject_id', 'grade_level_id', 'section_id'],
    'section_daily'       => ['date', 'section_id'],
    'section_summary'     => ['range', 'grade_level_id', 'section_id'],
    'section_comparison'  => ['range', 'grade_level_id'],
    'adviser'             => ['range', 'teacher_id'],
    'section_roster_rfid' => ['section_id'],
    'chronic_absence'     => ['range', 'grade_level_id', 'section_id', 'threshold'],
    'device_uptime'       => ['days', 'classroom_id'],
    'audit'               => ['range'],
    'security'            => ['range'],
];

/**
 * A one-line plain-English summary of each report, shown under the type
 * picker so an administrator can tell "Section Summary" from "Section
 * Comparison" without having to run both.
 */
$typeDescriptions = [
    'student_summary'     => 'Every student, one row each.',
    'daily'               => 'One day, all students.',
    'weekly'              => 'A week, day by day.',
    'monthly'             => 'A month, day by day.',
    'student'             => 'One student over a range.',
    'teacher'             => 'A teacher\'s sessions.',
    'subject'             => 'One subject, day by day.',
    'section_daily'       => 'Printable roll-call sheet.',
    'section_summary'     => 'Per-section totals.',
    'section_comparison'  => 'Sections in a grade, compared.',
    'adviser'             => 'An adviser\'s advisory class.',
    'section_roster_rfid' => 'Who has an RFID card.',
    'chronic_absence'     => 'Students below a threshold.',
    'device_uptime'       => 'Terminal online reliability.',
    'audit'               => 'Who changed what, and when.',
    'security'            => 'Blocked scans, failed logins.',
];

/**
 * Group the report types so the picker reads as a short menu of categories
 * rather than one flat list. Order within a group is deliberate: the
 * most-used report leads each group.
 */
$typeGroups = [
    'Attendance'   => ['student_summary', 'daily', 'weekly', 'monthly', 'student', 'teacher', 'subject'],
    'By section'   => ['section_daily', 'section_summary', 'section_comparison', 'adviser', 'section_roster_rfid', 'chronic_absence'],
    'Operations'   => ['device_uptime', 'audit', 'security'],
];
// Report types teachers may never run, even if they somehow reach this view.
$teacherHidden = ['audit', 'security', 'device_uptime'];
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Reports',
    'subtitle'    => 'Start with Quick summary for an at-a-glance attendance roll of every student, or open the Advanced builder for the full set of reports. Exports carry every row; the on-screen preview is capped so the browser stays responsive.',
    'breadcrumbs' => [['Dashboard', $base], ['Reports', null]],
]); ?>

<style nonce="<?= e(csp_nonce()) ?>">
    /* ---- report tab switch --------------------------------------------- */
    .rtabs { display:flex; gap:.35rem; margin-bottom:1rem; background:var(--surface-alt);
             padding:.3rem; border-radius:var(--radius-md, 10px); }
    .rtabs__btn { flex:1; border:0; background:transparent; cursor:pointer; padding:.55rem .6rem;
                  border-radius:var(--radius-sm, 8px); font-weight:600; font-size:.85rem;
                  color:var(--text-muted); display:flex; align-items:center; justify-content:center;
                  gap:.4rem; transition:background .12s, color .12s; }
    .rtabs__btn.is-active { background:var(--surface, #fff); color:var(--text); box-shadow:var(--shadow-sm, 0 1px 2px rgba(0,0,0,.08)); }

    /* ---- guided steps --------------------------------------------------- */
    .wiz-step { margin-bottom:1.15rem; }
    .wiz-step__label { display:flex; align-items:center; gap:.5rem; font-weight:600;
                       font-size:.82rem; margin-bottom:.5rem; }
    .wiz-step__num { display:inline-flex; align-items:center; justify-content:center;
                     width:1.35rem; height:1.35rem; border-radius:999px; background:var(--primary, #1e5bd6);
                     color:#fff; font-size:.72rem; font-weight:700; flex:none; }
    .wiz-step__hint { color:var(--text-muted); font-weight:400; font-size:.75rem; }

    .choice-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:.4rem; }
    .choice { border:1px solid var(--border, #d9dee6); background:var(--surface, #fff);
              border-radius:var(--radius-sm, 8px); padding:.6rem .5rem; cursor:pointer;
              font-weight:600; font-size:.83rem; color:var(--text); text-align:center;
              display:flex; align-items:center; justify-content:center; gap:.4rem;
              transition:border-color .12s, background .12s, color .12s; }
    .choice:hover { border-color:var(--primary, #1e5bd6); }
    .choice.is-active { border-color:var(--primary, #1e5bd6); background:var(--primary-050, rgba(30,91,214,.08));
                        color:var(--primary, #1e5bd6); }
    .choice i { font-size:.85rem; }

    .wiz-summary { background:var(--surface-alt); border-radius:var(--radius-sm, 8px);
                   padding:.6rem .7rem; font-size:.8rem; color:var(--text-muted); line-height:1.5; }
    .wiz-summary strong { color:var(--text); font-weight:600; }
</style>

<div class="grid" style="grid-template-columns:360px 1fr;align-items:start">
    <div>
        <div class="rtabs" role="tablist">
            <button type="button" class="rtabs__btn is-active" data-tab="quick" role="tab">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Quick summary
            </button>
            <button type="button" class="rtabs__btn" data-tab="advanced" role="tab">
                <i class="fa-solid fa-sliders"></i> Advanced builder
            </button>
        </div>

        <!-- ============================ QUICK SUMMARY ==================== -->
        <div class="card" id="quick-panel">
            <div class="card__header"><h2 class="card__title">Summarize attendance</h2></div>
            <div class="card__body">

                <!-- Step 1 · period -->
                <div class="wiz-step">
                    <div class="wiz-step__label">
                        <span class="wiz-step__num">1</span> Period
                    </div>
                    <div class="choice-grid" id="q-period">
                        <button type="button" class="choice is-active" data-period="today">Today</button>
                        <button type="button" class="choice" data-period="week">This week</button>
                        <button type="button" class="choice" data-period="month">This month</button>
                        <button type="button" class="choice" data-period="sy">School year</button>
                        <button type="button" class="choice" data-period="custom" style="grid-column:1 / -1">
                            <i class="fa-solid fa-calendar-days"></i> Custom dates…
                        </button>
                    </div>
                    <div class="form-grid mt-2" data-field="q-custom" hidden>
                        <div class="form-group">
                            <label for="q-from">From</label>
                            <input type="date" id="q-from" value="<?= e($defaultFrom) ?>">
                        </div>
                        <div class="form-group">
                            <label for="q-to">To</label>
                            <input type="date" id="q-to" value="<?= e($defaultTo) ?>">
                        </div>
                    </div>
                </div>

                <!-- Step 2 · school year -->
                <div class="wiz-step">
                    <div class="wiz-step__label">
                        <span class="wiz-step__num">2</span> School year
                    </div>
                    <select id="q-year">
                        <?php if ($schoolYears === []): ?>
                            <option value="">No school year configured</option>
                        <?php else: ?>
                            <?php foreach ($schoolYears as $year): ?>
                                <option value="<?= e($year['school_year_id']) ?>"
                                        data-start="<?= e($year['start_date']) ?>"
                                        data-end="<?= e($year['end_date']) ?>"
                                        <?= (int) $year['school_year_id'] === (int) $currentYear ? 'selected' : '' ?>>
                                    <?= e($year['year_label']) ?><?= (int) $year['is_current'] === 1 ? ' (current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Step 3 · who -->
                <div class="wiz-step">
                    <div class="wiz-step__label">
                        <span class="wiz-step__num">3</span> Who
                    </div>
                    <div class="choice-grid" id="q-scope">
                        <button type="button" class="choice is-active" data-scope="section">
                            <i class="fa-solid fa-users"></i> A section
                        </button>
                        <button type="button" class="choice" data-scope="student">
                            <i class="fa-solid fa-user"></i> One student
                        </button>
                    </div>

                    <!-- section scope -->
                    <div data-field="q-section-scope" class="mt-2">
                        <div class="form-group">
                            <label for="q-grade">Grade level</label>
                            <select id="q-grade">
                                <option value="">All grade levels</option>
                                <?php foreach ($gradeLevels as $grade): ?>
                                    <option value="<?= e($grade['grade_level_id']) ?>"><?= e($grade['grade_level_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="q-section">Section</label>
                            <select id="q-section">
                                <option value="">Every section in this year</option>
                                <?php foreach ($pickerSections as $section): ?>
                                    <option value="<?= e($section['section_id']) ?>"
                                            data-year="<?= e($section['school_year_id']) ?>"
                                            data-grade="<?= e($section['grade_level_id']) ?>">
                                        <?= e($section['section_code']) ?> — <?= e($section['section_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-help">Leave on “Every section” for the whole school year.</span>
                        </div>
                    </div>

                    <!-- student scope -->
                    <div data-field="q-student-scope" class="mt-2" hidden>
                        <div class="form-group">
                            <label for="q-student-search">Student</label>
                            <input type="search" id="q-student-search" placeholder="Search by name or student number"
                                   autocomplete="off">
                            <input type="hidden" id="q-student">
                            <div class="typeahead" id="q-student-results" hidden></div>
                            <span class="field-help" id="q-student-chosen">No student selected.</span>
                        </div>
                    </div>
                </div>

                <div class="wiz-summary mb-2" id="q-summary"></div>

                <button type="button" class="btn btn-primary btn-block" id="q-run">
                    <i class="fa-solid fa-chart-simple"></i> Preview summary
                </button>
            </div>
        </div>

        <!-- ============================ ADVANCED ========================= -->
        <div class="card" id="advanced-panel" hidden>
            <div class="card__header"><h2 class="card__title">Build a report</h2></div>

            <div class="card__body">
                <form id="report-form">
                    <div class="form-group">
                        <label for="r-type" class="required">Report type</label>
                        <select id="r-type" name="type" required>
                            <?php foreach ($typeGroups as $groupLabel => $groupTypes): ?>
                                <?php
                                // Skip a whole group if the viewer can run nothing in it.
                                $visible = array_filter(
                                    $groupTypes,
                                    fn ($t) => isset($types[$t]) && !($isTeacher && in_array($t, $teacherHidden, true))
                                );
                                if ($visible === []) { continue; }
                                ?>
                                <optgroup label="<?= e($groupLabel) ?>">
                                    <?php foreach ($visible as $value): ?>
                                        <option value="<?= e($value) ?>"><?= e($types[$value]) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-help" id="r-type-desc"></span>
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

                    <div class="form-group" data-field="school_year_id">
                        <label for="r-year">School year</label>
                        <select id="r-year" name="school_year_id">
                            <option value="">All school years</option>
                            <?php foreach ($schoolYears as $year): ?>
                                <option value="<?= e($year['school_year_id']) ?>"
                                        <?= (int) $year['school_year_id'] === (int) $currentYear ? 'selected' : '' ?>>
                                    <?= e($year['year_label']) ?><?= (int) $year['is_current'] === 1 ? ' (current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

                    <div class="flex gap-1">
                        <button type="submit" class="btn btn-primary" style="flex:1">
                            <i class="fa-solid fa-magnifying-glass"></i> Preview
                        </button>
                        <button type="button" class="btn btn-secondary" id="r-reset" title="Clear every filter back to its default">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div>
        <div class="card" id="preview-card">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="preview-title">Preview</h2>
                    <div class="text-xs text-muted" id="preview-subtitle">Choose a period and press Preview summary.</div>
                </div>
                <div class="flex gap-1" id="export-buttons">
                    <label class="checkbox" style="margin-right:.5rem">
                        <input type="checkbox" id="r-save" checked>
                        <span class="text-xs">Keep in history</span>
                    </label>
                    <button class="btn btn-secondary btn-sm" data-export="pdf"><i class="fa-solid fa-file-pdf"></i> PDF</button>
                    <button class="btn btn-secondary btn-sm" data-export="xlsx"><i class="fa-solid fa-file-excel"></i> Excel</button>
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
    const DESCS  = <?= json_js($typeDescriptions) ?>;

    // Colour the Status / Standing column exactly as the dashboards do, so a
    // preview reads at a glance instead of as a wall of grey text. Anything not
    // a known label falls back to a neutral badge rather than being mis-coloured.
    const STATUS_BADGE = {
        'Present':           'badge-success',
        'Late':              'badge-warning',
        'Left Early':        'badge-warning',
        'Incomplete':        'badge-warning',
        'Absent':            'badge-danger',
        'Excused':           'badge-info',
        'Official Business': 'badge-info',
        // Student-summary standings.
        'Good':              'badge-success',
        'At risk':           'badge-warning',
        'Chronic':           'badge-danger',
        'No data':           'badge-neutral',
    };

    function cellHtml(cell, isBadge) {
        const text = cell === null ? '—' : String(cell);
        if (isBadge && cell !== null && text !== '' && text !== '—') {
            return '<td><span class="badge ' + (STATUS_BADGE[text] || 'badge-neutral') + '">'
                + LS.util.escape(text) + '</span></td>';
        }
        return '<td>' + LS.util.escape(text) + '</td>';
    }

    /* ==================================================================== *
     *  Shared preview + export
     * ==================================================================== */
    let activeTab = 'quick';

    function renderPreview(data) {
        document.getElementById('preview-title').textContent    = data.title;
        document.getElementById('preview-subtitle').textContent = data.subtitle;

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

        const body = document.getElementById('preview-body');

        if (!data.rows || data.rows.length === 0) {
            body.innerHTML = '<div class="empty-state">'
                + '<div class="empty-state__icon"><i class="fa-solid fa-inbox"></i></div>'
                + '<div class="empty-state__title">No rows matched these filters</div>'
                + '<div class="empty-state__text">The report is still exportable — it will simply be empty.</div></div>';
            return;
        }

        // Colour any column whose header is "Status" or "Standing".
        const badgeCols = data.headers.reduce((acc, h, i) => {
            const key = String(h).trim().toLowerCase();
            if (key === 'status' || key === 'standing') acc.push(i);
            return acc;
        }, []);

        let html = '<div class="table-wrap" style="max-height:560px;overflow:auto"><table class="data"><thead><tr>'
            + data.headers.map((header) => '<th>' + LS.util.escape(header) + '</th>').join('')
            + '</tr></thead><tbody>'
            + data.rows.map((row) => '<tr>'
                + row.map((cell, i) => cellHtml(cell, badgeCols.indexOf(i) !== -1)).join('')
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

    async function runPreview(params, button) {
        LS.util.setBusy(button, true, 'Building…');

        const body = document.getElementById('preview-body');
        body.innerHTML = '<div style="padding:1rem">'
            + '<div class="skeleton skeleton--row"></div><div class="skeleton skeleton--row"></div>'
            + '<div class="skeleton skeleton--row"></div></div>';

        try {
            const response = await LS.http.post(BASE + '/reports/preview', params);
            renderPreview(response.data);
        } catch (error) {
            body.innerHTML = '<div class="alert alert-danger" style="margin:1rem">'
                + LS.util.escape(error.message || 'Could not build this report.') + '</div>';
        } finally {
            LS.util.setBusy(button, false);
        }
    }

    function runExport(params, format) {
        const fields = Object.assign({}, params, {
            format: format,
            save:   document.getElementById('r-save').checked ? '1' : '0',
            _csrf:  LS.config.csrfToken,
        });

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
    }

    /* ==================================================================== *
     *  Tab switching
     * ==================================================================== */
    const tabButtons = document.querySelectorAll('.rtabs__btn');
    const panels = { quick: document.getElementById('quick-panel'), advanced: document.getElementById('advanced-panel') };

    tabButtons.forEach((btn) => btn.addEventListener('click', function () {
        activeTab = btn.dataset.tab;
        tabButtons.forEach((b) => b.classList.toggle('is-active', b === btn));
        Object.entries(panels).forEach(([name, panel]) => { panel.hidden = name !== activeTab; });
    }));

    /* ==================================================================== *
     *  QUICK SUMMARY wizard
     * ==================================================================== */
    const qYear    = document.getElementById('q-year');
    const qGrade   = document.getElementById('q-grade');
    const qSection = document.getElementById('q-section');
    const qFrom    = document.getElementById('q-from');
    const qTo      = document.getElementById('q-to');
    const qStudent = document.getElementById('q-student');
    const qSummary = document.getElementById('q-summary');

    let qPeriod = 'today';
    let qScope  = 'section';

    const pad = (n) => String(n).padStart(2, '0');
    const fmt = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const todayStr = fmt(new Date());

    function periodRange() {
        if (qPeriod === 'custom') {
            return [qFrom.value || todayStr, qTo.value || todayStr];
        }
        if (qPeriod === 'today') {
            return [todayStr, todayStr];
        }
        if (qPeriod === 'week') {
            const d = new Date();
            const back = (d.getDay() + 6) % 7;      // days since Monday
            d.setDate(d.getDate() - back);
            return [fmt(d), todayStr];
        }
        if (qPeriod === 'month') {
            const d = new Date();
            return [fmt(new Date(d.getFullYear(), d.getMonth(), 1)), todayStr];
        }
        if (qPeriod === 'sy') {
            const opt = qYear.selectedOptions[0];
            const start = opt ? opt.dataset.start : '';
            const end   = opt ? opt.dataset.end : '';
            if (!start) return [todayStr, todayStr];
            // For the year in progress, count up to today; a past or future
            // year uses its own full span.
            const to = (todayStr >= start && todayStr <= end) ? todayStr : end;
            return [start, to];
        }
        return [todayStr, todayStr];
    }

    const PERIOD_LABEL = { today: 'Today', week: 'This week', month: 'This month', sy: 'The school year', custom: 'Custom range' };

    function updateSummary() {
        const [from, to] = periodRange();
        const yearLabel = qYear.selectedOptions[0] ? qYear.selectedOptions[0].textContent.trim() : '—';
        let who;

        if (qScope === 'student') {
            who = qStudent.value
                ? document.getElementById('q-student-chosen').textContent.replace(/^Selected:\s*/, '')
                : 'a student (none picked yet)';
        } else if (qSection.value) {
            who = qSection.selectedOptions[0].textContent.trim();
        } else {
            who = 'every student';
        }

        const range = from === to ? from : from + ' → ' + to;
        qSummary.innerHTML = 'Attendance of <strong>' + LS.util.escape(who) + '</strong> '
            + 'in <strong>' + LS.util.escape(yearLabel) + '</strong>, '
            + '<strong>' + LS.util.escape((PERIOD_LABEL[qPeriod] || '').toLowerCase()) + '</strong> '
            + '<span class="text-xs">(' + LS.util.escape(range) + ')</span>.';
    }

    /* ---- period buttons ------------------------------------------------- */
    document.getElementById('q-period').addEventListener('click', function (event) {
        const btn = event.target.closest('[data-period]');
        if (!btn) return;
        qPeriod = btn.dataset.period;
        this.querySelectorAll('.choice').forEach((c) => c.classList.toggle('is-active', c === btn));
        document.querySelector('[data-field="q-custom"]').hidden = qPeriod !== 'custom';
        updateSummary();
    });

    /* ---- scope buttons -------------------------------------------------- */
    document.getElementById('q-scope').addEventListener('click', function (event) {
        const btn = event.target.closest('[data-scope]');
        if (!btn) return;
        qScope = btn.dataset.scope;
        this.querySelectorAll('.choice').forEach((c) => c.classList.toggle('is-active', c === btn));
        document.querySelector('[data-field="q-section-scope"]').hidden = qScope !== 'section';
        document.querySelector('[data-field="q-student-scope"]').hidden = qScope !== 'student';
        updateSummary();
    });

    /* ---- section list follows year + grade ------------------------------ */
    function syncQuickSections() {
        const year = qYear.value, grade = qGrade.value;
        Array.from(qSection.options).forEach((opt) => {
            if (opt.value === '') return;
            const okYear  = !year  || opt.dataset.year  === year;
            const okGrade = !grade || opt.dataset.grade === grade;
            opt.hidden = !(okYear && okGrade);
        });
        if (qSection.selectedOptions[0] && qSection.selectedOptions[0].hidden) qSection.value = '';
    }

    qYear.addEventListener('change', function () { syncQuickSections(); updateSummary(); });
    qGrade.addEventListener('change', function () { syncQuickSections(); updateSummary(); });
    qSection.addEventListener('change', updateSummary);
    [qFrom, qTo].forEach((el) => el.addEventListener('change', updateSummary));

    /* ---- student typeahead (quick) -------------------------------------- */
    (function () {
        const search  = document.getElementById('q-student-search');
        const results = document.getElementById('q-student-results');
        const label   = document.getElementById('q-student-chosen');

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
            } catch (error) { results.hidden = true; }
        }, 300));

        results.addEventListener('click', function (event) {
            const item = event.target.closest('[data-id]');
            if (!item) return;
            qStudent.value = item.dataset.id;
            label.textContent = 'Selected: ' + item.textContent.trim();
            label.className = 'field-help text-success';
            search.value = '';
            results.hidden = true;
            updateSummary();
        });
    })();

    /* ---- quick params + run --------------------------------------------- */
    function quickParams() {
        const [from, to] = periodRange();
        const params = { date_from: from, date_to: to };
        if (qYear.value) params.school_year_id = qYear.value;

        if (qScope === 'student') {
            params.type = 'student';
            params.student_id = qStudent.value;
        } else {
            params.type = 'student_summary';
            if (qGrade.value)   params.grade_level_id = qGrade.value;
            if (qSection.value) params.section_id = qSection.value;
        }
        return params;
    }

    document.getElementById('q-run').addEventListener('click', function () {
        if (qScope === 'student' && !qStudent.value) {
            qSummary.innerHTML = '<span class="text-danger">Pick a student first, or switch to “A section”.</span>';
            return;
        }
        activeTab = 'quick';
        runPreview(quickParams(), this);
    });

    syncQuickSections();
    updateSummary();

    /* ==================================================================== *
     *  ADVANCED builder
     * ==================================================================== */
    const form    = document.getElementById('report-form');
    const type    = document.getElementById('r-type');
    const desc     = document.getElementById('r-type-desc');
    const grade   = document.getElementById('r-grade');
    const section = document.getElementById('r-section');

    function syncFields() {
        const wanted = FIELDS[type.value] || [];
        desc.textContent = DESCS[type.value] || '';

        form.querySelectorAll('[data-field]').forEach((node) => {
            const show = wanted.indexOf(node.dataset.field) !== -1;
            node.hidden = !show;

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

    function syncSections() {
        const gradeId = grade.value;
        Array.from(section.options).forEach((option) => {
            if (option.value === '') return;
            option.hidden = gradeId !== '' && option.dataset.grade !== gradeId;
        });
        if (section.selectedOptions[0] && section.selectedOptions[0].hidden) section.value = '';
    }

    grade.addEventListener('change', syncSections);

    document.getElementById('r-reset').addEventListener('click', function () {
        form.reset();
        const studentChosen  = document.getElementById('r-student');
        const studentLabel   = document.getElementById('r-student-chosen');
        const studentResults = document.getElementById('r-student-results');
        if (studentChosen)  studentChosen.value = '';
        if (studentResults) studentResults.hidden = true;
        if (studentLabel) {
            studentLabel.textContent = 'No student selected.';
            studentLabel.className = 'field-help';
        }
        syncSections();
        syncFields();
    });

    /* ---- student typeahead (advanced) ----------------------------------- */
    (function () {
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
            } catch (error) { results.hidden = true; }
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
    })();

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        activeTab = 'advanced';
        runPreview(LS.util.formData(form), form.querySelector('[type=submit]'));
    });

    /* ==================================================================== *
     *  Export (reads whichever tab is active)
     * ==================================================================== */
    document.getElementById('export-buttons').addEventListener('click', function (event) {
        const button = event.target.closest('[data-export]');
        if (!button) return;

        if (activeTab === 'quick') {
            if (qScope === 'student' && !qStudent.value) {
                qSummary.innerHTML = '<span class="text-danger">Pick a student first, or switch to “A section”.</span>';
                return;
            }
            runExport(quickParams(), button.dataset.export);
        } else {
            runExport(LS.util.formData(form), button.dataset.export);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
