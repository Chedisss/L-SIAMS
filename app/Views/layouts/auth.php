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
            color: #fff; padding: 3rem; display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
        }
        /* Subtle grid motif — evokes the classroom terminals without a bitmap. */
        .auth-hero::after {
            content: ''; position: absolute; inset: 0; opacity: .09;
            background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px);
            background-size: 44px 44px;
        }
        .auth-hero > * { position: relative; z-index: 1; }
        .auth-hero__brand { display: flex; align-items: center; gap: .85rem; }
        .auth-hero__logo {
            width: 46px; height: 46px; border-radius: 12px;
            background: rgba(255,255,255,.18); display: grid; place-items: center;
            font-weight: 700; font-size: 20px;
        }
        /* Centered logo lockup in place of the old marketing copy. */
        .auth-logo { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 1.05rem; }
        .auth-logo__mark { width: 132px; height: 132px; filter: drop-shadow(0 10px 26px rgba(0,0,0,.28)); }
        .auth-logo__shield { fill: url(#lsShield); stroke: rgba(255,255,255,.92); stroke-width: 2.5; }
        .auth-logo__check  { fill: none; stroke: #FBBF24; stroke-width: 8;
                             stroke-linecap: round; stroke-linejoin: round; }
        .auth-logo__word   { font-size: 46px; font-weight: 800; letter-spacing: .04em; line-height: 1; }
        .auth-logo__sub    { font-size: 13.5px; color: rgba(255,255,255,.7); max-width: 34ch; }
        .auth-hero__footer { font-size: 12px; color: rgba(255,255,255,.6); }

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
        <div class="auth-hero__brand">
            <span class="auth-hero__logo">L</span>
            <div>
                <div style="font-weight:700;font-size:17px">L-SIAMS</div>
                <div style="font-size:11px;letter-spacing:.07em;text-transform:uppercase;opacity:.75">
                    <?= e($schoolName ?? 'Attendance Monitoring') ?>
                </div>
            </div>
        </div>

        <div class="auth-logo">
            <svg class="auth-logo__mark" viewBox="0 0 100 100" role="img" aria-label="L-SIAMS logo">
                <defs>
                    <linearGradient id="lsShield" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#ffffff" stop-opacity=".24"/>
                        <stop offset="1" stop-color="#ffffff" stop-opacity=".06"/>
                    </linearGradient>
                </defs>
                <path class="auth-logo__shield"
                      d="M50 7 L86 21 L86 48 C86 71 70 87 50 94 C30 87 14 71 14 48 L14 21 Z"/>
                <path class="auth-logo__check" d="M33 50 L45 62 L68 36"/>
            </svg>

            <div class="auth-logo__word">L&#8209;SIAMS</div>
            <div class="auth-logo__sub">Secured in-classroom attendance monitoring</div>
        </div>

        <div class="auth-hero__footer">
            
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
