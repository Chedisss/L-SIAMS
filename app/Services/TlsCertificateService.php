<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The school's own certificate authority, and the server certificate it signs.
 *
 * L-SIAMS runs on a LAN with no public DNS name and no route to the internet,
 * so no public CA will ever issue it a certificate: Let's Encrypt cannot prove
 * control of "192.168.1.10", and a school is not going to buy a domain to run
 * a bell schedule. The alternative is to be our own authority — issue a root
 * once, keep it for the life of the installation, and sign the server's
 * certificate with it.
 *
 * Two properties of that arrangement are what make this worth doing at all,
 * and both are easy to lose by accident:
 *
 *   1. The root is generated ONCE and reused for every later reissue. The root
 *      is what gets installed on staff laptops and compiled into terminal
 *      firmware, and each of those is a manual trip to a physical device. If
 *      renewing the server certificate also replaced the root, every renewal
 *      would mean re-flashing every terminal in the building. So generate()
 *      reuses an existing root unless it is explicitly told not to, and the
 *      one flag that replaces it says so in its name.
 *
 *   2. The root's private key is encrypted at rest under APP_KEY. Whoever
 *      holds that key can mint a certificate for any name in the school and
 *      the terminals will believe it — it is a master key to every session on
 *      the network, and it is far more dangerous than the server key it signs.
 *      The server key has to sit in plaintext because Apache reads it
 *      unattended at boot; the root key has no such excuse, and a copied
 *      storage folder is useless without .env.
 *
 * Everything here goes through PHP's openssl extension rather than shelling
 * out to openssl.exe. XAMPP ships one, but its path moves between releases and
 * it needs an OPENSSL_CONF pointing at a config file that half of Windows
 * installs do not have, which is the single most common way this kind of setup
 * fails. The extension is already a hard requirement of the system (see
 * bin/env-check.php), so using it directly removes an entire class of "works
 * on my machine".
 */
final class TlsCertificateService
{
    /** Root validity. Long, because replacing it means visiting every terminal. */
    private const CA_DAYS = 3650;

    /** Server validity. Short enough to force a habit, long enough for a school year. */
    private const DEFAULT_SERVER_DAYS = 825;

    /** RSA-2048, not 4096. The ESP32 verifies the chain on every new TLS
     *  connection, and mbedtls takes roughly a second to check a 4096-bit
     *  signature on that hardware against roughly a tenth for 2048. On a
     *  terminal that reconnects all day the difference is felt at the card
     *  reader, and 2048 is not the weak link in this system. */
    private const KEY_BITS = 2048;

    /**
     * Absolute paths to every file this service owns.
     *
     * @return array{dir:string,ca_cert:string,ca_key:string,server_cert:string,server_key:string,chain:string,firmware_header:string}
     */
    public static function paths(): array
    {
        $dir = (string) Config::get('app.paths.tls', BASE_PATH . '/storage/tls');

        return [
            'dir'             => $dir,
            'ca_cert'         => $dir . '/ca.crt',
            'ca_key'          => $dir . '/ca.key.enc',
            'server_cert'     => $dir . '/server.crt',
            'server_key'      => $dir . '/server.key',
            'chain'           => $dir . '/chain.crt',
            'firmware_header' => $dir . '/ls_root_ca.h',
        ];
    }

    public static function caExists(): bool
    {
        $paths = self::paths();

        return is_file($paths['ca_cert']) && is_file($paths['ca_key']);
    }

    public static function serverCertificateExists(): bool
    {
        $paths = self::paths();

        return is_file($paths['server_cert']) && is_file($paths['server_key']);
    }

