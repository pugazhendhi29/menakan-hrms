<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\AttendanceService;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use App\Services\PayslipService;
use App\Services\ShiftService;

final class BusinessRulesTest extends FeatureTestCase
{
    private function user(string $key): array
    {
        return DB::one('SELECT * FROM users WHERE id = ?', [self::$f[$key]]);
    }

    public function testEmployeeCodesComeFromTheSequenceAndAreNeverReused(): void
    {
        $base = ['last_name' => 'Seq', 'department' => 'Drivers', 'designation' => 'Van Driver', 'employment_type' => 'full_time', 'start_date' => '2026-01-05', 'status' => 'active'];
        $a = EmployeeService::create(['first_name' => 'One'] + $base, self::$f['admin']);
        $codeA = DB::value('SELECT employee_code FROM employees WHERE id = ?', [$a]);
        EmployeeService::softDelete($a);
        $b = EmployeeService::create(['first_name' => 'Two'] + $base, self::$f['admin']);
        $codeB = DB::value('SELECT employee_code FROM employees WHERE id = ?', [$b]);
        $this->assertMatchesRegularExpression('/^A\d{3}$/', $codeA);
        $this->assertSame((int) substr($codeA, 1) + 1, (int) substr($codeB, 1));
    }

    public function testOnlyOneActiveAccountPerEmployeeAtDatabaseLevel(): void
    {
        $this->expectException(\PDOException::class);
        DB::insert('users', ['username' => 'bob2', 'role' => 'employee', 'employee_id' => self::$f['empA'], 'is_active' => 1]);
    }

    public function testEmployeeAccountMustBeLinkedAtDatabaseLevel(): void
    {
        $this->expectException(\PDOException::class);
        DB::insert('users', ['username' => 'nolink', 'role' => 'employee', 'employee_id' => null]);
    }

    public function testOnlyOneOpenAttendanceSession(): void
    {
        AttendanceService::checkIn(self::$f['empA'], self::$f['userA']);
        try {
            AttendanceService::checkIn(self::$f['empA'], self::$f['userA']);
            $this->fail('Second check-in should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already checked in', $e->getMessage());
        }
        $this->expectException(\PDOException::class); // the database also refuses a second open row
        DB::insert('attendance_records', ['employee_id' => self::$f['empA'], 'work_date' => '2026-01-01', 'check_in_at' => '2026-01-01 08:00:00', 'source' => 'manual']);
    }

    public function testLateStatusUsesGraceMinutes(): void
    {
        $shift = ['start_at' => '2026-10-12 05:00:00', 'break_minutes' => 30];
        $this->assertSame('present', AttendanceService::statusFor($shift, '2026-10-12 05:05:00'));
        $this->assertSame('late', AttendanceService::statusFor($shift, '2026-10-12 05:06:00'));
        $this->assertSame('present', AttendanceService::statusFor(null, '2026-10-12 09:00:00'));
    }

    public function testOpenSessionsOlderThanLimitAreFlaggedIncomplete(): void
    {
        DB::insert('attendance_records', ['employee_id' => self::$f['empB'], 'work_date' => '2026-01-05', 'check_in_at' => '2026-01-05 08:00:00', 'source' => 'web']);
        $this->assertSame(1, AttendanceService::flagIncomplete(self::$f['empB']));
        AttendanceService::checkIn(self::$f['empB'], self::$f['userB']); // a new session is allowed afterwards
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM attendance_records WHERE employee_id = ?', [self::$f['empB']]));
    }

