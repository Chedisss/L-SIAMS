<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

final class AuthorizationException extends HttpException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $message = 'You are not permitted to perform this action.', array $context = [])
    {
        parent::__construct(403, 'FORBIDDEN', $message, $context);
    }
}
