<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use App\Services\WorkingTimeChecker;
use PHPUnit\Framework\TestCase;

final class ValidatorAndRestTest extends TestCase
{
    public function testRequiredAndFormats(): void
    {
        $v = Validator::make(
            ['name' => '', 'email' => 'bad', 'start' => '2026-10-01', 'end' => '2026-09-30', 'postcode' => 'NN11 4AB', 'hours' => '61'],
            ['name' => 'required', 'email' => 'nullable|email', 'start' => 'required|date', 'end' => 'date|after_or_equal:start', 'postcode' => 'postcode', 'hours' => 'numeric|max:60']
        );
        $e = $v->errors();
        $this->assertArrayHasKey('name', $e);
        $this->assertArrayHasKey('email', $e);
        $this->assertArrayHasKey('end', $e);
        $this->assertArrayHasKey('hours', $e);
        $this->assertArrayNotHasKey('postcode', $e);
        $this->assertArrayNotHasKey('start', $e);
    }

    public function testIsDateRejectsImpossibleDates(): void
    {
        $this->assertTrue(Validator::isDate('2028-02-29'));
        $this->assertFalse(Validator::isDate('2026-02-29'));
        $this->assertFalse(Validator::isDate('2026-9-1'));
    }

    public function testRestBreachesBetweenShifts(): void
    {
        $shifts = [
            ['id' => 1, 'start_at' => '2026-10-12 05:00:00', 'end_at' => '2026-10-12 14:00:00'],
            ['id' => 2, 'start_at' => '2026-10-12 21:00:00', 'end_at' => '2026-10-13 05:00:00'], // 7h after shift 1
            ['id' => 3, 'start_at' => '2026-10-13 17:00:00', 'end_at' => '2026-10-13 22:00:00'], // 12h after shift 2
        ];
        $b = WorkingTimeChecker::restBreaches($shifts, 11);
        $this->assertCount(1, $b);
        $this->assertSame(420, $b[0]['rest_minutes']);
        $this->assertSame(2, $b[0]['before']['id']);
    }
}
