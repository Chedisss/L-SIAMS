<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationException extends HttpException
{
    /** @var array<string,list<string>> */
    private array $errors;

    /** @param array<string,list<string>> $errors */
    public function __construct(array $errors, string $message = 'Validation failed.', string $code = 'VALIDATION_ERROR')
    {
        parent::__construct(422, $code, $message);

        $this->errors = $errors;
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstMessage(): string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return $this->getMessage();
    }
}
