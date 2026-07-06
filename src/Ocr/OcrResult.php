<?php
declare(strict_types=1);

namespace GastosHogar\Ocr;

class OcrResult
{
    public function __construct(
        public readonly ?string $desc,
        public readonly ?float $amt,
        public readonly ?string $date,
        public readonly string $rawResponse,
    ) {}

    public function toArray(): array
    {
        return [
            'desc'        => $this->desc,
            'amt'         => $this->amt,
            'date'        => $this->date,
            'rawResponse' => $this->rawResponse,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            desc:        $data['desc'] ?? null,
            amt:         isset($data['amt']) ? (float) $data['amt'] : null,
            date:        $data['date'] ?? null,
            rawResponse: (string) ($data['rawResponse'] ?? ''),
        );
    }
}
