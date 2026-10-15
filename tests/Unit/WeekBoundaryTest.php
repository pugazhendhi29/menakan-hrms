<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WeekBoundary;
use PHPUnit\Framework\TestCase;

final class WeekBoundaryTest extends TestCase
{
    public function testWeekStartsOnMonday(): void
    {
        $this->assertSame('2026-10-12', WeekBoundary::weekStartForDate('2026-10-12'));
        $this->assertSame('2026-10-12', WeekBoundary::weekStartForDate('2026-10-14'));
        $this->assertSame('2026-10-12', WeekBoundary::weekStartForDate('2026-10-18')); // Sunday belongs to the week before
        $this->assertSame('2026-10-19', WeekBoundary::weekStartForDate('2026-10-19'));
    }

    public function testShiftCountsInTheUkWeekOfItsStart(): void
    {
        // Sunday 18 Oct 23:30 BST = 22:30 UTC: still the week of 12 Oct.
        $this->assertSame('2026-10-12', WeekBoundary::weekStartForUtc('2026-10-18 22:30:00'));
        // Monday 19 Oct 00:30 BST = Sunday 23:30 UTC: already the week of 19 Oct.
        $this->assertSame('2026-10-19', WeekBoundary::weekStartForUtc('2026-10-18 23:30:00'));
    }

    public function testWeekContainingClockChangeIs169Hours(): void
    {
        [$start, $end] = WeekBoundary::utcRange('2026-10-19');
        $this->assertSame('2026-10-18 23:00:00', $start);
        $this->assertSame('2026-10-26 00:00:00', $end);
        $this->assertSame(169 * 3600, strtotime($end) - strtotime($start));
    }

    public function testMonthGridIncludesNeighbouringMonthDays(): void
    {
        $weeks = WeekBoundary::monthGridWeeks(2026, 10);
        $this->assertSame('2026-09-28', $weeks[0]);
        $this->assertSame('2026-10-26', end($weeks));
        $this->assertCount(5, $weeks);
        // February 2027 starts on a Monday and has exactly 4 weeks.
        $this->assertSame(['2027-02-01', '2027-02-08', '2027-02-15', '2027-02-22'], WeekBoundary::monthGridWeeks(2027, 2));
    }

    public function testDaysOfWeek(): void
    {
        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'], WeekBoundary::daysOfWeek('2026-10-12'));
    }

    public function testUkDateOfUtc(): void
    {
        $this->assertSame('2026-10-15', WeekBoundary::ukDateOfUtc('2026-10-14 23:30:00')); // 00:30 BST next day
        $this->assertSame('2026-12-14', WeekBoundary::ukDateOfUtc('2026-12-14 23:30:00')); // GMT
    }
}
