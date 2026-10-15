<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EmployeeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HistoryValidationTest extends TestCase
{
    #[DataProvider('phones')]
    public function testPhoneNormalisation(string $code, string $number, string|null|false $expected): void
    {
        $this->assertSame($expected, EmployeeService::normalisePhone($code, $number));
    }

    public static function phones(): array
    {
        return [
            'UK mobile with trunk 0'   => ['+44', '07700 900123', '+44 7700900123'],
            'UK without 0'             => ['+44', '7700900123', '+44 7700900123'],
            'typed with own +code'     => ['+44', '+48 512 345 678', '+48 512345678'],
            '00 international prefix'  => ['+44', '0091 98765 43210', '+91 9876543210'],
            'chosen country code'      => ['+353', '087 123 4567', '+353 871234567'],
            'blank is allowed'         => ['+44', '   ', null],
            'letters rejected'         => ['+44', '0770 ABC 123', false],
            'too short'                => ['+44', '123', false],
            'unknown code from list'   => ['+999', '7700900123', false],
        ];
    }

    public function testSplitPhoneForTheForm(): void
    {
        $this->assertSame(['+44', '7700900123'], EmployeeService::splitPhone('+44 7700900123'));
        $this->assertSame(['+44', ''], EmployeeService::splitPhone(null));
    }

    private function exp(array $over = []): array
    {
        return $over + ['company_name' => 'Acme Haulage', 'job_title' => 'Driver', 'from_date' => '2020-01-01', 'to_date' => '2022-06-30',
            'location' => 'Leeds', 'reference_name' => 'Jo Bloggs', 'reference_email' => 'jo@example.com', 'reference_phone_code' => '+44', 'reference_phone' => '07700 900123'];
    }

    public function testValidRowsAreNormalisedAndBlankRowsIgnored(): void
    {
        $h = EmployeeService::validateHistory(
            [$this->exp(), ['company_name' => '', 'job_title' => ''], $this->exp(['company_name' => 'Now Ltd', 'is_current' => '1', 'to_date' => '2025-01-01'])],
            [['institute_name' => 'Leeds College', 'education_level' => 'Diploma', 'specialization' => 'Logistics', 'completion_date' => '2015-06-30'], ['institute_name' => '']]
        );
        $this->assertSame([], $h['errors']);
        $this->assertCount(2, $h['experience']);
        $this->assertSame('+44 7700900123', $h['experience'][0]['reference_phone']);
        $this->assertNull($h['experience'][1]['to_date']);          // currently works there
        $this->assertSame(1, $h['experience'][1]['is_current']);
        $this->assertCount(1, $h['education']);
    }

    public function testErrorsAreKeyedByRow(): void
    {
        $h = EmployeeService::validateHistory(
            [$this->exp(), $this->exp(['job_title' => '', 'to_date' => '2019-01-01', 'reference_email' => 'not-an-email', 'reference_phone' => '12'])],
            [['institute_name' => 'X', 'education_level' => 'Primary school']]
        );
        $this->assertArrayHasKey('experience.1.job_title', $h['errors']);
        $this->assertArrayHasKey('experience.1.to_date', $h['errors']);
        $this->assertArrayHasKey('experience.1.reference_email', $h['errors']);
        $this->assertArrayHasKey('experience.1.reference_phone', $h['errors']);
        $this->assertArrayHasKey('education.0.education_level', $h['errors']);
        $this->assertArrayNotHasKey('experience.0.job_title', $h['errors']);
    }

    public function testToDateNeededUnlessCurrent(): void
    {
        $h = EmployeeService::validateHistory([$this->exp(['to_date' => ''])], []);
        $this->assertSame('To date is required.', $h['errors']['experience.0.to_date']);
    }
}
