<?php
declare(strict_types=1);

namespace GastosHogar;

class Config
{
    public readonly string $appPass;
    public readonly string $dataFile;
    public readonly int    $sessionTtl;
    public readonly int    $maxAttempts;
    public readonly int    $lockoutSec;
    public readonly int    $rememberMeDays;
    public readonly int    $ticketMaxBytes;

    /** @var string[] */
    public readonly array $categories;

    /** @var array<string,string> */
    public readonly array $categoryColors;

    /** Paleta de colores asignados por posición a cada persona */
    public readonly array $personPalette;

    public function __construct()
    {
        $this->appPass     = (string) $_ENV['APP_PASS'];
        $this->dataFile    = (string) $_ENV['DATA_FILE'];
        $this->sessionTtl  = (int)    $_ENV['SESSION_TTL'];
        $this->maxAttempts = (int)    $_ENV['MAX_ATTEMPTS'];
        $this->lockoutSec  = (int)    $_ENV['LOCKOUT_SEC'];
        $this->rememberMeDays = (int) ($_ENV['REMEMBER_ME_DAYS'] ?? 15);
        $this->ticketMaxBytes = (int) ($_ENV['TICKET_MAX_BYTES'] ?? 4194304);

        $this->categories = [
            'Alimentos', 'Servicios', 'Transporte', 'Salud',
            'Educación', 'Hogar', 'Limpieza', 'Entretenimiento', 'Ropa', 'Otro',
        ];

        $this->categoryColors = [
            'Alimentos'       => '#16a34a',
            'Servicios'       => '#2563eb',
            'Transporte'      => '#d97706',
            'Salud'           => '#dc2626',
            'Educación'       => '#7c3aed',
            'Hogar'           => '#0891b2',
            'Limpieza'        => '#facc15',
            'Entretenimiento' => '#ea580c',
            'Ropa'            => '#db2777',
            'Otro'            => '#64748b',
        ];

        $this->personPalette = [
            '#4f46e5', '#e11d48', '#059669', '#d97706', '#7c3aed', '#0891b2',
        ];
    }
}
