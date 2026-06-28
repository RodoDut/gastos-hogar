<?php
declare(strict_types=1);

namespace GastosHogar\Auth;

use GastosHogar\Config;

class Auth
{
    public function __construct(private readonly Config $config) {}

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['ok']);
    }

    public function checkSessionTimeout(): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > $this->config->sessionTtl) {
            session_destroy();
            return false;
        }

        $_SESSION['last_active'] = time();
        return true;
    }

    public function login(string $password): bool
    {
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        $lastTry  = (int) ($_SESSION['last_attempt']   ?? 0);
        $elapsed  = time() - $lastTry;

        if ($attempts >= $this->config->maxAttempts && $elapsed < $this->config->lockoutSec) {
            return false;
        }

        if ($elapsed >= $this->config->lockoutSec) {
            $_SESSION['login_attempts'] = 0;
        }

        if ($this->verifyPassword($password)) {
            $_SESSION['login_attempts'] = 0;
            session_regenerate_id(true);
            $_SESSION['ok']          = 1;
            $_SESSION['last_active'] = time();
            $_SESSION['csrf']        = bin2hex(random_bytes(32));
            return true;
        }

        $_SESSION['login_attempts'] = ((int) ($_SESSION['login_attempts'] ?? 0)) + 1;
        $_SESSION['last_attempt']   = time();
        return false;
    }

    public function logout(): void
    {
        session_destroy();
    }

    public function getLockoutMessage(): string
    {
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        $lastTry  = (int) ($_SESSION['last_attempt']   ?? 0);
        $elapsed  = time() - $lastTry;

        if ($attempts >= $this->config->maxAttempts && $elapsed < $this->config->lockoutSec) {
            $wait = $this->config->lockoutSec - $elapsed;
            return "Demasiados intentos. Esperá {$wait} segundo" . ($wait !== 1 ? 's' : '') . '.';
        }

        return '';
    }

    public function validateCsrf(): void
    {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            http_response_code(403);
            die('Token de seguridad inválido. <a href="javascript:history.back()">Volver</a>');
        }
    }

    public function csrfField(): string
    {
        $token = htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<input type="hidden" name="csrf" value="' . $token . '">';
    }

    private function verifyPassword(string $input): bool
    {
        return str_starts_with($this->config->appPass, '$2y$')
            ? password_verify($input, $this->config->appPass)
            : hash_equals($this->config->appPass, $input);
    }
}
