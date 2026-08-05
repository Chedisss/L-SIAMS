<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

final class AuthenticationException extends HttpException
{
    /** @param array<string,mixed> $context */
    public function __construct(
        string $message = 'Authentication is required.',
        string $code = 'UNAUTHENTICATED',
        array $context = []
    ) {
        parent::__construct(401, $code, $message, $context);
    }
}
