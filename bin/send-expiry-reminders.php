<?php

/**
 * Daily job: notifies admins (one summary) and each employee about documents that expire
 * within the warning period or have expired. Safe to run more than once a day.
 *
 * Windows Task Scheduler (daily 07:00):
 *   C:\xampp\php\php.exe C:\path\to\menakan-hrms\bin\send-expiry-reminders.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Core\DB;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\Mailer;
use App\Services\Notifier;
use App\Services\Settings;

$days = (int) Settings::get('document_expiry_warning_days', 30);
$docs = DocumentService::expiring($days);
$today = uk_today();
$alreadySent = fn (int $userId, string $title) => (bool) DB::value(
    'SELECT id FROM notifications WHERE user_id = ? AND title = ? AND created_at >= ?', [$userId, $title, to_utc("$today 00:00")]
);

$employeeNotices = 0;
foreach ($docs as $d) {
    $left = (int) floor((strtotime($d['expiry_date']) - strtotime($today)) / 86400);
    $title = $left < 0 ? "Your {$d['category_name']} has expired" : "Your {$d['category_name']} expires in $left days";
    $userId = DB::value('SELECT id FROM users WHERE employee_id = ? AND is_active = 1', [$d['employee_id']]);
    if ($userId && !$alreadySent((int) $userId, $title)) {
        Notifier::toUser((int) $userId, 'document', $title, 'Expiry date ' . uk_date($d['expiry_date']) . '. Please bring the renewed document to HR.', '/my/documents');
        $employeeNotices++;
    }
}

if ($docs) {
    $title = 'Documents needing renewal: ' . count($docs);
    $lines = array_map(fn ($d) => sprintf('%s %s - %s - %s', $d['employee_code'], employee_name($d), $d['category_name'], uk_date($d['expiry_date'])), $docs);
    foreach (DB::all("SELECT id, email, username FROM users WHERE role = 'admin' AND is_active = 1") as $admin) {
        if (!$alreadySent((int) $admin['id'], $title)) {
            Notifier::toUser((int) $admin['id'], 'document', $title, implode('; ', array_slice($lines, 0, 5)) . (count($lines) > 5 ? '…' : ''), '/documents?status=expiring');
            Mailer::send($admin['email'] ?: $admin['username'], $title, "Documents expiring within $days days or already expired:\n\n" . implode("\n", $lines));
        }
    }
}

AuditLogger::log('job.expiry_reminders', 'employee_document', null, sprintf('Expiry reminder job: %d documents, %d employee notices', count($docs), $employeeNotices), null, null);
fwrite(STDOUT, sprintf("%d documents expiring within %d days or expired; %d employee notifications sent.\n", count($docs), $days, $employeeNotices));
