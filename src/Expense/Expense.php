<?php
declare(strict_types=1);

namespace GastosHogar\Expense;

class Expense
{
    public function __construct(
        public readonly string $id,
        public readonly string $who,
        public readonly string $desc,
        public readonly float  $amt,
        public readonly string $cat,
        public readonly string $date,
        public readonly string $ownerId,
    ) {}

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'who'      => $this->who,
            'desc'     => $this->desc,
            'amt'      => $this->amt,
            'cat'      => $this->cat,
            'date'     => $this->date,
            'owner_id' => $this->ownerId,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id:      $data['id'],
            who:     $data['who'],
            desc:    $data['desc'],
            amt:     (float) $data['amt'],
            cat:     $data['cat'],
            date:    $data['date'],
            ownerId: $data['owner_id'] ?? '',
        );
    }
}
