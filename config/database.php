<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'driver'   => 'mysql',
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (int) Env::get('DB_PORT', '3306'),
    'database' => Env::get('DB_NAME', 'lsiams_db'),
    'username' => Env::get('DB_USER', 'lsiams_app'),
    'password' => Env::get('DB_PASS', ''),
    'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => 'utf8mb4_unicode_ci',

    // Persistent connections keep PHP-FPM workers from paying TCP + auth cost on
    // every tap. With pm.max_children ~50 this stays well under max_connections.
    'persistent' => true,

    // Seconds to wait for the server to answer before giving up. Deliberately
    // short: every caller either has a person waiting on it or is a worker that
    // will try again shortly, and neither is served by a connect that hangs.
    'connect_timeout' => (int) Env::get('DB_CONNECT_TIMEOUT', '5'),

    'options' => [
        // READ-COMMITTED avoids gap locks that would otherwise serialise taps
        // coming from different devices into the same attendance tables.
        'isolation' => 'READ-COMMITTED',
        'sql_mode'  => 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO',
    ],

    // Deadlock (1213) / lock-wait-timeout (1205) retry policy — Part 17.2.
    'deadlock_retry' => [
        'attempts'    => 3,
        'backoff_ms'  => [50, 150, 400],
    ],
];
