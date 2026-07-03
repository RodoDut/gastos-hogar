<?php
declare(strict_types=1);

namespace GastosHogar\Auth;

use GastosHogar\Config;
use GastosHogar\User\User;
use GastosHogar\User\UserRepositoryInterface;

/**
 * Cookie persistente "recordarme" con patrón selector/validator (Barry Jaspan):
 * el selector identifica la fila, el validator (secreto) se guarda solo como
 * hash y se rota en cada uso. Si el validator no matchea, se asume robo de
 * token y se invalidan todas las sesiones del usuario.
 */
class RememberMeService
{
    private const COOKIE_NAME = 'remember_me';

    public function __construct(
        private readonly Config $config,
        private readonly RememberMeRepositoryInterface $tokens,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function issueAndSetCookie(User $user): void
    {
        if (random_int(1, 20) === 1) {
            $this->tokens->purgeExpired();
        }

        $selector  = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = time() + ($this->config->rememberMeDays * 86400);

        $this->tokens->save(new RememberMeToken(
            selector:      $selector,
            validatorHash: hash('sha256', $validator),
            userId:        $user->id,
            expiresAt:     $expiresAt,
        ));

        $this->setCookie("$selector:$validator", $expiresAt);
    }

    public function attemptLogin(): ?User
    {
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        $token = $this->tokens->findBySelector($selector);
        if ($token === null || $token->isExpired()) {
            $this->clearCookie();
            return null;
        }

        if (!hash_equals($token->validatorHash, hash('sha256', $validator))) {
            // Robo de token: el selector es válido pero el validator no matchea.
            $this->tokens->deleteAllForUser($token->userId);
            $this->clearCookie();
            return null;
        }

        $user = $this->users->findById($token->userId);
        if ($user === null || !$user->active) {
            $this->tokens->deleteBySelector($selector);
            $this->clearCookie();
            return null;
        }

        $this->tokens->deleteBySelector($selector);
        $this->issueAndSetCookie($user);

        return $user;
    }

    public function forget(): void
    {
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            $this->tokens->deleteBySelector($selector);
        }

        $this->clearCookie();
    }

    private function setCookie(string $value, int $expiresAt): void
    {
        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => $expiresAt,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
