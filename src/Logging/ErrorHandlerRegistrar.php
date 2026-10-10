<?php
declare(strict_types=1);

namespace GastosHogar\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Registra los handlers globales de error/excepción/shutdown de PHP para que
 * todo quede trazado en el LoggerInterface inyectado, sin convertir errores
 * en excepciones ni mostrar detalles internos al cliente.
 */
final class ErrorHandlerRegistrar
{
    private const FATAL_SEVERITIES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function register(): void
    {
        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    private function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Obligatorio: @chmod/@unlink/@file_put_contents bajan error_reporting()
        // pero no lo anulan, así que un error silenciado a propósito no debe logearse.
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $this->logger->log($this->levelForSeverity($severity), $message, [
            'severity' => $severity,
            'file'     => $file,
            'line'     => $line,
        ]);

        return true;
    }

    private function handleException(Throwable $e): void
    {
        $this->logger->critical('unhandled_exception', ['exception' => $e]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<title>GastosHogar</title></head><body>'
            . '<p>Ocurrió un error inesperado. Intentá de nuevo más tarde.</p>'
            . '</body></html>';
    }

    private function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL_SEVERITIES, true)) {
            return;
        }

        $this->logger->critical('fatal_error', [
            'severity' => $error['type'],
            'file'     => $error['file'],
            'line'     => $error['line'],
            'message'  => $error['message'],
        ]);
    }

    private function levelForSeverity(int $severity): string
    {
        return match ($severity) {
            E_WARNING, E_USER_WARNING => LogLevel::WARNING,
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT => LogLevel::NOTICE,
            default => LogLevel::ERROR,
        };
    }
}
