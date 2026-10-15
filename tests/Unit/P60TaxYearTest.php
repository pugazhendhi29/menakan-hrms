<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\P60Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class P60TaxYearTest extends TestCase
{
    #[DataProvider('dates')]
    public function testTaxYearRunsSixthAprilToFifthApril(string $date, int $start): void
    {
        $this->assertSame($start, P60Service::taxYearStartFor($date));
    }

    public static function dates(): array
    {
        return [
            '5 April is the last day' => ['2026-04-05', 2025],
            '6 April starts a new year' => ['2026-04-06', 2026],
            'January'                   => ['2026-01-15', 2025],
            'October'                   => ['2026-10-15', 2026],
            '31 December'               => ['2026-12-31', 2026],
        ];
    }

    public function testLabelsAndRange(): void
    {
        $this->assertSame('2025/26', P60Service::label(2025));
        $this->assertSame('2099/00', P60Service::label(2099));
        $this->assertSame('6 Apr 2025 – 5 Apr 2026', P60Service::range(2025));
    }

    public function testLatestCompletedTaxYear(): void
    {
        $this->assertSame(2025, P60Service::latestCompleted('2026-10-15'));   // 2025/26 ended on 5 April 2026
        $this->assertSame(2024, P60Service::latestCompleted('2026-04-05'));   // 2025/26 has not ended yet
    }
}
