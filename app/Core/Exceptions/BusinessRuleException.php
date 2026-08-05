<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A request that was well-formed and authorised but violates a domain rule —
 * SECTION_MISMATCH, DUPLICATE_TIME_IN, MINIMUM_DWELL_NOT_MET and friends.
 *
 * These are expected outcomes, not faults: they carry a device-renderable
 * display payload so the ESP32 can show the right OLED text and LED colour.
 */
final class BusinessRuleException extends HttpException
{
    /** @var array<string,mixed> */
    private array $display;

    /**
     * @param array<string,mixed> $display
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $code,
        string $message,
        array $display = [],
        int $status = 409,
        array $context = []
    ) {
        parent::__construct($status, $code, $message, $context);

        $this->display = $display;
    }

    /** @return array<string,mixed> */
    public function display(): array
    {
        return $this->display;
    }
}
