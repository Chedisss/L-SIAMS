<?php
declare(strict_types=1);

/**
 * Environment probe for start.bat.
 *
 * start.bat used to ask these questions with inline `php -r "..."` snippets,
 * one of them nested inside a FOR /F. That put PHP source through two layers of
 * cmd.exe quote-stripping, and when a machine mangled it the result was:
 *
 *     PHP Parse error: syntax error, unexpected end of file ... on line 1
 *     [ok] PHP error:
 *
 * — a parse error from the probe, and the "version" printed as the second word
 * of the error message. Nothing was wrong with PHP; the launcher had simply
 * failed to ask. Moving the questions into a file removes every layer of
 * quoting between the batch file and the code, so there is nothing left to
 * mangle.
 *
 * Usage:
 *   php bin/env-check.php version     prints "8.2.4", exits 0
 *                                     exits 1 if older than 8.1
 *   php bin/env-check.php required    prints any missing required extensions
 *                                     exits 1 if any are missing
 *   php bin/env-check.php optional    prints any missing optional extensions
 *                                     exits 1 if any are missing
 *   php bin/env-check.php database    exits 0 if the database answers
 *
 * Kept free of the framework except where the check itself needs it: a
 * launcher that cannot boot the application still has to be able to say why.
 */

$command = $argv[1] ?? 'version';

switch ($command) {
    case 'version':
        echo PHP_VERSION, PHP_EOL;
        exit(PHP_VERSION_ID >= 80100 ? 0 : 1);

    case 'required':
    case 'optional':
        $needed = $command === 'required'
            ? ['pdo_mysql', 'openssl', 'mbstring', 'json']
            : ['zip', 'gd'];

        $missing = array_values(array_filter(
            $needed,
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        if ($missing !== []) {
            echo implode(' ', $missing), PHP_EOL;
            exit(1);
        }

        exit(0);

    case 'database':
        // Probed at the socket before PDO is allowed near it.
        //
        // PDO::ATTR_TIMEOUT bounds the TCP connect and nothing after it, so a
        // port that accepts the connection and then never sends MySQL's
        // greeting leaves PDO blocked on a read with no timeout at all. That
        // is not hypothetical: it is exactly what "Checking the database..."
        // sitting there forever looks like, and it survives minutes of waiting
        // because nothing in the stack has agreed to give up.
        //
        // A raw socket with an explicit read timeout can give up, and can tell
        // the three failures apart — nothing listening, something listening
        // that is not MySQL, and MySQL answering but refusing us.
        $host    = null;
        $port    = 3306;
        $timeout = 5;

        require __DIR__ . '/../bootstrap.php';

        $host    = (string) App\Core\Config::get('database.host', '127.0.0.1');
        $port    = (int) App\Core\Config::get('database.port', 3306);
        $timeout = max(1, (int) App\Core\Config::get('database.connect_timeout', 5));

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!is_resource($socket)) {
            fwrite(STDERR, sprintf(
                "Nothing is listening on %s:%d — %s\n",
                $host,
                $port,
                $errstr !== '' ? $errstr : 'connection refused'
            ));
            fwrite(STDERR, "  Start MySQL in the XAMPP Control Panel, or correct DB_HOST/DB_PORT in .env.\n");
            exit(2);
        }

        // MySQL speaks first. If nothing arrives inside the window, whatever is
        // on that port is not a database, and connecting would hang.
        stream_set_timeout($socket, $timeout);
        $greeting = fread($socket, 1);
        $timedOut = stream_get_meta_data($socket)['timed_out'] ?? false;
        fclose($socket);

        if ($timedOut || $greeting === '' || $greeting === false) {
            fwrite(STDERR, sprintf(
                "Something is listening on %s:%d but it is not MySQL — it never sent a greeting.\n",
                $host,
                $port
            ));
            fwrite(STDERR, "  Check DB_PORT in .env. XAMPP's MySQL is normally 3306.\n");
            exit(2);
        }

        try {

            App\Core\Database::instance()->scalar('SELECT 1');
            exit(0);
        } catch (Throwable $e) {
            // STDERR rather than STDOUT: start.bat sends both to nul and
            // prints its own guidance, so this stays out of the launcher. But
            // when the same command is run by hand — which is what start.bat
            // now tells people to do when it cannot connect — the actual
            // reason is the whole point, and hiding it left them guessing at a
            // failure the server had already explained.
            $reason = $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();

            fwrite(STDERR, 'Database connection failed: ' . $reason . PHP_EOL);
            fwrite(STDERR, sprintf(
                '  host=%s port=%s database=%s user=%s' . PHP_EOL,
                (string) App\Core\Config::get('database.host', '?'),
                (string) App\Core\Config::get('database.port', '?'),
                (string) App\Core\Config::get('database.database', '?'),
                (string) App\Core\Config::get('database.username', '?')
            ));

            exit(2);
        }

    default:
        fwrite(STDERR, "Unknown check: {$command}\n");
        exit(64);
}
