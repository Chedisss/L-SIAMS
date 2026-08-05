<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.auth');
$__view->start('content');
?>

<h2 class="auth-card__title">Forgotten password</h2>
<p class="auth-card__subtitle">
    Enter your registered email address. An administrator will be notified to arrange a reset in person.
</p>

<div class="alert alert-info">
    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
    <div class="alert__body">
        L-SIAMS runs on the school network with no internet access, so reset links cannot be emailed.
        Your administrator will issue new credentials directly.
    </div>
</div>

<form method="post" action="/forgot-password" novalidate>
    <?= csrf_field() ?>

    <div class="form-group">
        <label for="email" class="required">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="email" required autofocus
               class="<?= field_error('email') ? 'is-invalid' : '' ?>">
        <?php if ($message = field_error('email')): ?>
            <span class="field-error"><?= e($message) ?></span>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2">
        <i class="fa-solid fa-paper-plane"></i> Request a reset
    </button>
</form>

<div class="text-center mt-3">
    <a href="/login" class="text-sm">Back to sign in</a>
</div>

<?php $__view->stop(); ?>
