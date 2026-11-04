<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\ValidationException;
use App\Services\ShiftService;

/** Shift Import › Create shifts by pattern: employees, a date range, weekdays and times; bank holidays are left free. */
final class ShiftPatternTest extends FeatureTestCase
{
    private function dates(): array
    {
        return array_map(fn ($r) => uk_date($r['start_at']), DB::all("SELECT start_at FROM shifts WHERE employee_id = ? AND status = 'published' ORDER BY start_at", [self::$f['empA']]));
    }

    public function testCreatesShiftsOnTheChosenWeekdaysAndSkipsBankHolidaysLeaveAndExistingShifts(): void
    {
        foreach (['2026-12-25' => 'Christmas Day', '2026-12-28' => 'Boxing Day (substitute day)', '2027-01-01' => "New Year's Day"] as $d => $n) {
            DB::query("INSERT IGNORE INTO public_holidays (holiday_date, name, region) VALUES (?, ?, 'england-and-wales')", [$d, $n]);
        }
        DB::insert('leave_requests', ['employee_id' => self::$f['empA'], 'leave_type_id' => self::$f['annual'], 'start_date' => '2026-12-30', 'end_date' => '2026-12-30',
            'days' => 1, 'status' => 'approved', 'requested_by' => self::$f['userA']]);
        ShiftService::save(null, ['employee_id' => self::$f['empA'], 'date' => '2026-12-22', 'start_time' => '10:00', 'end_time' => '14:00', 'break_minutes' => '0',
            'status' => 'published'], self::$f['admin']);

        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('Create shifts by pattern', $this->get('/shift-import')->body);
        $res = $this->post('/shift-import/pattern', ['employee_ids' => [self::$f['empA']], 'from' => '2026-12-21', 'to' => '2027-01-03', 'weekdays' => ['1', '2', '3', '4', '5'],
            'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => '30', 'status' => 'published', 'shift_type_id' => '', 'location_id' => '', 'notes' => '']);
        $this->assertSame(302, $res->status);

        // Mon–Fri 21/12–03/01 is 10 days: 3 bank holidays, 1 leave day and 1 day with a shift are skipped.
        $this->assertSame(['21/12/2026', '22/12/2026', '23/12/2026', '24/12/2026', '29/12/2026', '31/12/2026'], $this->dates());
        $this->assertSame(450, (int) DB::value("SELECT net_minutes FROM shifts WHERE employee_id = ? AND start_at >= ? ORDER BY start_at LIMIT 1", [self::$f['empA'], to_utc('2026-12-21 00:00')]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'shift.pattern'"));

        // Running it again creates nothing new.
        $r = ShiftService::createPattern(['employee_ids' => [self::$f['empA']], 'from' => '2026-12-21', 'to' => '2027-01-03', 'weekdays' => [1, 2, 3, 4, 5],
            'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => '30', 'status' => 'published'], self::$f['admin']);
        $this->assertSame(0, $r['created']);
        $this->assertSame(['bank holiday' => 3, 'on leave' => 1, 'already has a shift' => 6], $r['skipped']);
    }

    public function testDeletesTheChosenEmployeesShiftsInARange(): void
    {
        $base = ['start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => '30', 'status' => 'published'];
        ShiftService::createPattern($base + ['employee_ids' => [self::$f['empA'], self::$f['empAdmin']], 'from' => '2026-10-05', 'to' => '2026-10-09', 'weekdays' => [1, 2, 3, 4, 5]], self::$f['admin']);
        ShiftService::save(null, ['employee_id' => self::$f['empA'], 'date' => '2026-10-07', 'start_time' => '18:00', 'end_time' => '20:00', 'break_minutes' => '0', 'status' => 'draft', 'override_reason' => 'Covering a late run'], self::$f['admin']);
        $count = fn (int $e) => (int) DB::value("SELECT COUNT(*) FROM shifts WHERE employee_id = ?", [$e]);
        $this->assertSame([6, 5], [$count(self::$f['empA']), $count(self::$f['empAdmin'])]);
        $this->assertNotNull(DB::value("SELECT id FROM attendance_records WHERE employee_id = ? AND work_date = '2026-10-06' AND status = 'present'", [self::$f['empA']]));

        // Only Bob's published shifts from Tuesday to Thursday go; his draft and Alice's shifts stay.
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('id="delete-shifts"', $this->get('/shift-import')->body);
        $this->post('/shift-import/delete', ['employee_ids' => [self::$f['empA']], 'from' => '2026-10-06', 'to' => '2026-10-08', 'status' => 'published']);
        $this->assertSame([3, 5], [$count(self::$f['empA']), $count(self::$f['empAdmin'])]);
        $this->assertSame('off', DB::value("SELECT status FROM attendance_records WHERE employee_id = ? AND work_date = '2026-10-06'", [self::$f['empA']]), 'The freed day is a day off');
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'shift.deleted'"));

        // "All" takes the draft too.
        $this->assertSame(3, ShiftService::deleteRange(['employee_ids' => [self::$f['empA']], 'from' => '2026-10-05', 'to' => '2026-10-09', 'status' => 'all'], self::$f['admin']));
        $this->assertSame(0, $count(self::$f['empA']));
    }

    public function testNeedsEmployeesWeekdaysAndAValidRange(): void
    {
        try {
            ShiftService::createPattern(['employee_ids' => [], 'from' => '2026-12-10', 'to' => '2026-12-01', 'weekdays' => [],
                'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => '30', 'status' => 'published'], self::$f['admin']);
            $this->fail('An empty pattern was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['employee_ids', 'weekdays'], array_keys($e->errors));
        }
        $this->expectException(ValidationException::class);
        ShiftService::createPattern(['employee_ids' => [self::$f['empA']], 'from' => '2026-12-10', 'to' => '2026-12-01', 'weekdays' => [1],
            'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => '30', 'status' => 'published'], self::$f['admin']);
    }
}
