<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Route table and dispatcher.
 *
 * Middleware is attached per route (and per group), and the pipeline runs
 * before the controller is ever constructed — that is what makes it impossible
 * for a controller to "forget" its auth check. A route with no middleware is
 * public by explicit declaration, never by omission.
 */
final class Router
{
    /** @var array<string, list<array{pattern:string,regex:string,params:list<string>,handler:mixed,middleware:list<string>,name:?string}>> */
    private array $routes = [];
    /** @var array<string,string> */
    private array $namedRoutes = [];
    /** @var list<string> */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    /** @param list<string> $middleware */
    public function group(string $prefix, array $middleware, callable $definition): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = rtrim($previousPrefix . $prefix, '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $definition($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /** @param list<string> $middleware */
    public function get(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('GET', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function post(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('POST', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function put(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function patch(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function delete(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    private function addRoute(string $method, string $path, mixed $handler, array $middleware, ?string $name): void
    {
        $fullPath = $this->groupPrefix . $path;
        $fullPath = $fullPath === '' ? '/' : rtrim($fullPath, '/');
        $fullPath = $fullPath === '' ? '/' : $fullPath;

        $params = [];
        $regex  = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $matches) use (&$params): string {
                $params[] = $matches[1];
                $pattern  = $matches[2] ?? '[^/]+';

                return '(' . $pattern . ')';
            },
            $fullPath
        );

        $this->routes[$method][] = [
            'pattern'    => $fullPath,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name'       => $name,
        ];

        if ($name !== null) {
            $this->namedRoutes[$name] = $fullPath;
        }
    }

    public function route(string $name, array $params = []): string
    {
        $path = $this->namedRoutes[$name] ?? '/';

        foreach ($params as $key => $value) {
            $path = str_replace(['{' . $key . '}', '{' . $key . ':\d+}'], (string) $value, $path);
        }

        return $path;
    }

    /**
     * @return array{route:array<string,mixed>,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                array_shift($matches);
                $params = [];

                foreach ($route['params'] as $index => $paramName) {
                    $params[$paramName] = $matches[$index] ?? '';
                }

                return ['route' => $route, 'params' => $params];
            }
        }

        return null;
    }

    /** Methods a path would accept — used to answer 405 correctly instead of 404. */
    public function allowedMethods(string $path): array
    {
        $allowed = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    public function dispatch(Request $request): Response
    {
        $match = $this->match($request->method(), $request->path());

        if ($match === null) {
            $allowed = $this->allowedMethods($request->path());

            if ($allowed !== []) {
                throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'This endpoint does not accept ' . $request->method() . '.');
            }

            throw new HttpException(404, 'NOT_FOUND', 'The requested resource does not exist.');
        }

        $request->setRouteParams($match['params']);

        return $this->runPipeline($request, $match['route']);
    }

    /** @param array<string,mixed> $route */
    private function runPipeline(Request $request, array $route): Response
    {
        /** @var list<string> $middlewareStack */
        $middlewareStack = $route['middleware'];

        $destination = function (Request $request) use ($route): Response {
            return $this->callHandler($route['handler'], $request);
        };

        $pipeline = array_reduce(
            array_reverse($middlewareStack),
            static function (callable $next, string $middlewareName): callable {
                return static function (Request $request) use ($next, $middlewareName): Response {
                    $middleware = MiddlewareRegistry::resolve($middlewareName);

                    return $middleware->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    private function callHandler(mixed $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            /** @var Response $result */
            $result = $handler($request);

            return $result;
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            if (!class_exists($class)) {
                throw new HttpException(500, 'HANDLER_MISSING', 'Route handler is not available.');
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new HttpException(500, 'HANDLER_MISSING', 'Route handler is not available.');
            }

            /** @var Response $result */
            $result = $controller->{$method}($request);

            return $result;
        }

        throw new HttpException(500, 'HANDLER_INVALID', 'Route handler could not be resolved.');
    }
}
