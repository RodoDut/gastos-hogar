<?php
declare(strict_types=1);

namespace GastosHogar\Logging;

use Throwable;

/**
 * Convierte un registro de log (nivel, mensaje, contexto) en una línea JSON.
 */
final class JsonLineFormatter
{
    private const REDACT_KEY_PATTERN = '/pass|pwd|token|secret|cookie|authorization|csrf/i';
    private const MAX_TRACE_FRAMES   = 10;

    public function format(string $level, string $message, array $context): string
    {
        $record = [
            'ts'      => date('c'),
            'level'   => $level,
            'message' => $message,
            'context' => $this->normalizeContext($context),
        ];

        $json = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $json = json_encode([
                'ts'      => date('c'),
                'level'   => 'error',
                'message' => 'log_encode_failed',
                'context' => ['json_error' => json_last_error_msg()],
            ]) ?: '{"ts":"","level":"error","message":"log_encode_failed","context":{}}';
        }

        return $json . "\n";
    }

    private function normalizeContext(array $context): array
    {
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $context['exception'] = $this->normalizeException($context['exception']);
        }

        return $this->redact($context);
    }

    private function normalizeException(Throwable $e): array
    {
        return [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $this->truncatedTrace($e),
        ];
    }

    /** @return string[] */
    private function truncatedTrace(Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $location = isset($frame['file'])
                ? ' at ' . $frame['file'] . ':' . ($frame['line'] ?? 0)
                : '';

            $frames[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()' . $location;
        }

        return $frames;
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $v) {
            $redacted[$key] = (is_string($key) && preg_match(self::REDACT_KEY_PATTERN, $key) === 1)
                ? '[redacted]'
                : $this->redact($v);
        }

        return $redacted;
    }
}
