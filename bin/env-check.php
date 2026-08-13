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
        try {
            require __DIR__ . '/../bootstrap.php';

            App\Core\Database::instance()->scalar('SELECT 1');
            exit(0);
        } catch (Throwable $e) {
            // The message goes nowhere by design — start.bat prints its own
            // guidance, and a raw PDO error naming the host and user is not
            // what somebody double-clicking a launcher needs to read.
            exit(2);
        }

    default:
        fwrite(STDERR, "Unknown check: {$command}\n");
        exit(64);
}
