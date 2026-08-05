<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An error that maps directly onto an HTTP status and a machine-readable code.
 *
 * The `code` is what the ESP32 firmware and the front-end switch on; the
 * `message` is for humans. Internal detail belongs in the log, never in either.
 */
class HttpException extends RuntimeException
{
    private int $status;
    private string $errorCode;
    /** @var array<string,mixed> */
    private array $context;

    /** @param array<string,mixed> $context */
    public function __construct(
        int $status,
        string $errorCode,
        string $message,
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);

        $this->status    = $status;
        $this->errorCode = $errorCode;
        $this->context   = $context;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
