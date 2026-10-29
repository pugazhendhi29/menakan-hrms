<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Visa expiry reminders from employees.visa_end_date: 90, 60 and 30 days before the visa ends, then "expired".
 * The dashboard lists every current employee in one of these stages; the daily job (bin/send-expiry-reminders.php)
 * notifies admins once per stage.
 */
final class VisaReminder
{
    /** Days before expiry at which a reminder is due, largest first. */
    public const STAGES = [90, 60, 30];

    /**
     * Current employees whose visa ends within 90 days or has ended, soonest first, with days_left and stage.
     * @return array<int, array<string, mixed>>
     */
    public static function due(?int $departmentId = null): array
    {
        $today = uk_today();
        $sql = "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.visa_type, e.visa_end_date, d.name AS department
                  FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                 WHERE e.deleted_at IS NULL AND e.status <> 'left' AND e.visa_end_date IS NOT NULL AND e.visa_end_date <= ?";
        $params = [date('Y-m-d', strtotime($today . ' +' . self::STAGES[0] . ' days'))];
        if ($departmentId) {
            $sql .= ' AND e.department_id = ?';
            $params[] = $departmentId;
        }
        $rows = DB::all($sql . ' ORDER BY e.visa_end_date, e.first_name, e.last_name', $params);
        foreach ($rows as &$r) {
            $r['days_left'] = (int) floor((strtotime($r['visa_end_date']) - strtotime($today)) / 86400);
            $r['stage'] = self::stage($r['days_left']);
        }
        return $rows;
    }

    /** 90, 60 or 30 (the reminder now due), 0 when expired, null when more than 90 days away. */
    public static function stage(int $daysLeft): ?int
    {
        if ($daysLeft < 0) {
            return 0;
        }
        $stage = null;
        foreach (self::STAGES as $s) {
            if ($daysLeft <= $s) {
                $stage = $s;
            }
        }
        return $stage;
    }

    public static function stageLabel(int $stage): string
    {
        return $stage === 0 ? 'Expired' : "$stage-day reminder";
    }

    /**
     * Notifies every admin (bell and email) about each reminder now due, once per employee, stage and visa end date:
     * a renewed visa (new end date) starts again at 90 days. A stage missed while the job was not running is caught
     * up with only the latest one. @return int reminders sent
     */
    public static function sendDue(): int
    {
        $admins = DB::all("SELECT id, email, username FROM users WHERE role = 'admin' AND is_active = 1");
        $sent = 0;
        foreach (self::due() as $r) {
            $name = employee_name($r);
            $title = $r['stage'] === 0
                ? sprintf('Visa expired: %s (%s)', $name, uk_date($r['visa_end_date']))
                : sprintf('Visa expires in %d days: %s (%s)', $r['stage'], $name, uk_date($r['visa_end_date']));
            $body = sprintf('%s %s · %s · visa ends %s (%s). Please arrange a renewal or a right to work check.', $r['employee_code'], $name,
                $r['visa_type'] ?: 'Visa', uk_date($r['visa_end_date']), $r['days_left'] < 0 ? -$r['days_left'] . ' days ago' : $r['days_left'] . ' days left');
            foreach ($admins as $admin) {
                if (DB::value('SELECT id FROM notifications WHERE user_id = ? AND type = ? AND title = ?', [$admin['id'], 'visa', mb_substr($title, 0, 150)])) {
                    continue;
                }
                Notifier::toUser((int) $admin['id'], 'visa', $title, $body, '/employees/' . $r['id']);
                $sent++;
                try {
                    Mailer::send($admin['email'] ?: $admin['username'], $title, $body);
                } catch (\Throwable $e) {
                    error_log('Visa reminder email to ' . $admin['username'] . ' failed: ' . $e->getMessage());   // the bell notification is already there
                }
            }
        }
        return $sent;
    }
}
