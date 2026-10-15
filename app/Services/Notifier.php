<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Notifier
{
    public static function toUser(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        DB::insert('notifications', [
            'user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 150),
            'body' => $body === null ? null : mb_substr($body, 0, 500), 'link' => $link, 'created_at' => now_utc(),
        ]);
    }

    /** Notifies the active account linked to an employee, if there is one. */
    public static function toEmployee(int $employeeId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $userId = DB::value('SELECT id FROM users WHERE employee_id = ? AND is_active = 1', [$employeeId]);
        if ($userId) {
            self::toUser((int) $userId, $type, $title, $body, $link);
        }
    }

    public static function toAdmins(string $type, string $title, ?string $body = null, ?string $link = null, ?int $exceptUserId = null): void
    {
        foreach (DB::column("SELECT id FROM users WHERE role = 'admin' AND is_active = 1") as $id) {
            if ((int) $id !== $exceptUserId) {
                self::toUser((int) $id, $type, $title, $body, $link);
            }
        }
    }

    public static function unreadCount(int $userId): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}
