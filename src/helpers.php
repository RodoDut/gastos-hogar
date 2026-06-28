<?php
declare(strict_types=1);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float $n): string
{
    return '$ ' . number_format($n, 2, ',', '.');
}

function asset(string $path): string
{
    $full    = dirname(__DIR__) . '/public/' . ltrim($path, '/');
    $version = file_exists($full) ? filemtime($full) : 0;
    return '/' . ltrim($path, '/') . '?v=' . $version;
}
