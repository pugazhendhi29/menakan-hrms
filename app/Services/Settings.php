<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (DB::all('SELECT `key`, `value`, type FROM system_settings') as $row) {
                self::$cache[$row['key']] = match ($row['type']) {
                    'int'     => (int) $row['value'],
                    'decimal' => (float) $row['value'],
                    'bool'    => $row['value'] === '1',
                    default   => $row['value'],
                };
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, string $value, ?int $userId): void
    {
        DB::update('system_settings', ['value' => $value, 'updated_by' => $userId, 'updated_at' => now_utc()], '`key` = ?', [$key]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
