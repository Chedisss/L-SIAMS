<?php
/** @var int $status */
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($status) ?> · <?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/icons.css')) ?>">
</head>
<body>
<div style="min-height:100vh;display:grid;place-items:center;padding:2rem">
    <div class="card" style="max-width:520px;width:100%;margin:0">
        <div class="card__body text-center" style="padding:2.5rem 2rem">
            <div style="font-size:44px;color:var(--<?= $status >= 500 ? 'danger' : ($status === 403 ? 'warning' : 'primary') ?>);margin-bottom:.75rem">
                <i class="fa-solid <?= $status === 404 ? 'fa-compass' : ($status === 403 ? 'fa-lock' : 'fa-triangle-exclamation') ?>"></i>
            </div>

            <div class="text-muted text-sm mono mb-1">HTTP <?= e($status) ?></div>
            <h1 style="font-size:22px"><?= e($title) ?></h1>
            <p class="text-muted"><?= e($message) ?></p>

            <div class="flex gap-1 justify-between mt-3" style="justify-content:center">
                <?php /* This page deliberately loads no application script — it
                         has to render when the application itself is what broke,
                         so it links only the two stylesheets. That is why the
                         button did nothing: data-action="history-back" is bound
                         in app.js, which is never here to bind it. The handler
                         is therefore inline, and small enough to read. */ ?>
                <button type="button" class="btn btn-secondary" id="go-back" hidden>
                    <i class="fa-solid fa-arrow-left"></i> Go back
                </button>
                <a class="btn btn-primary" href="/">
                    <i class="fa-solid fa-house"></i> Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var back = document.getElementById('go-back');

    // Offered only when there is somewhere to go. Arriving here from a typed
    // URL, a bookmark or a new tab leaves history.length at 1, and a "Go back"
    // that cannot go back is worse than no button — Dashboard is then the only
    // honest way out, and it is already there.
    if (window.history.length <= 1) {
        return;
    }

    back.hidden = false;

    back.addEventListener('click', function () {
        var here = window.location.href;

        window.history.back();

        // Going back does not always go anywhere: the previous entry can be
        // this same error page, after a refresh or a second failure in a row.
        // If we are still here a moment later, take the dashboard rather than
        // leaving a button that once again did nothing.
        window.setTimeout(function () {
            if (window.location.href === here) {
                window.location.href = '/';
            }
        }, 400);
    });
})();
</script>
</body>
</html>
