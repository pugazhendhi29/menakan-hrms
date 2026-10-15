<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PayslipMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayslipMatcherTest extends TestCase
{
    private const EMPLOYEES = [
        ['id' => 1, 'employee_code' => 'MT0001', 'first_name' => 'Priya', 'last_name' => 'Anand'],
        ['id' => 4, 'employee_code' => 'MT0004', 'first_name' => 'Ravi', 'last_name' => 'Patel'],
        ['id' => 12, 'employee_code' => 'MT0012', 'first_name' => 'Daniel', 'last_name' => 'Okafor'],
        ['id' => 23, 'employee_code' => 'MT0023', 'first_name' => 'Ryan', 'last_name' => 'Patel'],
    ];

    #[DataProvider('names')]
    public function testParse(string $file, ?string $code, ?int $year, ?int $month): void
    {
        $p = PayslipMatcher::parse($file, 'MT', 4);
        $this->assertSame([$code, $year, $month], [$p['code'], $p['year'], $p['month']]);
    }

    public static function names(): array
    {
        return [
            'blueprint example'      => ['MT001_2026-09.pdf', 'MT0001', 2026, 9],
            'four digit code'        => ['MT0012_2026-09.pdf', 'MT0012', 2026, 9],
            'dash and underscore'    => ['MT0012-2026_09.pdf', 'MT0012', 2026, 9],
            'month first'            => ['payslip MT12 09-2026.pdf', 'MT0012', 2026, 9],
            'compact period'         => ['MT0012_202609.pdf', 'MT0012', 2026, 9],
            'no period'              => ['MT0012.pdf', 'MT0012', null, null],
            'invalid month ignored'  => ['MT0012_2026-13.pdf', 'MT0012', null, null],
            'code digits not a year' => ['MT2010_2026-05.pdf', 'MT2010', 2026, 5],
        ];
    }

    private function match(string $file, bool $exists = false): array
    {
        return PayslipMatcher::match($file, self::EMPLOYEES, fn () => $exists, 'MT', 4);
    }

    public function testMatchedByPaddedCode(): void
    {
        $m = $this->match('MT012_2026-09.pdf');
        $this->assertSame(PayslipMatcher::MATCHED, $m['status']);
        $this->assertSame(12, $m['employee_id']);
    }

    public function testUnknownEmployee(): void
    {
        $this->assertSame(PayslipMatcher::UNKNOWN, $this->match('MT9999_2026-09.pdf')['status']);
    }

    public function testAmbiguousSurname(): void
    {
        $m = $this->match('Patel_2026-09.pdf');
        $this->assertSame(PayslipMatcher::AMBIGUOUS, $m['status']);
        $this->assertSame([4, 23], $m['candidates']);
    }

    public function testFullNameResolvesAmbiguity(): void
    {
        $m = $this->match('Ryan_Patel_2026-09.pdf');
        $this->assertSame(PayslipMatcher::MATCHED, $m['status']);
        $this->assertSame(23, $m['employee_id']);
    }

    public function testDuplicatePeriod(): void
    {
        $this->assertSame(PayslipMatcher::DUPLICATE, $this->match('MT0012_2026-09.pdf', true)['status']);
    }

    public function testMissingPeriod(): void
    {
        $this->assertSame(PayslipMatcher::INVALID, $this->match('MT0012.pdf')['status']);
    }

    public function testDefaultFormatIsLetterAWithThreeDigits(): void
    {
        $this->assertSame('A001', PayslipMatcher::parse('A1_2026-09.pdf')['code']);
        $this->assertSame('A012', PayslipMatcher::parse('a012-2026_09.pdf')['code']);
        $this->assertSame('A1234', PayslipMatcher::parse('A1234_2026-09.pdf')['code']);
        $this->assertNull(PayslipMatcher::parse('Anand_2026-09.pdf')['code']); // a name starting with A is not a code
        $m = PayslipMatcher::match('A012_2026-09.pdf', [['id' => 5, 'employee_code' => 'A012', 'first_name' => 'X', 'last_name' => 'Y']], fn () => false);
        $this->assertSame([PayslipMatcher::MATCHED, 5, 2026, 9], [$m['status'], $m['employee_id'], $m['year'], $m['month']]);
    }

    public function testFormatCode(): void
    {
        $this->assertSame('A001', \App\Services\EmployeeService::formatCode('A', 1, 3));
        $this->assertSame('A999', \App\Services\EmployeeService::formatCode('A', 999, 3));
        $this->assertSame('A1000', \App\Services\EmployeeService::formatCode('A', 1000, 3));
    }
}