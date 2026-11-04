<?php

/**
 * Management reporting automation. Run every hour; running it more often sends nothing twice.
 *
 *  - reminds people whose daily / weekly / monthly report is due within the reminder window (Settings)
 *  - marks reports Overdue once the deadline has passed (never approves them) and tells the employee and
 *    a daily summary to their line manager
 *  - reminds managers about reports waiting for their decision, and escalates a report to the next manager
 *    up when it has waited longer than the escalation period (Settings)
 *  - reminds people about management actions past their due date
 *  - tells the Directors when a month's consolidated company report is ready
 *
 * Windows Task Scheduler (hourly):  C:\xampp\php\php.exe C:\path\to\menakan-hrms\bin\report-scheduler.php
 * cPanel cron (hourly):             /usr/local/bin/php /home/<user>/<app>/bin/report-scheduler.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Core\DB;
use App\Services\AuditLogger;
use App\Services\ReportAccess;
use App\Services\ReportCatalog;
use App\Services\ReportNotifier;
use App\Services\ReportPeriod;
use App\Services\ReportService;

$now = now_utc();
$today = uk_today();
$counts = ['reminders' => 0, 'overdue' => 0, 'review_reminders' => 0, 'escalated' => 0, 'actions' => 0, 'company' => 0];
$people = array_keys(ReportAccess::people());
$reminderHours = max(0, (int) setting('report_reminder_hours', 2));

// ---- Deadline reminders and overdue reports
$lookback = ['daily' => '-10 days', 'weekly' => '-5 weeks', 'monthly' => '-2 months'];
$overdueByManager = [];
foreach (ReportCatalog::PERIODS as $period => $label) {
    $from = max(ReportPeriod::startDate(), date('Y-m-d', strtotime($today . ' ' . $lookback[$period])));
    $expected = ReportPeriod::expected($people, $period, $from, $today);
    foreach ($expected as $employeeId => $starts) {
        foreach ($starts as $start) {
            $end = ReportPeriod::bounds($period, $start)[1];
            $due = ReportPeriod::dueAt($period, $end);
            $r = ReportService::forPeriod($employeeId, $period, $start);
            if ($r && (int) $r['current_version'] > 0) {
                continue;   // submitted (or resubmitted): nothing to chase
            }
            $what = strtolower($label) . ' report for ' . ReportPeriod::label($period, $start);
            if ($due > $now) {
                $remindFrom = gmdate('Y-m-d H:i:s', strtotime($due . ' UTC') - $reminderHours * 3600);
                if ($now >= $remindFrom && ReportNotifier::once("due:$period:$start:$employeeId", fn () => ReportNotifier::toEmployee($employeeId, 'report_due',
                    'Reminder: your ' . $what . ' is due by ' . uk_time($due), 'Please complete and submit it before the deadline.', '/reporting/my?period=' . $period))) {
                    $counts['reminders']++;
                }
                continue;
            }
            $id = ReportService::markOverdue($employeeId, $period, $start);
            if ($id !== null) {
                $counts['overdue']++;
                ReportNotifier::toEmployee($employeeId, 'report_overdue', 'Overdue: your ' . $what, 'The deadline was ' . uk_datetime($due) . '. Please submit it as soon as you can; it will be marked as late.', '/reporting/reports/' . $id . '/edit');
                if ($m = ReportAccess::managerOf($employeeId)) {
                    $overdueByManager[$m][] = employee_name(ReportAccess::person($employeeId)) . ' (' . ReportPeriod::shortLabel($period, $start) . ')';
                }
            }
        }
    }
}
foreach ($overdueByManager as $managerId => $list) {
    ReportNotifier::once("overdue-summary:$managerId:$today:" . md5(implode('|', $list)), fn () => ReportNotifier::toEmployee($managerId, 'report_overdue',
        count($list) . ' report(s) from your team are overdue', implode('; ', array_slice($list, 0, 8)) . (count($list) > 8 ? '…' : ''), '/reporting/team'));
}

// ---- Reports waiting for a manager's decision: daily reminder, then automatic escalation
$escalateDays = max(1, (int) setting('report_escalate_after_days', 2));
$waiting = DB::all('SELECT r.id, (SELECT MAX(created_at) FROM report_reviews rv WHERE rv.report_id = r.id AND rv.action IN (\'escalated\',\'auto_escalated\')) AS escalated_at
                      FROM reports r WHERE r.status IN (' . DB::placeholders(ReportAccess::AWAITING) . ')', ReportAccess::AWAITING);
foreach ($waiting as $w) {
    $r = ReportService::find((int) $w['id']);
    // Measured from when the manager actually received it (a report for an earlier date may show an older submission time).
    $since = $w['escalated_at'] ?? $r['received_at'] ?? $r['submitted_at'];
    $decider = ReportAccess::decider($r);
    if ($decider === null || $since === null) {
        continue;
    }
    $age = (strtotime($now . ' UTC') - strtotime($since . ' UTC')) / 86400;
    if ($age >= $escalateDays) {
        try {
            if (ReportService::escalate($r, '', null, true)) {
                $counts['escalated']++;
                continue;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "Could not escalate report {$r['id']}: {$e->getMessage()}\n");
        }
    }
    if ($age >= 1 && ReportNotifier::once("awaiting:{$r['id']}:$decider:$today", fn () => ReportNotifier::toEmployee($decider, 'report_awaiting',
        'Waiting for your approval: ' . $r['report_no'] . ' from ' . employee_name($r), 'Submitted ' . uk_datetime($r['submitted_at']) . '. Please approve it or return it for correction.', '/reporting/reports/' . $r['id']))) {
        $counts['review_reminders']++;
    }
}

// ---- Management actions past their due date
foreach (DB::all("SELECT * FROM management_actions WHERE status IN ('open','in_progress') AND due_date < ?", [$today]) as $a) {
    if (ReportNotifier::once("action-overdue:{$a['id']}:$today", fn () => ReportNotifier::toEmployee((int) $a['assigned_to_employee_id'], 'report_action',
        'Overdue action: ' . $a['title'], 'It was due ' . uk_date($a['due_date']) . '.', '/reporting/actions'))) {
        $counts['actions']++;
    }
}

// ---- Monthly company report ready for the Directors (after last month's deadline)
$lastMonth = ReportPeriod::bounds('monthly', date('Y-m-d', strtotime(date('Y-m-01', strtotime($today)) . ' -1 day')));
if ($lastMonth[1] >= ReportPeriod::startDate() && ReportPeriod::dueAt('monthly', $lastMonth[1]) <= $now) {
    foreach ($people as $id) {
        if (ReportAccess::isDirector($id) && ReportNotifier::once("company-month:{$lastMonth[0]}:$id", fn () => ReportNotifier::toEmployee($id, 'report_summary',
            'Company performance report for ' . date('F Y', strtotime($lastMonth[0])) . ' is ready', 'The consolidated monthly reports from every department are on the Directors\' dashboard.',
            '/reporting/directors?period=monthly&date=' . $lastMonth[0]))) {
            $counts['company']++;
        }
    }
}

if (array_sum($counts)) {
    AuditLogger::log('job.report_scheduler', 'report', null, 'Report scheduler: ' . implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($counts), $counts)), null, null);
}
fwrite(STDOUT, implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($counts), $counts)) . "\n");
