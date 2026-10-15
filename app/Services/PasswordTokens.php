<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;

/** One-time links for password reset / account activation. Only the SHA-256 of a token is stored. */
final class PasswordTokens
{
    public static function issue(int $userId, string $purpose, ?int $createdBy, int $hours = 48): string
    {
        $token = bin2hex(random_bytes(32));
        DB::query('UPDATE password_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_utc(), $userId]);
        DB::insert('password_tokens', [
            'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $hours * 3600), 'created_by' => $createdBy,
        ]);
        $link = Config::get('app.url') . '/password/reset/' . $token;
        $user = DB::one('SELECT username, email FROM users WHERE id = ?', [$userId]);
        Mailer::send(
            $user['email'] ?: $user['username'],
            $purpose === 'activation' ? 'Activate your Menakan HR Portal account' : 'Reset your Menakan HR Portal password',
            "Hello,\n\nUse this link to " . ($purpose === 'activation' ? 'set your password and activate your account' : 'choose a new password') .
            ":\n$link\n\nThe link expires in $hours hours and can be used once.\nUsername: {$user['username']}\n"
        );
        return $link;
    }

    public static function find(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return DB::one(
            'SELECT t.*, u.username, u.is_active FROM password_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > ?',
            [hash('sha256', $token), now_utc()]
        );
    }

    public static function consume(int $tokenId): void
    {
        DB::update('password_tokens', ['used_at' => now_utc()], 'id = ?', [$tokenId]);
    }
}
