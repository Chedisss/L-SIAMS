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
            color: #fff; padding: 3rem; display: flex; flex-direction: column; justify-content: center;
            position: relative; overflow: hidden;
        }
        /* Subtle grid motif — evokes the classroom terminals without a bitmap. */
        .auth-hero::after {
            content: ''; position: absolute; inset: 0; opacity: .09;
            background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px);
            background-size: 44px 44px;
        }
        .auth-hero > * { position: relative; z-index: 1; }
        /* A single centered logo lockup — nothing else on the panel. */
        .auth-logo { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 1.1rem; }
        .auth-logo__mark { width: 148px; height: 148px; filter: drop-shadow(0 12px 30px rgba(0,0,0,.30)); }
        .auth-logo__bldg      { fill: #ffffff; }
        .auth-logo__accent    { fill: #FBBF24; }
        .auth-logo__flag      { fill: none; stroke: #ffffff; stroke-width: 2; stroke-linecap: round; }
        .auth-logo__flagcloth { fill: #FBBF24; }
        .auth-logo__word   { font-size: 52px; font-weight: 800; letter-spacing: .04em; line-height: 1; margin-top: .3rem; }
        .auth-logo__rule   { width: 60px; height: 3px; border-radius: 2px; background: #FBBF24; opacity: .95; }
        .auth-logo__sub    { font-size: 14px; color: rgba(255,255,255,.72); max-width: 30ch; line-height: 1.5; }

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
        <div class="auth-logo">
            <svg class="auth-logo__mark" viewBox="0 0 100 100" role="img" aria-label="L-SIAMS school building logo">
                <path class="auth-logo__flag" d="M50 21 L50 10"/>
                <path class="auth-logo__flagcloth" d="M50 11 L61 13.5 L50 16 Z"/>
                <path class="auth-logo__bldg" d="M12 44 L50 21 L88 44 Z"/>
                <path class="auth-logo__bldg" d="M22 44 L78 44 L78 78 L22 78 Z"/>
                <path class="auth-logo__bldg" d="M16 78 L84 78 L84 83 L16 83 Z"/>
                <circle class="auth-logo__accent" cx="50" cy="36" r="3.4"/>
                <rect class="auth-logo__accent" x="29" y="53" width="9" height="10" rx="1.5"/>
                <rect class="auth-logo__accent" x="62" y="53" width="9" height="10" rx="1.5"/>
                <path class="auth-logo__accent" d="M44 78 L44 61 Q44 55 50 55 Q56 55 56 61 L56 78 Z"/>
            </svg>

            <div class="auth-logo__word">L&#8209;SIAMS</div>
            <span class="auth-logo__rule"></span>
            <div class="auth-logo__sub">Smart attendance for every classroom.</div>
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