    public function testAnnualEntitlementIs28DaysAYearPlusBankHolidays(): void
    {
        $s = LeaveService::annualSummary(self::$f['empA'], '2026-06-01');
        $this->assertSame(['2026-01-01', '2026-12-31', 2026], [$s['year_start'], $s['year_end'], $s['year']]);   // the leave year is the calendar year
        $this->assertSame(28.0, $s['entitlement']);
        $this->assertSame(28.0, $s['remaining']);   // bank holidays are on top: nothing is taken off the 28 days
        $this->assertSame(['2027-01-01', '2027-12-31', 2027], LeaveService::yearBounds('2027-01-01'));
        $this->assertSame(['2026-01-01', '2026-12-31', 2026], LeaveService::yearBounds('2026-12-31'));

        // Pro rata: a 3-day-a-week worker gets 28 x 3 / 5 = 16.8, rounded up to 17; a mid-year joiner a share of the year.
        $part = DB::insert('work_patterns', ['name' => 'Mon-Wed', 'mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 0, 'fri' => 0, 'sat' => 0, 'sun' => 0]);
        DB::update('employees', ['work_pattern_id' => $part], 'id = ?', [self::$f['empB']]);
        $this->assertSame(17.0, LeaveService::annualSummary(self::$f['empB'], '2026-06-01')['entitlement']);
        DB::update('employees', ['start_date' => '2026-07-01', 'work_pattern_id' => self::$f['pattern']], 'id = ?', [self::$f['empAdmin']]);
        $this->assertSame(14.5, LeaveService::annualSummary(self::$f['empAdmin'], '2026-08-01')['entitlement']);
    }

    public function testApproveDeductsAndCancelReverses(): void
    {
        $id = LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-02', 'end_date' => '2026-11-06'], self::$f['userA']);
        $this->assertSame(5.0, (float) DB::value('SELECT days FROM leave_requests WHERE id = ?', [$id]));
        $this->assertSame(23.0, LeaveService::annualSummary(self::$f['empA'], '2026-06-01')['available']);
        LeaveService::approve($id, $this->user('admin'), null);
        $this->assertSame(23.0, LeaveService::annualSummary(self::$f['empA'], '2026-06-01')['remaining']);
        LeaveService::cancel($id, $this->user('admin'), true);
        $this->assertSame(28.0, LeaveService::annualSummary(self::$f['empA'], '2026-06-01')['remaining']);
        $types = DB::column('SELECT DISTINCT CAST(entry_type AS CHAR) FROM leave_ledger WHERE employee_id = ?', [self::$f['empA']]);
        sort($types);
        $this->assertSame(['accrual', 'deduction', 'reversal'], $types);
    }

    public function testCannotBookMoreThanAvailable(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Not enough leave left');
        LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-09-01', 'end_date' => '2026-10-30'], self::$f['userA']);
    }

    public function testRequestCannotCrossLeaveYear(): void
    {
        $this->expectException(ValidationException::class);
        LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-12-30', 'end_date' => '2027-01-04'], self::$f['userA']);
    }

    public function testAdminCannotApproveOwnLeave(): void
    {
        $id = LeaveService::request(self::$f['empAdmin'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-02', 'end_date' => '2026-11-02'], self::$f['admin']);
        $this->expectException(HttpException::class);
        LeaveService::approve($id, $this->user('admin'), null);
    }

    public function testRejectionNeedsAReason(): void
    {
        $id = LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-02', 'end_date' => '2026-11-02'], self::$f['userA']);
        $this->expectException(ValidationException::class);
        LeaveService::reject($id, $this->user('admin'), '');
    }

    private function shift(string $date, string $s, string $e, array $extra = []): array
    {
        return $extra + ['employee_id' => (string) self::$f['empA'], 'date' => $date, 'start_time' => $s, 'end_time' => $e, 'break_minutes' => '30', 'status' => 'draft'];
    }

    public function testShiftOverlapAndWeeklyLimit(): void
    {
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $d) {
            ShiftService::save(null, $this->shift($d, '08:00', '16:30'), self::$f['admin']);
        }
        try {
            ShiftService::save(null, $this->shift('2026-11-02', '12:00', '13:00', ['break_minutes' => '0']), self::$f['admin']);
            $this->fail('Overlap should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('overlaps', $e->getMessage());
        }
        try {
            ShiftService::save(null, $this->shift('2026-11-06', '08:00', '16:30'), self::$f['admin']);
            $this->fail('Over-limit shift needs a reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('override_reason', $e->errors);
        }
        $r = ShiftService::save(null, $this->shift('2026-11-06', '08:00', '16:30', ['override_reason' => 'Cover']), self::$f['admin']);
        $this->assertNotEmpty($r['warnings']);
        $this->assertSame(2400, (int) DB::value("SELECT approved_minutes FROM hours_exceptions WHERE employee_id = ? AND week_start = '2026-11-02'", [self::$f['empA']]));
    }

    public function testAbsenceAndOnLeaveDetection(): void
    {
        [$s, $e] = \App\Services\HoursCalculator::utcSpan('2026-10-05', '08:00', '16:00');
        DB::insert('shifts', ['employee_id' => self::$f['empA'], 'start_at' => $s, 'end_at' => $e, 'break_minutes' => 30, 'net_minutes' => 450, 'week_start' => '2026-10-05', 'status' => 'published']);
        $this->assertSame('absent', AttendanceService::absences('2026-10-05', '2026-10-05', self::$f['empA'])[0]['day_status']);
        DB::insert('leave_requests', ['employee_id' => self::$f['empA'], 'leave_type_id' => self::$f['sick'], 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'days' => 1, 'status' => 'approved']);
        $this->assertSame('on_leave', AttendanceService::absences('2026-10-05', '2026-10-05', self::$f['empA'])[0]['day_status']);
        DB::insert('attendance_records', ['employee_id' => self::$f['empA'], 'work_date' => '2026-10-05', 'check_in_at' => $s, 'check_out_at' => $e, 'net_minutes' => 450, 'source' => 'manual']);
        $this->assertSame([], AttendanceService::absences('2026-10-05', '2026-10-05', self::$f['empA']));
    }

    public function testPayslipReuploadArchivesPrevious(): void
    {
        $first = PayslipService::saveStored(self::$f['empA'], 2026, 8, $this->storedPdf(), 'a.pdf', 10, self::$f['admin']);
        $second = PayslipService::saveStored(self::$f['empA'], 2026, 8, $this->storedPdf(), 'b.pdf', 10, self::$f['admin']);
        $old = DB::one('SELECT * FROM payslips WHERE id = ?', [$first]);
        $this->assertSame('archived', $old['status']);
        $this->assertNull($old['is_current']);
        $this->assertSame(1, PayslipService::publish([$second, $first], self::$f['admin']));
    }

    public function testAuditLogNeverStoresSecrets(): void
    {
        \App\Services\AuditLogger::log('test.secret', null, null, 'x', ['password' => 'Hunter2', 'nested' => ['token' => 'abc'], 'name' => 'ok']);
        $changes = DB::value("SELECT changes FROM audit_logs WHERE action = 'test.secret'");
        $this->assertStringNotContainsString('Hunter2', $changes);
        $this->assertStringNotContainsString('abc', $changes);
        $this->assertStringContainsString('[redacted]', $changes);
    }

    public function testWrongPasswordFiveTimesLocksTheAccount(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'bob', 'password' => 'wrong']);
        }
        $this->post('/login', ['username' => 'bob', 'password' => 'Password12345']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertTrue(\App\Services\LoginThrottle::isLocked('bob', '127.0.0.1'));
    }

    public function testSuccessfulLoginRegeneratesSessionAndRecordsIt(): void
    {
        $_SESSION = [];
        \App\Core\Session::start();
        $before = \App\Core\Session::id();
        $res = $this->post('/login', ['username' => 'bob', 'password' => 'Password12345']);
        $this->assertSame(302, $res->status);
        $this->assertStringEndsWith('/my', $res->header('Location'));
        $this->assertNotSame($before, \App\Core\Session::id());
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM user_sessions WHERE session_id_hash = ?', [hash('sha256', \App\Core\Session::id())]));
    }

    private function newEmployee(array $extra): int
    {
        return EmployeeService::create($extra + ['first_name' => 'Text', 'last_name' => 'Fields', 'employment_type' => 'full_time',
            'start_date' => '2026-01-05', 'status' => 'active', 'department' => 'Drivers', 'designation' => 'Van Driver'], self::$f['admin']);
    }

    public function testTypedDepartmentDesignationAndLocationAreReusedOrCreated(): void
    {
        $before = (int) DB::value('SELECT COUNT(*) FROM departments');
        $a = $this->newEmployee(['department' => 'drivers', 'designation' => 'VAN DRIVER', 'location' => 'North Yard']);
        $row = DB::one('SELECT * FROM employees WHERE id = ?', [$a]);
        $this->assertSame(self::$f['dept'], (int) $row['department_id']);   // case-insensitive reuse
        $this->assertSame(self::$f['job'], (int) $row['job_title_id']);
        $this->assertSame('North Yard', DB::value('SELECT name FROM locations WHERE id = ?', [$row['location_id']]));
        $b = $this->newEmployee(['department' => 'Night Operations', 'designation' => 'Night Planner', 'location' => 'north yard']);
        $rowB = DB::one('SELECT * FROM employees WHERE id = ?', [$b]);
        $this->assertSame($before + 1, (int) DB::value('SELECT COUNT(*) FROM departments'));
        $this->assertSame('NIGHTOPER', DB::value('SELECT code FROM departments WHERE id = ?', [$rowB['department_id']]));
        $this->assertSame((int) $rowB['department_id'], (int) DB::value('SELECT department_id FROM job_titles WHERE id = ?', [$rowB['job_title_id']]));
        $this->assertSame($row['location_id'], $rowB['location_id']);
    }

    public function testReportingManagerLinksByIdOrNameOtherwiseKeepsText(): void
    {
        $code = DB::value('SELECT employee_code FROM employees WHERE id = ?', [self::$f['empA']]);
        $byId = DB::one('SELECT * FROM employees WHERE id = ?', [$this->newEmployee(['reporting_manager' => $code . ' · Bob Driver'])]);
        $this->assertSame(self::$f['empA'], (int) $byId['line_manager_id']);
        $this->assertNull($byId['reporting_manager_name']);
        $byName = DB::one('SELECT * FROM employees WHERE id = ?', [$this->newEmployee(['reporting_manager' => 'Cara Courier'])]);
        $this->assertSame(self::$f['empB'], (int) $byName['line_manager_id']);
        $text = DB::one('SELECT * FROM employees WHERE id = ?', [$this->newEmployee(['reporting_manager' => 'Agency supervisor (Hays)'])]);
        $this->assertNull($text['line_manager_id']);
        $this->assertSame('Agency supervisor (Hays)', $text['reporting_manager_name']);
    }

    public function testCannotBeOwnReportingManager(): void
    {
        $code = DB::value('SELECT employee_code FROM employees WHERE id = ?', [self::$f['empA']]);
        $emp = DB::one('SELECT * FROM employees WHERE id = ?', [self::$f['empA']]);
        $this->expectException(ValidationException::class);
        EmployeeService::update(self::$f['empA'], ['first_name' => $emp['first_name'], 'last_name' => $emp['last_name'], 'employment_type' => 'full_time',
            'start_date' => $emp['start_date'], 'status' => 'active', 'department' => 'Drivers', 'designation' => 'Van Driver', 'reporting_manager' => $code], self::$f['admin']);
    }

    public function testFollowUpDate(): void
    {
        $id = $this->newEmployee(['rtw_check_date' => '2026-09-01', 'rtw_followup_date' => '2027-03-01']);
        $row = DB::one('SELECT * FROM employees WHERE id = ?', [$id]);
        $this->assertSame('2027-03-01', $row['rtw_followup_date']);
        try {
            $this->newEmployee(['rtw_check_date' => '2026-09-01', 'rtw_followup_date' => '2026-08-01']);
            $this->fail('Follow-up before last check should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rtw_followup_date', $e->errors);
        }
    }

    private function form(int $id, array $extra = []): array
    {
        $e = DB::one('SELECT * FROM employees WHERE id = ?', [$id]);
        return $extra + ['first_name' => $e['first_name'], 'last_name' => $e['last_name'], 'employment_type' => 'full_time', 'start_date' => $e['start_date'],
            'status' => 'active', 'department' => 'Drivers', 'designation' => 'Van Driver'];
    }

    public function testEmployeeFormSavesRepeatableHistory(): void
    {
        $id = self::$f['empB'];
        EmployeeService::update($id, $this->form($id, ['history_submitted' => '1',
            'experience' => [
                ['company_name' => 'Acme', 'job_title' => 'Driver', 'from_date' => '2019-01-01', 'to_date' => '2020-12-31', 'reference_phone_code' => '+44', 'reference_phone' => '07700 900456'],
                ['company_name' => 'Side Job Ltd', 'job_title' => 'Courier', 'from_date' => '2025-01-01', 'is_current' => '1'],
            ],
            'education' => [['institute_name' => 'Leeds College', 'education_level' => 'A-Level']]]), self::$f['admin']);
        $rows = DB::all('SELECT * FROM employee_work_experience WHERE employee_id = ? ORDER BY from_date', [$id]);
        $this->assertCount(2, $rows);
        $this->assertSame('+44 7700900456', $rows[0]['reference_phone']);
        $this->assertNull($rows[1]['to_date']);
        $this->assertSame('A-Level', DB::value('SELECT education_level FROM employee_education WHERE employee_id = ?', [$id]));
        // Saving without the history section (e.g. another caller) leaves it untouched.
        EmployeeService::update($id, $this->form($id), self::$f['admin']);
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM employee_work_experience WHERE employee_id = ?', [$id]));
        // Removing every row on the form clears it.
        EmployeeService::update($id, $this->form($id, ['history_submitted' => '1']), self::$f['admin']);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM employee_work_experience WHERE employee_id = ?', [$id]));
    }

    public function testHistoryErrorsBlockTheWholeSave(): void
    {
        $id = self::$f['empB'];
        try {
            EmployeeService::update($id, $this->form($id, ['first_name' => 'Changed', 'history_submitted' => '1',
                'experience' => [['company_name' => 'Acme', 'job_title' => '', 'from_date' => '2019-01-01', 'to_date' => '2020-01-01']]]), self::$f['admin']);
            $this->fail('Invalid history should block the save');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('experience.0.job_title', $e->errors);
        }
        $this->assertSame('Cara', DB::value('SELECT first_name FROM employees WHERE id = ?', [$id]));
    }

    public function testMyProfileIsReadOnlyForEmployees(): void
    {
        DB::insert('employee_work_experience', ['employee_id' => self::$f['empA'], 'company_name' => 'Old Ltd', 'job_title' => 'Driver', 'from_date' => '2018-01-01', 'to_date' => '2019-01-01']);
        $this->actingAs(self::$f['userA']);
        $page = $this->get('/my/profile');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Old Ltd', $page->body);   // shown
        $this->assertStringNotContainsString('Add / edit', $page->body);   // but not editable
        $this->assertStringContainsString('Your profile is read only', $page->body);
        $this->assertSame(404, $this->get('/my/history')->status);
        $this->assertNotSame(302, $this->post('/my/history', ['experience' => [['company_name' => 'Mine Ltd', 'job_title' => 'Driver', 'from_date' => '2018-01-01']]])->status);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM employee_work_experience WHERE company_name = 'Mine Ltd'"));
    }

    public function testOrganisationChartIsViewOnlyForEmployees(): void
    {
        DB::update('employees', ['line_manager_id' => self::$f['empAdmin']], 'id IN (?, ?)', [self::$f['empA'], self::$f['empB']]);
        $this->actingAs(self::$f['userA']);
        $res = $this->get('/hierarchy');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Menakan Transport Organizational Structure', $res->body);
        $this->assertStringContainsString('Cara', $res->body);
        $this->assertStringNotContainsString('/employees/', $res->body);   // no links into admin pages
        $this->assertStringNotContainsString('Change manager', $res->body);
        $this->actingAs(self::$f['admin']);
        $admin = $this->get('/hierarchy');
        $this->assertStringContainsString('/employees/' . self::$f['empB'] . '?tab=personal', $admin->body);
        $this->assertStringContainsString('Change manager', $admin->body);
        $search = $this->get('/hierarchy', ['q' => 'cara']);
        $this->assertStringContainsString('is-match', $search->body);
        $this->assertStringContainsString('1 matching person', $search->body);
    }

    public function testLeaveRequestedAndApprovedDates(): void
    {
        $id = LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'requested_on' => '2026-10-01'], self::$f['admin'], true);
        $this->assertSame('2026-10-01', DB::value('SELECT requested_on FROM leave_requests WHERE id = ?', [$id]));
        try {
            LeaveService::approve($id, $this->user('admin2'), null, '2026-09-30');
            $this->fail('Approved before requested should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approved_on', $e->errors);
        }
        LeaveService::approve($id, $this->user('admin2'), null, '2026-10-03');
        $this->assertSame('2026-10-03', DB::value('SELECT approved_on FROM leave_requests WHERE id = ?', [$id]));
        $own = LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-10', 'end_date' => '2026-11-10'], self::$f['userA']);
        $this->assertSame(uk_today(), DB::value('SELECT requested_on FROM leave_requests WHERE id = ?', [$own]));
    }

    public function testFutureRequestedDateIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-11', 'end_date' => '2026-11-11', 'requested_on' => '2099-01-01'], self::$f['admin'], true);
    }

