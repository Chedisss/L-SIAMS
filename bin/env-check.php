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
 *   php bin/env-check.php inihelp [required|optional]
 *                                     prints the remedy for the extensions that
 *                                     group is missing, naming the php.ini this
 *                                     interpreter actually loaded
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

    case 'inihelp':
        // The remedy, worked out for the interpreter that is actually running.
        //
        // start.bat used to print "Open C:\xampp\php\php.ini" no matter which
        // PHP it had found. When that PHP was a standalone build — say
        // C:\php-8.4.3\php.exe, picked up from PATH because XAMPP was not in
        // the usual place — the file it named belonged to a different
        // interpreter, or did not exist at all. Editing it changed nothing, and
        // the launcher then said the same thing again.
        //
        // Worse, a PHP unpacked from the .zip has no php.ini whatsoever: the
        // archive ships php.ini-development and php.ini-production and neither
        // is read until one is copied into place. There are no ';extension='
        // lines to uncomment in a file that is not there, so the old
        // instruction could not be followed even in principle.
        $group  = $argv[2] ?? 'required';
        $needed = $group === 'optional'
            ? ['zip', 'gd']
            : ['pdo_mysql', 'openssl', 'mbstring', 'json'];

        $missing = array_values(array_filter(
            $needed,
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        if ($missing === []) {
            exit(0);
        }

        $loaded  = php_ini_loaded_file();
        $phpDir  = dirname(PHP_BINARY);
        $extDir  = (string) ini_get('extension_dir');
        $lines   = [];

        if ($loaded === false || $loaded === '') {
            // No php.ini at all. Say so, and say where one comes from — the
            // sample files sit next to php.exe in every Windows build.
            $sample = null;
            foreach (['php.ini-development', 'php.ini-production'] as $candidate) {
                if (is_file($phpDir . DIRECTORY_SEPARATOR . $candidate)) {
                    $sample = $candidate;
                    break;
                }
            }

            $lines[] = 'This PHP has no php.ini at all, so these extensions are switched off';
            $lines[] = 'and there is no line to uncomment yet. Create one first:';
            $lines[] = '';
            $lines[] = '  1. Go to  ' . $phpDir;

            if ($sample !== null) {
                $lines[] = '  2. Copy  ' . $sample . '  and name the copy  php.ini';
            } else {
                $lines[] = '  2. Create a file there called  php.ini';
                $lines[] = '     (a stock Windows build ships php.ini-development to copy;';
                $lines[] = '      if it is missing, re-download PHP from windows.php.net)';
            }

            $lines[] = '  3. Open that php.ini in Notepad and set the extension folder:';
            $lines[] = '';
            $lines[] = '       extension_dir = "' . $phpDir . DIRECTORY_SEPARATOR . 'ext"';
            $lines[] = '';
            $lines[] = '  4. Then delete the \';\' at the start of each of these lines:';
        } else {
            $lines[] = 'Open this file - it is the php.ini this PHP actually reads:';
            $lines[] = '';
            $lines[] = '  ' . $loaded;
            $lines[] = '';
            $lines[] = 'Delete the \';\' at the start of each of these lines:';
        }

        foreach ($missing as $extension) {
            $lines[] = '    extension=' . $extension;
        }

        // An enabled extension still will not load if extension_dir points
        // somewhere that does not exist — a standalone build's default is the
        // relative "ext", which only resolves when PHP is started from its own
        // directory. That failure looks identical to "not enabled", so name it.
        if ($loaded !== false && $loaded !== '' && ($extDir === '' || !is_dir($extDir))) {
            $lines[] = '';
            $lines[] = 'Also set the extension folder in that same file - it currently';
            $lines[] = $extDir === ''
                ? 'is not set, so PHP will not find the .dll files:'
                : 'points at "' . $extDir . '", which is not a folder that exists:';
            $lines[] = '';
            $lines[] = '    extension_dir = "' . $phpDir . DIRECTORY_SEPARATOR . 'ext"';
        }

        $lines[] = '';
        $lines[] = 'Save the file, close this window, and run start.bat again.';

        // Indented to the column start.bat uses for everything else, because
        // this is printed straight to the console rather than echoed by the
        // batch file line by line.
        foreach ($lines as $line) {
            echo $line === '' ? '' : '      ' . $line, PHP_EOL;
        }

        exit(1);

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
