<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in web server, which start.bat uses so the system
 * runs without configuring Apache.
 *
 * The built-in server hands *every* request to its router script, including
 * ones for files that plainly exist — so without this, a request for
 * /assets/css/app.css reaches the front controller, gets routed like a page,
 * and comes back as a 404 in text/html. The site renders as unstyled markup.
 *
 * Apache does the same job with the "serve existing files directly" rewrite in
 * public/.htaccess; nginx equivalents are in docs/DEPLOYMENT.md. The built-in
 * server reads neither, so the rules that matter are repeated here.
 */

$root = realpath(__DIR__ . '/../public');

if ($root === false) {
    http_response_code(500);
    exit('public/ is missing.');
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = rawurldecode(is_string($path) ? $path : '/');

/**
 * Only these are served as files. An allowlist rather than a blocklist because
 * the built-in server *executes* any .php it serves: uploads/ holds
 * user-supplied data, and .htaccess turns the PHP engine off for it precisely
 * so a script smuggled in as an upload can never run. Anything not listed here
 * falls through to the application, which answers with its own 404.
 */
$serveable = [
    'css', 'js', 'map', 'json', 'txt', 'webmanifest',
    'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp',
    'woff', 'woff2', 'ttf', 'otf', 'eot',
    'pdf', 'csv', 'xlsx', 'zip', 'bin',
    'mp4', 'webm', 'mp3', 'ogg',
];

$candidate = realpath($root . $path);

$isFile = $path !== '/'
    && $candidate !== false
    // realpath() has resolved any ../ by now, so this is what stops a request
    // for /../.env from reading outside the document root.
    && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)
    && is_file($candidate)
    // Mirrors the .htaccess rule that refuses to serve dotfiles.
    && !str_contains(str_replace('\\', '/', substr($candidate, strlen($root))), '/.')
    && in_array(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)), $serveable, true);

if ($isFile) {
    // Tells the built-in server to return the file itself, with the right
    // Content-Type, instead of using this script's output.
    return false;
}

require $root . '/index.php';
