<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Services\HoursCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HoursCalculatorTest extends TestCase
{
    public function testDayShiftNetMinutesSubtractsBreak(): void
    {
        [$s, $e] = HoursCalculator::utcSpan('2026-10-12', '06:00', '14:30');
        $this->assertSame(480, HoursCalculator::netMinutes($s, $e, 30));
    }

    public function testBstIsConvertedToUtcForStorage(): void
    {
        [$s, $e, $overnight] = HoursCalculator::utcSpan('2026-07-01', '09:00', '17:00');
        $this->assertSame('2026-07-01 08:00:00', $s);
        $this->assertSame('2026-07-01 16:00:00', $e);
        $this->assertFalse($overnight);
    }

    public function testGmtWinterTimeEqualsUtc(): void
    {
        [$s] = HoursCalculator::utcSpan('2026-12-01', '09:00', '17:00');
        $this->assertSame('2026-12-01 09:00:00', $s);
    }

    public function testEndBeforeStartMeansNextDay(): void
    {
        [$s, $e, $overnight] = HoursCalculator::utcSpan('2026-10-14', '22:00', '06:00');
        $this->assertTrue($overnight);
        $this->assertSame('2026-10-14 21:00:00', $s);
        $this->assertSame('2026-10-15 05:00:00', $e);
        $this->assertSame(450, HoursCalculator::netMinutes($s, $e, 30));
    }

    /** Clocks go back at 02:00 on Sun 25 Oct 2026: a 22:00–06:00 shift lasts 9 real hours. */
    public function testNightShiftWhenClocksGoBackIsNineHours(): void
    {
        [$s, $e] = HoursCalculator::utcSpan('2026-10-24', '22:00', '06:00');
        $this->assertSame(9 * 60, HoursCalculator::netMinutes($s, $e, 0));
    }

    /** Clocks go forward at 01:00 on Sun 29 Mar 2026: a 22:00–06:00 shift lasts 7 real hours. */
    public function testNightShiftWhenClocksGoForwardIsSevenHours(): void
    {
        [$s, $e] = HoursCalculator::utcSpan('2026-03-28', '22:00', '06:00');
        $this->assertSame(7 * 60, HoursCalculator::netMinutes($s, $e, 0));
    }

    public function testBreakLongerThanShiftIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        HoursCalculator::netMinutes('2026-10-12 08:00:00', '2026-10-12 08:30:00', 30);
    }

    public function testNegativeDurationIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        HoursCalculator::netMinutes('2026-10-12 10:00:00', '2026-10-12 09:00:00', 0);
    }

    public function testNegativeBreakIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        HoursCalculator::netMinutes('2026-10-12 08:00:00', '2026-10-12 16:00:00', -5);
    }

    #[DataProvider('limitCases')]
    public function testWeeklyLimitPriority(array $employee, int $expected): void
    {
        $this->assertSame($expected, HoursCalculator::limitMinutes($employee));
    }

    public static function limitCases(): array
    {
        return [
            'employee override wins'      => [['weekly_hours_limit' => '40.00', 'contract_hours' => '20.00'], 2400],
            'contract hours when no override' => [['weekly_hours_limit' => null, 'contract_hours' => '22.50'], 1350],
        ];
    }
}
