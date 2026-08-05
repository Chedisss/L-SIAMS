<?php
declare(strict_types=1);

namespace App\Core;

abstract class Middleware
{
    /** @var list<string> */
    protected array $parameters;

    /** @param list<string> $parameters */
    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }

    /** @param callable(Request):Response $next */
    abstract public function handle(Request $request, callable $next): Response;
}
