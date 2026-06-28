<?php
declare(strict_types=1);

namespace GastosHogar\View;

class View
{
    public function __construct(private readonly string $templatesPath) {}

    public function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require $this->templatesPath . '/' . $template . '.html.php';
    }
}
