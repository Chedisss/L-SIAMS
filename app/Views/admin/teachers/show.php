<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => trim($teacher['first_name'] . ' ' . $teacher['last_name']),
    'subtitle'    => $teacher['employee_number'] . ' · ' . $teacher['department_name'],
    'breadcrumbs' => [['Dashboard', '/admin'], ['Teachers', '/admin/teachers'], [$teacher['employee_number'], null]],
    'actions'     => '<button class="btn btn-secondary" id="reset-password"><i class="fa-solid fa-key"></i> Reset password</button>'
        . '<a class="btn btn-primary" href="/admin/teachers/' . (int) $teacher['teacher_id'] . '/edit"><i class="fa-solid fa-pen"></i> Edit</a>',
]); ?>

<div class="grid grid--3">
    <div class="card">
        <div class="card__body text-center">
            <?php if (!empty($teacher['photo_path'])): ?>
                <img class="avatar avatar--lg" src="/uploads/<?= e($teacher['photo_path']) ?>" alt="" style="margin:0 auto .75rem">
            <?php else: ?>
                <span class="avatar avatar--lg" style="margin:0 auto .75rem"><?= e(initials($teacher['first_name'] . ' ' . $teacher['last_name'])) ?></span>
            <?php endif; ?>
            <h3 style="margin-bottom:.15rem"><?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?></h3>
            <div class="text-muted text-sm"><?= e($teacher['position'] ?? 'Teacher') ?></div>
            <div class="mt-2">
                <span class="badge <?= e(status_badge($teacher['status'])) ?>"><?= e(ucfirst((string) $teacher['status'])) ?></span>
                <span class="badge badge-primary"><?= e($teacher['department_code']) ?></span>
            </div>
            <div class="mt-3 text-left">
                <?php foreach ([
                    'Email'      => $teacher['email'],
                    'Phone'      => $teacher['phone'] ?? '—',
                    'Username'   => $teacher['username'] ?? 'no account',
                    'Last sign-in' => time_ago($teacher['last_login_at']),
                    'Grade levels' => implode(', ', array_map(static fn (array $g): string => (string) $g['grade_level_code'], $constraints['grade_levels'])) ?: 'none',
                ] as $label => $value): ?>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted"><?= e($label) ?></span>
                        <span class="text-sm"><?= e($value) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">Fingerprint</h2></div>
        <div class="card__body">
            <?php if ($teacher['fingerprint_status'] !== 'enrolled'): ?>
                <div class="alert alert-warning" style="margin:0">
                    <span class="alert__icon"><i class="fa-solid fa-fingerprint"></i></span>
                    <div class="alert__body">
                        Not enrolled. This teacher cannot open an attendance session — the fingerprint
                        is the only way to start one.
                        <div class="mt-1"><a class="btn btn-primary btn-sm" href="/admin/fingerprints">Enrol now</a></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid" style="grid-template-columns:repeat(2,1fr);gap:.5rem">
                    <?php foreach ([
                        ['Sensor slot', $teacher['sensor_template_id']],
                        ['Enrolled', format_date($teacher['enrollment_date'])],
                        ['Verifications', $teacher['verification_count']],
                        ['Last verified', time_ago($teacher['last_verified_at'])],
                    ] as [$label, $value]): ?>
                        <div style="padding:.5rem .65rem;background:var(--surface-alt);border-radius:var(--radius-sm)">
                            <div class="text-xs text-muted"><?= e($label) ?></div>
                            <div class="fw-600"><?= e($value ?? '—') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">Assignment constraints</h2></div>
        <div class="card__body">
            <p class="text-sm text-muted">
                What this teacher may be assigned, derived from their department and grade levels.
            </p>
            <div class="grid" style="grid-template-columns:repeat(2,1fr);gap:.5rem">
                <?php foreach ([
                    ['Assignable subjects', $constraints['assignable_subject_count']],
                    ['Assignable sections', $constraints['assignable_section_count']],
                    ['Active schedules', $constraints['active_schedule_count']],
                    ['Grade levels', count($constraints['grade_levels'])],
                ] as [$label, $value]): ?>
                    <div style="padding:.5rem .65rem;background:var(--surface-alt);border-radius:var(--radius-sm)">
                        <div class="text-xs text-muted"><?= e($label) ?></div>
                        <div class="fw-600" style="font-size:19px"><?= e($value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$constraints['has_grade_level']): ?>
                <div class="alert alert-warning mt-2" style="margin-bottom:0">
                    <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div class="alert__body">No grade level assigned — this teacher cannot be scheduled.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Schedule</h2></div>
        <div class="card__body--flush">
            <?php if ($schedules === []): ?>
                <?php $__view->include('partials.empty-state', ['icon' => 'fa-calendar-days', 'title' => 'No classes assigned']); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Section</th><th>Room</th></tr></thead>
                        <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td><span class="badge badge-neutral"><?= e(substr((string) $schedule['day_of_week'], 0, 3)) ?></span></td>
                                <td class="nowrap mono text-sm"><?= e(substr((string) $schedule['start_time'], 0, 5)) ?>–<?= e(substr((string) $schedule['end_time'], 0, 5)) ?></td>
                                <td class="cell-primary"><?= e($schedule['subject_code']) ?></td>
                                <td><span class="badge badge-primary"><?= e($schedule['section_code']) ?></span></td>
                                <td class="text-sm"><?= e($schedule['room_number']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">Recent activity</h2></div>
        <div class="card__body--flush" style="max-height:420px;overflow-y:auto">
            <?php foreach ($fingerprintLogs as $log): ?>
                <div class="live-row">
                    <span class="live-row__arrow <?= $log['result'] === 'verified' ? 'text-success' : 'text-danger' ?>">
                        <i class="fa-solid fa-fingerprint"></i>
                    </span>
                    <span class="live-row__body">
                        <span class="live-row__name"><?= e(ucfirst(str_replace('_', ' ', (string) $log['result']))) ?></span>
                        <span class="live-row__meta"><?= e($log['room_number'] ? 'Room ' . $log['room_number'] : ($log['device_id'] ?? '')) ?></span>
                    </span>
                    <span class="live-row__time"><?= e(time_ago($log['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
            <?php foreach ($loginHistory as $login): ?>
                <div class="live-row">
                    <span class="live-row__arrow <?= $login['status'] === 'success' ? 'text-success' : 'text-muted' ?>">
                        <i class="fa-solid fa-right-to-bracket"></i>
                    </span>
                    <span class="live-row__body">
                        <span class="live-row__name"><?= e(ucfirst(str_replace('_', ' ', (string) $login['status']))) ?></span>
                        <span class="live-row__meta"><?= e($login['ip_address']) ?> · <?= e($login['browser'] ?? '') ?></span>
                    </span>
                    <span class="live-row__time"><?= e(time_ago($login['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($fingerprintLogs === [] && $loginHistory === []): ?>
                <div class="text-muted text-sm" style="padding:1rem">No activity recorded yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('reset-password').addEventListener('click', async function () {
    const LS = window.LSIAMS;

    const result = await LS.modal.confirm({
        title: 'Reset password?',
        message: 'A new temporary password is issued and every session for this teacher is signed out '
               + 'immediately. They will be asked to choose a new password at next sign-in.',
        confirmLabel: 'Reset password',
        requirePassword: true,
    });

    if (!result) return;

    try {
        const response = await LS.http.post('/admin/teachers/<?= (int) $teacher['teacher_id'] ?>/reset-password', {
            confirm_password: result.password,
        });

        await LS.modal.confirm({
            title: 'New credentials',
            message: 'Username: ' + response.data.username + '\nPassword: ' + response.data.password
                   + '\n\nThis is the only time it is shown.',
            confirmLabel: 'I have saved it',
            cancelLabel: 'Close',
        });
    } catch (error) {
        LS.toast.fromError(error);
    }
});
</script>
<?php $__view->stop(); ?>
