<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;

/**
 * Employee ID cards. Admins create, edit, regenerate and revoke them; employees only see their own
 * current card. The card shows live employee details (photo, name, ID, designation, department);
 * the card record holds the card number, issue and expiry dates and status. Every change is audited.
 */
final class IdCardService
{
    public const STATUSES = ['active' => 'Active', 'suspended' => 'Suspended'];

    public static function current(int $employeeId): ?array
    {
        return DB::one('SELECT * FROM id_cards WHERE employee_id = ? AND is_current = 1', [$employeeId]);
    }

    /** Earlier (replaced or revoked) cards, newest first. */
    public static function history(int $employeeId): array
    {
        return DB::all('SELECT * FROM id_cards WHERE employee_id = ? AND is_current IS NULL ORDER BY version DESC, id DESC', [$employeeId]);
    }

    public static function defaultExpiry(): string
    {
        $d = (string) setting('id_card_expiry_date', '');
        return self::isDate($d) && $d > uk_today() ? $d : date('Y-m-d', strtotime(uk_today() . ' +1 year'));
    }

    public static function create(int $employeeId, array $in, int $userId): int
    {
        $e = DB::one("SELECT id, employee_code, status, deleted_at FROM employees WHERE id = ?", [$employeeId]);
        if (!$e || $e['deleted_at'] !== null || $e['status'] === 'left') {
            throw new ValidationException('An ID card can only be created for a current employee.');
        }
        if (self::current($employeeId)) {
            throw new ValidationException('This employee already has an ID card. Edit it, or use Regenerate to issue a new one.');
        }
        [$issue, $expiry] = self::dates($in);
        return DB::transaction(function () use ($e, $issue, $expiry, $in, $userId) {
            $version = (int) DB::value('SELECT COALESCE(MAX(version), 0) FROM id_cards WHERE employee_id = ?', [(int) $e['id']]) + 1;
            $id = DB::insert('id_cards', [
                'employee_id' => (int) $e['id'], 'card_number' => self::number($e['employee_code'], $version), 'version' => $version,
                'issue_date' => $issue, 'expiry_date' => $expiry, 'status' => 'active', 'is_current' => 1,
                'notes' => self::notes($in), 'created_by' => $userId,
            ]);
            AuditLogger::log('id_card.created', 'id_card', $id, sprintf('Created ID card %s (expires %s)', self::number($e['employee_code'], $version), uk_date($expiry)),
                ['employee_id' => (int) $e['id']]);
            return $id;
        });
    }

    /** Edit the current card's dates, status and notes. */
    public static function update(int $id, array $in, int $userId): void
    {
        $card = self::currentById($id);
        [$issue, $expiry] = self::dates($in);
        $status = (string) ($in['status'] ?? $card['status']);
        if (!isset(self::STATUSES[$status])) {
            throw new ValidationException('Choose a valid status.', ['status' => 'Choose Active or Suspended.']);
        }
        $new = ['issue_date' => $issue, 'expiry_date' => $expiry, 'status' => $status, 'notes' => self::notes($in)];
        $changes = [];
        foreach ($new as $k => $v) {
            if ((string) $card[$k] !== (string) $v) {
                $changes[$k] = ['from' => $card[$k], 'to' => $v];
            }
        }
        if (!$changes) {
            return;
        }
        DB::update('id_cards', $new + ['updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('id_card.updated', 'id_card', $id, 'Updated ID card ' . $card['card_number'] . ' (' . implode(', ', array_keys($changes)) . ')',
            ['employee_id' => (int) $card['employee_id']] + $changes);
    }

    /** Issues a new card (next version, new card number) and archives the current one. */
    public static function regenerate(int $id, array $in, int $userId): int
    {
        $card = self::currentById($id);
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationException('Give a reason for regenerating this ID card.', ['reason' => 'A reason is required.']);
        }
        [$issue, $expiry] = self::dates($in);
        $code = (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [(int) $card['employee_id']]);
        return DB::transaction(function () use ($card, $code, $issue, $expiry, $reason, $in, $userId) {
            DB::update('id_cards', ['status' => 'replaced', 'is_current' => null, 'ended_at' => now_utc(), 'ended_reason' => mb_substr($reason, 0, 255)], 'id = ?', [(int) $card['id']]);
            $version = (int) DB::value('SELECT MAX(version) FROM id_cards WHERE employee_id = ?', [(int) $card['employee_id']]) + 1;
            $newId = DB::insert('id_cards', [
                'employee_id' => (int) $card['employee_id'], 'card_number' => self::number($code, $version), 'version' => $version,
                'issue_date' => $issue, 'expiry_date' => $expiry, 'status' => 'active', 'is_current' => 1,
                'notes' => self::notes($in), 'created_by' => $userId,
            ]);
            AuditLogger::log('id_card.regenerated', 'id_card', $newId, sprintf('Regenerated ID card: %s replaces %s. Reason: %s', self::number($code, $version), $card['card_number'], $reason),
                ['employee_id' => (int) $card['employee_id'], 'previous_id' => (int) $card['id']]);
            return $newId;
        });
    }

    /** Withdraws the current card; the employee no longer sees a card until a new one is created. */
    public static function revoke(int $id, string $reason, int $userId): void
    {
        $card = self::currentById($id);
        if (trim($reason) === '') {
            throw new ValidationException('Give a reason for revoking this ID card.', ['reason' => 'A reason is required.']);
        }
        DB::update('id_cards', ['status' => 'revoked', 'is_current' => null, 'ended_at' => now_utc(), 'ended_reason' => mb_substr(trim($reason), 0, 255),
            'updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('id_card.revoked', 'id_card', $id, 'Revoked ID card ' . $card['card_number'] . ': ' . trim($reason), ['employee_id' => (int) $card['employee_id']]);
    }

    public static function number(string $employeeCode, int $version): string
    {
        return sprintf('%s-%02d', $employeeCode, $version);
    }

    private static function currentById(int $id): array
    {
        return DB::one('SELECT * FROM id_cards WHERE id = ? AND is_current = 1', [$id])
            ?? throw new ValidationException('Only the current ID card can be changed.');
    }

    /** @return array{0:string,1:string} issue date, expiry date */
    private static function dates(array $in): array
    {
        $issue = trim((string) ($in['issue_date'] ?? '')) ?: uk_today();
        $expiry = trim((string) ($in['expiry_date'] ?? ''));
        $errors = [];
        if (!self::isDate($issue)) {
            $errors['issue_date'] = 'Enter a valid issue date.';
        }
        if (!self::isDate($expiry)) {
            $errors['expiry_date'] = 'Enter a valid expiry date.';
        } elseif (self::isDate($issue) && $expiry <= $issue) {
            $errors['expiry_date'] = 'The expiry date must be after the issue date.';
        }
        if ($errors) {
            throw new ValidationException('Please check the ID card dates.', $errors);
        }
        return [$issue, $expiry];
    }

    private static function notes(array $in): ?string
    {
        $n = trim((string) ($in['notes'] ?? ''));
        return $n === '' ? null : mb_substr($n, 0, 255);
    }

    private static function isDate(string $d): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}
