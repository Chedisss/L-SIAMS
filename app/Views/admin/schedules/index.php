<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Schedules',
    'subtitle'    => 'Teacher, subject, section, room and the attendance windows for each class.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Schedules', null]],
    'actions'     => '<button class="btn btn-primary" data-modal-open="schedule-modal">'
        . '<i class="fa-solid fa-plus"></i> Add Schedule</button>',
]); ?>

<form class="filter-bar" data-no-submit>
    <div class="form-group">
        <label for="filter-teacher">Teacher</label>
        <select id="filter-teacher" name="teacher_id">
            <option value="">All teachers</option>
            <?php foreach ($teachers as $teacher): ?>
                <option value="<?= e($teacher['teacher_id']) ?>"
                    <?= (int) ($filters['teacher_id'] ?? 0) === (int) $teacher['teacher_id'] ? 'selected' : '' ?>>
                    <?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?>
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
                    <?= (int) ($filters['section_id'] ?? 0) === (int) $section['section_id'] ? 'selected' : '' ?>>
                    <?= e($section['section_code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="filter-room">Classroom</label>
        <select id="filter-room" name="classroom_id">
            <option value="">All rooms</option>
            <?php foreach ($classrooms as $room): ?>
                <option value="<?= e($room['classroom_id']) ?>"
                    <?= (int) ($filters['classroom_id'] ?? 0) === (int) $room['classroom_id'] ? 'selected' : '' ?>>
                    Room <?= e($room['room_number']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="filter-day">Day</label>
        <select id="filter-day" name="day_of_week">
            <option value="">All days</option>
            <?php foreach ($days as $day): ?>
                <option value="<?= e($day) ?>" <?= $filters['day_of_week'] === $day ? 'selected' : '' ?>><?= e($day) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="clear-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__header">
        <h2 class="card__title"><?= e(count($schedules)) ?> schedule(s)</h2>
    </div>

    <div class="card__body--flush">
        <?php if ($schedules === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'   => 'fa-calendar-days',
                'title'  => 'No schedules available',
                'text'   => 'A schedule links a teacher, a subject, a section and a device-equipped room to a time slot. Attendance can only be taken during one.',
                'action' => '<button class="btn btn-primary" data-modal-open="schedule-modal"><i class="fa-solid fa-plus"></i> Add Schedule</button>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Day</th><th>Time</th><th>Subject</th><th>Department</th>
                        <th>Teacher</th><th>Section</th><th>Room</th><th>Terminal</th>
                        <th>Windows</th><th class="numeric">Sessions</th><th style="width:90px"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td class="nowrap"><span class="badge badge-neutral"><?= e(substr((string) $schedule['day_of_week'], 0, 3)) ?></span></td>
                            <td class="nowrap mono text-sm">
                                <?= e(substr((string) $schedule['start_time'], 0, 5)) ?>–<?= e(substr((string) $schedule['end_time'], 0, 5)) ?>
                            </td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($schedule['subject_code']) ?></span>
                                <span class="cell-muted"><?= e($schedule['subject_name']) ?></span>
                            </td>
                            <td class="text-sm text-muted"><?= e($schedule['department_name']) ?></td>
                            <td><?= e($schedule['teacher_name']) ?></td>
                            <td>
                                <span class="badge badge-primary"><?= e($schedule['section_code']) ?></span>
                                <div class="text-xs text-muted mt-1"><?= e($schedule['grade_level_code']) ?> · <?= e($schedule['enrolled_count']) ?> students</div>
                            </td>
                            <td>
                                <?= e($schedule['room_number']) ?>
                                <?php if ((int) $schedule['room_capacity'] < (int) $schedule['enrolled_count']): ?>
                                    <div class="text-xs text-warning">seats <?= e($schedule['room_capacity']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($schedule['device_id'] === null): ?>
                                    <span class="badge badge-danger">No terminal</span>
                                <?php else: ?>
                                    <span class="badge <?= e(status_badge($schedule['device_status'])) ?>">
                                        <?= e($schedule['device_id']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs text-muted nowrap">
                                In: −<?= e($schedule['time_in_window_open']) ?>/+<?= e($schedule['time_in_window_close']) ?>m<br>
                                Late: +<?= e($schedule['late_threshold_minutes']) ?>m · Dwell: <?= e($schedule['minimum_dwell_minutes']) ?>m
                            </td>
                            <td class="numeric text-muted"><?= e($schedule['session_count']) ?></td>
                            <td class="nowrap">
                                <button class="btn btn-ghost btn-sm" title="Edit"
                                        data-edit-schedule="<?= e($schedule['schedule_id']) ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button class="btn btn-ghost btn-sm text-danger" title="Archive"
                                        data-archive-schedule="<?= e($schedule['schedule_id']) ?>">
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

<!-- ===================================================================== -->
<!-- Schedule form — the Part 14.3/14.4 cascading, conflict-checked form    -->
<!-- ===================================================================== -->
<div class="modal-backdrop" id="schedule-modal">
    <div class="modal modal--lg" role="dialog" aria-modal="true" aria-labelledby="schedule-modal-title">
        <div class="modal__header">
            <h3 class="modal__title" id="schedule-modal-title">Add Schedule</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <form id="schedule-form" data-draft="schedule">
            <div class="modal__body">
                <input type="hidden" name="schedule_id" id="schedule-id">

                <div class="alert alert-info">
                    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                    <div class="alert__body">
                        Each choice narrows the next: the subject decides which grade levels apply,
                        the grade level decides the sections, and the two together decide which
                        teachers may take the class. The server re-checks every pairing on save.
                    </div>
                </div>

                <?php
                // Grouped by department so the list stays navigable once a school
                // has a few dozen subjects.
                $subjectsByDepartment = [];
                foreach ($subjects as $subjectOption) {
                    $subjectsByDepartment[(string) $subjectOption['department_name']][] = $subjectOption;
                }
                ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="s-subject" class="required">Subject</label>
                        <select id="s-subject" name="subject_id" required>
                            <option value="">Select a subject…</option>
                            <?php foreach ($subjectsByDepartment as $departmentName => $departmentSubjects): ?>
                                <optgroup label="── <?= e($departmentName) ?> ──">
                                    <?php foreach ($departmentSubjects as $subjectOption): ?>
                                        <option value="<?= e($subjectOption['subject_id']) ?>">
                                            <?= e($subjectOption['subject_code']) ?> — <?= e($subjectOption['subject_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-help" id="subject-help">Start here — everything below follows from it.</span>
                    </div>

                    <div class="form-group">
                        <label for="s-grade" class="required">Grade level</label>
                        <select id="s-grade" name="grade_level_id" required disabled>
                            <option value="">Select a subject first</option>
                        </select>
                        <span class="field-help" id="grade-help"></span>
                    </div>

                    <div class="form-group">
                        <label for="s-section" class="required">Section</label>
                        <select id="s-section" name="section_id" required disabled>
                            <option value="">Select a grade level first</option>
                        </select>
                        <span class="field-help" id="section-help"></span>
                    </div>

                    <div class="form-group">
                        <label for="s-teacher" class="required">Teacher</label>
                        <select id="s-teacher" name="teacher_id" required disabled>
                            <option value="">Select a section first</option>
                        </select>
                        <span class="field-help" id="teacher-help"></span>
                    </div>

                    <div class="form-group">
                        <label for="s-classroom" class="required">Classroom</label>
                        <select id="s-classroom" name="classroom_id" required disabled>
                            <option value="">Select a section first</option>
                        </select>
                        <span class="field-help" id="classroom-help"></span>
                    </div>

                    <div class="form-group">
                        <label for="s-day" class="required">Day</label>
                        <select id="s-day" name="day_of_week" required>
                            <?php foreach ($days as $day): ?>
                                <option value="<?= e($day) ?>"><?= e($day) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="s-start" class="required">Start time</label>
                        <input type="time" id="s-start" name="start_time" required value="08:00">
                    </div>

                    <div class="form-group">
                        <label for="s-end" class="required">End time</label>
                        <input type="time" id="s-end" name="end_time" required value="09:00">
                    </div>
                </div>

                <div id="conflict-banner" class="mt-2"></div>

                <h4 class="mt-3">Attendance windows</h4>
                <p class="text-muted text-sm">
                    Minutes relative to the start and end of the class. The tap-in window must close
                    before the tap-out window opens, so a student can always tell what a tap will do.
                </p>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="s-in-open">Tap-in opens <span class="label__hint">before start</span></label>
                        <input type="number" id="s-in-open" name="time_in_window_open" value="10" min="0" max="180">
                    </div>
                    <div class="form-group">
                        <label for="s-late">Late after <span class="label__hint">from start</span></label>
                        <input type="number" id="s-late" name="late_threshold_minutes" value="15" min="0" max="180">
                    </div>
                    <div class="form-group">
                        <label for="s-in-close">Tap-in closes <span class="label__hint">after start</span></label>
                        <input type="number" id="s-in-close" name="time_in_window_close" value="30" min="1" max="240">
                    </div>
                    <div class="form-group">
                        <label for="s-out-open">Tap-out opens <span class="label__hint">before end</span></label>
                        <input type="number" id="s-out-open" name="time_out_window_open" value="10" min="0" max="180">
                    </div>
                    <div class="form-group">
                        <label for="s-out-close">Tap-out closes <span class="label__hint">after end</span></label>
                        <input type="number" id="s-out-close" name="time_out_window_close" value="15" min="0" max="180">
                    </div>
                    <div class="form-group">
                        <label for="s-dwell">Minimum stay <span class="label__hint">minutes</span></label>
                        <input type="number" id="s-dwell" name="minimum_dwell_minutes" value="20" min="0" max="480">
                    </div>
                </div>

                <div id="window-preview" class="alert alert-info mt-2" style="display:none"></div>
            </div>

            <div class="modal__footer">
                <label class="checkbox" style="margin-right:auto">
                    <input type="checkbox" name="allow_override" id="s-override">
                    <span class="text-sm">Allow room capacity override</span>
                </label>
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="schedule-submit">Save schedule</button>
            </div>
        </form>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    const subject   = document.getElementById('s-subject');
    const grade     = document.getElementById('s-grade');
    const section   = document.getElementById('s-section');
    const teacher   = document.getElementById('s-teacher');
    const classroom = document.getElementById('s-classroom');
    const banner    = document.getElementById('conflict-banner');
    const form      = document.getElementById('schedule-form');

    function setLoading(select, message) {
        select.disabled = true;
        select.innerHTML = '<option value="">' + message + '</option>';
    }

    function setHelp(id, text, tone) {
        const node = document.getElementById(id);
        node.textContent = text || '';
        node.className = 'field-help' + (tone ? ' text-' + tone : '');
    }

    /* Subject → grade levels → sections → teachers, each step narrowing the
       next (Part 14.4). Every dependent dropdown is repopulated from the
       server rather than filtered client-side from a full list, so the options
       offered are the options the validators will accept.

       The cascade used to start at the teacher, which asked the administrator
       to know who could take a class before saying what the class was. This
       way round the class is the fixed thing and the people are what narrow. */

    function resetBelow(from) {
        const chain = [
            [grade,     'Select a subject first',     'grade-help'],
            [section,   'Select a grade level first', 'section-help'],
            [teacher,   'Select a section first',     'teacher-help'],
            [classroom, 'Select a section first',     'classroom-help'],
        ];

        chain.slice(from).forEach(([select, message, helpId]) => {
            select.value = '';
            setLoading(select, message);
            setHelp(helpId, '');
        });
    }

    /* Each step is awaitable so the edit path can walk the same cascade in
       order instead of guessing at it with nested timers. */

    async function loadGradeLevels(subjectId) {
        resetBelow(0);

        if (!subjectId) return;

        setLoading(grade, 'Loading…');

        try {
            const response = await LS.http.get('/api/subjects/' + encodeURIComponent(subjectId) + '/grade-levels');

            populateFlat(grade, response.data.grade_levels, 'grade_level_id',
                (item) => item.grade_level_code + ' — ' + item.grade_level_name);

            setHelp('grade-help', response.data.helper_text,
                response.data.empty ? 'warning' : (response.data.explicit ? null : 'muted'));
        } catch (error) {
            LS.toast.fromError(error);
            setLoading(grade, 'Could not load');
        }
    }

    async function loadSections(gradeLevelId) {
        resetBelow(1);

        if (!gradeLevelId) return;

        setLoading(section, 'Loading…');

        try {
            const response = await LS.http.get('/api/sections/assignable', { grade_level_ids: [gradeLevelId] });

            populateGrouped(section, response.data.grouped, 'section_id', 'section_code', 'section_name');
            setHelp('section-help', response.data.helper_text, response.data.empty ? 'warning' : null);
        } catch (error) {
            LS.toast.fromError(error);
            setLoading(section, 'Could not load');
        }
    }

    subject.addEventListener('change', function () { loadGradeLevels(this.value); });
    grade.addEventListener('change', function () { loadSections(this.value); });

    function populateGrouped(select, grouped, valueKey, codeKey, nameKey) {
        select.innerHTML = '<option value="">Select…</option>';

        const groups = Object.keys(grouped || {});

        if (groups.length === 0) {
            select.innerHTML = '<option value="">None available</option>';
            select.disabled = true;
            return;
        }

        groups.forEach((groupName) => {
            const optgroup = document.createElement('optgroup');
            // The spec asks for "── English Department ──" style headers.
            optgroup.label = '── ' + groupName + ' ──';

            grouped[groupName].forEach((item) => {
                const option = document.createElement('option');
                option.value = item[valueKey];
                option.textContent = item[codeKey] + ' — ' + item[nameKey];

                if (item.is_cross_department == 1) {
                    option.textContent += '  (cross-department)';
                    option.dataset.exception = 'true';
                }

                optgroup.appendChild(option);
            });

            select.appendChild(optgroup);
        });

        select.disabled = false;
    }

    /** A plain, ungrouped select. */
    function populateFlat(select, items, valueKey, label) {
        select.innerHTML = '<option value="">Select…</option>';

        if (!items || items.length === 0) {
            select.innerHTML = '<option value="">None available</option>';
            select.disabled = true;
            return;
        }

        items.forEach((item) => {
            const option = document.createElement('option');
            option.value = item[valueKey];
            option.textContent = label(item);
            select.appendChild(option);
        });

        select.disabled = false;
    }

    /* Section → the teachers who may take this subject here, and the rooms
       with a terminal. Both depend on the section, so both run together. */
    async function loadTeachersAndRooms(sectionId) {
        resetBelow(2);

        if (!sectionId) return;

        setLoading(teacher, 'Loading…');
        setLoading(classroom, 'Loading…');

        try {
            const [teacherResponse, roomResponse] = await Promise.all([
                LS.http.get('/api/teachers/assignable', { subject_id: subject.value, section_id: sectionId }),
                LS.http.get('/api/classrooms/assignable', { section_id: sectionId }),
            ]);

            populateFlat(teacher, teacherResponse.data.teachers, 'teacher_id', (item) =>
                item.last_name + ', ' + item.first_name
                + ' (' + item.department_code + ')'
                + (item.is_primary_department == 1 ? '' : '  · secondary department')
                + (item.has_fingerprint == 1 ? '' : '  ⚠ no fingerprint'));

            setHelp('teacher-help', teacherResponse.data.helper_text,
                teacherResponse.data.empty ? 'warning' : null);

            classroom.innerHTML = '<option value="">Select a room…</option>';

            (roomResponse.data.classrooms || []).forEach((room) => {
                const option = document.createElement('option');
                option.value = room.classroom_id;
                option.textContent = 'Room ' + room.room_number
                    + (room.building ? ' (' + room.building + ')' : '')
                    + ' · seats ' + room.capacity
                    + (room.capacity_ok == 1 ? '' : '  ⚠ too small');
                classroom.appendChild(option);
            });

            classroom.disabled = (roomResponse.data.classrooms || []).length === 0;
            setHelp('classroom-help', roomResponse.data.helper_text, roomResponse.data.empty ? 'warning' : null);
            checkConflicts();
        } catch (error) {
            LS.toast.fromError(error);
            setLoading(teacher, 'Could not load');
            setLoading(classroom, 'Could not load');
        }
    }

    section.addEventListener('change', function () { loadTeachersAndRooms(this.value); });

    /* Live conflict check once day and both times are filled (Part 14.4). */
    const checkConflicts = LS.util.debounce(async function () {
        const payload = {
            teacher_id:   teacher.value,
            subject_id:   subject.value,
            section_id:   section.value,
            classroom_id: classroom.value,
            day_of_week:  document.getElementById('s-day').value,
            start_time:   document.getElementById('s-start').value,
            end_time:     document.getElementById('s-end').value,
            schedule_id:  document.getElementById('schedule-id').value || 0,
        };

        if (!payload.teacher_id || !payload.section_id || !payload.classroom_id
            || !payload.start_time || !payload.end_time) {
            banner.innerHTML = '';
            return;
        }

        try {
            const response = await LS.http.post('/admin/schedules/check-conflict', payload);

            if (!response.data.has_conflicts) {
                banner.innerHTML = '<div class="alert alert-success" style="margin:0">'
                    + '<span class="alert__icon"><i class="fa-solid fa-circle-check"></i></span>'
                    + '<div class="alert__body">No conflicts.</div></div>';
                return;
            }

            const list = response.data.conflicts
                .map((c) => '<li>' + LS.util.escape(c.message) + '</li>').join('');

            banner.innerHTML = '<div class="alert alert-danger" style="margin:0">'
                + '<span class="alert__icon"><i class="fa-solid fa-circle-exclamation"></i></span>'
                + '<div class="alert__body"><div class="alert__title">Schedule conflict</div>'
                + '<ul style="margin:.3rem 0 0;padding-left:1.1rem">' + list + '</ul></div></div>';
        } catch (error) { /* the server re-checks on save regardless */ }
    }, 400);

    ['s-day', 's-start', 's-end'].forEach((id) => {
        document.getElementById(id).addEventListener('blur', checkConflicts);
        document.getElementById(id).addEventListener('change', checkConflicts);
    });

    classroom.addEventListener('change', checkConflicts);

    /* Window preview: turn the raw minute offsets into real clock times. */
    function updateWindowPreview() {
        const start = document.getElementById('s-start').value;
        const end   = document.getElementById('s-end').value;
        const box   = document.getElementById('window-preview');

        if (!start || !end) { box.style.display = 'none'; return; }

        const toMinutes = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
        const toClock   = (m) => String(Math.floor(((m % 1440) + 1440) % 1440 / 60)).padStart(2, '0')
                               + ':' + String(m % 60).padStart(2, '0');

        const s = toMinutes(start), en = toMinutes(end);
        const v = (id) => parseInt(document.getElementById(id).value, 10) || 0;

        box.style.display = 'flex';
        box.innerHTML = '<span class="alert__icon"><i class="fa-solid fa-clock"></i></span>'
            + '<div class="alert__body">'
            + '<strong>Tap in</strong> ' + toClock(s - v('s-in-open')) + ' – ' + toClock(s + v('s-in-close'))
            + ' &nbsp;·&nbsp; <strong>Late from</strong> ' + toClock(s + v('s-late'))
            + '<br><strong>Tap out</strong> ' + toClock(en - v('s-out-open')) + ' – ' + toClock(en + v('s-out-close'))
            + ' &nbsp;·&nbsp; <strong>Minimum stay</strong> ' + v('s-dwell') + ' min'
            + '</div>';
    }

    ['s-start', 's-end', 's-in-open', 's-late', 's-in-close', 's-out-open', 's-out-close', 's-dwell']
        .forEach((id) => document.getElementById(id).addEventListener('input', updateWindowPreview));

    updateWindowPreview();

    /* ---- submit ---------------------------------------------------------- */

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = document.getElementById('schedule-submit');
        LS.util.setBusy(button, true, 'Validating…');

        const data = LS.util.formData(form);
        const id = data.schedule_id;
        delete data.schedule_id;

        try {
            const response = id
                ? await LS.http.put('/admin/schedules/' + id, data)
                : await LS.http.post('/admin/schedules', data);

            LS.drafts.clear(form);
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    /* ---- edit / archive -------------------------------------------------- */

    document.addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-edit-schedule]');

        if (edit) {
            try {
                const response = await LS.http.get('/admin/schedules/' + edit.dataset.editSchedule);
                const s = response.data.schedule;

                document.getElementById('schedule-modal-title').textContent = 'Edit Schedule';
                document.getElementById('schedule-id').value = s.schedule_id;

                // Walk the cascade in order, waiting for each list before
                // choosing from it. The previous version fired the first
                // request and then guessed at the rest with nested timers,
                // which silently lost the selection whenever a request took
                // longer than the guess.
                subject.value = s.subject_id;
                await loadGradeLevels(s.subject_id);

                grade.value = s.grade_level_id;
                await loadSections(s.grade_level_id);

                section.value = s.section_id;
                await loadTeachersAndRooms(s.section_id);

                teacher.value   = s.teacher_id;
                classroom.value = s.classroom_id;

                document.getElementById('s-day').value   = s.day_of_week;
                document.getElementById('s-start').value = String(s.start_time).slice(0, 5);
                document.getElementById('s-end').value   = String(s.end_time).slice(0, 5);

                ['time_in_window_open', 'late_threshold_minutes', 'time_in_window_close',
                 'time_out_window_open', 'time_out_window_close', 'minimum_dwell_minutes']
                    .forEach((field) => { form.elements[field].value = s[field]; });

                updateWindowPreview();
                checkConflicts();
                LS.modal.open('schedule-modal');
            } catch (error) {
                LS.toast.fromError(error);
            }
        }

        const archive = event.target.closest('[data-archive-schedule]');

        if (archive) {
            const result = await LS.modal.confirm({
                title: 'Archive schedule?',
                message: 'The schedule stops being available for new attendance sessions. '
                       + 'All attendance already recorded against it is kept.',
                confirmLabel: 'Archive',
                danger: true,
            });

            if (!result) return;

            try {
                const response = await LS.http.post('/admin/schedules/' + archive.dataset.archiveSchedule + '/archive', {});
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });

    // Reset the form when the modal opens for a new schedule.
    document.getElementById('schedule-modal').addEventListener('modal:close', () => {
        setTimeout(() => {
            form.reset();
            document.getElementById('schedule-id').value = '';
            document.getElementById('schedule-modal-title').textContent = 'Add Schedule';
            banner.innerHTML = '';
            subject.value = '';
            resetBelow(0);
            setHelp('subject-help', 'Start here — everything below follows from it.');
        }, 200);
    });

    ['filter-teacher', 'filter-section', 'filter-room', 'filter-day'].forEach((id) => {
        document.getElementById(id).addEventListener('change', () => {
            const params = new URLSearchParams();
            ['teacher_id', 'section_id', 'classroom_id', 'day_of_week'].forEach((name, index) => {
                const value = document.getElementById(['filter-teacher', 'filter-section', 'filter-room', 'filter-day'][index]).value;
                if (value) params.set(name, value);
            });
            window.location.search = params.toString();
        });
    });
})();
</script>
<?php $__view->stop(); ?>
