<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Services\EmployeeService;
use App\Services\EmployeeTransfer;
use App\Services\ExcelIO;

final class EmployeeImportTest extends FeatureTestCase
{
    /** Writes a one-sheet workbook with the export headers (or $headers) and returns the batch id after validation. */
    private function import(array $rows, ?array $headers = null): int
    {
        $path = tempnam(sys_get_temp_dir(), 'empimp') . '.xlsx';
        file_put_contents($path, ExcelIO::build('Employees', $headers ?? EmployeeTransfer::headers(), $rows));
        return EmployeeTransfer::validateFile($path, 'employees.xlsx', 'imports/test/' . bin2hex(random_bytes(8)) . '.xlsx', self::$f['admin']);
    }

    /** A row in export layout with only the given columns filled (keys are field keys). */
    private function row(array $values): array
    {
        return array_map(fn ($key) => $values[$key] ?? '', array_keys(EmployeeTransfer::columns()));
    }

    private function code(string $key): string
    {
        return (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [self::$f[$key]]);
    }

    private function rows(int $batch): array
    {
        return DB::all('SELECT * FROM employee_import_rows WHERE batch_id = ? ORDER BY row_no', [$batch]);
    }

    public function testExportHasEveryFieldAndReimportsWithNoChanges(): void
    {
        DB::update('employees', ['phone' => '+44 7700 900123', 'ni_number' => 'AB123456C', 'salary' => 28500, 'date_of_birth' => '1990-05-04', 'notes' => "Line one\nLine two",
            'marital_status' => 'Married', 'permanent_same_as_present' => 1, 'line_manager_id' => self::$f['empAdmin']], 'id = ?', [self::$f['empA']]);
        $this->actingAs(self::$f['admin']);
        $res = $this->get('/employees/export', ['format' => 'xlsx']);
        $this->assertSame(200, $res->status);
        $path = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        file_put_contents($path, $res->body);
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame(['Employees', 'Work Experience', 'Education', 'Instructions'], $book->getSheetNames());
        $headers = $book->getSheet(0)->rangeToArray('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(EmployeeTransfer::headers())) . '1')[0];
        $this->assertSame(EmployeeTransfer::headers(), $headers);
        foreach (['NI Number', 'Passport Number', 'Salary', 'Visa End Date', 'Right to Work Check', 'Reporting Manager', 'Personal Email', 'Notes'] as $h) {
            $this->assertContains($h, $headers);
        }
        $this->assertGreaterThan(0, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.exported'"));

        // Re-importing the untouched export changes nothing.
        $batch = EmployeeTransfer::validateFile($path, 'export.xlsx', 'imports/test/x.xlsx', self::$f['admin']);
        $rows = $this->rows($batch);
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame('unchanged', $r['result'], "row {$r['row_no']}: {$r['messages']} {$r['changes']}");
        }
        $csv = $this->get('/employees/export', ['format' => 'csv'])->body;
        $this->assertStringContainsString('"Employee ID",Title,"First Name","Last Name"', $csv);
    }

    public function testUpdateByEmployeeIdOnlyChangesFilledCellsAndClear(): void
    {
        DB::update('employees', ['preferred_name' => 'Bobby', 'city' => 'Leeds'], 'id = ?', [self::$f['empA']]);
        $batch = $this->import([$this->row(['employee_code' => strtolower($this->code('empA')), 'phone' => '07700 900999', 'preferred_name' => 'clear', 'status' => 'On leave', 'contract_hours' => 30])]);
        $r = $this->rows($batch)[0];
        $this->assertSame(['update', 'ready'], [$r['action'], $r['result']], (string) $r['messages']);
        $this->assertStringContainsString('Phone: (empty) → 07700 900999', $r['changes']);
        $this->assertStringContainsString('Preferred Name: Bobby → (empty)', $r['changes']);
        $this->assertSame('Bob', DB::value('SELECT first_name FROM employees WHERE id = ?', [self::$f['empA']]));   // nothing saved before confirm

        $res = EmployeeTransfer::confirm($batch, self::$f['admin']);
        $this->assertSame(['created' => 0, 'updated' => 1, 'failed' => 0], $res);
        $e = DB::one('SELECT * FROM employees WHERE id = ?', [self::$f['empA']]);
        $this->assertSame(['07700 900999', null, 'Leeds', 'on_leave', 'Bob', 'Driver'], [$e['phone'], $e['preferred_name'], $e['city'], $e['status'], $e['first_name'], $e['last_name']]);
        $this->assertEquals(30, (float) $e['contract_hours']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.updated' AND entity_id = ?", [self::$f['empA']]));
    }

    public function testNewEmployeesWithNextIdOrAGivenId(): void
    {
        $next = EmployeeService::previewNextCode();
        [$prefix, $pad] = EmployeeService::codeFormat();
        $given = EmployeeService::formatCode($prefix, 90, $pad);
        $batch = $this->import([
            $this->row(['first_name' => 'New', 'last_name' => 'Starter', 'department' => 'Drivers', 'designation' => 'Van Driver', 'start_date' => '03/11/2026', 'reporting_manager' => $this->code('empA')]),
            $this->row(['employee_code' => $given, 'first_name' => 'Given', 'last_name' => 'Code', 'department' => 'Warehouse', 'designation' => 'Picker', 'start_date' => '2026-11-03', 'employment_type' => 'part_time']),
        ]);
        $this->assertSame(['ready', 'ready'], array_column($this->rows($batch), 'result'), json_encode(array_column($this->rows($batch), 'messages')));
        EmployeeTransfer::confirm($batch, self::$f['admin']);
        $a = DB::one("SELECT * FROM employees WHERE first_name = 'New' AND last_name = 'Starter'");
        $b = DB::one("SELECT * FROM employees WHERE first_name = 'Given'");
        $this->assertSame([$next, 'full_time', 'active', '2026-11-03', self::$f['empA']], [$a['employee_code'], $a['employment_type'], $a['status'], $a['start_date'], (int) $a['line_manager_id']]);
        $this->assertSame([$given, 'part_time'], [$b['employee_code'], $b['employment_type']]);
        $this->assertTrue((bool) DB::value("SELECT id FROM departments WHERE name = 'Warehouse'"));   // created like the form does
        // The sequence moved past the given ID, so the next automatic ID never collides with it.
        $this->assertSame(EmployeeService::formatCode($prefix, 91, $pad), EmployeeService::previewNextCode());
    }

    public function testProblemsAreReportedPerRowAndNothingIsSaved(): void
    {
        $archived = EmployeeService::create(['first_name' => 'Old', 'last_name' => 'Timer', 'department' => 'Drivers', 'designation' => 'Van Driver', 'employment_type' => 'full_time',
            'start_date' => '2020-01-01', 'status' => 'active'], self::$f['admin']);
        EmployeeService::softDelete($archived);
        $archivedCode = (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [$archived]);
        $batch = $this->import([
            $this->row(['employee_code' => $this->code('empA'), 'status' => 'Retired', 'date_of_birth' => '31/02/1990']),                 // bad choice + bad date
            $this->row(['employee_code' => $this->code('empA'), 'city' => 'York']),                                                       // same ID twice
            $this->row(['employee_code' => $archivedCode, 'city' => 'York']),                                                             // archived ID
            $this->row(['employee_code' => 'XYZ-1', 'first_name' => 'Bad', 'last_name' => 'Format', 'department' => 'D', 'designation' => 'D', 'start_date' => '01/01/2026']),
            $this->row(['first_name' => 'No', 'last_name' => 'Department', 'start_date' => '01/01/2026']),                                // required fields missing
            $this->row(['first_name' => 'Dee', 'last_name' => 'Rector', 'department' => 'Board', 'designation' => 'Director', 'start_date' => '01/01/2026',
                'reporting_manager' => $this->code('empA')]),                                                                            // Directors report to no one
            $this->row(['employee_code' => $this->code('empB'), 'email' => 'not-an-email']),
        ]);
        $msgs = array_column($this->rows($batch), 'messages');
        $this->assertSame(array_fill(0, 7, 'error'), array_column($this->rows($batch), 'result'));
        $this->assertStringContainsString('Status: "Retired" is not one of', $msgs[0]);
        $this->assertStringContainsString('Date of Birth: use DD/MM/YYYY', $msgs[0]);
        $this->assertStringContainsString('is also on row 2', $msgs[1]);
        $this->assertStringContainsString('archived employee', $msgs[2]);
        $this->assertStringContainsString('not in the Employee ID format', $msgs[3]);
        $this->assertStringContainsString('Department', $msgs[4]);
        $this->assertStringContainsString('Directors do not have a reporting manager', $msgs[5]);
        $this->assertStringContainsString('Email', $msgs[6]);
        $this->assertSame(0, EmployeeTransfer::confirm($batch, self::$f['admin'])['updated']);
        $report = tempnam(sys_get_temp_dir(), 'err') . '.xlsx';
        file_put_contents($report, EmployeeTransfer::errorReport($batch));
        $this->assertSame(8, \PhpOffice\PhpSpreadsheet\IOFactory::load($report)->getSheet(0)->getHighestDataRow());   // header + 7 rows
    }

    public function testFileWithOnlySomeColumnsUpdatesJustThose(): void
    {
        $batch = $this->import([[$this->code('empB'), 'Manchester']], ['Employee ID', 'City']);
        $this->assertSame('ready', $this->rows($batch)[0]['result'], (string) $this->rows($batch)[0]['messages']);
        EmployeeTransfer::confirm($batch, self::$f['admin']);
        $e = DB::one('SELECT * FROM employees WHERE id = ?', [self::$f['empB']]);
        $this->assertSame(['Manchester', 'Cara', 'Courier'], [$e['city'], $e['first_name'], $e['last_name']]);
        // A file without an Employee ID column is refused.
        $this->expectException(\App\Core\ValidationException::class);
        $this->import([['Someone']], ['First Name']);
    }

    public function testImportScreensAreAdminOnlyAndWorkOverHttp(): void
    {
        $this->actingAs(self::$f['userA']);
        $this->assertSame(403, $this->get('/employees/import')->status);
        $this->assertSame(403, $this->get('/employees/export')->status);
        $this->actingAs(self::$f['admin']);
        $page = $this->get('/employees/import')->body;
        $this->assertStringContainsString('Employee ID is the key', $page);
        $this->assertSame(200, $this->get('/employees/import/template')->status);
        $batch = $this->import([$this->row(['employee_code' => $this->code('empB'), 'city' => 'Hull'])]);
        $preview = $this->get("/employees/import/$batch")->body;
        $this->assertStringContainsString('City: (empty) → Hull', $preview);
        $this->assertStringContainsString('Import 0 new and 1 updated employees', $preview);
        $this->post("/employees/import/$batch/confirm");
        $this->assertSame('Hull', DB::value('SELECT city FROM employees WHERE id = ?', [self::$f['empB']]));
        $this->assertSame('imported', DB::value('SELECT status FROM employee_import_batches WHERE id = ?', [$batch]));
        $this->assertStringContainsString('Import', $this->get('/employees')->body);
    }
}
