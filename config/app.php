<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name'      => Env::get('APP_NAME', 'L-SIAMS'),
    'full_name' => 'Local IoT-Based Multi-Layer Secured Attendance Monitoring System',
    'env'       => Env::get('APP_ENV', 'production'),
    'debug'     => Env::bool('APP_DEBUG', false),
    'url'       => rtrim((string) Env::get('APP_URL', 'https://192.168.1.10'), '/'),
    'timezone'  => Env::get('APP_TIMEZONE', 'Asia/Manila'),
    'version'   => '1.0.0',

    // Paths are resolved once here so nothing else has to guess at directory layout.
    'paths' => [
        'root'       => BASE_PATH,
        'app'        => BASE_PATH . '/app',
        'views'      => BASE_PATH . '/app/Views',
        'storage'    => BASE_PATH . '/storage',
        'logs'       => BASE_PATH . '/storage/logs',
        'reports'    => BASE_PATH . '/storage/reports',
        'cache'      => BASE_PATH . '/storage/cache',
        'tmp'        => BASE_PATH . '/storage/tmp',
        'backups'    => BASE_PATH . '/database/backups',
        'migrations' => BASE_PATH . '/database/migrations',
        'seeders'    => BASE_PATH . '/database/seeders',
        'uploads'    => BASE_PATH . '/public/uploads',
        'public'     => BASE_PATH . '/public',
    ],

    'pagination' => [
        'default_per_page' => 25,
        'allowed'          => [10, 25, 50, 100],
        'max_per_page'     => 100,
    ],

    'uploads' => [
        'max_bytes'          => 4 * 1024 * 1024,
        'image_extensions'   => ['jpg', 'jpeg', 'png'],
        'image_mimes'        => ['image/jpeg', 'image/png'],
        'document_extensions' => ['csv', 'xlsx', 'pdf'],
        'document_mimes'     => [
            'text/csv', 'text/plain', 'application/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/pdf',
        ],
    ],
];