    public function testPayslipIsEmailedToTheEmployeeOnUpload(): void
    {
        DB::update('employees', ['email' => 'bob.driver@example.com'], 'id = ?', [self::$f['empA']]);
        $log = \App\Core\Config::get('app.storage_path') . '/logs/mail.log';
        $before = is_file($log) ? filesize($log) : 0;
        $id = PayslipService::saveStored(self::$f['empA'], 2026, 3, $this->storedPdf(), 'march.pdf', 10, self::$f['admin']);
        $r = PayslipService::emailToEmployee($id);
        $this->assertTrue($r['sent']);
        $row = DB::one('SELECT * FROM payslips WHERE id = ?', [$id]);
        $this->assertSame('bob.driver@example.com', $row['emailed_to']);
        $this->assertNotNull($row['emailed_at']);
        $entry = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('To: bob.driver@example.com', $entry);
        $this->assertMatchesRegularExpression('/Attachments: Payslip_A\d{3}_2026-03\.pdf/', $entry);
        $this->assertStringContainsString('From: hr@menakantransport.com', $entry);
        $this->assertStringContainsString("Kind regards,\n\nHuman Resource\nMenakan Transport Ltd\nUnit 11, 58 Peregrine Road,\nHainault, Essex, UK\nIG6 3SZ\nMenakan Transport Ltd | Human Resources Department", $entry);
        // No email address: recorded as not sent, nothing thrown.
        DB::update('employees', ['email' => null], 'id = ?', [self::$f['empB']]);
        $id2 = PayslipService::saveStored(self::$f['empB'], 2026, 3, $this->storedPdf(), 'march.pdf', 10, self::$f['admin']);
        $r2 = PayslipService::emailToEmployee($id2);
        $this->assertFalse($r2['sent']);
        $this->assertNull(DB::value('SELECT emailed_at FROM payslips WHERE id = ?', [$id2]));
    }

