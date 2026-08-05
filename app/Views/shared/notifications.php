<?php
/** @var App\Core\View $__view */

use App\Core\Auth;

$__view->extend('layouts.app');
$__view->start('content');

$home   = Auth::isTeacher() ? '/teacher' : '/admin';
$unread = 0;

foreach ($grouped as $items) {
    foreach ($items as $notification) {
        if ((int) $notification['is_read'] === 0) {
            $unread++;
        }
    }
}
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Notifications',
    'subtitle'    => 'System events that need your attention — device problems, security alerts, sessions that closed themselves.',
    'breadcrumbs' => [['Dashboard', $home], ['Notifications', null]],
    'actions'     => $unread > 0
        ? '<button class="btn btn-secondary" id="mark-all"><i class="fa-solid fa-check-double"></i> Mark all read</button>'
        : '',
]); ?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">
            Recent
            <?php if ($unread > 0): ?>
                <span class="badge badge-danger"><?= e($unread) ?> unread</span>
            <?php endif; ?>
        </h2>

        <label class="checkbox">
            <input type="checkbox" id="unread-only" <?= isset($_GET['unread_only']) ? 'checked' : '' ?>>
            <span class="text-sm">Unread only</span>
        </label>
    </div>

    <div class="card__body--flush">
        <?php if ($grouped === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-bell-slash',
                'title' => 'Nothing to show',
                'text'  => 'Notifications appear here when a terminal goes offline, a session auto-closes, '
                    . 'an API key nears rotation, or a security rule fires.',
            ]); ?>
        <?php else: ?>
            <?php foreach ($grouped as $bucket => $items): ?>
                <div class="notif-group">
                    <div class="notif-group__label"><?= e($bucket) ?></div>

                    <?php foreach ($items as $notification): ?>
                        <?php $isUnread = (int) $notification['is_read'] === 0; ?>
                        <div class="notif <?= $isUnread ? 'is-unread' : '' ?>"
                             data-notification="<?= e($notification['notification_id']) ?>">
                            <span class="notif__icon notif__icon--<?= e($notification['priority']) ?>">
                                <i class="fa-solid <?= e(match ((string) $notification['category']) {
                                    'device'      => 'fa-microchip',
                                    'security'    => 'fa-shield-halved',
                                    'attendance'  => 'fa-clipboard-user',
                                    'backup'      => 'fa-database',
                                    'api_key'     => 'fa-key',
                                    default       => 'fa-circle-info',
                                }) ?>"></i>
                            </span>

                            <div class="notif__body">
                                <div class="notif__title"><?= e($notification['title']) ?></div>
                                <div class="notif__message"><?= e($notification['message']) ?></div>
                                <div class="notif__meta">
                                    <?= e(time_ago($notification['created_at'])) ?>
                                    · <?= e(str_replace('_', ' ', (string) $notification['category'])) ?>
                                    <?php if ($notification['priority'] !== 'normal'): ?>
                                        · <span class="badge <?= e(match ((string) $notification['priority']) {
                                            'critical' => 'badge-danger',
                                            'high'     => 'badge-warning',
                                            default    => 'badge-neutral',
                                        }) ?>"><?= e($notification['priority']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="notif__actions">
                                <?php if ($notification['link']): ?>
                                    <a class="btn btn-ghost btn-sm" href="<?= e($notification['link']) ?>">Open</a>
                                <?php endif; ?>

                                <?php if ($isUnread): ?>
                                    <button class="btn btn-ghost btn-sm" data-read="<?= e($notification['notification_id']) ?>"
                                            title="Mark read"><i class="fa-solid fa-check"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
(function () {
    const LS = window.LSIAMS;

    document.getElementById('unread-only').addEventListener('change', function () {
        window.location.search = this.checked ? '?unread_only=1' : '';
    });

    document.addEventListener('click', async function (event) {
        const read = event.target.closest('[data-read]');

        if (read) {
            try {
                await LS.http.post('/notifications/' + read.dataset.read + '/read', {});

                const row = read.closest('.notif');
                row.classList.remove('is-unread');
                read.remove();

                LS.notifications.refreshBadge();
            } catch (error) {
                LS.toast.fromError(error);
            }
            return;
        }

        const all = event.target.closest('#mark-all');

        if (all) {
            LS.util.setBusy(all, true, 'Marking…');

            try {
                const response = await LS.http.post('/notifications/read-all', {});
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 600);
            } catch (error) {
                LS.toast.fromError(error);
                LS.util.setBusy(all, false);
            }
        }
    });

    // A notification arriving while this page is open should show up here, not
    // only in the header badge.
    LS.realtime.on('system.notification', () => {
        LS.toast.info('New notification received.');
        setTimeout(() => window.location.reload(), 1500);
    });
})();
</script>
<?php $__view->stop(); ?>
