<?php
/**
 * Opening the class that is inside its attendance window right now.
 *
 * Rendered inside the "Current class" card, in place of the empty state, so
 * the first heading a teacher reads is also where they can act. It carries two
 * routes into the same session:
 *
 *   - the fingerprint, watched rather than driven. Nothing here tells the
 *     terminal to scan; it already is. What this adds is sight of the result,
 *     which until now existed only in the terminal's serial log — a beep the
 *     person at a computer cannot hear.
 *
 *   - the password failover, folded away behind one click, for a finger the
 *     reader will not read or a reader that has failed outright.
 *
 * @var App\Core\View $__view
 * @var array<string,mixed> $liveClass
 */

$terminalDown = ($liveClass['device_id'] ?? null) === null
    || (string) ($liveClass['device_status'] ?? '') !== 'active';
?>

<div class="enrol-scan">
    <?php /*
        Which class this is about, stated once and never touched by the
        polling script. The heading above says "Current class" and the lines
        below are a running commentary on the scan, so without this the
        teacher is looking at a password form with no indication of what it
        would open — and a teacher with two classes back to back has a real
        question there.
    */ ?>
    <div class="enrol-scan__subject">
        <strong><?= e($liveClass['subject_code']) ?></strong> with
        <strong><?= e($liveClass['section_code']) ?></strong>
        · Room <?= e($liveClass['room_number']) ?>
        · <span class="mono"><?= e(substr((string) $liveClass['start_time'], 0, 5)) ?>–<?= e(substr((string) $liveClass['end_time'], 0, 5)) ?></span>
    </div>

    <div class="enrol-scan__icon is-waiting" id="ss-icon"><i class="fa-solid fa-fingerprint"></i></div>
    <div class="enrol-scan__stage" id="ss-stage">Ready when you are</div>
    <div class="enrol-scan__detail text-sm text-muted" id="ss-detail">
        Scan your fingerprint on the terminal in Room <?= e($liveClass['room_number']) ?>.
    </div>

    <ol class="enrol-scan__steps" id="ss-steps">
        <li data-stage="window">Class window is open</li>
        <li data-stage="scan">Scan your fingerprint at the terminal</li>
        <li data-stage="open">Session opens and students may tap</li>
    </ol>

    <div class="alert alert-warning mt-2 hidden" id="ss-warning">
        <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <div class="alert__body" id="ss-warning-text"></div>
    </div>

    <div class="flex gap-1 mt-2" style="justify-content:center">
        <button class="btn btn-primary" id="ss-watch">
            <i class="fa-solid fa-eye"></i> I am scanning now
        </button>
        <a class="btn btn-secondary hidden" id="ss-goto" href="/teacher/sessions">
            <i class="fa-solid fa-arrow-right"></i> Open the session
        </a>
    </div>

    <div class="text-xs text-muted mt-2">
        Terminal <?= e($liveClass['device_id'] ?? 'not assigned') ?>
        · scan window <?= e(substr((string) $liveClass['scan_opens'], 0, 5)) ?>–<?= e(substr((string) $liveClass['scan_closes'], 0, 5)) ?>
    </div>
</div>

<?php if ($terminalDown): ?>
    <?php /*
        Said plainly, because the failover below will happily open a session
        into a room whose reader is not answering — and it should, since the
        session has to exist before the terminal reconnects and syncs its
        queued taps. What must not happen is a teacher opening it, seeing
        "Session open", and assuming the class is being recorded.
    */ ?>
    <div class="alert alert-warning mt-2">
        <?php /* icons.css carries a hand-drawn subset, not all of Font Awesome —
                 fa-plug-circle-exclamation is not in it and renders as a blank box. */ ?>
        <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <div class="alert__body">
            <div class="alert__title">The terminal in Room <?= e($liveClass['room_number']) ?> is not reporting in</div>
            You can still open the session, and any taps the terminal has queued will sync when it
            reconnects — but until it does, students tapping their cards will not be recorded.
        </div>
    </div>
<?php endif; ?>

<?php /*
    The failover.

    A fingerprint is the right primary control and the wrong only control. Wet
    hands, a cut, a burn, a plaster, a reader that died overnight — any one of
    them used to end with a full class whose attendance was never recorded,
    because there was no second way in.

    It stays folded away behind one click. The scan is what should happen, and
    a button of equal weight beside it would invite teachers to skip the reader
    on an ordinary morning.
*/ ?>
<div class="session-fallback" id="ss-fallback">
    <button type="button" class="btn btn-ghost btn-sm" id="ss-fallback-toggle"
            aria-expanded="false" aria-controls="ss-fallback-form">
        <i class="fa-solid fa-key"></i> The reader will not read my finger
    </button>

    <form class="session-fallback__form hidden" id="ss-fallback-form" autocomplete="off" novalidate>
        <p class="text-sm">
            Open <strong><?= e($liveClass['subject_code']) ?></strong> with
            <strong><?= e($liveClass['section_code']) ?></strong> using your account
            password instead of a scan. Everything else is unchanged: students still
            tap their cards on the terminal in Room <?= e($liveClass['room_number']) ?>.
        </p>
        <p class="text-xs text-muted">
            The session is recorded as opened without a fingerprint, and an
            administrator is notified. Use it when the reader cannot read you —
            not instead of it.
        </p>

        <div class="form-group">
            <label for="ss-password" class="required">Password</label>
            <input type="password" id="ss-password" name="password"
                   autocomplete="current-password" required>
        </div>

        <div class="form-group">
            <label for="ss-password-confirm" class="required">Confirmation of password</label>
            <input type="password" id="ss-password-confirm" name="password_confirmation"
                   autocomplete="current-password" required>
        </div>

        <div class="alert alert-danger hidden" id="ss-fallback-error">
            <span class="alert__icon"><i class="fa-solid fa-circle-exclamation"></i></span>
            <div class="alert__body" id="ss-fallback-error-text"></div>
        </div>

        <button type="submit" class="btn btn-warning btn-block" id="ss-fallback-submit">
            <i class="fa-solid fa-door-open"></i>
            Start the session without scanning
        </button>
    </form>
</div>
