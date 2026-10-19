<?php
// Sends N taps to /api/attendance/tap, signed exactly like the ESP32 firmware
// (HMAC-SHA256 over method, path, device, timestamp, nonce, body hash), and
// times each full HTTP round trip.
declare(strict_types=1);
$c = json_decode((string) file_get_contents($argv[1]), true);
$base = $argv[2]; $n = (int) ($argv[3] ?? 40);
$lat = []; $codes = [];
foreach (array_slice($c['cards'], 0, $n) as $uid) {
    $path  = '/api/attendance/tap';
    $rid   = vsprintf('%s%s-%s-4%s-%s-%s%s%s', [bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)),
             substr(bin2hex(random_bytes(2)), 1), dechex(random_int(0x8000, 0xbfff)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2))]);
    $body  = json_encode(['rfid_uid' => $uid, 'request_id' => $rid]);
    $ts    = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $sig   = hash_hmac('sha256', "POST\n$path\n{$c['device_id']}\n$ts\n$nonce\n" . hash('sha256', $body), $c['hmac']);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => $body, 'header' => implode("\r\n", [
        'Content-Type: application/json', 'X-Forwarded-Proto: https', "X-LSIAMS-Device-Id: {$c['device_id']}",
        "X-LSIAMS-Api-Key: {$c['api_key']}", "X-LSIAMS-Timestamp: $ts", "X-LSIAMS-Nonce: $nonce",
        "X-LSIAMS-Signature: $sig", "X-LSIAMS-Request-Id: $rid"])]]);
    $t0 = hrtime(true);
    $resp = @file_get_contents($base . $path, false, $ctx);
    $lat[] = (hrtime(true) - $t0) / 1e6;
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $code = (int) $m[1]; } }
    $codes[$code] = ($codes[$code] ?? 0) + 1;
}
sort($lat);
$p = static fn (float $q): float => $lat[(int) min(count($lat) - 1, floor($q * count($lat)))];
$ok = $codes[201] ?? 0;
printf("  %d taps (%d recorded): median %.1f ms | 95th percentile %.1f ms | slowest %.1f ms | mean %.1f ms\n",
    count($lat), $ok, $p(0.5), $p(0.95), end($lat), array_sum($lat) / count($lat));
if ($ok !== count($lat)) { echo '  WARNING: not every tap was recorded, HTTP codes: ' . json_encode($codes) . "\n"; }
