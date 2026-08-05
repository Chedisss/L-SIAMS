<?php
declare(strict_types=1);

/**
 * Shared bootstrap for every entry point: the web front controller, the CLI
 * console, the WebSocket server and the test harness.
 *
 * A PSR-4 autoloader is registered directly so the application runs without a
 * `composer install` step — a real consideration for an on-premise LAN
 * deployment where the server may have no outbound internet access at all.
 * If vendor/autoload.php exists it is preferred.
 */

define('BASE_PATH', __DIR__);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file     = __DIR__ . '/app/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

require __DIR__ . '/app/Helpers/functions.php';

return App\Core\App::boot(__DIR__);
