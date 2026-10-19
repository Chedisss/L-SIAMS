<?php
// L-SIAMS response-time benchmark: creates one test terminal, one class of 60 test
// students and an open session in the COPY's own test database, then prints the
// terminal's credentials as JSON. Never run it against the real system folder.
declare(strict_types=1);
$root = $argv[1] ?? '';
if ($root === '' || !is_file($root . '/bootstrap.php')) { fwrite(STDERR, "usage: php bench_setup.php <copy folder>\n"); exit(1); }
require $root . '/bootstrap.php';
require $root . '/tests/concurrency/Fixture.php';
use App\Services\AttendanceSessionService;
use App\Services\ApiKeyService;
use App\Services\SettingsService;
SettingsService::hydrate();
$f = new Fixture();
$f->build(1, 60, 1);
$device = $f->device(0);
AttendanceSessionService::open($device, $f->teacher(), $f->schedule(0), 0);
$key = ApiKeyService::generateForDevice((int) $device['id'], null);
echo json_encode(['device_id' => $device['device_id'], 'api_key' => $key['api_key'],
    'hmac' => $key['hmac_secret'], 'cards' => array_values($f->ids['cards'][$f->ids['sections'][0]])]);
