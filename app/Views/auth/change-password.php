<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.auth');
$__view->start('content');
?>

<h2 class="auth-card__title"><?= $forced ? 'Choose a new password' : 'Change password' ?></h2>
<p class="auth-card__subtitle">
    <?= $forced
        ? 'Your account was created with a temporary password. Choose your own before continuing.'
        : 'Changing your password signs out all your other sessions.' ?>
</p>

<form method="post" action="/password/change" novalidate id="password-form">
    <?= csrf_field() ?>

    <?php if (!$forced): ?>
        <div class="form-group">
            <label for="current_password" class="required">Current password</label>
            <input type="password" id="current_password" name="current_password"
                   autocomplete="current-password" required
                   class="<?= field_error('current_password') ? 'is-invalid' : '' ?>">
            <?php if ($message = field_error('current_password')): ?>
                <span class="field-error"><?= e($message) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="form-group">
        <label for="password" class="required">New password</label>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required minlength="12"
               class="<?= field_error('password') ? 'is-invalid' : '' ?>">

        <div class="strength"><div class="strength__bar" id="strength-bar"></div></div>
        <span class="strength__label" id="strength-label">
            At least <?= e(config('security.password.min_length', 12)) ?> characters, with upper and lower case, a number and a symbol.
        </span>

        <?php if ($message = field_error('password')): ?>
            <span class="field-error"><?= e($message) ?></span>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label for="password_confirmation" class="required">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required>
        <span class="field-error hidden" id="match-error">Passwords do not match.</span>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg mt-2" id="submit-button">
        <i class="fa-solid fa-key"></i> Update password
    </button>
</form>

<?php if (!$forced): ?>
    <div class="text-center mt-3">
        <a href="/profile" class="text-sm">Back to my profile</a>
    </div>
<?php endif; ?>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
    (function () {
        const password = document.getElementById('password');
        const confirm  = document.getElementById('password_confirmation');
        const bar      = document.getElementById('strength-bar');
        const label    = document.getElementById('strength-label');
        const matchErr = document.getElementById('match-error');
        const form     = document.getElementById('password-form');

        // Local scoring only — the real policy is enforced server-side and this
        // meter is advisory feedback while typing.
        function score(value) {
            let points = Math.min(40, value.length * 3);
            if (/[A-Z]/.test(value)) points += 12;
            if (/[a-z]/.test(value)) points += 12;
            if (/\d/.test(value))    points += 12;
            if (/[^A-Za-z0-9]/.test(value)) points += 14;
            if (/(.)\1{2,}/.test(value)) points -= 10;
            const unique = new Set(value).size;
            points += value.length ? Math.round((unique / value.length) * 10) : 0;
            return Math.max(0, Math.min(100, points));
        }

        password.addEventListener('input', function () {
            const value = this.value;
            const points = score(value);

            bar.style.width = points + '%';
            bar.style.background = points < 40 ? 'var(--danger)'
                : points < 70 ? 'var(--warning)' : 'var(--success)';

            label.textContent = value === ''
                ? 'At least 12 characters, with upper and lower case, a number and a symbol.'
                : points < 40 ? 'Weak — add length and variety.'
                : points < 70 ? 'Fair — a few more characters would help.'
                : 'Strong password.';
        });

        function checkMatch() {
            const mismatch = confirm.value !== '' && password.value !== confirm.value;
            matchErr.classList.toggle('hidden', !mismatch);
            confirm.classList.toggle('is-invalid', mismatch);
            return !mismatch;
        }

        confirm.addEventListener('input', checkMatch);

        form.addEventListener('submit', function (event) {
            if (!checkMatch()) {
                event.preventDefault();
                confirm.focus();
            }
        });
    })();
</script>
<?php $__view->stop(); ?>
