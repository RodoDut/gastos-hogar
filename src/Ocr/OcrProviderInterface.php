<?php
declare(strict_types=1);

namespace GastosHogar\Ocr;

interface OcrProviderInterface
{
    public function extract(string $filePath): OcrResult;
}
