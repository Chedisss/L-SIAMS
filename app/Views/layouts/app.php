<?php
/**
 * Authenticated application shell.
 *
 * @var App\Core\View $__view
 * @var array<string,mixed>|null $authUser
 */

use App\Core\Auth;

$role     = Auth::role() ?? 'guest';
$isAdmin  = Auth::isAdmin();
$photo    = $authUser['photo_path'] ?? null;
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle ?? 'Dashboard') ?> · <?= e($appName) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%232563EB'/><text x='50' y='68' font-size='52' font-family='sans-serif' font-weight='bold' fill='white' text-anchor='middle'>L</text></svg>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/icons.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>

<div class="app-shell">
    <?php $__view->include('partials.sidebar', ['role' => $role, 'isAdmin' => $isAdmin]); ?>

    <div class="main">
        <header class="topbar">
            <button class="topbar__toggle" type="button" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="search">
                <i class="fa-solid fa-magnifying-glass search__icon"></i>
                <label class="visually-hidden" for="global-search">Search the system</label>
                <input type="search" id="global-search" placeholder="Search students, teachers, devices…  (press /)"
                       autocomplete="off" spellcheck="false">
                <div class="search__results" id="search-results" role="listbox"></div>
            </div>

            <div class="topbar__spacer"></div>

            <span class="clock" id="live-clock" aria-live="off"></span>

            <span class="connection-pill" id="connection-indicator" data-state="connecting"
                  title="Live update status">
                <span class="connection-pill__label">Connecting</span>
            </span>

            <div class="topbar__actions">
                <button class="icon-button" id="theme-toggle" type="button" aria-label="Toggle dark mode">
                    <i class="fa-solid fa-moon"></i>
                </button>

                <div class="dropdown">
                    <button class="icon-button" type="button" data-dropdown="notification-menu"
                            aria-label="Notifications" aria-haspopup="true">
                        <i class="fa-solid fa-bell"></i>
                        <?php if (($unreadNotifications ?? 0) > 0): ?>
                            <span class="icon-button__badge" id="notification-count"><?= e(min(99, $unreadNotifications)) ?></span>
                        <?php else: ?>
                            <span class="icon-button__badge hidden" id="notification-count">0</span>
                        <?php endif; ?>
                    </button>

                    <div class="dropdown__menu" id="notification-menu" style="min-width:320px">
                        <div class="dropdown__header flex justify-between items-center">
                            <strong>Notifications</strong>
                            <button class="btn btn-ghost btn-sm" type="button" id="mark-all-read">Mark all read</button>
                        </div>
                        <div id="notification-list" style="max-height:340px;overflow-y:auto">
                            <div class="text-muted text-sm" style="padding:.8rem">Loading…</div>
                        </div>
                        <div class="dropdown__divider"></div>
                        <a class="dropdown__item" href="/notifications">
                            <i class="fa-solid fa-inbox"></i> View all notifications
                        </a>
                    </div>
                </div>

                <div class="dropdown">
                    <button type="button" data-dropdown="profile-menu"
                            style="background:none;border:0;cursor:pointer;padding:0;display:flex"
                            aria-label="Account menu" aria-haspopup="true">
                        <?php if ($photo): ?>
                            <img class="avatar" src="/uploads/<?= e($photo) ?>" alt="">
                        <?php else: ?>
                            <span class="avatar"><?= e(initials(Auth::fullName())) ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="dropdown__menu" id="profile-menu">
                        <div class="dropdown__header">
                            <div class="fw-600"><?= e(Auth::fullName()) ?></div>
                            <div class="text-muted text-sm"><?= e(ucfirst($role)) ?> · <?= e(Auth::username()) ?></div>
                        </div>
                        <a class="dropdown__item" href="/profile"><i class="fa-solid fa-user"></i> My profile</a>
                        <a class="dropdown__item" href="/password/change"><i class="fa-solid fa-key"></i> Change password</a>
                        <?php if ($isAdmin): ?>
                            <a class="dropdown__item" href="/admin/settings"><i class="fa-solid fa-gear"></i> System settings</a>
                        <?php endif; ?>
                        <div class="dropdown__divider"></div>
                        <form method="post" action="/logout" style="margin:0">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown__item dropdown__item--danger">
                                <i class="fa-solid fa-right-from-bracket"></i> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content" id="main-content">
            <?php $__missingSecrets = App\Core\App::missingSecrets(); ?>
            <?php if ($__missingSecrets !== []): ?>
                <?php /* Without these nothing can encrypt a terminal's secret
                         or issue a key, so the failure lands on whatever page
                         needs one first and looks like a fault there. */ ?>
                <div class="alert alert-danger mb-2" role="alert">
                    <span class="alert__icon"><i class="fa-solid fa-key"></i></span>
                    <div class="alert__body">
                        <strong>This installation has no encryption keys.</strong>
                        Registering a terminal, rotating a key and downloading a provisioning file will
                        all fail until they are set.
                        Run <span class="mono">console.bat key:generate</span> in the project folder,
                        then reload.
                        <div class="text-xs text-muted mt-1">
                            Missing: <?= e(implode(', ', $__missingSecrets)) ?>.
                            If this installation already holds data encrypted under a different key,
                            restore that <span class="mono">.env</span> instead — new keys cannot recover it.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php $__pendingMigrations = App\Core\App::pendingMigrations(); ?>
            <?php if ($__pendingMigrations !== []): ?>
                <?php /* Shown on every page that still renders, because the
                         ones that do not are already answering 500 and cannot
                         explain themselves. Whoever sees this is one command
                         away from fixing the whole thing. */ ?>
                <div class="alert alert-danger mb-2" role="alert">
                    <span class="alert__icon"><i class="fa-solid fa-database"></i></span>
                    <div class="alert__body">
                        <strong>The database is <?= e(count($__pendingMigrations)) ?> update(s) behind this version of the system.</strong>
                        Pages that use the newer tables will fail to load until it catches up. Nothing is
                        damaged and no data has been lost.
                        Run <span class="mono">console.bat migrate</span> in the project folder, then reload.
                        <div class="text-xs text-muted mt-1">
                            Outstanding: <?= e(implode(', ', $__pendingMigrations)) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (App\Core\Clock::isShifted()): ?>
                <?php /* Every timestamp written while this is on is a time that
                         did not happen. Impossible to leave on by accident. */ ?>
                <div class="alert alert-warning mb-2" role="alert">
                    <span class="alert__icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <div class="alert__body">
                        <strong>The clock is shifted for testing.</strong>
                        This system is running at
                        <strong><?= e(App\Core\Clock::now()->format('D d M Y, H:i')) ?></strong>
                        while the real time is
                        <?= e(App\Core\Clock::real()->format('D d M Y, H:i')) ?>
                        (<span class="mono"><?= e(App\Core\Clock::offset()) ?></span>).
                        Attendance recorded now carries the shifted time.
                        Undo it with <span class="mono">console.bat time:shift --clear</span>.
                    </div>
                </div>
            <?php endif; ?>

            <?php $__view->include('partials.flash', ['flashes' => $flashes ?? []]); ?>
            <?= $__view->section('content', $content ?? '') ?>
        </main>
    </div>
</div>

<?php $__view->include('partials.session-modal', ['idleTimeout' => $idleTimeout ?? 10]); ?>

<script nonce="<?= e(csp_nonce()) ?>">
    window.__LSIAMS_CONFIG__ = <?= json_encode([
        'csrfToken'          => csrf_token(),
        'authenticated'      => true,
        'userId'             => Auth::id(),
        'role'               => $role,
        'idleTimeoutMinutes' => $idleTimeout ?? 10,
        'warnSecondsBefore'  => $warnSeconds ?? 60,
        'sessionExpiresIn'   => $sessionExpiresIn ?? null,
        'realtimeUrl'        => $realtimeUrl ?? '',
        'realtimeEnabled'    => (bool) config('realtime.enabled', true),
        'sseEnabled'         => (bool) config('realtime.fallback.sse_enabled', true),
        'pollIntervalMs'     => $pollInterval ?? 3000,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/session.js')) ?>"></script>
<script src="<?= e(asset('js/realtime.js')) ?>"></script>
<script src="<?= e(asset('js/charts.js')) ?>"></script>
<script src="<?= e(asset('js/notifications.js')) ?>"></script>
<?= $__view->section('scripts') ?>
</body>
</html>