    /**
     * Issue a server certificate, creating the root first if there is not one.
     *
     * @param list<string> $hosts DNS names the server answers to
     * @param list<string> $ips   IP addresses the server answers on
     *
     * @return array{ca_created:bool,hosts:list<string>,ips:list<string>,expires:string,ca_expires:string,fingerprint:string,paths:array<string,string>}
     */
    public static function generate(
        array $hosts,
        array $ips,
        bool $replaceCa = false,
        int $serverDays = self::DEFAULT_SERVER_DAYS
    ): array {
        [$hosts, $ips] = self::validateNames($hosts, $ips);

        if ($serverDays < 1 || $serverDays > self::CA_DAYS) {
            throw new ValidationException(['days' => [
                sprintf('Certificate validity must be between 1 and %d days.', self::CA_DAYS),
            ]]);
        }

        $paths = self::paths();
        self::ensureDirectory($paths['dir']);

        $config = self::writeOpensslConfig($hosts, $ips);

        try {
            $caCreated = false;

            if ($replaceCa || !self::caExists()) {
                self::createCertificateAuthority($config, $paths);
                $caCreated = true;
            }

            $caCert = (string) file_get_contents($paths['ca_cert']);
            $caKey  = self::loadCaPrivateKey();

            // The server keypair is regenerated on every issue. Reusing a key
            // across certificates saves nothing here and quietly extends the
            // window in which one stolen file stays useful.
            $serverKey = self::newPrivateKey($config);

            $csr = openssl_csr_new(
                self::distinguishedName($hosts[0] ?? $ips[0] ?? 'localhost'),
                $serverKey,
                ['config' => $config, 'digest_alg' => 'sha256']
            );

            if ($csr === false) {
                throw new RuntimeException('Could not build the certificate request: ' . self::opensslErrors());
            }

            $certificate = openssl_csr_sign(
                $csr,
                $caCert,
                $caKey,
                $serverDays,
                ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_server'],
                self::serialNumber()
            );

            if ($certificate === false) {
                throw new RuntimeException('Could not sign the server certificate: ' . self::opensslErrors());
            }

            if (!openssl_x509_export($certificate, $certificatePem)) {
                throw new RuntimeException('Could not export the server certificate: ' . self::opensslErrors());
            }

            if (!openssl_pkey_export($serverKey, $serverKeyPem, null, ['config' => $config])) {
                throw new RuntimeException('Could not export the server key: ' . self::opensslErrors());
            }

            self::writeFile($paths['server_cert'], $certificatePem, 0644);
            self::writeFile($paths['server_key'], $serverKeyPem, 0600);
            self::writeFile($paths['chain'], $certificatePem . $caCert, 0644);
            self::writeFile($paths['firmware_header'], self::buildFirmwareHeader($caCert), 0644);

            $parsed = openssl_x509_parse($certificatePem);
            $caInfo = openssl_x509_parse($caCert);

            return [
                'ca_created'  => $caCreated,
                'hosts'       => $hosts,
                'ips'         => $ips,
                'expires'     => date('Y-m-d', (int) ($parsed['validTo_time_t'] ?? 0)),
                'ca_expires'  => date('Y-m-d', (int) ($caInfo['validTo_time_t'] ?? 0)),
                'fingerprint' => self::fingerprint($caCert),
                'paths'       => $paths,
            ];
        } finally {
            @unlink($config);
        }
    }

    /**
     * What is currently on disk, for the doctor and the settings screen.
     *
     * @return array{ca:?array<string,mixed>,server:?array<string,mixed>}
     */
    public static function inspect(): array
    {
        $paths = self::paths();

        return [
            'ca'     => self::describe($paths['ca_cert']),
            'server' => self::describe($paths['server_cert']),
        ];
    }

    /**
     * Does the server certificate actually cover the name people will type?
     *
     * A certificate valid for 192.168.1.10 says nothing about
     * https://attendance.school.local, and the browser will refuse the second
     * even though the first works perfectly. This is the check that catches a
     * server whose address changed after the certificate was issued — the DHCP
     * lease moved, and nobody connected that to the warning.
     *
     * @return array{covered:bool,name:string,names:list<string>}
     */
    public static function covers(string $url): array
    {
        $host  = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        $names = self::subjectAltNames();

        foreach ($names as $name) {
            if (strcasecmp($name, $host) === 0) {
                return ['covered' => true, 'name' => $host, 'names' => $names];
            }
        }

        return ['covered' => false, 'name' => $host, 'names' => $names];
    }

