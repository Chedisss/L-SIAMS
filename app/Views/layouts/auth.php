<?php
/** @var App\Core\View $__view */
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle ?? 'Sign in') ?> · <?= e($appName ?? 'L-SIAMS') ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%232563EB'/><text x='50' y='68' font-size='52' font-family='sans-serif' font-weight='bold' fill='white' text-anchor='middle'>L</text></svg>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/icons.css')) ?>">
    <style nonce="<?= e(csp_nonce()) ?>">
        .auth-shell {
            min-height: 100vh; display: grid;
            grid-template-columns: 1fr 460px;
            background: var(--bg);
        }
        .auth-hero {
            background:
                repeating-radial-gradient(circle at 112% 4%, rgba(255,255,255,.055) 0 1.5px, transparent 1.5px 100px),
                repeating-radial-gradient(circle at -12% 98%, rgba(255,255,255,.05) 0 1.5px, transparent 1.5px 100px),
                linear-gradient(160deg, #1B4FCB 0%, #2464E6 58%, #2E74F2 100%);
            color: #fff; padding: 3rem; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center;
            position: relative; overflow: hidden;
        }
        /* Subtle grid motif, kept under the logo lockup. */
        .auth-hero::after {
            content: ''; position: absolute; inset: 0; opacity: .07; z-index: 0;
            background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px);
            background-size: 46px 46px;
        }
        .auth-hero > * { position: relative; z-index: 1; }

        .auth-hero__lock  { display: flex; flex-direction: column; align-items: center; max-width: 30rem; }
        /* Official logo artwork (the PNG already includes the L-SIAMS wordmark). */
        .auth-hero__mark img { width: 260px; max-width: 68%; height: auto; filter: drop-shadow(0 14px 32px rgba(0,0,0,.30)); }
        .auth-hero__tag  { margin-top: .3rem; font-size: 12.5px; letter-spacing: .2em; color: rgba(219,234,255,.72); }
        .auth-hero__desc { margin-top: 1.8rem; font-size: 17px; line-height: 1.55; color: rgba(255,255,255,.9); max-width: 24ch; }

        .auth-panel { display: flex; align-items: center; justify-content: center; padding: 2.5rem 2rem; }
        .auth-card { width: 100%; max-width: 360px; }
        .auth-card__title { font-size: 24px; margin-bottom: .3rem; }
        .auth-card__subtitle { color: var(--text-muted); font-size: 13.5px; margin-bottom: 1.75rem; }

        @media (max-width: 900px) {
            .auth-shell { grid-template-columns: 1fr; }
            .auth-hero { display: none; }
        }
    </style>
</head>
<body>
<div class="auth-shell">
    <aside class="auth-hero">
        <div class="auth-hero__lock">
            <div class="auth-hero__mark">
                <img src="<?= e(asset('img/lsiams-logo.png')) ?>" alt="L-SIAMS logo">
            </div>
            <div class="auth-hero__tag">Secure Every Entry. Track Every Moment</div>
            <p class="auth-hero__desc">Local IoT-based, multi-layer secured attendance monitoring with RFID and biometric authentication.</p>
        </div>
    </aside>

    <main class="auth-panel">
        <div class="auth-card">
            <?php $__view->include('partials.flash', ['flashes' => $flashes ?? []]); ?>
            <?= $__view->section('content', $content ?? '') ?>
        </div>
    </main>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
    window.__LSIAMS_CONFIG__ = <?= json_encode([
        'csrfToken'     => csrf_token(),
        'authenticated' => false,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= $__view->section('scripts') ?>
</body>
</html>
