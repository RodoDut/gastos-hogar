<?php
declare(strict_types=1);

namespace GastosHogar\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Logger PSR-3 a archivo plano (una línea JSON por registro), con rotación
 * por tamaño. Nunca lanza: cualquier fallo de I/O se traga en silencio.
 */
final class FileLogger extends AbstractLogger
{
    private const LEVEL_ORDER = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly int $minLevelValue;

    public function __construct(
        private readonly string $path,
        string $minLevel,
        private readonly array $baseContext = [],
        private readonly int $maxBytes = 1_048_576,
        private readonly int $maxFiles = 3,
        private readonly JsonLineFormatter $formatter = new JsonLineFormatter(),
    ) {
        $this->minLevelValue = self::LEVEL_ORDER[strtolower($minLevel)] ?? self::LEVEL_ORDER[LogLevel::WARNING];
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        try {
            $this->doLog($level, (string) $message, $context);
        } catch (Throwable) {
            // Un logger que tumba el request es peor que no tener logger.
        }
    }

    private function doLog(mixed $level, string $message, array $context): void
    {
        $levelName  = strtolower((string) $level);
        $levelValue = self::LEVEL_ORDER[$levelName] ?? null;

        if ($levelValue === null || $levelValue < $this->minLevelValue) {
            return;
        }

        $line = $this->formatter->format($levelName, $message, array_merge($this->baseContext, $context));
        $this->writeLine($line);
    }

    private function writeLine(string $line): void
    {
        $dir = dirname($this->path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        $handle = @fopen($this->path, 'c+b');
        if ($handle === false) {
            return;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return;
            }

            try {
                $this->rotateIfNeeded($handle);
                @fseek($handle, 0, SEEK_END);
                @fwrite($handle, $line);
            } finally {
                @flock($handle, LOCK_UN);
            }
        } finally {
            @fclose($handle);
        }

        @chmod($this->path, 0600);
    }

    /** @param resource $handle */
    private function rotateIfNeeded($handle): void
    {
        $stat = @fstat($handle);
        if ($stat === false || $stat['size'] < $this->maxBytes) {
            return;
        }

        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $from = $this->path . '.' . $i;
            if (!is_file($from)) {
                continue;
            }

            if ($i + 1 > $this->maxFiles) {
                @unlink($from);
            } else {
                @rename($from, $this->path . '.' . ($i + 1));
            }
        }

        @copy($this->path, $this->path . '.1');
        @ftruncate($handle, 0);
    }
}
