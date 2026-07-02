<?php
declare(strict_types=1);

namespace GastosHogar\Auth;

use GastosHogar\Config;
use GastosHogar\User\User;
use GastosHogar\User\UserRepositoryInterface;

class Auth
{
    private ?User $cachedActor   = null;
    private bool  $actorResolved = false;

    public function __construct(
        private readonly Config $config,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function isLoggedIn(): bool
    {
        return $this->actor() !== null;
    }

    /** Usuario autenticado actual, re-resuelto desde people.json (nunca confiar solo en la sesión). */
    public function actor(): ?User
    {
        if ($this->actorResolved) {
            return $this->cachedActor;
        }
        $this->actorResolved = true;

        $userId = $_SESSION['user_id'] ?? null;
        if (!is_string($userId)) {
            return $this->cachedActor = null;
        }

        $user = $this->users->findById($userId);
        if ($user === null || !$user->active) {
            session_destroy();
            return $this->cachedActor = null;
        }

        return $this->cachedActor = $user;
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

    public function login(string $username, string $password): bool
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

        $user = $this->users->findByUsername($username);

        if ($user !== null && $user->active && password_verify($password, $user->passwordHash)) {
            $_SESSION['login_attempts'] = 0;
            session_regenerate_id(true);
            $_SESSION['user_id']     = $user->id;
            $_SESSION['last_active'] = time();
            $_SESSION['csrf']        = bin2hex(random_bytes(32));
            $this->actorResolved     = false;
            $this->cachedActor       = null;
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
}
