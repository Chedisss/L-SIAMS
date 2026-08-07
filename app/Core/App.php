<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\AuthenticationException;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Services\SecurityLogService;
use App\Services\SettingsService;
use Throwable;

/**
 * Application kernel: boot, dispatch, and the single error boundary.
 *
 * Everything that can fail is funnelled through handleThrowable(), which is the
 * only place in the system permitted to decide what an end user or a device
 * sees when something goes wrong. Internal detail is logged; the response
 * carries a stable machine code and a sentence a human can act on.
 */
final class App
{
    private static ?App $instance = null;

    private Router $router;
    private float $startedAt;

    private function __construct()
    {
        $this->startedAt = microtime(true);
        $this->router    = new Router();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function boot(string $basePath): self
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $basePath);
        }

        Env::load($basePath . '/.env');
        Config::loadDirectory($basePath . '/config');

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');

        $debug = (bool) Config::get('app.debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        // Sessions used for flash/CSRF only; auth state lives in `user_sessions`.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');

        $app = self::instance();
        $app->registerHandlers();

        return $app;
    }

    private function registerHandlers(): void
    {
        set_exception_handler(function (Throwable $e): void {
            $this->handleThrowable($e, Request::capture())->send();
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::critical('Fatal error', [
                    'message' => $error['message'],
                    'file'    => $error['file'],
                    'line'    => $error['line'],
                ]);

                if (!headers_sent()) {
                    Response::fail('INTERNAL_ERROR', 'An unexpected error occurred. The incident has been logged.', 500)->send();
                }
            }
        });
    }

    public function router(): Router
    {
        return $this->router;
    }

    /** @param list<string> $routeFiles */
    public function loadRoutes(array $routeFiles): self
    {
        $router = $this->router;

        foreach ($routeFiles as $file) {
            if (is_file($file)) {
                (static function (Router $router) use ($file): void {
                    require $file;
                })($router);
            }
        }

        return $this;
    }

    public function run(): void
    {
        $request = Request::capture();

        try {
            // Runtime settings from the database override file config for every
            // request, so a Settings change takes effect without a deploy.
            SettingsService::hydrate();

            $response = $this->router->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->handleThrowable($e, $request);
        }

        $response
            ->withHeader('X-Response-Time', sprintf('%.1fms', (microtime(true) - $this->startedAt) * 1000))
            ->send();
    }

    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    private function handleThrowable(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            return $this->respondValidation($e, $request);
        }

        if ($e instanceof BusinessRuleException) {
            // Expected domain rejections. Logged by the service that raised them.
            return Response::json([
                'success' => false,
                'code'    => $e->errorCode(),
                'message' => $e->getMessage(),
                'data'    => $e->display(),
            ] + $e->display(), $e->status());
        }

        if ($e instanceof HttpException) {
            return $this->respondHttp($e, $request);
        }

        // Anything reaching here is a bug or an outage. Log everything, disclose
        // nothing: stack traces and SQL never cross the response boundary.
        Logger::exception($e, [
            'path'   => $request->path(),
            'method' => $request->method(),
            'ip'     => $request->ip(),
            'user'   => Auth::id(),
        ]);

        $debug   = (bool) Config::get('app.debug', false);
        $message = $debug
            ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            : 'An unexpected error occurred. The incident has been logged.';

        if ($request->wantsJson()) {
            return Response::fail('INTERNAL_ERROR', $message, 500);
        }

        return $this->errorPage(500, 'Something went wrong', $message);
    }

    private function respondValidation(ValidationException $e, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::validationError($e->errors(), $e->getMessage());
        }

        Flash::withErrors($e->errors());
        Flash::withInput($request->all());
        Flash::error($e->firstMessage());

        $referer = $request->header('referer');
        $target  = $referer !== null && str_starts_with($referer, Site::url())
            ? $referer
            : $request->path();

        return Response::redirect($target, 303);
    }

    private function respondHttp(HttpException $e, Request $request): Response
    {
        if ($e instanceof AuthenticationException || $e->status() === 401) {
            if ($request->wantsJson()) {
                return Response::fail($e->errorCode(), $e->getMessage(), 401);
            }

            $reason = $e->errorCode() === 'SESSION_EXPIRED' ? '?reason=idle_timeout' : '';

            return Response::redirect('/login' . $reason, 302);
        }

        if ($e->status() === 403) {
            SecurityLogService::log(
                'PERMISSION_VIOLATION',
                'medium',
                sprintf('Blocked %s %s', $request->method(), $request->path()),
                ['user_id' => Auth::id(), 'path' => $request->path()]
            );
        }

        if ($request->wantsJson()) {
            return Response::json([
                'success' => false,
                'code'    => $e->errorCode(),
                'message' => $e->getMessage(),
                'data'    => $e->context(),
            ], $e->status());
        }

        return $this->errorPage($e->status(), self::titleForStatus($e->status()), $e->getMessage());
    }

    private function errorPage(int $status, string $title, string $message): Response
    {
        try {
            return View::response('errors.error', [
                'status'    => $status,
                'title'     => $title,
                'message'   => $message,
                'pageTitle' => $status . ' — ' . $title,
            ], $status);
        } catch (Throwable) {
            return Response::html(
                sprintf(
                    '<!doctype html><meta charset="utf-8"><title>%d</title><body style="font-family:system-ui;padding:3rem"><h1>%d — %s</h1><p>%s</p></body>',
                    $status,
                    $status,
                    htmlspecialchars($title, ENT_QUOTES),
                    htmlspecialchars($message, ENT_QUOTES)
                ),
                $status
            );
        }
    }

    private static function titleForStatus(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Sign-in required',
            403 => 'Access denied',
            404 => 'Page not found',
            405 => 'Method not allowed',
            409 => 'Conflict',
            413 => 'Request too large',
            419 => 'Session expired',
            422 => 'Validation failed',
            429 => 'Too many requests',
            503 => 'Service unavailable',
            default => 'Error',
        };
    }
}
