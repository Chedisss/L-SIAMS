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

    <div class="flex gap-1 mb-3" style="justify-content:flex-end">
        <a class="btn btn-secondary" href="/admin/teachers">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i> <?= $editing ? 'Save changes' : 'Register teacher' ?>
        </button>
    </div>
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
<script>
(function () {
    const LS = window.LSIAMS;
    const editing = <?= $editing ? 'true' : 'false' ?>;
    const teacherId = <?= (int) ($teacher['teacher_id'] ?? 0) ?>;
    const preselectedSubjects = <?= json_attr($subjectIds) ?>;
    const preselectedSections = <?= json_attr($sectionIds) ?>;

    const department = document.getElementById('department_id');
    const subjects   = document.getElementById('subject_ids');
    const sections   = document.getElementById('section_ids');
    const form       = document.getElementById('teacher-form');

    /* Subjects follow the department; sections follow the grade levels. Both
       lists come from the server, which is also what re-validates them. */
    async function loadSubjects() {
        if (!editing) {
            const departmentId = department.value;
            if (!departmentId) {
                subjects.innerHTML = '<option disabled>Select a department first</option>';
                return;
            }
        }

        subjects.innerHTML = '<option disabled>Loading…</option>';

        try {
            const url = editing || teacherId
                ? '/api/subjects/assignable?teacher_id=' + teacherId
                : null;

            if (!url) {
                // A new teacher has no id yet, so filter the full subject list
                // by the chosen department locally; the server still enforces it.
                subjects.innerHTML = '<option disabled>Save the teacher to assign subjects</option>';
                document.getElementById('subject-help').textContent =
                    'Subjects can be assigned once the teacher exists — their department decides which are available.';
                return;
            }

            const response = await LS.http.get(url);
            renderGrouped(subjects, response.data.grouped, 'subject_id', 'subject_code', 'subject_name', preselectedSubjects);
            document.getElementById('subject-help').textContent = response.data.helper_text;
        } catch (error) {
            subjects.innerHTML = '<option disabled>Could not load</option>';
        }
    }

    async function loadSections() {
        if (!teacherId) {
            sections.innerHTML = '<option disabled>Save the teacher to assign sections</option>';
            document.getElementById('section-help').textContent =
                'Sections can be assigned once the teacher exists — their grade levels decide which are available.';
            return;
        }

        sections.innerHTML = '<option disabled>Loading…</option>';

        try {
            const response = await LS.http.get('/api/sections/assignable', { teacher_id: teacherId });
            renderGrouped(sections, response.data.grouped, 'section_id', 'section_code', 'section_name', preselectedSections);
            document.getElementById('section-help').textContent = response.data.helper_text;
        } catch (error) {
            sections.innerHTML = '<option disabled>Could not load</option>';
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
        box.addEventListener('change', () => { if (teacherId) loadSections(); });
    });

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
