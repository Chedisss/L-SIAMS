#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Certificate authority and server certificate tests.
 *
 *   php tests/tls/run.php
 *
 * No database and no network: everything here is signature and extension
 * checking, done with the same openssl the server uses. It runs against a
 * temporary directory, so an installation's real certificates in storage/tls
 * are never read, written or replaced.
 *
 * The properties under test are the ones whose failure is silent. A
 * certificate with the wrong extensions is accepted by openssl and refused by
 * every browser; a root that is quietly regenerated on reissue leaves every
 * terminal in the building unable to connect, weeks later, with nothing having
 * visibly changed. Both are cheap to assert and expensive to discover.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Exceptions\ValidationException;
use App\Services\TlsCertificateService;

$passed   = 0;
$failed   = 0;
$failures = [];

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        printf("  \033[32m✓\033[0m %s\n", $name);

        return;
    }

    $failed++;
    $failures[] = $name . ($detail === '' ? '' : ' — ' . $detail);
    printf("  \033[31m✗ %s%s\033[0m\n", $name, $detail === '' ? '' : ' — ' . $detail);
}

function group(string $name): void
{
    printf("\n\033[1m%s\033[0m\n", $name);
}

/** Everything runs here, so a real installation is never touched. */
$sandbox = sys_get_temp_dir() . '/lsiams-tls-test-' . bin2hex(Crypto::randomBytes(4));
Config::set('app.paths.tls', $sandbox);

