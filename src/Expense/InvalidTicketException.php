<?php
declare(strict_types=1);

namespace GastosHogar\Expense;

class InvalidTicketException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $reason = 'unknown',
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function details(): array
    {
        return $this->details;
    }
}
