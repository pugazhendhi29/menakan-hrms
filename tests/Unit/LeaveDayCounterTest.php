<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LeaveDayCounter;
use PHPUnit\Framework\TestCase;

final class LeaveDayCounterTest extends TestCase
{
    private const MON_FRI = ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 0, 'sun' => 0];
    private const MON_WED = ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 0, 'fri' => 0, 'sat' => 0, 'sun' => 0];
    private const HOLIDAYS = ['2026-12-25', '2026-12-28'];

    public function testWeekendsAreNotCounted(): void
    {
        $this->assertSame(5.0, LeaveDayCounter::count('2026-10-12', '2026-10-18', self::MON_FRI, []));
    }

    public function testBankHolidaysAreNotCounted(): void
    {
        // Thu 24 – Tue 29 Dec: 24, 29 count; 25 (holiday), 26–27 (weekend), 28 (substitute holiday) do not.
        $this->assertSame(2.0, LeaveDayCounter::count('2026-12-24', '2026-12-29', self::MON_FRI, self::HOLIDAYS));
    }

    public function testHalfDays(): void
    {
        $this->assertSame(4.5, LeaveDayCounter::count('2026-10-12', '2026-10-16', self::MON_FRI, [], true));
        $this->assertSame(4.0, LeaveDayCounter::count('2026-10-12', '2026-10-16', self::MON_FRI, [], true, true));
        $this->assertSame(0.5, LeaveDayCounter::count('2026-10-12', '2026-10-12', self::MON_FRI, [], true));
    }

    public function testPartTimePatternOnlyCountsWorkingDays(): void
    {
        $this->assertSame(3.0, LeaveDayCounter::count('2026-10-12', '2026-10-18', self::MON_WED, []));
    }

    public function testEndBeforeStartIsZero(): void
    {
        $this->assertSame(0.0, LeaveDayCounter::count('2026-10-16', '2026-10-12', self::MON_FRI, []));
    }

    public function testFullYearEntitlementIs28DaysForFiveDayWorker(): void
    {
        $this->assertSame(28.0, LeaveDayCounter::entitlement(5.6, 5, '2026-01-01', '2026-12-31', '2020-01-01', null));
    }

    public function testEntitlementIsCappedAt28ForSixDayWorker(): void
    {
        $this->assertSame(28.0, LeaveDayCounter::entitlement(5.6, 6, '2026-01-01', '2026-12-31', '2020-01-01', null));
    }

    public function testPartTimeEntitlementIsProRataByDays(): void
    {
        // 5.6 x 3 = 16.8 -> rounded up to the next half day
        $this->assertSame(17.0, LeaveDayCounter::entitlement(5.6, 3, '2026-01-01', '2026-12-31', '2020-01-01', null));
    }

    public function testStarterPartWayThroughYearIsProRated(): void
    {
        // Starts 1 July: 184 of 365 days -> 28 x 184/365 = 14.11 -> 14.5
        $this->assertSame(14.5, LeaveDayCounter::entitlement(5.6, 5, '2026-01-01', '2026-12-31', '2026-07-01', null));
    }

    public function testLeaverIsProRated(): void
    {
        // Leaves 31 March: 90 of 365 days -> 6.9 -> 7.0
        $this->assertSame(7.0, LeaveDayCounter::entitlement(5.6, 5, '2026-01-01', '2026-12-31', '2020-01-01', '2026-03-31'));
    }

    public function testWorkingDaysPerWeek(): void
    {
        $this->assertSame(5, LeaveDayCounter::workingDaysPerWeek(self::MON_FRI));
        $this->assertSame(3, LeaveDayCounter::workingDaysPerWeek(self::MON_WED));
    }
}
