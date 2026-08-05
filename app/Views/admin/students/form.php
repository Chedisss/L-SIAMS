<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$editing = $student !== null;
$action  = $editing ? '/admin/students/' . $student['student_id'] : '/admin/students';
?>

<?php $__view->include('partials.page-header', [
    'title'       => $editing ? 'Edit Student' : 'Add Student',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Students', '/admin/students'], [$editing ? 'Edit' : 'Add', null]],
]); ?>

<form method="post" action="<?= e($action) ?>" data-draft="student-<?= e($editing ? $student['student_id'] : 'new') ?>" novalidate>
    <?= csrf_field() ?>
    <?php if ($editing): ?><?= method_field('PUT') ?><?php endif; ?>

    <div class="grid grid--2">
        <div class="card">
            <div class="card__header"><h2 class="card__title">Identity</h2></div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="student_number" class="required">Student number</label>
                        <input type="text" id="student_number" name="student_number" required maxlength="30"
                               value="<?= e(old('student_number', $student['student_number'] ?? '')) ?>"
                               class="<?= field_error('student_number') ? 'is-invalid' : '' ?>">
                        <?php if ($m = field_error('student_number')): ?><span class="field-error"><?= e($m) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= ($student['status'] ?? 'active') === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="first_name" class="required">First name</label>
                        <input type="text" id="first_name" name="first_name" required maxlength="60"
                               value="<?= e(old('first_name', $student['first_name'] ?? '')) ?>">
                        <?php if ($m = field_error('first_name')): ?><span class="field-error"><?= e($m) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="middle_name">Middle name</label>
                        <input type="text" id="middle_name" name="middle_name" maxlength="60"
                               value="<?= e(old('middle_name', $student['middle_name'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name" class="required">Last name</label>
                        <input type="text" id="last_name" name="last_name" required maxlength="60"
                               value="<?= e(old('last_name', $student['last_name'] ?? '')) ?>">
                        <?php if ($m = field_error('last_name')): ?><span class="field-error"><?= e($m) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="suffix">Suffix</label>
                        <input type="text" id="suffix" name="suffix" maxlength="10" placeholder="Jr., III"
                               value="<?= e(old('suffix', $student['suffix'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <?php foreach (['Male', 'Female', 'Other', 'Prefer not to say'] as $option): ?>
                                <option value="<?= e($option) ?>" <?= ($student['gender'] ?? '') === $option ? 'selected' : '' ?>>
                                    <?= e($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="birthdate">Birthdate</label>
                        <input type="date" id="birthdate" name="birthdate"
                               value="<?= e(old('birthdate', $student['birthdate'] ?? '')) ?>">
                    </div>

                    <div class="form-group form-group--full">
                        <label for="email">Email <span class="label__hint">optional</span></label>
                        <input type="email" id="email" name="email"
                               value="<?= e(old('email', $student['email'] ?? '')) ?>">
                    </div>

                    <div class="form-group form-group--full">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" maxlength="255"
                               value="<?= e(old('address', $student['address'] ?? '')) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title">Placement</h2>
                </div>
                <div class="card__body">
                    <?php if ($editing): ?>
                        <div class="alert alert-info">
                            <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="alert__body">
                                This student is in <strong><?= e($student['section_code']) ?></strong>
                                (<?= e($student['grade_level_name']) ?>).
                                Moving them is a <strong>transfer</strong>, not an edit — it needs a reason
                                and an effective date, and it never rewrites past attendance.
                                <div class="mt-1">
                                    <a class="btn btn-secondary btn-sm" href="/admin/students/<?= e($student['student_id']) ?>">
                                        Transfer this student
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-sm">
                            A student's grade level is derived from their section, so choosing the section
                            is what places them. The server re-checks that the pair actually match.
                        </p>

                        <div class="form-group">
                            <label for="grade_level_id" class="required">Grade level</label>
                            <select id="grade_level_id" name="grade_level_id" required>
                                <option value="">Select a grade level…</option>
                                <?php foreach ($gradeLevels as $grade): ?>
                                    <option value="<?= e($grade['grade_level_id']) ?>">
                                        <?= e($grade['grade_level_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="section_id" class="required">Section</label>
                            <select id="section_id" name="section_id" required disabled>
                                <option value="">Select a grade level first</option>
                            </select>
                            <span class="field-help" id="section-help"></span>
                            <?php if ($m = field_error('section_id')): ?><span class="field-error"><?= e($m) ?></span><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="card_uid">RFID card UID <span class="label__hint">optional</span></label>
                            <input type="text" id="card_uid" name="card_uid" maxlength="32"
                                   placeholder="Tap a card on the enrolment reader" style="font-family:var(--mono)">
                            <span class="field-help">Can be assigned later from the RFID Cards page.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h2 class="card__title">Guardian</h2></div>
                <div class="card__body">
                    <div class="form-group">
                        <label for="guardian_name" class="required">Guardian name</label>
                        <input type="text" id="guardian_name" name="guardian_name" required maxlength="150"
                               value="<?= e(old('guardian_name', $student['guardian_name'] ?? '')) ?>">
                        <?php if ($m = field_error('guardian_name')): ?><span class="field-error"><?= e($m) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="guardian_contact" class="required">Contact number</label>
                        <input type="tel" id="guardian_contact" name="guardian_contact" required maxlength="20"
                               value="<?= e(old('guardian_contact', $student['guardian_contact'] ?? '')) ?>">
                        <?php if ($m = field_error('guardian_contact')): ?><span class="field-error"><?= e($m) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="guardian_email">Guardian email</label>
                        <input type="email" id="guardian_email" name="guardian_email"
                               value="<?= e(old('guardian_email', $student['guardian_email'] ?? '')) ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex gap-1 justify-between mb-3" style="justify-content:flex-end">
        <a class="btn btn-secondary" href="/admin/students">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i> <?= $editing ? 'Save changes' : 'Register student' ?>
        </button>
    </div>
</form>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
(function () {
    const grade   = document.getElementById('grade_level_id');
    const section = document.getElementById('section_id');
    const help    = document.getElementById('section-help');

    if (!grade || !section) return;

    // Sections are filtered by grade level here purely for convenience — the
    // server re-validates that the section really belongs to the grade level,
    // so a tampered form gains nothing.
    const sections = <?= json_attr(array_map(static fn (array $s): array => [
        'id'    => (int) $s['section_id'],
        'grade' => (int) $s['grade_level_id'],
        'code'  => (string) $s['section_code'],
        'name'  => (string) $s['section_name'],
        'free'  => (int) $s['capacity'] - (int) $s['enrolled_count'],
    ], $sections)) ?>;

    grade.addEventListener('change', function () {
        const gradeId = parseInt(this.value, 10);

        section.innerHTML = '<option value="">Select a section…</option>';

        const matching = sections.filter((s) => s.grade === gradeId);

        if (matching.length === 0) {
            section.innerHTML = '<option value="">No sections for this grade level</option>';
            section.disabled = true;
            help.textContent = 'Create a section for this grade level before enrolling students.';
            help.className = 'field-help text-warning';
            return;
        }

        matching.forEach((s) => {
            const option = document.createElement('option');
            option.value = s.id;
            option.textContent = s.code + ' — ' + s.name + '  (' + s.free + ' place(s) free)';
            if (s.free <= 0) {
                option.disabled = true;
                option.textContent += '  — full';
            }
            section.appendChild(option);
        });

        section.disabled = false;
        help.textContent = 'Showing sections for the selected grade level only.';
        help.className = 'field-help';
    });
})();
</script>
<?php $__view->stop(); ?>