    public function testAuditScreensAreGoneButLoggingContinues(): void
    {
        $this->actingAs(self::$f['admin']);
        $this->assertSame(404, $this->get('/audit')->status);
        $this->assertStringNotContainsString('Audit Logs', $this->get('/dashboard')->body);
        $before = (int) DB::value('SELECT COUNT(*) FROM audit_logs');
        LeaveService::request(self::$f['empA'], ['leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-12', 'end_date' => '2026-11-12'], self::$f['userA']);
        $this->assertSame($before + 1, (int) DB::value('SELECT COUNT(*) FROM audit_logs'));
    }

    public function testDirectorsCannotHaveAReportingManager(): void
    {
        $base = ['last_name' => 'Board', 'department' => 'Director', 'designation' => 'Director', 'employment_type' => 'full_time', 'start_date' => '2026-01-05', 'status' => 'active'];
        $first = EmployeeService::create(['first_name' => 'First'] + $base, self::$f['admin']);
        $firstCode = DB::value('SELECT employee_code FROM employees WHERE id = ?', [$first]);
        foreach ([$firstCode, 'Someone Outside'] as $manager) {   // another Director, or a manager typed as text
            try {
                EmployeeService::create(['first_name' => 'Second', 'reporting_manager' => $manager] + $base, self::$f['admin']);
                $this->fail('A Director must not have a reporting manager');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Directors do not have a reporting manager', $e->errors['reporting_manager']);
            }
        }
        // Non-Directors may report to a Director.
        $staff = EmployeeService::create(['first_name' => 'Staff', 'designation' => 'Transport Manager', 'reporting_manager' => $firstCode] + $base, self::$f['admin']);
        $this->assertSame($first, (int) DB::value('SELECT line_manager_id FROM employees WHERE id = ?', [$staff]));
    }