    /** @return list<string> Every DNS name and IP the server certificate carries. */
    public static function subjectAltNames(): array
    {
        $paths = self::paths();

        if (!is_file($paths['server_cert'])) {
            return [];
        }

        $parsed = @openssl_x509_parse((string) file_get_contents($paths['server_cert']));
        $raw    = (string) ($parsed['extensions']['subjectAltName'] ?? '');

        if ($raw === '') {
            return [];
        }

        $names = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            } elseif (str_starts_with($entry, 'IP Address:')) {
                $names[] = substr($entry, 11);
            } elseif (str_starts_with($entry, 'IP:')) {
                $names[] = substr($entry, 3);
            }
        }

        return array_values(array_unique(array_map('trim', $names)));
    }

    /**
     * Confirm the root key still decrypts.
     *
     * Worth its own check because the failure is silent until the day someone
     * needs to renew, which is exactly the day they have no time to discover
     * that APP_KEY changed eighteen months ago.
     */
    public static function caKeyReadable(): bool
    {
        try {
            self::loadCaPrivateKey();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------

    /** @param array<string,string> $paths */
    private static function createCertificateAuthority(string $config, array $paths): void
    {
        $key = self::newPrivateKey($config);

        $csr = openssl_csr_new(
            [
                'countryName'            => 'PH',
                'organizationName'       => (string) Config::get('app.name', 'L-SIAMS'),
                'organizationalUnitName' => 'Attendance System',
                'commonName'             => (string) Config::get('app.name', 'L-SIAMS') . ' Local Root CA',
            ],
            $key,
            ['config' => $config, 'digest_alg' => 'sha256']
        );

        if ($csr === false) {
            throw new RuntimeException('Could not build the root request: ' . self::opensslErrors());
        }

        // Self-signed: passing null as the issuer is what makes it a root.
        $certificate = openssl_csr_sign(
            $csr,
            null,
            $key,
            self::CA_DAYS,
            ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_ca'],
            self::serialNumber()
        );

        if ($certificate === false) {
            throw new RuntimeException('Could not sign the root certificate: ' . self::opensslErrors());
        }

        if (!openssl_x509_export($certificate, $certificatePem)) {
            throw new RuntimeException('Could not export the root certificate: ' . self::opensslErrors());
        }

        if (!openssl_pkey_export($key, $keyPem, null, ['config' => $config])) {
            throw new RuntimeException('Could not export the root key: ' . self::opensslErrors());
        }

        self::writeFile($paths['ca_cert'], $certificatePem, 0644);
        self::writeFile($paths['ca_key'], Crypto::encrypt($keyPem), 0600);
    }

    /** @return \OpenSSLAsymmetricKey */
    private static function loadCaPrivateKey(): mixed
    {
        $paths = self::paths();

        if (!is_file($paths['ca_key'])) {
            throw new BusinessRuleException(
                'TLS_CA_MISSING',
                'This installation has no certificate authority yet.',
                [],
                409
            );
        }

        try {
            $pem = Crypto::decrypt((string) file_get_contents($paths['ca_key']));
        } catch (Throwable $e) {
            throw new BusinessRuleException(
                'TLS_CA_KEY_UNREADABLE',
                'The certificate authority key cannot be decrypted. It was encrypted under a '
                . 'different APP_KEY than the one this installation is using now.',
                [],
                409,
                ['reason' => $e->getMessage()]
            );
        }

        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new RuntimeException('The certificate authority key is not a usable private key.');
        }

        return $key;
    }

    /** @return \OpenSSLAsymmetricKey */
    private static function newPrivateKey(string $config): mixed
    {
        $key = openssl_pkey_new([
            'config'           => $config,
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a private key: ' . self::opensslErrors());
        }

        return $key;
    }

    /**
     * Write the openssl config this run needs, rather than depending on one
     * being installed. The extension sections are the whole point: a
     * certificate without subjectAltName is rejected outright by every browser
     * released since 2017, which no longer look at commonName at all, and one
     * without extendedKeyUsage=serverAuth is refused by mbedtls on the ESP32.
     *
     * @param list<string> $hosts
     * @param list<string> $ips
     */
    private static function writeOpensslConfig(array $hosts, array $ips): string
    {
        $alt   = [];
        $index = 1;

        foreach ($hosts as $host) {
            $alt[] = sprintf('DNS.%d = %s', $index++, $host);
        }

        $index = 1;

        foreach ($ips as $ip) {
            $alt[] = sprintf('IP.%d = %s', $index++, $ip);
        }

        // Nowdoc: nothing in here is a PHP variable, and the alt_names block is
        // substituted below rather than interpolated.
        $config = <<<'CNF'
        [ req ]
        default_bits       = 2048
        default_md         = sha256
        distinguished_name = dn
        prompt             = no

        [ dn ]

        [ v3_ca ]
        basicConstraints     = critical, CA:TRUE, pathlen:0
        keyUsage             = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash

        [ v3_server ]
        basicConstraints       = critical, CA:FALSE
        keyUsage               = critical, digitalSignature, keyEncipherment
        extendedKeyUsage       = serverAuth
        subjectKeyIdentifier   = hash
        authorityKeyIdentifier = keyid, issuer
        subjectAltName         = @alt_names

        [ alt_names ]
        @ALT_NAMES@

        CNF;

        $config = str_replace('@ALT_NAMES@', implode("\n", $alt), $config);

        $file = tempnam(sys_get_temp_dir(), 'lsiams-ssl-');

        if ($file === false || file_put_contents($file, $config) === false) {
            throw new RuntimeException('Could not write a temporary OpenSSL configuration file.');
        }

        return $file;
    }

    /** @return array<string,string> */
    private static function distinguishedName(string $commonName): array
    {
        return [
            'countryName'            => 'PH',
            'organizationName'       => (string) Config::get('app.name', 'L-SIAMS'),
            'organizationalUnitName' => 'Attendance Server',
            // Ignored by browsers, which read subjectAltName only, but openssl
            // still requires a subject and an empty one produces odd output in
            // every tool that prints it.
            'commonName'             => substr($commonName, 0, 64),
        ];
    }

    /**
     * The root certificate as a C string the sketch can compile in.
     *
     * Generated rather than pasted, because a PEM re-typed by hand into a
     * header is a guaranteed afternoon: mbedtls rejects the whole certificate
     * if a single line is wrapped differently, and the only symptom on the
     * board is a connection that fails with no explanation.
     */
    private static function buildFirmwareHeader(string $caPem): string
    {
        $lines = [];

        foreach (preg_split('/\R/', trim($caPem)) ?: [] as $line) {
            $lines[] = '  "' . $line . '\\n"';
        }

        $body        = implode("\n", $lines);
        $fingerprint = self::fingerprint($caPem);
        $parsed      = openssl_x509_parse($caPem);
        $expires     = date('Y-m-d', (int) ($parsed['validTo_time_t'] ?? 0));

        return <<<HEADER
        /* ls_root_ca.h — the school's root certificate, for LS_SERVER_URL over https.
         *
         * GENERATED FILE. Produced by `console.bat tls:generate`; do not edit by hand.
         *
         *   SHA-256 fingerprint : {$fingerprint}
         *   Root expires        : {$expires}
         *
         * Copy this file into the sketch folder, next to L_SIAMS_Bench.ino, and the
         * sketch picks it up on its own — there is nothing to paste and nothing to
         * configure. The board then refuses to talk to any server that cannot prove
         * it holds a certificate signed by this root, which is what stops somebody
         * on the school Wi-Fi from standing in the middle of the conversation.
         *
         * This is a public certificate. It is safe to copy, email and commit. The
         * private half never leaves the server, and is not in this file.
         *
         * Reissuing the SERVER certificate does not change this file. Only
         * `tls:generate --new-ca` does, and that means re-flashing every terminal.
         */
        #ifndef LS_ROOT_CA_H
        #define LS_ROOT_CA_H

        static const char LS_ROOT_CA[] PROGMEM =
        {$body};

        #endif

        HEADER;
    }

    /** @return array<string,mixed>|null */
    private static function describe(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $pem    = (string) file_get_contents($path);
        $parsed = @openssl_x509_parse($pem);

        if (!is_array($parsed)) {
            return null;
        }

        $expiresAt = (int) ($parsed['validTo_time_t'] ?? 0);

        return [
            'subject'     => (string) ($parsed['subject']['CN'] ?? '(no common name)'),
            'issuer'      => (string) ($parsed['issuer']['CN'] ?? '(unknown issuer)'),
            'expires_at'  => $expiresAt,
            'expires'     => date('Y-m-d', $expiresAt),
            'days_left'   => (int) floor(($expiresAt - time()) / 86400),
            'fingerprint' => self::fingerprint($pem),
            'self_signed' => ($parsed['subject']['CN'] ?? null) === ($parsed['issuer']['CN'] ?? false),
        ];
    }

    private static function fingerprint(string $pem): string
    {
        $digest = openssl_x509_fingerprint($pem, 'sha256');

        if ($digest === false) {
            return '(unavailable)';
        }

        return strtoupper(implode(':', str_split($digest, 2)));
    }

    /**
     * Validate and normalise the names the certificate will carry.
     *
     * Every name reaching this point ends up inside an OpenSSL config file, so
     * anything that could introduce a newline or a section header would be
     * writing arbitrary config. The character classes below are what make that
     * impossible, and are deliberately narrower than the RFCs allow.
     *
     * @param list<string> $hosts
     * @param list<string> $ips
     *
     * @return array{0:list<string>,1:list<string>}
     */
    private static function validateNames(array $hosts, array $ips): array
    {
        $cleanHosts = [];
        $cleanIps   = [];
        $errors     = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
                $errors['host'][] = sprintf('"%s" is not a valid hostname.', $host);
                continue;
            }

            $cleanHosts[] = $host;
        }

        foreach ($ips as $ip) {
            $ip = trim((string) $ip);

            if ($ip === '') {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $errors['ip'][] = sprintf('"%s" is not a valid IP address.', $ip);
                continue;
            }

            $cleanIps[] = $ip;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // localhost always works, so the machine running the server can always
        // reach itself — the console, the worker and the realtime bridge all
        // talk to it that way.
        $cleanHosts[] = 'localhost';
        $cleanIps[]   = '127.0.0.1';

        $cleanHosts = array_values(array_unique($cleanHosts));
        $cleanIps   = array_values(array_unique($cleanIps));

        if ($cleanHosts === [] && $cleanIps === []) {
            throw new ValidationException(['host' => ['Name at least one hostname or IP address.']]);
        }

        return [$cleanHosts, $cleanIps];
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the certificate directory: ' . $dir);
        }

        @chmod($dir, 0700);
    }

    private static function writeFile(string $path, string $contents, int $mode): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not write ' . $path);
        }

        @chmod($path, $mode);
    }

    /**
     * Serial numbers must be unique per issuer and unpredictable — a guessable
     * serial is one of the ingredients of a certificate forgery. Drawn from the
     * same CSPRNG as every other secret in the system; see Crypto.
     */
    private static function serialNumber(): int
    {
        // 7 bytes keeps the value inside a signed 64-bit integer on every
        // platform, which is what openssl_csr_sign() accepts.
        return max(1, (int) hexdec(bin2hex(Crypto::randomBytes(7))));
    }

    private static function opensslErrors(): string
    {
        $messages = [];

        while (($error = openssl_error_string()) !== false) {
            $messages[] = $error;
        }

        return $messages === [] ? 'no detail available' : implode('; ', $messages);
    }
}
