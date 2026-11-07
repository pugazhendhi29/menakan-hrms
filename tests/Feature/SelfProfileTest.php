<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;

/** Employees edit their own Personal, Identity and Contact details, work experience and education; the rest is admin only. */
final class SelfProfileTest extends FeatureTestCase
{
    public function testEmployeeEditsOwnDetailsButNotAdminOnlyFields(): void
    {
        DB::update('employees', ['salary' => 30000, 'phone' => '01234 567890'], 'id = ?', [self::$f['empA']]);
        $this->actingAs(self::$f['userA']);
        $page = $this->get('/my/profile/edit')->body;
        foreach (['Personal details', 'Identity information', 'Contact details', 'name="experience', 'name="education'] as $s) {
            $this->assertStringContainsString($s, $page);
        }
        foreach (['name="salary"', 'name="department"', 'name="reporting_manager"', 'name="end_date"', 'name="first_name"', 'name="status"'] as $s) {
            $this->assertStringNotContainsString($s, $page);
        }

        $this->post('/my/profile', [
            'nationality' => 'Irish', 'marital_status' => 'Married', 'ni_number' => 'AB123456C', 'passport_number' => 'P1234567',
            'address_line1' => '1 New Road', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'permanent_same_as_present' => '1',
            'personal_mobile' => '07700 900123', 'emergency_contact_name' => 'Jo Driver', 'emergency_contact_phone' => '07700 900999',
            'experience' => [['company_name' => 'Old Co', 'job_title' => 'Driver', 'from_date' => '2020-01-01', 'to_date' => '2023-12-31']],
            // Admin-only fields smuggled into the request are ignored.
            'salary' => '99999', 'first_name' => 'Hacker', 'status' => 'left', 'end_date' => '2026-01-01', 'department' => 'Finance',
        ]);

        $e = DB::one('SELECT * FROM employees WHERE id = ?', [self::$f['empA']]);
        $this->assertSame(['Irish', 'Married', '1 New Road', 'Leeds', '07700 900123', 'Jo Driver'],
            [$e['nationality'], $e['marital_status'], $e['address_line1'], $e['city'], $e['personal_mobile'], $e['emergency_contact_name']]);
        $this->assertNotEmpty($e['ni_number']);
        $this->assertSame(['Bob', 'active', null, 30000.0, (int) self::$f['dept']], [$e['first_name'], $e['status'], $e['end_date'], (float) $e['salary'], (int) $e['department_id']]);
        $this->assertSame('', (string) $e['phone']);   // phone is a contact field the employee cleared
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM employee_work_experience WHERE employee_id = ?', [self::$f['empA']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1", [self::$f['userA']]));
    }

    public function testEmployeeCannotOpenTheAdminEditPage(): void
    {
        $this->actingAs(self::$f['userA']);
        $this->assertNotSame(200, $this->get('/employees/' . self::$f['empA'] . '/edit')->status);
        $this->assertNotSame(200, $this->get('/employees/' . self::$f['empB'] . '/edit')->status);
    }
}