    public function testHierarchyShowsTheCompanyThenDirectors(): void
    {
        $base = ['last_name' => 'Board', 'department' => 'Director', 'designation' => 'Director', 'employment_type' => 'full_time', 'start_date' => '2026-01-05', 'status' => 'active'];
        $dir = EmployeeService::create(['first_name' => 'Dana'] + $base, self::$f['admin']);
        $lone = EmployeeService::create(['first_name' => 'Solo'] + $base, self::$f['admin']);   // a Director with no reports still sits on Level 1
        DB::update('employees', ['line_manager_id' => $dir], 'id = ?', [self::$f['empA']]);
        $this->actingAs(self::$f['admin']);
        $html = $this->get('/hierarchy')->body;
        $company = strpos($html, 'class="org-company"');
        $this->assertNotFalse($company);
        $this->assertStringContainsString('Menakan Transport Ltd', $html);
        $placed = strpos($html, 'Not yet placed');
        $tree = $placed === false ? $html : substr($html, 0, $placed);
        foreach ([$dir, $lone, self::$f['empA']] as $id) {
            $this->assertGreaterThan($company, strpos($tree, '/employees/' . $id . '?tab=personal'), "employee $id is in the tree below the company");
        }
        $this->assertGreaterThan(strpos($html, '/employees/' . $dir . '?tab=personal'), strpos($html, '/employees/' . self::$f['empA'] . '?tab=personal'));
        $this->assertSame(2, substr_count($html, 'org-card is-director'));
    }

