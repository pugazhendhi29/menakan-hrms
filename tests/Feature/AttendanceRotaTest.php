<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\ValidationException;
use App\Services\AttendanceService;
use App\Services\ShiftService;

/** Attendance recorded from the rota: published shifts that have ended, up to AttendanceService::ROTA_UNTIL. */
final class AttendanceRotaTest extends FeatureTestCase
{
    private function shift(string $date, string $start, string $end, array $over = []): int
    {
        return ShiftService::save(null, $over + ['employee_id' => self::$f['empA'], 'date' => $date, 'start_time' => $start, 'end_time' => $end,
            'break_minutes' => '30', 'status' => 'published'], self::$f['admin'])['id'];
    }

    private function rota(int $shiftId): ?array
    {
        return DB::one("SELECT * FROM attendance_records WHERE shift_id = ? AND source = 'rota'", [$shiftId]);
    }

    public function testPublishedShiftsThatEndedAreRecordedAsAttendanceAndFollowTheShift(): void
    {
        $id = $this->shift('2026-10-05', '08:00', '16:00');
        $a = $this->rota($id);
        $this->assertNotNull($a);
        $this->assertSame(['2026-10-05', '08:00', '16:00', 30, 450, 'present'], [$a['work_date'], uk_time($a['check_in_at']), uk_time($a['check_out_at']),
            (int) $a['break_minutes'], (int) $a['net_minutes'], $a['status']]);

        // Not recorded: drafts, shifts that have not ended, and shifts after 31/10/2026.
        $this->assertNull($this->rota($this->shift('2026-10-06', '08:00', '16:00', ['status' => 'draft'])));
        $this->assertNull($this->rota($this->shift(uk_now()->modify('+1 day')->format('Y-m-d'), '08:00', '16:00')));
        $this->assertNull($this->rota($this->shift('2026-11-02', '08:00', '16:00')));

        // The shift changes: so does its attendance. Cancelled: the attendance goes.
        ShiftService::save($id, ['employee_id' => self::$f['empA'], 'date' => '2026-10-05', 'start_time' => '09:00', 'end_time' => '17:30', 'break_minutes' => '45', 'status' => 'published'], self::$f['admin']);
        $a = $this->rota($id);
        $this->assertSame(['09:00', '17:30', 45], [uk_time($a['check_in_at']), uk_time($a['check_out_at']), (int) $a['break_minutes']]);
        ShiftService::cancel($id, self::$f['admin']);
        $this->assertNull($this->rota($id));
    }

    public function testLeaveDaysRealAttendanceAndDaysBeforeTheStartDateAreLeftAlone(): void
    {
        DB::insert('leave_requests', ['employee_id' => self::$f['empA'], 'leave_type_id' => self::$f['annual'], 'start_date' => '2026-10-07', 'end_date' => '2026-10-07',
            'days' => 1, 'status' => 'approved', 'requested_by' => self::$f['userA']]);
        $this->assertNull($this->rota($this->shift('2026-10-07', '08:00', '16:00')));

        DB::insert('attendance_records', ['employee_id' => self::$f['empA'], 'work_date' => '2026-10-08', 'check_in_at' => to_utc('2026-10-08 08:05'),
            'check_out_at' => to_utc('2026-10-08 16:10'), 'break_minutes' => 30, 'net_minutes' => 455, 'source' => 'web']);
        $this->assertNull($this->rota($this->shift('2026-10-08', '08:00', '16:00')));   // the real check-in counts

        DB::update('employees', ['start_date' => '2026-10-12'], 'id = ?', [self::$f['empA']]);
        $this->assertNull($this->rota($this->shift('2026-10-09', '08:00', '16:00')));   // before the start date
        $this->assertNotNull($this->rota($this->shift('2026-10-12', '08:00', '16:00')));
    }

    public function testRotaAttendanceCanOnlyBeChangedOnALeaveDay(): void
    {
        $id = $this->shift('2026-10-13', '08:00', '16:00');
        $a = $this->rota($id);
        $edit = ['work_date' => '2026-10-13', 'check_in' => '10:00', 'check_out' => '14:00', 'break_minutes' => '0', 'reason' => 'Left early'];
        try {
            AttendanceService::save((int) $a['id'], $edit, self::$f['admin']);
            $this->fail('A rota record was changed on a working day.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('only be changed on a day the employee is on approved leave', $e->getMessage());
        }
        $this->actingAs(self::$f['admin']);
        $this->assertSame(302, $this->get('/attendance/' . $a['id'] . '/edit')->status);
        $this->assertStringContainsString('bi-lock', $this->get('/attendance', ['from' => '2026-10-12', 'to' => '2026-10-18'])->body);

        // The employee took leave that day: now it can be corrected (and no longer follows the shift) or removed.
        DB::insert('leave_requests', ['employee_id' => self::$f['empA'], 'leave_type_id' => self::$f['annual'], 'start_date' => '2026-10-13', 'end_date' => '2026-10-13',
            'days' => 1, 'status' => 'approved', 'requested_by' => self::$f['userA']]);
        $this->assertSame(200, $this->get('/attendance/' . $a['id'] . '/edit')->status);
        AttendanceService::save((int) $a['id'], $edit, self::$f['admin']);
        $this->assertSame(['manual', '10:00'], array_values(array_map(fn ($v) => $v, ['source' => DB::value('SELECT source FROM attendance_records WHERE id = ?', [$a['id']]),
            'in' => uk_time((string) DB::value('SELECT check_in_at FROM attendance_records WHERE id = ?', [$a['id']]))])));

        $other = $this->rota($this->shift('2026-10-14', '08:00', '16:00'));
        DB::insert('leave_requests', ['employee_id' => self::$f['empA'], 'leave_type_id' => self::$f['annual'], 'start_date' => '2026-10-14', 'end_date' => '2026-10-14',
            'days' => 1, 'status' => 'approved', 'requested_by' => self::$f['userA']]);
        $this->post('/attendance/' . $other['id'] . '/remove', ['reason' => 'On approved leave']);
        $this->assertNull(DB::value('SELECT id FROM attendance_records WHERE id = ?', [$other['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'attendance.removed' AND entity_id = ?", [$other['id']]));
        AttendanceService::fillFromRota();
        $this->assertNull($this->rota((int) $other['shift_id']));   // not recorded again: the employee is on leave
    }
}
