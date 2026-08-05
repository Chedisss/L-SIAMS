<?php
/** @var list<array{type:string,message:string}> $flashes */

$icons = [
    'success' => 'fa-circle-check',
    'danger'  => 'fa-circle-exclamation',
    'warning' => 'fa-triangle-exclamation',
    'info'    => 'fa-circle-info',
];
?>
<?php foreach ($flashes as $flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="alert">
        <span class="alert__icon"><i class="fa-solid <?= e($icons[$flash['type']] ?? 'fa-circle-info') ?>"></i></span>
        <div class="alert__body"><?= e($flash['message']) ?></div>
    </div>
<?php endforeach; ?>
