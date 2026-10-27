<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Services\EmployeeService;

/** Employee statuses are a list kept in Settings > Employee statuses. */
final class EmployeeStatusTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EmployeeService::forgetStatuses();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        EmployeeService::forgetStatuses();   // the list is cached; the rows were rolled back
    }

    public function testAdminCreatesAStatusAndUsesItOnAnEmployee(): void
    {
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('Employee statuses', $this->get('/settings')->body);
        $this->post('/settings/employee_statuses', ['name' => 'Notice period', 'code' => '', 'colour' => '#7c3aed', 'counts_as_working' => '1', 'sort_order' => '60']);
        $s = DB::one("SELECT * FROM employee_statuses WHERE name = 'Notice period'");
        $this->assertSame(['notice_period', '#7c3aed', 1, 0], [$s['code'], $s['colour'], (int) $s['counts_as_working'], (int) $s['is_system']]);   // code made from the name

        // It appears on the employee form and the list filter, and can be saved on an employee.
        $this->assertStringContainsString('<option value="notice_period"', $this->get('/employees/create')->body);
        $this->assertStringContainsString('<option value="notice_period"', $this->get('/employees')->body);
        $input = \App\Services\EmployeeTransfer::currentInput(DB::one('SELECT * FROM employees WHERE id = ?', [self::$f['empA']]));
        EmployeeService::update(self::$f['empA'], ['status' => 'notice_period'] + $input, self::$f['admin']);
        $this->assertSame('notice_period', DB::value('SELECT status FROM employees WHERE id = ?', [self::$f['empA']]));
        $page = $this->get('/employees/' . self::$f['empA'])->body;
        $this->assertStringContainsString('color:#7c3aed">Notice period</span>', $page);

        // Renaming the code moves the employees who have it.
        $this->post('/settings/employee_statuses', ['id' => $s['id'], 'name' => 'Serving notice', 'code' => 'serving_notice', 'colour' => '#7c3aed', 'counts_as_working' => '1', 'sort_order' => '60']);
        $this->assertSame('serving_notice', DB::value('SELECT status FROM employees WHERE id = ?', [self::$f['empA']]));

        // Switched off: no longer offered for others, but the employee who has it keeps it on their form.
        $this->post("/settings/employee_statuses/{$s['id']}/toggle");
        EmployeeService::forgetStatuses();
        $this->assertStringNotContainsString('<option value="serving_notice"', $this->get('/employees/create')->body);
        $this->assertStringContainsString('<option value="serving_notice"', $this->get('/employees/' . self::$f['empA'] . '/edit')->body);
    }

    public function testBuiltInStatusesCannotBeSwitchedOffOrRecoded(): void
    {
        $this->actingAs(self::$f['admin']);
        $left = DB::one("SELECT * FROM employee_statuses WHERE code = 'left'");
        $this->post("/settings/employee_statuses/{$left['id']}/toggle");
        $this->assertSame(1, (int) DB::value('SELECT is_active FROM employee_statuses WHERE id = ?', [$left['id']]));
        $this->post('/settings/employee_statuses', ['id' => $left['id'], 'name' => 'Former employee', 'code' => 'gone', 'colour' => '#000000', 'counts_as_working' => '1', 'sort_order' => '90']);
        $row = DB::one('SELECT * FROM employee_statuses WHERE id = ?', [$left['id']]);
        $this->assertSame(['Former employee', 'left', 0], [$row['name'], $row['code'], (int) $row['counts_as_working']]);   // renamed, but still "left" and never counted
        $this->assertStringContainsString('Built in', $this->get('/settings/employee_statuses')->body);
        $this->post('/settings/employee_statuses', ['name' => 'Bad', 'code' => '9 bad code!', 'sort_order' => '1']);
        $this->assertNull(DB::value("SELECT id FROM employee_statuses WHERE name = 'Bad'"));
    }

    public function testDashboardActiveHeadcountFollowsCountsAsWorking(): void
    {
        DB::insert('employee_statuses', ['code' => 'secondment', 'name' => 'Secondment', 'counts_as_working' => 0, 'sort_order' => 70]);
        DB::update('employees', ['status' => 'secondment'], 'id = ?', [self::$f['empB']]);
        $this->actingAs(self::$f['admin']);
        $total = (int) DB::value("SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL AND status <> 'left'");
        $body = $this->get('/dashboard')->body;
        $this->assertStringContainsString(($total - 1) . ' active', $body);
    }
}
