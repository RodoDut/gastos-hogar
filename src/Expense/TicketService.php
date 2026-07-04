<?php
declare(strict_types=1);

namespace GastosHogar\Expense;

/**
 * Valida, guarda, borra y sirve los comprobantes adjuntos a un gasto.
 * No conoce HTTP ni sesión: solo filesystem.
 */
class TicketService
{
    private const ALLOWED_MIME_EXT = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly string $ticketsDir,
        private readonly int $maxBytes,
    ) {}

    public function store(string $expenseId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name'] ?? '')
        ) {
            throw new InvalidTicketException('No se pudo subir el archivo.');
        }

        if (($file['size'] ?? 0) > $this->maxBytes) {
            throw new InvalidTicketException('El archivo supera el tamaño máximo permitido.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME_EXT[$mime])) {
            throw new InvalidTicketException('Tipo de archivo no permitido. Solo se aceptan JPG, PNG o PDF.');
        }

        $ext      = self::ALLOWED_MIME_EXT[$mime];
        $filename = $expenseId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        if (!is_dir($this->ticketsDir)) {
            mkdir($this->ticketsDir, 0700, true);
        }

        $htaccess = $this->ticketsDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $dest = $this->ticketsDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new InvalidTicketException('No se pudo guardar el archivo.');
        }
        @chmod($dest, 0600);

        return $filename;
    }

    public function delete(?string $filename): void
    {
        if ($filename === null) {
            return;
        }

        $path = $this->ticketsDir . '/' . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function streamToClient(string $filename): void
    {
        $path = $this->ticketsDir . '/' . basename($filename);

        if (!is_file($path)) {
            http_response_code(404);
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $path) ?: 'application/octet-stream';
        finfo_close($finfo);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($filename) . '"');
        readfile($path);
        exit;
    }
}
