<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$editing = $teacher !== null;
?>

<?php $__view->include('partials.page-header', [
    'title'       => $editing ? 'Edit Teacher' : 'Register Teacher',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Teachers', '/admin/teachers'], [$editing ? 'Edit' : 'Register', null]],
]); ?>

<div class="alert alert-info">
    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
    <div class="alert__body">
        Department and grade level are not paperwork — they decide what this teacher can be
        assigned. Subjects are filtered to their department; sections to their grade level.
        Both are re-checked on the server whatever the form sends.
    </div>
</div>

<form id="teacher-form" data-draft="teacher-<?= e($editing ? $teacher['teacher_id'] : 'new') ?>">
    <div class="grid grid--2">
        <div class="card">
            <div class="card__header"><h2 class="card__title">Identity</h2></div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="employee_number" class="required">Employee number</label>
                        <input type="text" id="employee_number" name="employee_number" required maxlength="30"
                               value="<?= e($teacher['employee_number'] ?? $suggestedEmployee ?? '') ?>"
                               <?= $editing ? 'readonly' : '' ?>>
                        <?php if ($editing): ?><span class="field-help">Immutable after creation.</span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="active" <?= ($teacher['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($teacher['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="first_name" class="required">First name</label>
                        <input type="text" id="first_name" name="first_name" required maxlength="60"
                               value="<?= e($teacher['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="middle_name">Middle name</label>
                        <input type="text" id="middle_name" name="middle_name" maxlength="60"
                               value="<?= e($teacher['middle_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name" class="required">Last name</label>
                        <input type="text" id="last_name" name="last_name" required maxlength="60"
                               value="<?= e($teacher['last_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="suffix">Suffix</label>
                        <input type="text" id="suffix" name="suffix" maxlength="10" value="<?= e($teacher['suffix'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="email" class="required">Email</label>
                        <input type="email" id="email" name="email" required value="<?= e($teacher['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" maxlength="20" value="<?= e($teacher['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group form-group--full">
                        <label for="position">Position</label>
                        <input type="text" id="position" name="position" maxlength="80"
                               placeholder="Teacher III" value="<?= e($teacher['position'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">Assignment</h2></div>
            <div class="card__body">
                <div class="form-group">
                    <label for="department_id" class="required">Department</label>
                    <select id="department_id" name="department_id" required>
                        <option value="">Select a department…</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e($department['department_id']) ?>"
                                <?= (int) ($teacher['department_id'] ?? 0) === (int) $department['department_id'] ? 'selected' : '' ?>>
                                <?= e($department['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-help">Determines which subjects can be assigned.</span>
                </div>

                <div class="form-group">
                    <label class="required">Assigned grade level(s)</label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:.25rem">
                        <?php foreach ($gradeLevels as $grade): ?>
                            <label class="checkbox">
                                <input type="checkbox" name="grade_level_ids[]" value="<?= e($grade['grade_level_id']) ?>"
                                    <?= in_array((int) $grade['grade_level_id'], $gradeLevelIds, true) ? 'checked' : '' ?>>
                                <span><?= e($grade['grade_level_name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="field-help">Determines which sections can be assigned. A teacher without one cannot be scheduled.</span>
                </div>

                <div class="form-group">
                    <label for="subject_ids">Subjects</label>
                    <select id="subject_ids" name="subject_ids[]" multiple size="7">
                        <option disabled>Select a department first</option>
                    </select>
                    <span class="field-help" id="subject-help"></span>
                </div>

                <div class="form-group">
                    <label for="section_ids">Sections</label>
                    <select id="section_ids" name="section_ids[]" multiple size="7">
                        <option disabled>Select grade level(s) first</option>
                    </select>
                    <span class="field-help" id="section-help"></span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$editing): ?>
        <div class="card">
            <div class="card__header"><h2 class="card__title">Web account</h2></div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" maxlength="32" autocomplete="off">
                        <span class="field-help" id="username-help">Leave blank to generate one from the name.</span>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-group">
                            <input type="text" id="password" name="password" autocomplete="new-password"
                                   placeholder="Leave blank to generate a strong one">
                            <button type="button" class="btn btn-secondary" id="generate-password">Generate</button>
                        </div>
                        <div class="strength"><div class="strength__bar" id="strength-bar"></div></div>
                        <span class="field-help">Shown once after registration, then unrecoverable.</span>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="checkbox">
                            <input type="checkbox" name="force_password_change" checked>
                            <span>Require a password change at first sign-in</span>
                        </label>
                    </div>
                </div>

                <div class="alert alert-warning" style="margin-bottom:0">
                    <span class="alert__icon"><i class="fa-solid fa-fingerprint"></i></span>
                    <div class="alert__body">
                        The account stays inactive until this teacher's fingerprint is enrolled on a
                        classroom terminal — attendance can never be opened without it.
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$editing): ?>
        <div class="card">
            <div class="card__header">
                <h2 class="card__title"><i class="fa-solid fa-fingerprint"></i> Fingerprint</h2>
                <span class="badge badge-warning" id="fp-badge">Required</span>
            </div>
            <div class="card__body">
                <p class="text-sm text-muted">
                    <strong>Last step.</strong> Fill in everything above first, then have the teacher
                    scan. Their print is saved with the record, and from then on placing that finger
                    on a terminal opens their attendance session.
                </p>
                <p class="text-sm text-muted">
                    It is taken before the record is created because a teacher without one cannot open
                    a single session — registering first would produce an account that exists and does
                    nothing.
                </p>

                <?php if ($devices === []): ?>
                    <div class="alert alert-danger mt-2">
                        <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <div class="alert__body">
                            <strong>No scanner is available, so no teacher can be registered.</strong>
                            A fingerprint has to be read by a fingerprint sensor, and none is activated yet.
                            The usual answer is an <strong>enrolment scanner</strong> — an ESP32 and R307 on
                            your desk, registered here as a scanner rather than a classroom terminal, so the
                            whole job happens at this computer. <a href="/admin/devices">Register one</a>,
                            then come back.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-grid mt-2">
                        <div class="form-group">
                            <label for="fp-device" class="required">Scanner</label>
                            <select id="fp-device">
                                <option value="">Select the scanner…</option>
                                <?php foreach ($devices as $device): ?>
                                <option value="<?= e($device['id']) ?>" data-health="<?= e($device['health'] ?? '') ?>">
                                    <?= $device['enrollment_station'] ? '🖐 ' : '' ?><?= e($device['device_id']) ?><?php
                                        ?><?= $device['enrollment_station']
                                            ? ' — enrolment scanner'
                                            : ($device['room_number'] ? ' — Room ' . e($device['room_number']) : '') ?><?php
                                        ?> (<?= e(device_health_label($device['health'] ?? '')) ?>)
                                </option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="align-self:end">
                            <button type="button" class="btn btn-primary" id="fp-start" disabled>
                                <i class="fa-solid fa-fingerprint"></i> Scan fingerprint
                            </button>
                            <span class="field-help text-warning" id="fp-gate"></span>
                        </div>
                    </div>

                    <div class="enrol-scan hidden" id="fp-scan">
                        <div class="enrol-scan__icon" id="fp-icon"><i class="fa-solid fa-fingerprint"></i></div>
                        <div class="enrol-scan__stage" id="fp-stage">Waiting for the terminal…</div>
                        <div class="enrol-scan__detail text-sm text-muted" id="fp-detail"></div>

                        <ol class="enrol-scan__steps" id="fp-steps">
                            <li data-stage="waiting_for_device">Terminal picks up the request</li>
                            <li data-stage="place_finger">Teacher places their finger</li>
                            <li data-stage="remove_finger">Teacher lifts their finger</li>
                            <li data-stage="place_again">Teacher places the same finger again</li>
                            <li data-stage="storing">Sensor stores the template</li>
                        </ol>

                        <button type="button" class="btn btn-ghost btn-sm mt-2 hidden" id="fp-restart">
                            <i class="fa-solid fa-rotate"></i> Scan again
                        </button>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="fingerprint_request_id" id="fp-request-id" value="">
            </div>
        </div>
    <?php endif; ?>

    <div class="flex gap-1 mb-3" style="justify-content:flex-end">
        <a class="btn btn-secondary" href="/admin/teachers">Cancel</a>
        <button type="submit" class="btn btn-primary" id="teacher-submit"
                <?= $editing ? '' : 'disabled' ?>>
            <i class="fa-solid fa-check"></i> <?= $editing ? 'Save changes' : 'Register teacher' ?>
        </button>
    </div>
    <?php if (!$editing): ?>
        <p class="text-xs text-muted" style="text-align:right;margin-top:-.6rem" id="submit-hint">
            Scan the fingerprint to enable this.
        </p>
    <?php endif; ?>
</form>

<div class="modal-backdrop" id="credentials-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header"><h3 class="modal__title"><i class="fa-solid fa-key"></i> Account credentials</h3></div>
        <div class="modal__body">
            <div class="credential-warning">
                <strong>Shown once only.</strong> Copy or print these now — the password is stored
                hashed and cannot be recovered, only reset.
            </div>
            <div class="credential-box">
                <div class="credential-box__label">Username</div>
                <button class="credential-box__copy" type="button" data-copy="#cred-username">Copy</button>
                <span id="cred-username"></span>
            </div>
            <div class="credential-box">
                <div class="credential-box__label">Password</div>
                <button class="credential-box__copy" type="button" data-copy="#cred-password">Copy</button>
                <span id="cred-password"></span>
            </div>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn-secondary" id="print-slip"><i class="fa-solid fa-print"></i> Print slip</button>
            <button type="button" class="btn btn-primary" id="credentials-done">I have saved these</button>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;
    const editing = <?= $editing ? 'true' : 'false' ?>;
    const teacherId = <?= (int) ($teacher['teacher_id'] ?? 0) ?>;
    const preselectedSubjects = <?= json_js($subjectIds) ?>;
    const preselectedSections = <?= json_js($sectionIds) ?>;

    let submitted = false;

    const department = document.getElementById('department_id');
    const subjects   = document.getElementById('subject_ids');
    const sections   = document.getElementById('section_ids');
    const form       = document.getElementById('teacher-form');

    /* Subjects follow the department; sections follow the grade levels. Both
       lists come from the server, which is also what re-validates them. */
    async function loadSubjects() {
        const departmentId = department.value;

        // An unsaved teacher has no id, so ask by department instead. Both
        // forms of the question are answered by the same endpoint, and the
        // server re-validates whatever comes back on submit either way.
        const query = teacherId
            ? { teacher_id: teacherId }
            : (departmentId ? { department_id: departmentId } : null);

        if (!query) {
            subjects.innerHTML = '<option disabled>Select a department first</option>';
            document.getElementById('subject-help').textContent =
                'Subjects follow the department — choose one above.';
            return;
        }

        subjects.innerHTML = '<option disabled>Loading…</option>';

        try {
            const response = await LS.http.get('/api/subjects/assignable', query);
            renderGrouped(subjects, response.data.grouped, 'subject_id', 'subject_code', 'subject_name', preselectedSubjects);
            document.getElementById('subject-help').textContent = response.data.helper_text;
        } catch (error) {
            subjects.innerHTML = '<option disabled>Could not load</option>';
            document.getElementById('subject-help').textContent =
                (error && error.message) || 'Could not load the subject list.';
        }
    }

    function checkedGradeLevels() {
        return Array.from(document.querySelectorAll('[name="grade_level_ids[]"]:checked'))
            .map((box) => box.value);
    }

    async function loadSections() {
        const grades = checkedGradeLevels();

        // Editing reads the saved grade levels through the teacher; creating
        // reads the boxes that are ticked right now, so the list keeps up as
        // they are ticked.
        const query = teacherId
            ? { teacher_id: teacherId }
            : (grades.length ? { grade_level_ids: grades } : null);

        if (!query) {
            sections.innerHTML = '<option disabled>Select grade level(s) first</option>';
            document.getElementById('section-help').textContent =
                'Sections follow the grade levels — tick at least one above.';
            return;
        }

        sections.innerHTML = '<option disabled>Loading…</option>';

        try {
            const response = await LS.http.get('/api/sections/assignable', query);
            renderGrouped(sections, response.data.grouped, 'section_id', 'section_code', 'section_name', preselectedSections);
            document.getElementById('section-help').textContent = response.data.helper_text;
        } catch (error) {
            sections.innerHTML = '<option disabled>Could not load</option>';
            document.getElementById('section-help').textContent =
                (error && error.message) || 'Could not load the section list.';
        }
    }

    function renderGrouped(select, grouped, valueKey, codeKey, nameKey, selected) {
        select.innerHTML = '';

        const groups = Object.keys(grouped || {});

        if (groups.length === 0) {
            select.innerHTML = '<option disabled>None available</option>';
            return;
        }

        groups.forEach((groupName) => {
            const optgroup = document.createElement('optgroup');
            optgroup.label = '── ' + groupName + ' ──';

            grouped[groupName].forEach((item) => {
                const option = document.createElement('option');
                option.value = item[valueKey];
                option.textContent = item[codeKey] + ' — ' + item[nameKey];
                option.selected = (selected || []).includes(Number(item[valueKey]));
                optgroup.appendChild(option);
            });

            select.appendChild(optgroup);
        });
    }

    department.addEventListener('change', loadSubjects);
    loadSubjects();
    loadSections();

    document.querySelectorAll('[name="grade_level_ids[]"]').forEach((box) => {
        box.addEventListener('change', loadSections);
    });

    /* ---- fingerprint, captured before the record exists --------------------- */
    const fpStart   = document.getElementById('fp-start');
    const submitBtn = document.getElementById('teacher-submit');

    if (fpStart) {
        const FP_STAGES = ['waiting_for_device', 'ready', 'place_finger', 'remove_finger',
                           'place_again', 'storing', 'done'];

        const fpScan    = document.getElementById('fp-scan');
        const fpRestart = document.getElementById('fp-restart');
        const fpBadge   = document.getElementById('fp-badge');
        const fpHidden  = document.getElementById('fp-request-id');
        const hint      = document.getElementById('submit-hint');

        let fpRequestId = null;
        let fpTimer     = null;

        function fpPaint(data) {
            document.getElementById('fp-stage').textContent =
                data.status === 'completed' ? 'Fingerprint captured'
                : data.status === 'failed'    ? 'Scan failed'
                : data.status === 'expired'   ? 'Timed out'
                : data.status === 'cancelled' || data.status === 'abandoned' ? 'Cancelled'
                : (data.stage === 'waiting_for_device' ? 'Waiting for the terminal…' : 'Scanning');

            /* A terminal whose sensor did not answer at boot heartbeats
               normally and looks online, so this dialog used to sit on
               "Waiting for the terminal…" until somebody gave up. It cannot
               ever be picked up, and the reason is knowable, so say it here
               rather than leaving a blank detail line under a spinner. */
            const sensorDead = !data.finished && data.terminal_sensor_ok === false;

            document.getElementById('fp-detail').textContent = sensorDead
                ? data.device_id + ' is online but its fingerprint sensor is not responding, '
                  + 'so this scan cannot start. Check the sensor wiring and reset the board, '
                  + 'or register the teacher on a terminal whose sensor works.'
                : (data.message || '');

            const reached = FP_STAGES.indexOf(data.stage);

            document.querySelectorAll('#fp-steps li').forEach((item) => {
                const at = FP_STAGES.indexOf(item.dataset.stage);
                item.classList.toggle('is-done', data.status === 'completed' || (reached > -1 && at < reached));
                item.classList.toggle('is-current', data.status === 'scanning' && at === reached);
            });

            document.getElementById('fp-icon').className = 'enrol-scan__icon'
                + (data.status === 'completed' ? ' is-success' : (data.finished ? ' is-error' : ' is-waiting'));
        }

        function fpStop() {
            if (fpTimer) { clearInterval(fpTimer); fpTimer = null; }
        }

        /* The capture is what makes the record usable, so the form stays shut
           until one exists. The server refuses without it either way; this is
           only so nobody fills in twenty fields to be told at the end. */
        function fpSetCaptured(requestId) {
            fpHidden.value = requestId || '';
            submitBtn.disabled = !requestId;

            fpBadge.textContent = requestId ? 'Captured' : 'Required';
            fpBadge.className   = 'badge ' + (requestId ? 'badge-success' : 'badge-warning');

            if (hint) hint.textContent = requestId
                ? 'Fingerprint captured — it is attached when you save.'
                : 'Scan the fingerprint to enable this.';
        }

        async function fpPoll() {
            if (!fpRequestId) return;

            try {
                const response = await LS.http.get('/admin/fingerprints/scan/' + fpRequestId, null, { passive: true });
                const data = response.data;

                fpPaint(data);

                if (!data.finished) return;

                fpStop();

                if (data.status === 'completed') {
                    fpSetCaptured(fpRequestId);
                    fpRestart.classList.remove('hidden');
                    LS.toast.success('Fingerprint captured. Finish the form to save it.');
                } else {
                    fpRequestId = null;
                    fpSetCaptured(null);
                    fpRestart.classList.remove('hidden');
                    LS.toast.warning(data.message || 'The scan did not complete.');
                }
            } catch (error) {
                fpStop();
                fpRequestId = null;
                fpSetCaptured(null);
                LS.toast.fromError(error);
            }
        }

        /* The details come first, then the finger. A capture taken before the
           form is filled in holds a sensor slot for half an hour and names the
           person "New teacher"; worse, it invites someone to scan, wander off
           filling in the rest, and find the capture timed out. */
        const REQUIRED = ['employee_number', 'first_name', 'last_name', 'email', 'department_id'];

        function missingDetails() {
            const missing = REQUIRED.filter((id) => {
                const field = document.getElementById(id);
                return !field || field.value.trim() === '';
            });

            if (document.querySelectorAll('[name="grade_level_ids[]"]:checked').length === 0) {
                missing.push('grade_level_ids');
            }

            return missing;
        }

        function labelFor(id) {
            const field = document.getElementById(id);
            const label = field && field.closest('.form-group')
                ? field.closest('.form-group').querySelector('label')
                : null;

            return label ? label.textContent.replace('*', '').trim() : id;
        }

        function refreshScanGate() {
            const missing = missingDetails();

            fpStart.disabled = missing.length > 0;

            const gate = document.getElementById('fp-gate');

            if (!gate) return;

            gate.textContent = missing.length === 0
                ? ''
                : 'Fill in ' + (missing.includes('grade_level_ids')
                    ? missing.filter((m) => m !== 'grade_level_ids').map(labelFor).concat('at least one grade level').join(', ')
                    : missing.map(labelFor).join(', '))
                  + ' before scanning.';
        }

        form.addEventListener('input', refreshScanGate);
        form.addEventListener('change', refreshScanGate);
        refreshScanGate();

        async function fpBegin() {
            const missing = missingDetails();

            if (missing.length > 0) {
                LS.toast.warning('Fill in the teacher\'s details first — the fingerprint is the last step.');
                return;
            }

            const deviceId = document.getElementById('fp-device').value;

            if (!deviceId) {
                LS.toast.warning('Choose the terminal the teacher is standing at.');
                return;
            }

            LS.util.setBusy(fpStart, true, 'Asking the terminal…');
            fpRestart.classList.add('hidden');
            fpSetCaptured(null);

            const name = (document.getElementById('first_name').value + ' '
                        + document.getElementById('last_name').value).trim();

            try {
                const response = await LS.http.post('/admin/fingerprints/scan/registration', {
                    name: name,
                    device_row_id: deviceId,
                });

                fpRequestId = response.data.request_id;
                fpScan.classList.remove('hidden');
                fpPaint(response.data);
                LS.toast.info(response.message);

                fpStop();
                fpTimer = setInterval(fpPoll, 1500);
            } catch (error) {
                LS.toast.fromError(error);
            } finally {
                LS.util.setBusy(fpStart, false);
            }
        }

        fpStart.addEventListener('click', fpBegin);
        fpRestart.addEventListener('click', fpBegin);

        /* Leaving without saving means the template sits in the sensor holding
           a slot nothing owns, so the server is told to reclaim it. */
        window.addEventListener('beforeunload', () => {
            if (fpRequestId && !submitted && navigator.sendBeacon) {
                const body = new FormData();
                body.append('_csrf', LS.config.csrfToken);
                navigator.sendBeacon('/admin/fingerprints/scan/' + fpRequestId + '/cancel', body);
            }
        });
    }

    const generate = document.getElementById('generate-password');

    if (generate) {
        generate.addEventListener('click', async function () {
            try {
                const response = await LS.http.get('/admin/users/generate-password');
                document.getElementById('password').value = response.data.password;
                document.getElementById('strength-bar').style.width = '100%';
                document.getElementById('strength-bar').style.background = 'var(--success)';
            } catch (error) {
                LS.toast.fromError(error);
            }
        });
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        const data = LS.util.formData(form);

        try {
            const response = editing
                ? await LS.http.put('/admin/teachers/' + teacherId, data)
                : await LS.http.post('/admin/teachers', data);

            submitted = true;
            LS.drafts.clear(form);

            if (!editing && response.data.password) {
                document.getElementById('cred-username').textContent = response.data.username;
                document.getElementById('cred-password').textContent = response.data.password;
                window.__credentialSlip = response.data.credential_slip;
                window.__redirect = response.data.redirect;
                LS.modal.open('credentials-modal');
            } else {
                LS.toast.success(response.message);
                setTimeout(() => { window.location.href = '/admin/teachers/' + teacherId; }, 700);
            }
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);

            // A department or grade-level change that would break existing
            // schedules comes back as a cascade code; confirm and resend.
            if (error.code === 'DEPARTMENT_CHANGE_CASCADE' || error.code === 'GRADE_LEVEL_CHANGE_CASCADE') {
                const result = await LS.modal.confirm({
                    title: 'This change affects existing assignments',
                    message: error.message + ' Affected schedules will be archived; their attendance history is kept.',
                    confirmLabel: 'Continue anyway',
                    danger: true,
                });

                if (result) {
                    data.confirm_cascade = true;
                    try {
                        const retry = await LS.http.put('/admin/teachers/' + teacherId, data);
                        LS.toast.success(retry.message);
                        setTimeout(() => window.location.reload(), 700);
                    } catch (retryError) {
                        LS.toast.fromError(retryError);
                    }
                }
            } else {
                LS.toast.fromError(error);
            }
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    const done = document.getElementById('credentials-done');

    if (done) {
        done.addEventListener('click', () => {
            document.getElementById('cred-password').textContent = '';
            window.location.href = window.__redirect || '/admin/teachers';
        });
    }

    const print = document.getElementById('print-slip');

    if (print) {
        print.addEventListener('click', () => {
            const win = window.open('', '_blank', 'width=460,height=620');
            win.document.write('<pre style="font-family:monospace;font-size:12px">'
                + LS.util.escape(window.__credentialSlip || '') + '</pre>');
            win.document.close();
            win.print();
        });
    }
})();
</script>
<?php $__view->stop(); ?>
