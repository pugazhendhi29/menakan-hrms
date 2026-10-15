<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EmployeeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmployeeProfileTest extends TestCase
{
    #[DataProvider('ninos')]
    public function testNationalInsuranceNumberFormat(string $nino, bool $valid): void
    {
        $this->assertSame($valid, EmployeeService::isValidNino($nino));
    }

    public static function ninos(): array
    {
        return [
            'standard'            => ['AB123456C', true],
            'spaces and lowercase' => ['jg 10 37 59 a', true],
            'HMRC example QQ is never issued' => ['QQ123456C', false],
            'suffix must be A-D'  => ['QQ123456E', false],
            'prefix BG not issued' => ['BG123456A', false],
            'prefix GB not issued' => ['GB123456A', false],
            'D not a first letter' => ['DA123456A', false],
            'O not a second letter' => ['AO123456A', false],
            'too few digits'      => ['QQ12345C', false],
        ];
    }

    public function testMonthsBetween(): void
    {
        $this->assertSame(23, EmployeeService::monthsBetween('2022-01-01', '2023-12-31'));
        $this->assertSame(24, EmployeeService::monthsBetween('2022-01-01', '2024-01-01'));
        $this->assertSame(0, EmployeeService::monthsBetween('2024-01-10', '2024-02-05'));
        $this->assertSame(0, EmployeeService::monthsBetween('2024-05-01', '2024-01-01'));
    }

    public function testFormatMonths(): void
    {
        $this->assertSame('Less than 1 month', EmployeeService::formatMonths(0));
        $this->assertSame('1 month', EmployeeService::formatMonths(1));
        $this->assertSame('1 year', EmployeeService::formatMonths(12));
        $this->assertSame('2 years 7 months', EmployeeService::formatMonths(31));
    }

    public function testAge(): void
    {
        $this->assertSame(36, EmployeeService::age('1990-05-17', '2026-10-14'));
        $this->assertSame(35, EmployeeService::age('1990-10-15', '2026-10-14')); // birthday tomorrow
        $this->assertNull(EmployeeService::age(null));
    }

    public function testMaskHidesMiddleOfIdentifiers(): void
    {
        $this->assertSame('AB****56C', EmployeeService::mask('AB123456C'));
        $this->assertSame('—', EmployeeService::mask(null));
        $this->assertSame('***', EmployeeService::mask('ABC'));
    }
}
