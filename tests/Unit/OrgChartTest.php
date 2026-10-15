<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OrgChart;
use PHPUnit\Framework\TestCase;

final class OrgChartTest extends TestCase
{
    private static function p(int $id, ?int $manager): array
    {
        return ['id' => $id, 'line_manager_id' => $manager];
    }

    public function testBuildsTreeFromReportingManagers(): void
    {
        $t = OrgChart::build([self::p(1, null), self::p(2, 1), self::p(3, 1), self::p(4, 2), self::p(5, null)]);
        $this->assertSame([1, 5], $t['roots']);
        $this->assertSame([1 => [2, 3], 2 => [4]], $t['children']);
        $this->assertSame([], $t['cycle']);
    }

    public function testManagerOutsideTheChartMakesARoot(): void
    {
        // 7 reports to 99, who has left (not in the chart), and 8 reports to themselves by mistake.
        $t = OrgChart::build([self::p(7, 99), self::p(8, 8), self::p(9, 7)]);
        $this->assertSame([7, 8], $t['roots']);
        $this->assertSame([7 => [9]], $t['children']);
    }

    public function testCircularReportingIsDetected(): void
    {
        $t = OrgChart::build([self::p(1, null), self::p(2, 3), self::p(3, 2), self::p(4, 3)]);
        $this->assertSame([1], $t['roots']);
        $this->assertEqualsCanonicalizing([2, 3, 4], $t['cycle']);
    }

    public function testSearchKeepsTheChainOfManagers(): void
    {
        $managerOf = [1 => null, 2 => 1, 3 => 2, 4 => 1];
        $this->assertSame([3 => true, 2 => true, 1 => true], OrgChart::withAncestors([3], $managerOf));
        $loop = OrgChart::withAncestors([5], [5 => 6, 6 => 5]);   // terminates on a loop
        $this->assertSame([5 => true, 6 => true], $loop);
    }

    public function testDirectorsAreLevelOneUnderTheCompany(): void
    {
        $d = fn (int $id, ?int $m, string $title) => ['id' => $id, 'line_manager_id' => $m, 'designation' => $title];
        // 1-3 are Directors; 3 was wrongly set to report to Director 1. 4 is a manager under 2; 5 under 4; 6 has no manager and no reports.
        $t = OrgChart::build([$d(1, null, 'Director'), $d(2, null, 'Managing Director'), $d(3, 1, 'Director'), $d(4, 2, 'Transport Manager'),
            $d(5, 4, 'Van Driver'), $d(6, null, 'Trainee'), $d(7, null, 'Operations Manager'), $d(8, 7, 'Planner')]);
        $this->assertSame([1, 2, 3, 7], $t['top']);                     // every Director, then a non-Director top-level manager
        $this->assertSame([2 => [4], 4 => [5], 7 => [8]], $t['children']); // Directors never sit under another Director
        $this->assertSame([6], $t['loose']);
        $this->assertTrue(OrgChart::isDirector('Finance Director'));
        $this->assertFalse(OrgChart::isDirector('Directorate Assistant'));
        $this->assertFalse(OrgChart::isDirector(null));
    }

    public function testEmptyOrganisation(): void
    {
        $this->assertSame(['roots' => [], 'children' => [], 'cycle' => [], 'top' => [], 'loose' => []], OrgChart::build([]));
    }
}