register_shutdown_function(static function () use ($sandbox): void {
    foreach (glob($sandbox . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($sandbox);
});

/** @return array<string,mixed> */
function parseCert(string $path): array
{
    return (array) openssl_x509_parse((string) file_get_contents($path));
}

// ---------------------------------------------------------------------------
group('Issuing');

$first = TlsCertificateService::generate(['attendance.school.local'], ['192.168.1.10']);
$paths = TlsCertificateService::paths();

check('a root is created on first run', $first['ca_created']);
check('the root certificate exists', is_file($paths['ca_cert']));
check('the root key is stored encrypted', is_file($paths['ca_key']));
check('the server certificate exists', is_file($paths['server_cert']));
check('the server key exists', is_file($paths['server_key']));

// The root key must never be readable as a PEM straight off the disk: that is
// the whole point of encrypting it under APP_KEY.
$storedCaKey = (string) file_get_contents($paths['ca_key']);
check(
    'the root key is not plaintext PEM on disk',
    !str_contains($storedCaKey, 'PRIVATE KEY'),
    'found a PEM header in ' . basename($paths['ca_key'])
);
check('the root key decrypts under APP_KEY', TlsCertificateService::caKeyReadable());

// ---------------------------------------------------------------------------
group('Trust chain');

$serverPem = (string) file_get_contents($paths['server_cert']);
$caPem     = (string) file_get_contents($paths['ca_cert']);
$caPublic  = openssl_pkey_get_public($caPem);

check(
    'the server certificate is signed by the root',
    $caPublic !== false && openssl_x509_verify($serverPem, $caPublic) === 1
);

$serverInfo = parseCert($paths['server_cert']);
$caInfo     = parseCert($paths['ca_cert']);

check(
    'the root is self-signed',
    ($caInfo['subject']['CN'] ?? '') === ($caInfo['issuer']['CN'] ?? null)
);
check(
    'the server certificate is issued by the root, not itself',
    ($serverInfo['issuer']['CN'] ?? '') === ($caInfo['subject']['CN'] ?? null)
    && ($serverInfo['subject']['CN'] ?? '') !== ($serverInfo['issuer']['CN'] ?? null)
);

// ---------------------------------------------------------------------------
group('Extensions browsers and mbedtls actually check');

$extensions = (array) ($serverInfo['extensions'] ?? []);
$caExt      = (array) ($caInfo['extensions'] ?? []);

// Browsers have ignored commonName since 2017. No SAN means no connection,
// however correct the rest of the certificate looks.
check(
    'the server certificate carries subjectAltName',
    isset($extensions['subjectAltName']) && $extensions['subjectAltName'] !== ''
);
check(
    'it is marked for server authentication',
    str_contains((string) ($extensions['extendedKeyUsage'] ?? ''), 'TLS Web Server Authentication')
);
check(
    'it is not itself a CA',
    str_contains((string) ($extensions['basicConstraints'] ?? ''), 'CA:FALSE')
);
check(
    'the root is marked as a CA',
    str_contains((string) ($caExt['basicConstraints'] ?? ''), 'CA:TRUE')
);
check(
    'the root may sign certificates',
    str_contains((string) ($caExt['keyUsage'] ?? ''), 'Certificate Sign')
);

$names = TlsCertificateService::subjectAltNames();

check('the requested hostname is present', in_array('attendance.school.local', $names, true));
check('the requested IP is present', in_array('192.168.1.10', $names, true));
// Both are added unconditionally: the console, the worker and the realtime
// bridge all reach the server as itself.
check('localhost is always present', in_array('localhost', $names, true));
check('127.0.0.1 is always present', in_array('127.0.0.1', $names, true));

check('covers() accepts a listed address', TlsCertificateService::covers('https://192.168.1.10')['covered']);
check('covers() rejects an unlisted address', !TlsCertificateService::covers('https://192.168.9.9')['covered']);

// ---------------------------------------------------------------------------
group('Reissue keeps the root — terminals stay in the field');

$caFingerprintBefore = $first['fingerprint'];
$serialBefore        = (string) ($serverInfo['serialNumberHex'] ?? '');

$second = TlsCertificateService::generate(['attendance.school.local'], ['192.168.1.10']);

check('reissuing does not create a new root', !$second['ca_created']);
check('the root fingerprint is unchanged', $second['fingerprint'] === $caFingerprintBefore);

// The property everything else rests on: firmware flashed against the old root
// must still validate the newly issued certificate.
$reissuedPem = (string) file_get_contents($paths['server_cert']);
check(
    'the reissued certificate still validates against the original root',
    $caPublic !== false && openssl_x509_verify($reissuedPem, $caPublic) === 1
);

$serialAfter = (string) (parseCert($paths['server_cert'])['serialNumberHex'] ?? '');
check(
    'each issue gets its own serial number',
    $serialBefore !== '' && $serialAfter !== '' && $serialBefore !== $serialAfter,
    sprintf('%s vs %s', $serialBefore, $serialAfter)
);

// ---------------------------------------------------------------------------
group('Replacing the root is possible but distinct');

$third = TlsCertificateService::generate(['attendance.school.local'], ['192.168.1.10'], true);

check('--new-ca creates a new root', $third['ca_created']);
check('the new root has a different fingerprint', $third['fingerprint'] !== $caFingerprintBefore);
check(
    'certificates signed by the old root no longer validate against the new one',
    ($newCaPublic = openssl_pkey_get_public((string) file_get_contents($paths['ca_cert']))) !== false
    && openssl_x509_verify($serverPem, $newCaPublic) !== 1
);

// ---------------------------------------------------------------------------
group('Input that must be refused');

// Every name reaches an OpenSSL config file. A newline would let a caller open
// a new section and write arbitrary configuration, so the validator is
// deliberately narrower than the RFCs.
$rejected = static function (array $hosts, array $ips): bool {
    try {
        TlsCertificateService::generate($hosts, $ips);

        return false;
    } catch (ValidationException) {
        return true;
    }
};

check('a hostname containing a newline is refused', $rejected(["evil\nDNS.9 = attacker.test"], []));
check('a hostname containing a space is refused', $rejected(['not a hostname'], []));
check('a hostname containing a slash is refused', $rejected(['school.local/../x'], []));
check('an IP that is not an IP is refused', $rejected([], ['999.1.1.1']));
check('an equals sign in a hostname is refused', $rejected(['a=b'], []));

// ---------------------------------------------------------------------------
group('The header the terminals compile in');

$header = (string) file_get_contents($paths['firmware_header']);

check('it declares the symbol the sketch uses', str_contains($header, 'LS_ROOT_CA[]'));
check('it has an include guard', str_contains($header, '#ifndef LS_ROOT_CA_H'));
check('it contains a certificate', str_contains($header, 'BEGIN CERTIFICATE'));
check('it contains no private key', !str_contains($header, 'PRIVATE KEY'));

// The PEM is re-emitted as C string literals; if the line splitting were wrong
// the board would reject the certificate with no useful diagnostic.
//
// Two different newlines are in play and both have to go: the literal \n
// escapes that are part of the PEM the board will see, and the real line
// breaks separating one C string literal from the next, which are not.
$reconstructed = str_replace(
    ['\\n', '"', ' ', "\r", "\n"],
    '',
    substr($header, (int) strpos($header, '"-----BEGIN'))
);
$reconstructed = substr($reconstructed, 0, (int) strpos($reconstructed, '-----ENDCERTIFICATE-----') + 24);

// Read fresh: the group above replaced the root, so the header is expected to
// carry the NEW one and comparing against the original would be the test's
// mistake rather than the code's.
$currentCaPem = (string) file_get_contents($paths['ca_cert']);

check(
    'the embedded certificate matches the root on disk',
    str_replace(["\n", "\r", ' '], '', $currentCaPem) === $reconstructed,
    'the header would not parse back to the same certificate'
);

// ---------------------------------------------------------------------------
group('Where the keys live');

$publicRoot = (string) Config::get('app.paths.public');

check(
    'the server key is outside the document root',
    !str_starts_with(realpath($paths['server_key']) ?: $paths['server_key'], $publicRoot)
);
check(
    'the root key is outside the document root',
    !str_starts_with(realpath($paths['ca_key']) ?: $paths['ca_key'], $publicRoot)
);

// ---------------------------------------------------------------------------
printf("\n%s\n", str_repeat('─', 60));

if ($failed === 0) {
    printf("\033[32m✓ %d assertions passed.\033[0m\n", $passed);
    exit(0);
}

printf("\033[31m✗ %d of %d assertions failed:\033[0m\n", $failed, $passed + $failed);

foreach ($failures as $failure) {
    printf("    %s\n", $failure);
}

exit(1);
