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
            background: linear-gradient(150deg, #1E3A8A 0%, #2563EB 55%, #3B82F6 100%);
            color: #fff; padding: 3.25rem; display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
        }
        /* Subtle grid motif — evokes the classroom terminals without a bitmap. */
        .auth-hero::after {
            content: ''; position: absolute; inset: 0; opacity: .09; z-index: 0;
            background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px);
            background-size: 44px 44px;
        }
        .auth-hero > * { position: relative; z-index: 1; }

        /* Diagonal light streaks, kept over the grid so the panel is not flat. */
        .auth-hero__streaks { position: absolute; inset: 0; overflow: hidden; z-index: 0; pointer-events: none; }
        .auth-hero__streaks span {
            position: absolute; height: 8px; border-radius: 8px; opacity: .5;
            background: linear-gradient(90deg, rgba(251,191,36,0), rgba(251,191,36,.85));
            transform: rotate(-32deg); transform-origin: left center;
        }
        .auth-hero__streaks span:nth-child(1) { left: -4%; bottom: 30%; width: 46%; }
        .auth-hero__streaks span:nth-child(2) { left:  8%; bottom: 21%; width: 30%; height: 6px; opacity: .38; }
        .auth-hero__streaks span:nth-child(3) { left: -2%; bottom: 11%; width: 56%; height: 10px; }
        .auth-hero__streaks span:nth-child(4) { left: 22%; bottom: 36%; width: 20%; height: 5px; opacity: .32; }
        .auth-hero__streaks span:nth-child(5) { left:  2%; bottom: 24%; width: 26%; opacity: .3;
            background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,.45)); }
        .auth-hero__streaks span:nth-child(6) { left: 30%; bottom: 15%; width: 34%; opacity: .4; }

        /* Logo mark: an L with a fingerprint inside (the official concept, as vector). */
        .auth-hero__brand { display: flex; align-items: center; gap: .85rem; }
        .auth-hero__brand svg { width: 62px; height: 62px; filter: drop-shadow(0 8px 20px rgba(0,0,0,.30)); }
        .auth-hero__name { font-size: 22px; font-weight: 800; letter-spacing: .04em; }
        .ls-l { fill: url(#lsL); }
        .ls-print path { fill: none; stroke: #EFF6FF; stroke-width: 2.4; stroke-linecap: round; }

        .auth-hero__body h1 { font-size: 40px; line-height: 1.14; max-width: 15ch; font-weight: 800; }
        .auth-hero__body h1 .ls-accent { color: #FBBF24; }
        .auth-hero__body p  { color: rgba(255,255,255,.82); font-size: 15px; max-width: 40ch;
                              margin-top: .9rem; line-height: 1.6; }
        .auth-hero__foot    { font-size: 12.5px; color: rgba(255,255,255,.58); }

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
        <div class="auth-hero__streaks" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>

        <div class="auth-hero__brand">
            <svg viewBox="0 0 100 100" role="img" aria-label="L-SIAMS logo — an L with a fingerprint">
                <defs>
                    <linearGradient id="lsL" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#BFDBFE"/>
                        <stop offset="1" stop-color="#2563EB"/>
                    </linearGradient>
                </defs>
                <rect class="ls-l" x="26" y="14" width="34" height="66" rx="11"/>
                <rect class="ls-l" x="26" y="64" width="60" height="16" rx="8"/>
                <g class="ls-print">
                    <path d="M38 46 A5 5 0 0 1 48 46"/>
                    <path d="M34 46 A9 9 0 0 1 52 46"/>
                    <path d="M30 46 A13 13 0 0 1 56 46"/>
                    <path d="M27 46 A16 16 0 0 1 59 46"/>
                    <path d="M43 46 L43 58"/>
                </g>
            </svg>
            <span class="auth-hero__name">L&#8209;SIAMS</span>
        </div>

        <div class="auth-hero__body">
            <h1>Secure Every Entry.<br><span class="ls-accent">Track Every Moment.</span></h1>
            <p>Local IoT-based, multi-layer secured attendance monitoring with RFID and biometric authentication.</p>
        </div>

        <div class="auth-hero__foot">&copy; <?= e(date('Y')) ?> L&#8209;SIAMS. All rights reserved.</div>
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
