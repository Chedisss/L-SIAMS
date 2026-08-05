<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Classrooms',
    'subtitle'    => 'A classroom can only host a schedule once it has a registered attendance terminal.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Academic Setup', null], ['Classrooms', null]],
    'actions'     => '<button class="btn btn-primary" data-modal-open="classroom-modal"><i class="fa-solid fa-plus"></i> Add Classroom</button>',
]); ?>

<div class="card">
    <div class="card__body--flush">
        <?php if ($classrooms === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-door-open',
                'title' => 'No classrooms registered',
                'text'  => 'Add the rooms where attendance will be taken.',
                'action' => '<button class="btn btn-primary" data-modal-open="classroom-modal"><i class="fa-solid fa-plus"></i> Add Classroom</button>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Room</th><th>Building</th><th>Floor</th><th class="numeric">Capacity</th>
                        <th>Terminal</th><th class="numeric">Schedules</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($classrooms as $room): ?>
                        <tr>
                            <td class="cell-primary"><?= e($room['room_number']) ?></td>
                            <td class="text-sm"><?= e($room['building'] ?? '—') ?></td>
                            <td class="text-sm"><?= e($room['floor'] ?? '—') ?></td>
                            <td class="numeric"><?= e($room['capacity']) ?></td>
                            <td>
                                <?php if ($room['device_id'] === null): ?>
                                    <span class="badge badge-danger">None registered</span>
                                <?php else: ?>
                                    <span class="badge <?= e(status_badge($room['device_status'])) ?> mono"><?= e($room['device_id']) ?></span>
                                    <div class="text-xs text-muted mt-1"><?= e($room['device_role']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="numeric"><?= e($room['schedule_count']) ?></td>
                            <td><span class="badge <?= e(status_badge($room['status'])) ?>"><?= e(ucfirst((string) $room['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-backdrop" id="classroom-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Add Classroom</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form data-ajax action="/admin/classrooms" data-reload="true">
            <div class="modal__body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="c-room" class="required">Room number</label>
                        <input type="text" id="c-room" name="room_number" required maxlength="30" placeholder="204">
                    </div>
                    <div class="form-group">
                        <label for="c-capacity" class="required">Capacity</label>
                        <input type="number" id="c-capacity" name="capacity" required min="1" max="300" value="50">
                    </div>
                    <div class="form-group">
                        <label for="c-building">Building</label>
                        <input type="text" id="c-building" name="building" maxlength="60">
                    </div>
                    <div class="form-group">
                        <label for="c-floor">Floor</label>
                        <input type="text" id="c-floor" name="floor" maxlength="20">
                    </div>
                    <div class="form-group form-group--full">
                        <label for="c-note">Location note</label>
                        <input type="text" id="c-note" name="location_note" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php $__view->stop(); ?>
