<?php
declare(strict_types=1);

namespace GastosHogar\Ocr;

/**
 * Extrae desc/amt/date de una foto de comprobante usando la Messages API
 * de Claude (Vision). No conoce HTTP ni sesión: solo filesystem + curl.
 */
class ClaudeVisionOcrProvider implements OcrProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL   = 'claude-haiku-4-5-20251001';
    private const MAX_TOKENS = 1024;
    private const TIMEOUT_SECONDS = 15;

    private const ALLOWED_MIME_BLOCK_TYPE = [
        'image/jpeg'      => 'image',
        'image/png'       => 'image',
        'application/pdf' => 'document',
    ];

    private const PROMPT = 'Sos un asistente que extrae datos de comprobantes de gastos '
        . '(tickets, facturas, recibos). Del comprobante adjunto, extraé: '
        . '"desc" (descripción corta del gasto, por ejemplo el nombre del comercio), '
        . '"amt" (el monto total, como número, sin símbolo de moneda ni separadores de miles) y '
        . '"date" (la fecha del comprobante en formato Y-m-d). '
        . 'Si no podés determinar alguno de estos campos con confianza, devolvé null para ese campo. '
        . 'Respondé ÚNICAMENTE con un objeto JSON con esas tres claves, sin texto adicional, '
        . 'sin markdown y sin explicaciones.';

    public function __construct(
        private readonly string $apiKey,
    ) {}

    public function extract(string $filePath): OcrResult
    {
        if ($this->apiKey === '') {
            throw new OcrException('Falta configurar la clave de la API de Claude (ANTHROPIC_API_KEY).');
        }

        if (!is_file($filePath)) {
            throw new OcrException('El archivo del comprobante no existe.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME_BLOCK_TYPE[$mime])) {
            throw new OcrException('Tipo de archivo no soportado para OCR: ' . $mime);
        }

        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            throw new OcrException('No se pudo leer el archivo del comprobante.');
        }

        $blockType = self::ALLOWED_MIME_BLOCK_TYPE[$mime];
        $payload   = [
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => $blockType,
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mime,
                            'data'       => base64_encode($fileData),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => self::PROMPT,
                    ],
                ],
            ]],
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new OcrException('Error de conexión con el servicio de OCR: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new OcrException('El servicio de OCR respondió con error (HTTP ' . $httpCode . '): ' . $response);
        }

        $decoded = json_decode($response, true);
        $text    = $decoded['content'][0]['text'] ?? null;

        if (!is_string($text)) {
            throw new OcrException('Respuesta inesperada del servicio de OCR: ' . $response);
        }

        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) {
            throw new OcrException('No se pudo interpretar los datos del comprobante: ' . $text);
        }

        return new OcrResult(
            desc:        is_string($parsed['desc'] ?? null) ? $parsed['desc'] : null,
            amt:         is_numeric($parsed['amt'] ?? null) ? (float) $parsed['amt'] : null,
            date:        is_string($parsed['date'] ?? null) ? $parsed['date'] : null,
            rawResponse: $text,
        );
    }
}
