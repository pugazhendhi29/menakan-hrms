<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AttendanceImportService;
use App\Services\ExcelIO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportValidationTest extends TestCase
{
    #[DataProvider('dates')]
    public function testParseDate(mixed $in, ?string $expected): void
    {
        $this->assertSame($expected, ExcelIO::parseDate($in));
    }

    public static function dates(): array
    {
        return [
            'uk text'            => ['12/10/2026', '2026-10-12'],
            'single digits'      => ['1/2/2026', '2026-02-01'],
            'iso text'           => ['2026-10-12', '2026-10-12'],
            'excel serial'       => [46307, '2026-10-12'],
            '31 September'       => ['31/09/2026', null],
            '29 Feb non-leap'    => ['29/02/2026', null],
            'US order rejected'  => ['10/31/2026', null],
            'rubbish'            => ['next tuesday', null],
            'blank'              => ['', null],
        ];
    }

    #[DataProvider('times')]
    public function testParseTime(mixed $in, ?string $expected): void
    {
        $this->assertSame($expected, ExcelIO::parseTime($in));
    }

    public static function times(): array
    {
        return [
            'text'          => ['06:00', '06:00'],
            'single hour'   => ['6:05', '06:05'],
            'with seconds'  => ['22:00:00', '22:00'],
            'dot separator' => ['14.30', '14:30'],
            'excel fraction' => [0.25, '06:00'],
            'excel half'    => [0.5, '12:00'],
            '24:00 invalid' => ['24:00', null],
            'bad minutes'   => ['10:75', null],
            'fraction >= 1' => [1.5, null],
            'blank'         => [null, null],
        ];
    }

    private function row(array $v): array
    {
        $find = fn (string $code) => $code === 'MT0012' ? ['id' => 12, 'employee_code' => 'MT0012', 'first_name' => 'Daniel', 'last_name' => 'Okafor', 'status' => 'active']
            : ($code === 'MT0008' ? ['id' => 8, 'employee_code' => 'MT0008', 'first_name' => 'Gareth', 'last_name' => 'Lloyd', 'status' => 'left'] : null);
        return AttendanceImportService::validateRow($v + ['employee_code' => 'MT0012', 'work_date' => '12/10/2026', 'check_in' => '06:00', 'check_out' => '14:30', 'break_minutes' => 30], $find, '2026-10-14');
    }

    public function testValidRow(): void
    {
        $r = $this->row([]);
        $this->assertSame([], $r['messages']);
        $this->assertSame(480, $r['net_minutes']);
        $this->assertSame('2026-10-12 05:00:00', $r['check_in_at']);
    }

    public function testOvernightRow(): void
    {
        $r = $this->row(['check_in' => '22:00', 'check_out' => '06:00']);
        $this->assertSame([], $r['messages']);
        $this->assertTrue($r['overnight']);
        $this->assertSame(450, $r['net_minutes']);
    }

    public function testErrorsAreCollected(): void
    {
        $this->assertContains('Employee ID not found', $this->row(['employee_code' => 'MT9999'])['messages']);
        $this->assertContains('Employee has left', $this->row(['employee_code' => 'MT0008'])['messages']);
        $this->assertContains('Invalid date', $this->row(['work_date' => '31/09/2026'])['messages']);
        $this->assertContains('Work Date is in the future', $this->row(['work_date' => '20/10/2026'])['messages']);
        $this->assertContains('Check Out missing', $this->row(['check_out' => ''])['messages']);
        $this->assertContains('Invalid Check In time', $this->row(['check_in' => '25:00'])['messages']);
        $this->assertContains('Break Minutes must be a whole number of 0 or more', $this->row(['break_minutes' => -5])['messages']);
        $this->assertContains('Shift longer than 16 hours', $this->row(['check_in' => '05:00', 'check_out' => '23:00', 'break_minutes' => 0])['messages']);
        $this->assertContains('The break must be shorter than the shift', $this->row(['check_in' => '06:00', 'check_out' => '06:20'])['messages']);
    }
}
