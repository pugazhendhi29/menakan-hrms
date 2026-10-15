<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Locks sign-in after 5 failures for the same account + IP within 15 minutes. */
final class LoginThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW_MINUTES = 15;

    public static function isLocked(string $username, string $ip): bool
    {
        return self::recentFailures($username, $ip) >= self::MAX_FAILURES;
    }

    public static function recentFailures(string $username, string $ip): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $lastSuccess = DB::value('SELECT MAX(attempted_at) FROM login_attempts WHERE username = ? AND ip = ? AND success = 1', [strtolower($username), $ip]);
        if ($lastSuccess && $lastSuccess > $since) {
            $since = $lastSuccess;
        }
        return (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND success = 0 AND attempted_at > ?', [strtolower($username), $ip, $since]);
    }

    public static function record(string $username, string $ip, bool $success): void
    {
        DB::insert('login_attempts', ['username' => mb_substr(strtolower($username), 0, 190), 'ip' => $ip, 'success' => $success ? 1 : 0, 'attempted_at' => now_utc()]);
    }
}