    public function testLeaveTypeDropdownFollowsTheConfiguredOrder(): void
    {
        DB::update('leave_types', ['sort_order' => 1], 'id = ?', [self::$f['annual']]);
        DB::update('leave_types', ['sort_order' => 2], 'id = ?', [self::$f['sick']]);
        $carer = DB::insert('leave_types', ['name' => "Carer's Leave", 'code' => 'CARER', 'is_paid' => 0, 'sort_order' => 10]);
        DB::insert('leave_types', ['name' => 'Bereavement Leave', 'code' => 'BRV', 'sort_order' => 7]);
        DB::insert('leave_types', ['name' => 'Old type', 'code' => 'OLD', 'sort_order' => 3, 'is_active' => 0]);
        $this->actingAs(self::$f['admin']);
        $form = $this->get('/leave/create')->body;
        $pos = fn (string $s) => strpos($form, $s);
        $this->assertTrue($pos('Annual leave') < $pos('Sickness') && $pos('Sickness') < $pos('Bereavement Leave') && $pos('Bereavement Leave') < $pos('Carer&#039;s Leave'), 'admin dropdown in list order');
        $this->assertStringNotContainsString('Old type', $form);   // switched-off types are not offered
        // Employees see only the types they may request (Sickness is admin-only), in the same order.
        $this->actingAs(self::$f['userA']);
        $mine = $this->get('/my/leave')->body;
        $this->assertStringNotContainsString('>Sickness<', $mine);
        $this->assertTrue(strpos($mine, 'Bereavement Leave') < strpos($mine, 'Carer&#039;s Leave'));
        $this->assertSame(10, (int) DB::value('SELECT sort_order FROM leave_types WHERE id = ?', [$carer]));
    }

