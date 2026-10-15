<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper. Under the CLI (tests, scripts) it uses a plain $_SESSION array
 * with a synthetic ID so the same code paths can be exercised without headers.
 */
final class Session
{
    public static function start(bool $secure = false): void
    {
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            $_SESSION['_cli_sid'] ??= bin2hex(random_bytes(16));
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (Config::get('app.session_idle', 30) * 60 + 300));
        session_name(Config::get('app.session_name', 'MTHRMSSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (Config::get('app.base_path') ?: '') . '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_save_path(Config::get('app.storage_path') . '/sessions');
        session_start();
    }

    public static function id(): string
    {
        return PHP_SAPI === 'cli' ? (string) ($_SESSION['_cli_sid'] ?? '') : session_id();
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI === 'cli') {
            $_SESSION['_cli_sid'] = bin2hex(random_bytes(16));
            return;
        }
        session_regenerate_id(true);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $v;
    }

    /** Clears all session data but keeps the session usable (new ID, fresh CSRF token). */
    public static function invalidate(): void
    {
        $_SESSION = [];
        self::regenerate();
        if (PHP_SAPI === 'cli') {
            $_SESSION['_cli_sid'] ??= bin2hex(random_bytes(16));
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlashes(): array
    {
        return self::pull('_flash', []);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}
