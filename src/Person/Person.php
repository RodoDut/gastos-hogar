<?php
declare(strict_types=1);

namespace GastosHogar\Person;

class Person
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }

    public static function fromArray(array $data): self
    {
        return new self(id: $data['id'], name: $data['name']);
    }
}
