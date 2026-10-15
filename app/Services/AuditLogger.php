<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;

/** Records who did what to which record. Secrets are stripped before anything is written. */
final class AuditLogger
{
    private const REDACT = ['password', 'password_hash', 'token', 'token_hash', '_csrf', 'current_password', 'password_confirmation'];

    public static function log(string $action, ?string $entityType, ?int $entityId, string $summary, ?array $changes = null, ?int $userId = null): void
    {
        DB::insert('audit_logs', [
            'user_id'     => $userId ?? Auth::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'summary'     => mb_substr($summary, 0, 255),
            'changes'     => $changes === null ? null : json_encode(self::redact($changes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null),
            'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
            'created_at'  => now_utc(),
        ]);
    }

    /** Returns only the fields that changed, as [field => [old, new]]. */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $new) {
            $old = $before[$k] ?? null;
            if ((string) $old !== (string) $new) {
                $out[$k] = [$old, $new];
            }
        }
        return $out;
    }

    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array((string) $k, self::REDACT, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }
}
