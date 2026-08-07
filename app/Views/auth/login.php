<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.auth');
$__view->start('content');
?>

<h2 class="auth-card__title">Sign in</h2>
<p class="auth-card__subtitle">Use your L-SIAMS staff account.</p>

<?php if (!empty($banner)): ?>
    <div class="alert alert-info" role="status">
        <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
        <div class="alert__body"><?= e($banner) ?></div>
    </div>
<?php endif; ?>

<form method="post" action="/login" novalidate>
    <?= csrf_field() ?>

    <div class="form-group">
        <label for="username" class="required">Username or email</label>
        <input type="text" id="username" name="username"
               value="<?= e(old('username')) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               required autofocus
               class="<?= field_error('username') ? 'is-invalid' : '' ?>">
        <?php if ($message = field_error('username')): ?>
            <span class="field-error"><?= e($message) ?></span>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label for="password" class="required">Password</label>
        <div style="position:relative">
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required
                   class="<?= field_error('password') ? 'is-invalid' : '' ?>"
                   style="padding-right:2.5rem">
            <button type="button" class="icon-button" id="toggle-password"
                    style="position:absolute;right:2px;top:50%;transform:translateY(-50%)"
                    aria-label="Show password" tabindex="-1">
                <i class="fa-solid fa-eye"></i>
            </button>
        </div>
        <?php if ($message = field_error('password')): ?>
            <span class="field-error"><?= e($message) ?></span>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg mt-2">
        <i class="fa-solid fa-right-to-bracket"></i> Sign in
    </button>
</form>

<div class="text-center mt-3">
    <a href="/forgot-password" class="text-sm">Forgotten your password?</a>
</div>

<p class="text-subtle text-xs text-center mt-3 mb-0">
    Students do not sign in. Attendance is recorded with an RFID card at the classroom terminal.
</p>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
    document.getElementById('toggle-password').addEventListener('click', function () {
        const field = document.getElementById('password');
        const icon  = this.querySelector('i');
        const show  = field.type === 'password';

        field.type = show ? 'text' : 'password';
        icon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
</script>
<?php $__view->stop(); ?>
