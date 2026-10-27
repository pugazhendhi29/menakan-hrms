<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Services\AttendanceImportService;
use App\Services\ExcelIO;
use App\Services\ShiftImportService;

/** Importing a file again with changed data updates the records already in the system instead of reporting clashes. */
final class ImportUpdateTest extends FeatureTestCase
{
    private function file(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        file_put_contents($path, ExcelIO::build('Import', $headers, $rows));
        return $path;
    }

    private function code(int $employeeId): string
    {
        return (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [$employeeId]);
    }

    private function shifts(array $rows): int
    {
        return ShiftImportService::validateFile($this->file(['Employee ID', 'Date', 'Start', 'End', 'Break Minutes', 'Notes'], $rows), 'rota.xlsx', 'k', self::$f['admin']);
    }

    private function attendance(array $rows): int
    {
        return AttendanceImportService::validateFile($this->file(['Employee ID', 'Work Date', 'Check In', 'Check Out', 'Break Minutes'], $rows), 'att.xlsx', 'k', self::$f['admin']);
    }

    public function testReimportingShiftsUpdatesChangedOnesAndSkipsUnchangedOnes(): void
    {
        $bob = $this->code(self::$f['empA']);
        $cara = $this->code(self::$f['empB']);
        ShiftImportService::confirm($this->shifts([[$bob, '03/11/2026', '09:00', '17:00', 30, '']]), false, '', self::$f['admin']);
        $shiftId = (int) DB::value('SELECT id FROM shifts WHERE employee_id = ?', [self::$f['empA']]);

        // The same file again: no errors, nothing changes.
        $b = $this->shifts([[$bob, '03/11/2026', '09:00', '17:00', 30, '']]);
        $this->assertSame(['duplicate', 'Already in the system: no changes'], array_values(DB::one('SELECT result, messages FROM shift_import_rows WHERE batch_id = ?', [$b])));
        $this->assertSame(['imported' => 0, 'updated' => 0, 'skipped' => 1], ShiftImportService::confirm($b, false, '', self::$f['admin']));

        // Bob's shift moved by an hour (it overlaps the old one) and Cara gets a new shift: no errors.
        $b = $this->shifts([[$bob, '03/11/2026', '10:00', '18:00', 45, 'Moved'], [$cara, '03/11/2026', '06:00', '14:00', 30, '']]);
        $this->assertSame(0, (int) DB::value('SELECT error_rows FROM shift_import_batches WHERE id = ?', [$b]));
        $row = DB::one('SELECT * FROM shift_import_rows WHERE batch_id = ? AND employee_code = ?', [$b, $bob]);
        $this->assertSame(['ready', $shiftId], [$row['result'], (int) $row['shift_id']]);
        $this->assertStringContainsString('Updates the existing shift: start 03/11 09:00 → 03/11 10:00', $row['messages']);
        $this->assertSame(['imported' => 1, 'updated' => 1, 'skipped' => 0], ShiftImportService::confirm($b, false, '', self::$f['admin']));

        $s = DB::one('SELECT * FROM shifts WHERE id = ?', [$shiftId]);
        $this->assertSame(['10:00', '18:00', 45, 435, 'Moved'], [uk_time($s['start_at']), uk_time($s['end_at']), (int) $s['break_minutes'], (int) $s['net_minutes'], $s['notes']]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM shifts WHERE employee_id = ? AND status <> 'cancelled'", [self::$f['empA']]));   // updated, not duplicated
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'shift.updated' AND entity_id = ?", [$shiftId]));
    }

    public function testReimportingAttendanceUpdatesChangedRecordsAutomatically(): void
    {
        $bob = $this->code(self::$f['empA']);
        AttendanceImportService::confirm($this->attendance([[$bob, '05/10/2026', '08:00', '16:00', 30]]), 'update', self::$f['admin']);
        $recordId = (int) DB::value('SELECT id FROM attendance_records WHERE employee_id = ?', [self::$f['empA']]);

        // Check in and check out both changed: before, the new check-in time made this an "overlaps" error.
        $b = $this->attendance([[$bob, '05/10/2026', '08:30', '17:00', 30]]);
        $row = DB::one('SELECT * FROM attendance_import_rows WHERE batch_id = ?', [$b]);
        $this->assertSame(['duplicate', $recordId], [$row['result'], (int) $row['existing_record_id']]);
        $this->assertSame('Updates the existing record: check in 08:00 → 08:30, check out 16:00 → 17:00', $row['messages']);
        $this->assertSame(['imported' => 0, 'updated' => 1, 'skipped' => 0], AttendanceImportService::confirm($b, 'update', self::$f['admin']));

        $a = DB::one('SELECT * FROM attendance_records WHERE id = ?', [$recordId]);
        $this->assertSame(['08:30', '17:00', 480], [uk_time($a['check_in_at']), uk_time($a['check_out_at']), (int) $a['net_minutes']]);
        $this->assertSame(['check_in_at', 'check_out_at'], DB::column('SELECT field FROM attendance_corrections WHERE attendance_id = ? ORDER BY field', [$recordId]));   // history kept

        // The same file again: nothing to change.
        $b = $this->attendance([[$bob, '05/10/2026', '08:30', '17:00', 30]]);
        $this->assertSame('Already in the system: no changes', DB::value('SELECT messages FROM attendance_import_rows WHERE batch_id = ?', [$b]));
        $this->assertSame(['imported' => 0, 'updated' => 0, 'skipped' => 1], AttendanceImportService::confirm($b, 'update', self::$f['admin']));

        // Approved records are kept as they are (not an error).
        DB::update('attendance_records', ['is_approved' => 1], 'id = ?', [$recordId]);
        $b = $this->attendance([[$bob, '05/10/2026', '09:00', '17:00', 30]]);
        $this->assertSame([0, 'duplicate_approved'], [(int) DB::value('SELECT error_rows FROM attendance_import_batches WHERE id = ?', [$b]),
            DB::value('SELECT result FROM attendance_import_rows WHERE batch_id = ?', [$b])]);
        AttendanceImportService::confirm($b, 'update', self::$f['admin']);
        $this->assertSame('08:30', uk_time((string) DB::value('SELECT check_in_at FROM attendance_records WHERE id = ?', [$recordId])));
    }

    public function testUpdatingIsTheDefaultOnTheConfirmScreen(): void
    {
        $bob = $this->code(self::$f['empA']);
        AttendanceImportService::confirm($this->attendance([[$bob, '06/10/2026', '08:00', '16:00', 30]]), 'update', self::$f['admin']);
        $b = $this->attendance([[$bob, '06/10/2026', '08:00', '16:30', 30]]);
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('name="duplicate_mode" value="update"', $this->get("/attendance-import/$b")->body);
        $this->post("/attendance-import/$b/confirm");   // posted without choosing anything
        $this->assertSame('16:30', uk_time((string) DB::value('SELECT check_out_at FROM attendance_records WHERE employee_id = ? AND work_date = ?', [self::$f['empA'], '2026-10-06'])));
    }
}
