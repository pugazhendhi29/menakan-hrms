<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;

/**
 * Management report notifications: always in-app (the bell), and by email when Settings allow it for
 * that kind of event and the person has not opted out (My Account).
 */
final class ReportNotifier
{
    /** event => setting that switches its email on */
    private const EMAIL = [
        'report_submitted' => 'report_email_submissions', 'report_awaiting' => 'report_email_submissions',
        'report_approved' => 'report_email_decisions', 'report_returned' => 'report_email_decisions', 'report_completed' => 'report_email_decisions',
        'report_due' => 'report_email_deadlines', 'report_overdue' => 'report_email_overdue',
        'report_escalated' => 'report_email_escalations', 'report_critical' => 'report_email_escalations',
        'report_action' => 'report_email_actions', 'report_summary' => 'report_email_decisions',
    ];

    /**
     * In-app notice (when the person has a login) and email (when Settings allow it and they have not opted out).
     * $emailWithoutAccount: also email someone with no login, at the address on their employee record
     * (used for the manager's copy of a submitted report, so it always reaches the line manager).
     * Returns whether an email was sent.
     * @param array<int, array{path:string, name:string, mime?:string}> $attachments
     */
    public static function toEmployee(int $employeeId, string $event, string $title, ?string $body = null, ?string $link = null,
                                      array $attachments = [], bool $emailWithoutAccount = false): bool
    {
        $person = DB::one('SELECT e.email AS employee_email, u.id AS user_id, u.email AS user_email, u.report_email_opt_out
                             FROM employees e LEFT JOIN users u ON u.employee_id = e.id AND u.is_active = 1 WHERE e.id = ?', [$employeeId]);
        if (!$person || (!$person['user_id'] && !$emailWithoutAccount)) {
            return false;   // no login account: shown on dashboards as "no account"
        }
        if ($person['user_id']) {
            Notifier::toUser((int) $person['user_id'], $event, $title, $body, $link);
        }
        $setting = self::EMAIL[$event] ?? null;
        $to = (string) ($person['user_email'] ?: $person['employee_email']);
        if (!$setting || !setting('report_email_enabled', true) || !setting($setting, false) || $person['report_email_opt_out'] || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $text = $title . ($body ? "\n\n" . $body : '') . ($link ? "\n\nOpen it in the HR portal:\n" . rtrim((string) Config::get('app.url', ''), '/') . $link : '');
        try {
            Mailer::send($to, $title, $text, $attachments);
            return true;
        } catch (\Throwable $e) {
            error_log(sprintf('[%s] Report email to employee %d failed: %s', date('c'), $employeeId, $e->getMessage()));
            return false;
        }
    }

    /**
     * Tells the line manager about a submitted report: in-app, and by email with the report attached as a PDF
     * and its answers in the message, so the manager can read it straight from their inbox.
     */
    public static function submittedToManager(int $managerId, array $report, string $title, string $summary): bool
    {
        $template = ReportService::template((int) $report['template_id']);
        $v = ReportService::version((int) $report['id']);
        $lines = [];
        foreach ($template['fields'] as $f) {
            $val = $f['field_type'] === 'calc' ? ($v['data']['calculated'][$f['field_key']] ?? null) : ($v['data']['fields'][$f['field_key']] ?? null);
            if ($val !== null && $val !== '') {
                $lines[] = $f['label'] . ': ' . ($f['display'] === 'text' ? str_replace("\n", "\n    ", (string) $val) : report_value($f['display'], $val));
            }
        }
        if (!empty($v['data']['critical'])) {
            $lines[] = 'CRITICAL ISSUE: ' . $v['data']['critical_note'];
        }
        if (!empty($v['data']['assessment'])) {
            $lines[] = 'Management assessment: ' . $v['data']['assessment'];
        }
        if ((int) $report['current_version'] > 1 && $v['amendment_note']) {
            $lines[] = 'Amendment (version ' . (int) $report['current_version'] . '): ' . $v['amendment_note'];
        }
        $body = $summary . "\n\n" . implode("\n", $lines) . "\n\nPlease approve it, or return it for correction, in the HR portal. The full report is attached as a PDF.";
        $tmp = null;
        $attachments = [];
        try {
            [$pdf, $name] = ReportExport::reportPdf($report);
            $tmp = tempnam(sys_get_temp_dir(), 'rpt');
            file_put_contents($tmp, $pdf);
            $attachments[] = ['path' => $tmp, 'name' => $name, 'mime' => 'application/pdf'];
        } catch (\Throwable $e) {
            error_log(sprintf('[%s] Report PDF for the email of %s failed: %s', date('c'), $report['report_no'], $e->getMessage()));
        }
        $sent = self::toEmployee($managerId, 'report_submitted', $title, $body, '/reporting/reports/' . $report['id'], $attachments, true);
        if ($tmp !== null) {
            @unlink($tmp);
        }
        if ($sent) {
            AuditLogger::log('report.emailed', 'report', (int) $report['id'], 'Emailed ' . $report['report_no'] . ' (PDF) to the line manager');
        }
        return $sent;
    }

    /** Sends once: returns false (and does nothing) if a notice with this key was already sent. */
    public static function once(string $key, callable $send): bool
    {
        try {
            DB::insert('report_notices', ['notice_key' => mb_substr($key, 0, 160), 'created_at' => now_utc()]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
        $send();
        return true;
    }
}
