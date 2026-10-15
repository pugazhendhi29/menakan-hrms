<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Current-user access. Employee-scoped queries must always use Auth::employeeId(),
 * which comes from the server-side session, never from the URL or form.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::get('user_id');
            self::$user = $id ? DB::one(
                'SELECT u.*, e.employee_code, e.first_name, e.last_name, e.deleted_at AS employee_deleted_at, e.photo_key
                   FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                  WHERE u.id = ? AND u.is_active = 1',
                [(int) $id]
            ) : null;
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function employeeId(): ?int
    {
        $u = self::user();
        return ($u && $u['employee_id'] !== null && $u['employee_deleted_at'] === null) ? (int) $u['employee_id'] : null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    public static function displayName(): string
    {
        $u = self::user();
        if (!$u) {
            return '';
        }
        return $u['first_name'] ? $u['first_name'] . ' ' . mb_substr((string) $u['last_name'], 0, 1) . '.' : $u['username'];
    }

    public static function login(array $user, Request $request): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('last_activity', time());
        DB::insert('user_sessions', [
            'user_id'         => (int) $user['id'],
            'session_id_hash' => hash('sha256', Session::id()),
            'ip'              => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'created_at'      => now_utc(),
            'last_seen_at'    => now_utc(),
        ]);
        DB::update('users', ['last_login_at' => now_utc()], 'id = ?', [(int) $user['id']]);
        self::reset();
    }

    public static function logout(): void
    {
        $sid = Session::id();
        if ($sid !== '') {
            DB::update('user_sessions', ['revoked_at' => now_utc()], 'session_id_hash = ? AND revoked_at IS NULL', [hash('sha256', $sid)]);
        }
        Session::invalidate();
        self::reset();
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$loaded = false;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /** Returns null when the password meets the policy, otherwise the reason. */
    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'Password must be at least 10 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }
        return null;
    }
}
