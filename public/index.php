<?php
declare(strict_types=1);

/**
 * Front controller.
 *
 * `public/` is the only directory that should be exposed by the web server.
 * Application code, configuration, .env, storage and backups all live one level
 * up and are unreachable over HTTP.
 */

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

$app->loadRoutes([
    dirname(__DIR__) . '/routes/web.php',
    dirname(__DIR__) . '/routes/api.php',
])->run();