    public function testCareerBreakStatus(): void
    {
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('<option value="career_break"', $this->get('/employees/create')->body);
        $id = EmployeeService::create(['first_name' => 'Cass', 'last_name' => 'Break', 'department' => 'Drivers', 'designation' => 'Van Driver', 'employment_type' => 'full_time',
            'start_date' => '2025-01-06', 'status' => 'career_break'], self::$f['admin']);
        $this->assertSame('career_break', DB::value('SELECT status FROM employees WHERE id = ?', [$id]));
        // Still a current employee: listed (with its badge), filterable, counted in the headcount report.
        $this->assertStringContainsString('Career Break', $this->get('/employees', ['q' => 'Cass'])->body);
        $filtered = $this->get('/employees', ['status' => 'career_break'])->body;
        $this->assertStringContainsString('Cass Break', $filtered);
        $this->assertStringNotContainsString('Bob Driver', $filtered);
        $report = $this->get('/reports/headcount')->body;
        $this->assertStringContainsString('<th>Career Break</th>', $report);   // one column per status, named as in Settings
        // The employee import accepts the label too.
        [$input] = (fn () => [\App\Services\EmployeeTransfer::currentInput(DB::one('SELECT * FROM employees WHERE id = ?', [$id]))])();
        $this->assertSame('career_break', $input['status']);
        $this->assertSame('Career Break', \App\Services\EmployeeService::statuses()['career_break']);
    }
}