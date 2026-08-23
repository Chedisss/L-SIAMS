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
        .auth-hero h1 { font-size: 34px; line-height: 1.2; max-width: 15ch; margin-bottom: .75rem; }
        .auth-hero p { color: rgba(255,255,255,.82); max-width: 46ch; font-size: 15px; }
        .auth-features { display: grid; gap: .85rem; margin-top: 2rem; }
        .auth-feature { display: flex; gap: .75rem; align-items: flex-start; font-size: 13.5px; color: rgba(255,255,255,.9); }
        .auth-feature i { margin-top: .18rem; opacity: .85; }
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

        <div>
            <h1>Attendance that happens in the classroom.</h1>
            <p>
                Local IoT-based, multi-layer secured attendance monitoring with RFID and
                biometric authentication. No cloud, no queues at the gate — just the
                right students, in the right room, verified by the right teacher.
            </p>

            <div class="auth-features">
                <div class="auth-feature">
                    <i class="fa-solid fa-fingerprint"></i>
                    <span>Attendance opens only after the assigned teacher verifies their fingerprint.</span>
                </div>
                <div class="auth-feature">
                    <i class="fa-solid fa-id-card"></i>
                    <span>Section-aware RFID: a card only counts in that student's own class.</span>
                </div>
                <div class="auth-feature">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Signed device requests, replay protection and a permanent audit trail.</span>
                </div>
                <div class="auth-feature">
                    <i class="fa-solid fa-wifi"></i>
                    <span>Runs entirely on the school LAN, and keeps recording when the network drops.</span>
                </div>
            </div>
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
