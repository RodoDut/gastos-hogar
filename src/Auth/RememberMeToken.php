<?php
declare(strict_types=1);

namespace GastosHogar\Auth;

class RememberMeToken
{
    public function __construct(
        public readonly string $selector,
        public readonly string $validatorHash,
        public readonly string $userId,
        public readonly int $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt < time();
    }

    public function toArray(): array
    {
        return [
            'selector'      => $this->selector,
            'validatorHash' => $this->validatorHash,
            'userId'        => $this->userId,
            'expiresAt'     => $this->expiresAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            selector:      $data['selector'],
            validatorHash: $data['validatorHash'],
            userId:        $data['userId'],
            expiresAt:     (int) $data['expiresAt'],
        );
    }
}
