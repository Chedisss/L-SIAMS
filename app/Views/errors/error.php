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
                <button type="button" class="btn btn-secondary" onclick="history.back()">
                    <i class="fa-solid fa-arrow-left"></i> Go back
                </button>
                <a class="btn btn-primary" href="/">
                    <i class="fa-solid fa-house"></i> Dashboard
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
