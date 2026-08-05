<?php
/**
 * Idle-timeout warning (Part 18.3).
 *
 * Appears 60 seconds before the server-side deadline. Dismissible only by the
 * two buttons — clicking the backdrop does nothing, because silently dismissing
 * it would leave the user believing they are still signed in.
 *
 * @var int $idleTimeout
 */
?>
<div class="modal-backdrop" id="session-warning-modal" data-persistent="true">
    <div class="modal modal--sm" role="alertdialog" aria-modal="true"
         aria-labelledby="session-warning-title" aria-describedby="session-warning-body">
        <div class="modal__header">
            <h3 class="modal__title" id="session-warning-title">
                <i class="fa-solid fa-clock text-warning"></i> Session expiring
            </h3>
        </div>

        <div class="modal__body" id="session-warning-body">
            <p>
                You will be signed out in
                <strong>less than a minute</strong>
                due to inactivity.
            </p>

            <div class="session-warning__countdown" id="session-countdown" aria-live="assertive">01:00</div>

            <div class="alert alert-warning" id="session-unsaved-notice" style="display:none;margin-bottom:0">
                <span class="alert__icon"><i class="fa-solid fa-floppy-disk"></i></span>
                <div class="alert__body">
                    <div class="alert__title">You have unsaved changes on this page.</div>
                    They are saved as a draft and will be restored after you sign in again.
                </div>
            </div>

            <p class="text-muted text-sm mt-2 mb-0">
                For security, L-SIAMS signs you out after <?= e($idleTimeout) ?> minutes of inactivity.
                Attendance continues on the classroom terminal regardless.
            </p>
        </div>

        <div class="modal__footer">
            <button type="button" class="btn btn-secondary" id="session-signout">Sign out now</button>
            <button type="button" class="btn btn-primary" id="session-stay">Stay signed in</button>
        </div>
    </div>
</div>
