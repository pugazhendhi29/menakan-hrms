<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Services\EmployeeService;
use App\Services\ReportAccess;
use App\Services\ReportCatalog;
use App\Services\ReportService;
use App\Services\Settings;

/**
 * Management reporting: 12 approved job titles, 36 templates, and reports travelling up the real
 * reporting lines (Freight Administrator -> Operations Supervisor -> Transport Manager -> Director).
 */
final class ReportingTest extends FeatureTestCase
{
    private array $p = [];   // employee ids
    private array $u = [];   // user ids
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        ReportAccess::flush();
        DB::update('system_settings', ['value' => '2026-01-01'], '`key` = ?', ['report_start_date']);
        DB::update('system_settings', ['value' => '1,2,3,4,5,6,7'], '`key` = ?', ['report_daily_days']);
        Settings::flush();
        $this->day = date('Y-m-d', strtotime(uk_today() . ' -2 days'));
        $job = fn (string $role) => (int) DB::value('SELECT id FROM job_titles WHERE report_role = ?', [$role]);
        $hash = password_hash('Password12345', PASSWORD_BCRYPT, ['cost' => 4]);
        $mk = function (string $key, string $first, string $role, ?string $manager) use ($job, $hash) {
            $this->p[$key] = DB::insert('employees', ['employee_code' => EmployeeService::nextCode(), 'first_name' => $first, 'last_name' => 'Test', 'department_id' => self::$f['dept'],
                'job_title_id' => $job($role), 'line_manager_id' => $manager ? $this->p[$manager] : null, 'start_date' => '2024-01-01', 'status' => 'active',
                'email' => "$key.work@menakan.example"]);
            $this->u[$key] = DB::insert('users', ['username' => 'rep_' . $key, 'email' => "$key@menakan.example", 'role' => 'employee', 'employee_id' => $this->p[$key], 'password_hash' => $hash,
                'can_backdate_reports' => 1]);   // most tests write reports for past days
        };
        $mk('dir', 'Dora', 'director', null);
        $mk('tm', 'Tom', 'transport_manager', 'dir');
        $mk('tm2', 'Tia', 'transport_manager', 'dir');           // same job title, not in Tom's chain
        $mk('os', 'Oscar', 'operations_supervisor', 'tm');
        $mk('fa', 'Fay', 'freight_administrator', 'os');
        ReportAccess::flush();
    }

    /** A complete set of answers for a template (numbers for number fields, text for text fields). */
    private function answers(int $templateId, array $override = []): array
    {
        $fields = [];
        foreach (ReportService::template($templateId)['fields'] as $f) {
            $fields[$f['field_key']] = match ($f['field_type']) {
                'calc' => null,
                'text' => 'Notes for ' . $f['field_key'],
                'pct' => '90',
                'money' => '1250.50',
                default => '4',
            };
        }
        return ['fields' => array_filter($override + $fields, fn ($v) => $v !== null)];
    }

    /** Starts and submits a report as the given person; returns the report row. */
    private function submit(string $who, string $period, array $override = [], array $extra = []): array
    {
        $this->actingAs($this->u[$who]);
        $res = $this->post('/reporting/start', ['period' => $period, 'date' => $this->day]);
        $this->assertSame(302, $res->status);
        $id = (int) DB::value('SELECT id FROM reports WHERE employee_id = ? AND period = ? ORDER BY id DESC LIMIT 1', [$this->p[$who], $period]);
        $r = ReportService::find($id);
        $res = $this->post("/reporting/reports/$id", $this->answers((int) $r['template_id'], $override) + $extra + ['intent' => 'submit']);
        $this->assertSame(302, $res->status);
        return ReportService::find($id);
    }

    private function review(string $who, int $id, string $decision, string $comment = 'Checked and agreed.', ?int $rating = 4): void
    {
        $this->actingAs($this->u[$who]);
        $this->post("/reporting/reports/$id/review", ['decision' => $decision, 'comment' => $comment, 'rating' => (string) $rating]);
    }

    private function statusOf(int $id): string
    {
        return (string) DB::value('SELECT status FROM reports WHERE id = ?', [$id]);
    }

    public function testExactlyTwelveApprovedTitlesAndThirtySixDistinctTemplates(): void
    {
        $this->assertSame(12, (int) DB::value('SELECT COUNT(*) FROM job_titles WHERE report_role IS NOT NULL'));
        $this->assertSame(array_keys(ReportCatalog::ROLES), DB::column('SELECT report_role FROM job_titles WHERE report_role IS NOT NULL ORDER BY FIELD(report_role, ' . DB::placeholders(array_keys(ReportCatalog::ROLES)) . ')', array_keys(ReportCatalog::ROLES)));
        $this->assertSame(36, (int) DB::value('SELECT COUNT(*) FROM report_templates WHERE is_active = 1'));
        $signatures = [];
        foreach (DB::all('SELECT id, role_code, period FROM report_templates') as $t) {
            $fields = ReportService::template((int) $t['id'])['fields'];
            $role = array_column(array_filter($fields, fn ($f) => $f['section'] === 'role'), 'field_key');
            $standard = array_column(array_filter($fields, fn ($f) => $f['section'] === 'standard'), 'field_key');
            $this->assertGreaterThanOrEqual(6, count($role), "{$t['role_code']} {$t['period']}");
            $this->assertSame(array_column(ReportCatalog::STANDARD, 0), $standard);
            $signatures[implode(',', $role)] = true;
        }
        $this->assertCount(36, $signatures, 'Every template has its own role-specific questions');
    }

    public function testReportTravelsUpTheRealChainToTheDirectorAndCompletes(): void
    {
        // Employee submits: it goes to their own line manager, who is notified.
        $fa = $this->submit('fa', 'daily');
        $this->assertSame('submitted', $fa['status']);
        $this->assertSame($this->p['os'], (int) $fa['line_manager_id']);
        $this->assertMatchesRegularExpression('/^MT-D-\d{6}$/', $fa['report_no']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'report_submitted'", [$this->u['os']]));

        // Not the author, not someone else's manager, and not without a comment.
        $this->review('fa', (int) $fa['id'], 'approved');
        $this->review('tm', (int) $fa['id'], 'approved');
        $this->assertSame('submitted', $this->statusOf((int) $fa['id']));
        $this->actingAs($this->u['os']);
        $this->assertSame(200, $this->get('/reporting/reports/' . $fa['id'])->status);
        $this->assertSame('under_review', $this->statusOf((int) $fa['id']));
        $this->review('os', (int) $fa['id'], 'approved', '');
        $this->assertSame('under_review', $this->statusOf((int) $fa['id']));
        $this->review('os', (int) $fa['id'], 'approved', 'Good day, paperwork complete.', 5);
        $this->assertSame('approved', $this->statusOf((int) $fa['id']));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'report_approved'", [$this->u['fa']]));

        // The supervisor's own report must add an assessment; it consolidates the approved team report.
        $this->actingAs($this->u['os']);
        $this->post('/reporting/start', ['period' => 'daily', 'date' => $this->day]);
        $osId = (int) DB::value("SELECT id FROM reports WHERE employee_id = ? AND period = 'daily'", [$this->p['os']]);
        $os = ReportService::find($osId);
        $this->post("/reporting/reports/$osId", $this->answers((int) $os['template_id']) + ['intent' => 'submit']);
        $this->assertSame('draft', $this->statusOf($osId), 'A manager cannot submit without an assessment');
        $os = $this->submit('os', 'daily', [], ['assessment' => 'Team met every target; one late delivery note chased.', 'team_rating' => '4']);
        $this->assertSame('submitted', $os['status']);
        $this->assertSame($this->p['tm'], (int) $os['line_manager_id']);
        $this->assertSame('forwarded', $this->statusOf((int) $fa['id']));
        $this->assertSame([(int) $fa['id']], array_map('intval', DB::column('SELECT child_report_id FROM report_consolidations WHERE parent_report_id = ?', [$osId])));
        $snapshot = ReportService::version($osId);
        $this->assertSame(1, $snapshot['consolidation']['counts']['approved']);

        // Transport Manager approves and forwards; the Director approves: the whole chain is completed.
        $this->review('tm', $osId, 'approved', 'Agreed with the supervisor.');
        $tm = $this->submit('tm', 'daily', [], ['assessment' => 'Operations stable across the depot today.', 'team_rating' => '4']);
        $this->assertSame($this->p['dir'], (int) $tm['line_manager_id']);
        $this->assertSame('forwarded', $this->statusOf($osId));
        $this->review('dir', (int) $tm['id'], 'approved', 'Noted, thank you.');
        foreach ([(int) $tm['id'], $osId, (int) $fa['id']] as $id) {
            $this->assertSame('completed', $this->statusOf($id));
        }

        // A Director's own report has no one above it: submitting completes it.
        $dir = $this->submit('dir', 'daily', [], ['assessment' => 'Company position steady; transport team performing well.', 'team_rating' => '5']);
        $this->assertSame('completed', $dir['status']);
        $this->assertGreaterThan(0, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'report' AND entity_id = ? AND action = 'report.completed'", [$fa['id']]));
    }

    public function testReturnedReportKeepsTheOriginalAndRecordsTheAmendment(): void
    {
        $fa = $this->submit('fa', 'daily', ['bookings_processed' => '12']);
        $this->review('os', (int) $fa['id'], 'returned', 'Bookings figure looks wrong, please check.');
        $this->assertSame('returned', $this->statusOf((int) $fa['id']));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'report_returned'", [$this->u['fa']]));

        $this->actingAs($this->u['fa']);
        $this->post('/reporting/reports/' . $fa['id'], $this->answers((int) $fa['template_id'], ['bookings_processed' => '21']) + ['intent' => 'submit']);
        $this->assertSame('returned', $this->statusOf((int) $fa['id']), 'An amendment needs a reason');
        $this->post('/reporting/reports/' . $fa['id'], $this->answers((int) $fa['template_id'], ['bookings_processed' => '21']) + ['intent' => 'submit', 'amendment_note' => 'Typo: 21 not 12.']);
        $this->assertSame('submitted', $this->statusOf((int) $fa['id']));
        $this->assertSame('12', ReportService::version((int) $fa['id'], 1)['data']['fields']['bookings_processed']);
        $this->assertSame('21', ReportService::version((int) $fa['id'], 2)['data']['fields']['bookings_processed']);
        $this->assertSame('Typo: 21 not 12.', ReportService::version((int) $fa['id'], 2)['amendment_note']);
        $this->assertSame(21.0, (float) DB::value("SELECT value FROM report_metrics WHERE report_id = ? AND field_key = 'bookings_processed'", [$fa['id']]));
        // Submitted reports cannot be edited.
        $this->assertSame(403, $this->post('/reporting/reports/' . $fa['id'], ['intent' => 'save'])->status);
    }

    public function testEveryoneSeesOnlyTheirOwnPartOfTheHierarchy(): void
    {
        $fa = $this->submit('fa', 'daily');
        $os = $this->submit('os', 'daily', [], ['assessment' => 'Nothing approved yet, will review tomorrow morning.', 'team_rating' => '3']);
        $url = '/reporting/reports/' . $fa['id'];
        foreach (['fa' => 200, 'os' => 200, 'tm' => 200, 'dir' => 200, 'tm2' => 404] as $who => $code) {
            $this->actingAs($this->u[$who]);
            $this->assertSame($code, $this->get($url)->status, $who);
            $this->assertSame($code, $this->get("$url/pdf")->status, "$who pdf");
        }
        $this->actingAs($this->u['fa']);
        $this->assertSame(404, $this->get('/reporting/reports/' . $os['id'])->status, 'Employees cannot read their manager\'s report');
        $this->assertSame(403, $this->get('/reporting/directors')->status);
        $this->assertSame(403, $this->get('/reporting/team')->status);
        $this->assertSame(403, $this->get('/reporting/export', ['scope' => 'company', 'period' => 'daily', 'date' => $this->day])->status);
        $this->assertStringNotContainsString($os['report_no'], $this->get('/reporting/reports')->body);
        $this->actingAs($this->u['tm2']);
        $this->assertStringNotContainsString($fa['report_no'], $this->get('/reporting/reports')->body);
        $this->assertSame(403, $this->get('/reporting/export', ['scope' => 'team', 'period' => 'daily', 'date' => $this->day, 'manager_id' => (string) $this->p['tm']])->status);
        $this->actingAs(self::$f['admin2']);
        $this->assertSame(200, $this->get($url)->status, 'HR admins have full access');
        $this->review('tm', (int) $fa['id'], 'approved');
        $this->assertSame('under_review', $this->statusOf((int) $fa['id']), 'Only the manager the report is waiting for may decide (it was opened by them above)');
    }

    public function testEscalationGoesToTheNextManagerUp(): void
    {
        $fa = $this->submit('fa', 'daily');
        $this->review('os', (int) $fa['id'], 'escalated', 'Needs a decision above my level.');
        $this->assertSame('escalated', $this->statusOf((int) $fa['id']));
        $this->assertSame($this->p['tm'], (int) DB::value('SELECT escalated_to_employee_id FROM reports WHERE id = ?', [$fa['id']]));
        $this->review('os', (int) $fa['id'], 'approved');
        $this->assertSame('escalated', $this->statusOf((int) $fa['id']));
        $this->review('tm', (int) $fa['id'], 'approved', 'Approved after escalation.');
        $this->assertSame('approved', $this->statusOf((int) $fa['id']));

        // Automatic escalation when nobody decided in time.
        $other = $this->submit('fa', 'weekly');
        $this->assertTrue(ReportService::escalate(ReportService::find((int) $other['id']), '', null, true));
        $this->assertSame('auto_escalated', DB::value('SELECT action FROM report_reviews WHERE report_id = ? ORDER BY id DESC LIMIT 1', [$other['id']]));
    }

    public function testOverdueReportsAreFlaggedNeverApprovedAndCanStillBeSubmittedLate(): void
    {
        $id = ReportService::markOverdue($this->p['fa'], 'daily', $this->day);
        $this->assertSame('overdue', $this->statusOf($id));
        $this->review('os', $id, 'approved');
        $this->assertSame('overdue', $this->statusOf($id), 'Overdue reports are not approved');
        $r = $this->submit('fa', 'daily');
        $this->assertSame($id, (int) $r['id']);
        $this->assertSame('submitted', $r['status']);
        $this->assertSame(1, (int) $r['is_late']);
    }

    public function testWeeklyFiguresComeFromApprovedDailyReportsOnly(): void
    {
        $monday = \App\Services\WeekBoundary::weekStartForDate(date('Y-m-d', strtotime(uk_today() . ' -7 days')));
        foreach (['0' => '10', '1' => '5', '2' => '100'] as $offset => $bookings) {
            $this->day = date('Y-m-d', strtotime("$monday +$offset days"));
            $r = $this->submit('fa', 'daily', ['bookings_processed' => $bookings, 'booking_errors' => '1']);
            if ($offset !== 2) {
                $this->review('os', (int) $r['id'], 'approved');
            }
        }
        $this->day = $monday;
        $weekly = $this->submit('fa', 'weekly');
        $v = ReportService::version((int) $weekly['id']);
        $this->assertSame(15.0, (float) $v['data']['calculated']['total_bookings']);
        $this->assertSame(2.0, (float) $v['data']['calculated']['administrative_errors']);
        $this->assertSame(['approved' => 2, 'pending' => 1], $v['data']['calculated_sources']['daily']);
        $this->assertArrayNotHasKey('total_bookings', $v['data']['fields'], 'Calculated figures are never typed in');
    }

    public function testDashboardsPagesAndExportsWorkForEachRole(): void
    {
        $fa = $this->submit('fa', 'daily', [], ['critical' => '1', 'critical_note' => 'Customs papers missing for two loads.']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'report_critical'", [$this->u['dir']]));
        $tpl = (int) DB::value('SELECT id FROM report_templates LIMIT 1');
        $pages = [
            'fa' => ['/reporting', '/reporting/my', '/reporting/my?period=weekly', '/reporting/my?period=monthly', '/reporting/reports', '/reporting/actions', '/reporting/hierarchy', "/reporting/templates/$tpl", '/notifications', '/reporting/reports/' . $fa['id'] . '/xlsx'],
            'os' => ['/reporting/team', '/reporting/team?period=weekly&scope=all', '/reporting/team?period=monthly', '/reporting/reports/' . $fa['id'], '/reporting/export?scope=team&period=daily&format=pdf&date=' . $this->day, '/reporting/export?scope=team&period=weekly&format=xlsx&date=' . $this->day],
            'dir' => ['/reporting/directors', '/reporting/directors?period=daily', '/reporting/directors?period=monthly&department_id=' . self::$f['dept'], '/reporting/team?manager_id=' . $this->p['tm'],
                '/reporting/export?scope=company&period=weekly&format=pdf&date=' . $this->day, '/reporting/export?scope=department&period=monthly&format=xlsx&department_id=' . self::$f['dept'] . '&date=' . $this->day, '/reporting/reports/export?critical=1'],
        ];
        foreach ($pages as $who => $urls) {
            $this->actingAs($this->u[$who]);
            foreach ($urls as $url) {
                [$path, $q] = array_pad(explode('?', $url, 2), 2, '');
                parse_str($q, $query);
                $res = $this->get($path, $query);
                $this->assertContains($res->status, [200, 302], "$who $url");
                if (str_contains($url, 'format=pdf') || str_ends_with($url, '/pdf')) {
                    $this->assertStringStartsWith('%PDF', $res->body, $url);
                }
            }
        }
        $this->actingAs($this->u['dir']);
        $this->assertStringContainsString('Customs papers missing', $this->get('/reporting/directors', ['period' => 'daily', 'date' => $this->day])->body);
        $this->actingAs($this->u['fa']);
        $this->assertStringContainsString('class="bell"', $this->get('/reporting/my')->body);
        $this->actingAs(self::$f['admin']);
        foreach (['/reporting/directors', '/reporting/templates', '/reporting/lines', '/reporting/hierarchy'] as $url) {
            $this->assertSame(200, $this->get($url)->status, $url);
        }
    }

    public function testManagementActionsAreRaisedAndClosed(): void
    {
        $fa = $this->submit('fa', 'daily');
        $this->actingAs($this->u['os']);
        $this->post('/reporting/reports/' . $fa['id'] . '/actions', ['title' => 'Chase missing customs papers', 'assigned_to' => (string) $this->p['fa'], 'priority' => 'high', 'due_date' => uk_today()]);
        $action = DB::one('SELECT * FROM management_actions WHERE report_id = ?', [$fa['id']]);
        $this->assertSame($this->p['fa'], (int) $action['assigned_to_employee_id']);
        $this->actingAs($this->u['tm2']);
        $this->assertSame(404, $this->post('/reporting/actions/' . $action['id'], ['status' => 'done', 'resolution_note' => 'x'])->status);
        $this->actingAs($this->u['fa']);
        $this->post('/reporting/actions/' . $action['id'], ['status' => 'done']);
        $this->assertSame('open', DB::value('SELECT status FROM management_actions WHERE id = ?', [$action['id']]), 'Closing needs a note');
        $this->post('/reporting/actions/' . $action['id'], ['status' => 'done', 'resolution_note' => 'Papers received from the customer.']);
        $this->assertSame('done', DB::value('SELECT status FROM management_actions WHERE id = ?', [$action['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'report_action' AND title LIKE '%is done%'", [$this->u['os']]));
    }

    public function testReportingLinesAreConfigurableWithoutLoops(): void
    {
        $this->actingAs(self::$f['admin']);
        $this->post('/reporting/lines', ['manager' => [$this->p['tm'] => (string) $this->p['fa']]]);
        $this->assertSame($this->p['dir'], (int) DB::value('SELECT line_manager_id FROM employees WHERE id = ?', [$this->p['tm']]), 'A loop is rejected');
        $this->post('/reporting/lines', ['manager' => [$this->p['dir'] => (string) $this->p['tm']]]);
        $this->assertNull(DB::value('SELECT line_manager_id FROM employees WHERE id = ?', [$this->p['dir']]), 'Directors report to no one');
        $this->post('/reporting/lines', ['manager' => [$this->p['fa'] => (string) $this->p['tm2']]]);
        $this->assertSame($this->p['tm2'], (int) DB::value('SELECT line_manager_id FROM employees WHERE id = ?', [$this->p['fa']]));
        ReportAccess::flush();
        $this->assertSame($this->p['tm2'], (int) $this->submit('fa', 'daily')['line_manager_id'], 'New reports go to the new manager');
        $this->actingAs($this->u['os']);
        $this->assertSame(403, $this->get('/reporting/lines')->status);
    }

    public function testNoNewJobTitlesCanBeCreated(): void
    {
        $this->actingAs(self::$f['admin']);
        $this->post('/settings/job_titles', ['title' => 'Night Planner']);
        $this->assertNull(DB::value("SELECT id FROM job_titles WHERE title = 'Night Planner'"));
        $this->expectException(\App\Core\ValidationException::class);
        EmployeeService::create(['first_name' => 'New', 'last_name' => 'Title', 'department' => 'Drivers', 'designation' => 'Night Planner', 'employment_type' => 'full_time',
            'start_date' => '2026-01-05', 'status' => 'active'], self::$f['admin']);
    }

    private function mailLog(): string
    {
        $f = \App\Core\Config::get('app.storage_path') . '/logs/mail.log';
        return is_file($f) ? (string) file_get_contents($f) : '';
    }

    public function testSubmittedReportIsEmailedToTheLineManagerWithThePdf(): void
    {
        $before = strlen($this->mailLog());
        $fa = $this->submit('fa', 'weekly');
        $mail = substr($this->mailLog(), $before);
        $this->assertStringContainsString('To: os@menakan.example', $mail);
        $this->assertStringContainsString('Subject: New weekly report from Fay Test awaiting your approval', $mail);
        $this->assertMatchesRegularExpression('/Attachments: ' . preg_quote($fa['report_no'], '/') . '-[A-Z0-9]+-[0-9-]+\.pdf \(\d[\d,]* bytes\)/', $mail);
        $this->assertStringContainsString('Freight administration workload: Notes for admin_workload', $mail);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'report' AND entity_id = ? AND action = 'report.emailed'", [$fa['id']]));

        // A line manager without a login still gets the email, at their employee address.
        DB::update('users', ['is_active' => 0], 'id = ?', [$this->u['os']]);
        $before = strlen($this->mailLog());
        $this->submit('fa', 'monthly');
        $this->assertStringContainsString('To: os.work@menakan.example', substr($this->mailLog(), $before));

        // Admins can switch submission emails off; the in-app notice still arrives.
        DB::update('users', ['is_active' => 1], 'id = ?', [$this->u['os']]);
        DB::update('system_settings', ['value' => '0'], '`key` = ?', ['report_email_submissions']);
        Settings::flush();
        $before = strlen($this->mailLog());
        $this->day = date('Y-m-d', strtotime(uk_today() . ' -3 days'));
        $this->submit('fa', 'daily');
        $this->assertSame('', substr($this->mailLog(), $before));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'report_submitted' AND title LIKE 'New daily%'", [$this->u['os']]));
    }

    public function testReportsForAnEarlierDateLookLikeNormalReportsAndGoUpTheChain(): void
    {
        $this->day = date('Y-m-d', strtotime(uk_today() . ' -40 days'));
        $r = $this->submit('fa', 'weekly');
        $this->assertSame(1, (int) $r['is_backdated']);
        $this->assertSame(0, (int) $r['is_late'], 'A report for an earlier date is treated as a normal report, not as late');
        $this->assertSame('submitted', $r['status']);
        $this->assertSame($this->p['os'], (int) $r['line_manager_id']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'report' AND entity_id = ? AND action = 'report.backdated'", [$r['id']]));
        $this->actingAs($this->u['os']);
        $page = $this->get('/reporting/reports/' . $r['id'])->body;
        $this->assertStringNotContainsString('ackdated', $page, 'It looks exactly like a normal report');
        $this->assertStringNotContainsString('Submitted late', $page);
        $this->assertStringNotContainsString('ackdated', substr($this->mailLog(), -3000), "The manager's email does not mention it either");
        $this->review('os', (int) $r['id'], 'approved', 'Earlier week accepted.');
        $this->assertSame('approved', $this->statusOf((int) $r['id']));

        // Today's report is not backdated, and nothing before 01/06/2026 can be reported (Fay started in 2024).
        $this->day = uk_today();
        $this->assertSame(0, (int) $this->submit('fa', 'daily')['is_backdated']);
        $this->actingAs($this->u['fa']);
        $this->assertStringContainsString('id="backdated"', $this->get('/reporting/my')->body);
        $this->assertStringContainsString('min="2026-06-01"', $this->get('/reporting/my')->body);
        foreach (['daily' => '2026-05-31', 'weekly' => '2026-05-25', 'monthly' => '2026-05-01'] as $period => $date) {
            $this->post('/reporting/start', ['period' => $period, 'date' => $date]);
            $this->assertNull(DB::value('SELECT id FROM reports WHERE employee_id = ? AND period = ? AND period_start = ?', [$this->p['fa'], $period, $date]), "$period $date");
        }
        $this->post('/reporting/start', ['period' => 'daily', 'date' => '2026-06-01']);
        $this->assertNotNull(DB::value("SELECT id FROM reports WHERE employee_id = ? AND period_start = '2026-06-01'", [$this->p['fa']]));
    }

    public function testDailyReportsAreDueOnEveryShiftDaySinceTheStartDate(): void
    {
        // Fay started 30 days ago and works two shifts; she has no backdated report access.
        $start = date('Y-m-d', strtotime(uk_today() . ' -30 days'));
        $shiftDay = date('Y-m-d', strtotime(uk_today() . ' -25 days'));
        $otherShift = date('Y-m-d', strtotime(uk_today() . ' -20 days'));
        $noShift = date('Y-m-d', strtotime(uk_today() . ' -22 days'));
        DB::update('system_settings', ['value' => uk_today()], '`key` = ?', ['report_start_date']);
        Settings::flush();
        DB::update('employees', ['start_date' => $start], 'id = ?', [$this->p['fa']]);
        DB::update('users', ['can_backdate_reports' => 0], 'id = ?', [$this->u['fa']]);
        ReportAccess::flush();
        foreach ([$shiftDay, $otherShift] as $d) {
            DB::insert('shifts', ['employee_id' => $this->p['fa'], 'start_at' => to_utc("$d 09:00"), 'end_at' => to_utc("$d 17:00"), 'break_minutes' => 30, 'net_minutes' => 450,
                'week_start' => $d, 'status' => 'published']);
        }
        $this->assertSame([$shiftDay, $otherShift], \App\Services\ReportPeriod::expected([$this->p['fa']], 'daily', $start, uk_today())[$this->p['fa']]);

        $this->actingAs($this->u['fa']);
        $page = $this->get('/reporting/my')->body;
        $this->assertStringContainsString(\App\Services\ReportPeriod::label('daily', $shiftDay), $page, 'Shift days since the start date are listed, even beyond the last 14 days');
        $this->assertStringNotContainsString(\App\Services\ReportPeriod::label('daily', $noShift), $page);

        // The missed report can be sent without backdated access; it is late, not backdated. A day without a shift cannot.
        $this->day = $shiftDay;
        $r = $this->submit('fa', 'daily');
        $this->assertSame([$shiftDay, 'submitted', 0, 1], [$r['period_start'], $r['status'], (int) $r['is_backdated'], (int) $r['is_late']]);
        $this->post('/reporting/start', ['period' => 'daily', 'date' => $noShift]);
        $this->assertNull(DB::value('SELECT id FROM reports WHERE employee_id = ? AND period_start = ?', [$this->p['fa'], $noShift]));
    }

    public function testWeeklyReportsAreBuiltFromDailyReportsAndMonthlyFromWeekly(): void
    {
        // Fay (no backdated access) worked Monday and Tuesday of a week four weeks ago, and nothing the week after.
        $monday = date('Y-m-d', strtotime(\App\Services\WeekBoundary::weekStartForDate(uk_today()) . ' -28 days'));
        $tuesday = date('Y-m-d', strtotime("$monday +1 day"));
        $quietWeek = date('Y-m-d', strtotime("$monday +7 days"));
        DB::update('users', ['can_backdate_reports' => 0], 'id = ?', [$this->u['fa']]);
        foreach ([$monday, $tuesday] as $d) {
            DB::insert('shifts', ['employee_id' => $this->p['fa'], 'start_at' => to_utc("$d 09:00"), 'end_at' => to_utc("$d 17:00"), 'break_minutes' => 30, 'net_minutes' => 450,
                'week_start' => $monday, 'status' => 'published']);
        }
        $weeks = \App\Services\ReportPeriod::expected([$this->p['fa']], 'weekly', $monday, uk_today())[$this->p['fa']];
        $this->assertContains($monday, $weeks);
        $this->assertNotContains($quietWeek, $weeks, 'No weekly report for a week without shifts');

        $this->day = $monday;
        $this->submit('fa', 'daily', ['tasks_completed' => 'Loaded the Leeds run']);
        $this->day = $tuesday;
        $this->submit('fa', 'daily', ['tasks_completed' => 'Cleared the Hull backlog']);

        // The missed weekly report opens without backdated access, with the daily answers rolled up.
        $this->actingAs($this->u['fa']);
        $this->post('/reporting/start', ['period' => 'weekly', 'date' => $monday]);
        $week = ReportService::forPeriod($this->p['fa'], 'weekly', $monday);
        $this->assertNotNull($week);
        $this->assertSame(0, (int) $week['is_backdated']);
        $page = $this->get('/reporting/reports/' . $week['id'] . '/edit')->body;
        $this->assertStringContainsString(\App\Services\ReportPeriod::shortLabel('daily', $monday) . ': Loaded the Leeds run', $page);
        $this->assertStringContainsString('Cleared the Hull backlog', $page);
        $this->assertStringContainsString('<b>2 of 2</b> submitted', $page);

        // Submitted, the weekly answers roll up into the monthly report in the same way.
        $this->day = $monday;
        $this->submit('fa', 'weekly', ['tasks_completed' => 'Leeds and Hull runs done']);
        $this->post('/reporting/start', ['period' => 'monthly', 'date' => $monday]);
        $month = ReportService::forPeriod($this->p['fa'], 'monthly', \App\Services\ReportPeriod::bounds('monthly', $monday)[0]);
        $this->assertStringContainsString('Leeds and Hull runs done', $this->get('/reporting/reports/' . $month['id'] . '/edit')->body);
    }

    public function testOnlyPeopleWithAccessCanCreateBackdatedReports(): void
    {
        DB::update('users', ['can_backdate_reports' => 0], 'id = ?', [$this->u['fa']]);
        $this->actingAs($this->u['fa']);
        $page = $this->get('/reporting/my')->body;
        $this->assertStringNotContainsString('id="backdated"', $page);
        $this->assertStringNotContainsString('can only be started by people with access', $page);
        $this->post('/reporting/start', ['period' => 'daily', 'date' => $this->day]);
        $this->assertNull(DB::value("SELECT id FROM reports WHERE employee_id = ? AND period = 'daily'", [$this->p['fa']]), 'No backdated report without access');

        // Today's report is fine, and an existing overdue report can still be completed.
        $this->post('/reporting/start', ['period' => 'daily', 'date' => uk_today()]);
        $this->assertNotNull(DB::value("SELECT id FROM reports WHERE employee_id = ? AND period = 'daily' AND period_start = ?", [$this->p['fa'], uk_today()]));
        $id = ReportService::markOverdue($this->p['fa'], 'daily', $this->day);
        $r = $this->submit('fa', 'daily');
        $this->assertSame($id, (int) $r['id']);
        $this->assertSame('submitted', $r['status']);
        $this->assertSame(0, (int) $r['is_backdated']);

        // HR gives the access in Users; then a backdated report can be created.
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('name="can_backdate_reports"', $this->get('/users/' . $this->u['fa'] . '/edit')->body);
        $this->post('/users/' . $this->u['fa'], ['username' => 'rep_fa', 'email' => 'fa@menakan.example', 'role' => 'employee', 'employee_id' => (string) $this->p['fa'],
            'can_backdate_threads' => '0', 'can_edit_employees' => '0', 'can_backdate_reports' => '1']);
        $this->assertSame(1, (int) DB::value('SELECT can_backdate_reports FROM users WHERE id = ?', [$this->u['fa']]));
        $this->day = date('Y-m-d', strtotime(uk_today() . ' -9 days'));
        $this->assertSame(1, (int) $this->submit('fa', 'daily')['is_backdated']);
    }

    public function testPeopleWithAccessChooseTheSubmissionTimeOfAReportForAnEarlierDate(): void
    {
        $day = date('Y-m-d', strtotime(uk_today() . ' -5 days'));
        $this->day = $day;
        $r = $this->submit('fa', 'daily', [], ['submitted_at' => $day . 'T17:30']);
        $this->assertSame(to_utc("$day 17:30"), $r['submitted_at'], 'The chosen time is the submission time');
        $this->assertGreaterThan($r['submitted_at'], $r['received_at'], 'The real time is kept separately');
        $this->assertSame(to_utc("$day 17:30"), ReportService::version((int) $r['id'])['submitted_at']);
        $this->assertSame(0, (int) $r['is_late']);
        $this->actingAs($this->u['os']);
        $this->assertStringContainsString(uk_date($day) . ' 17:30', $this->get('/reporting/reports/' . $r['id'])->body);

        // Not in the future, not before the period, and not by people without access.
        $this->actingAs($this->u['fa']);
        foreach ([[uk_today() . 'T23:59', 'weekly'], [date('Y-m-d', strtotime(date('Y-m-01', strtotime(uk_today() . ' -45 days')) . ' -1 day')) . 'T09:00', 'monthly']] as [$at, $period]) {
            $this->day = date('Y-m-d', strtotime(uk_today() . ' -45 days'));
            $this->post('/reporting/start', ['period' => $period, 'date' => $this->day]);
            $id = (int) DB::value('SELECT id FROM reports WHERE employee_id = ? AND period = ? ORDER BY id DESC LIMIT 1', [$this->p['fa'], $period]);
            $this->post("/reporting/reports/$id", $this->answers((int) ReportService::find($id)['template_id']) + ['intent' => 'submit', 'submitted_at' => $at]);
            $this->assertSame('draft', $this->statusOf($id), "$period at $at was refused");
        }
        // Without access the time can only be chosen once the report is overdue (past its deadline).
        DB::update('users', ['can_backdate_reports' => 0], 'id = ?', [$this->u['tm']]);
        $this->actingAs($this->u['tm']);
        $this->post('/reporting/start', ['period' => 'daily', 'date' => uk_today()]);
        $today = ReportService::forPeriod($this->p['tm'], 'daily', uk_today());
        if ($today['due_at'] > now_utc()) {
            $this->assertStringNotContainsString('name="submitted_at"', $this->get('/reporting/reports/' . $today['id'] . '/edit')->body);
        }

        // Tom's report from 3 days ago is overdue: he enters when he really sent it, and it is late only if that was after the deadline.
        $this->day = date('Y-m-d', strtotime(uk_today() . ' -3 days'));
        $id = ReportService::markOverdue($this->p['tm'], 'daily', $this->day);
        $this->assertStringContainsString('name="submitted_at"', $this->get("/reporting/reports/$id/edit")->body);
        $this->post("/reporting/reports/$id", $this->answers((int) ReportService::find($id)['template_id']) + ['intent' => 'submit', 'submitted_at' => $this->day . 'T10:00',
            'assessment' => 'Supervisor report reviewed and agreed today.', 'team_rating' => '4']);
        $tm = ReportService::find($id);
        $this->assertSame(['submitted', to_utc($this->day . ' 10:00'), 0], [$tm['status'], $tm['submitted_at'], (int) $tm['is_late']]);
    }
}
