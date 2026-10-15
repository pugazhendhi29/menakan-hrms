<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Services\OrgChartPdf;

final class OrgChartPdfTest extends FeatureTestCase
{
    private static function person(int $id, ?int $manager, string $title, string $dept = 'Operations'): array
    {
        return ['id' => $id, 'employee_code' => sprintf('A%03d', $id), 'first_name' => 'P' . $id, 'last_name' => 'Person', 'preferred_name' => null, 'photo_key' => null,
            'line_manager_id' => $manager, 'reporting_manager_name' => null, 'status' => 'active', 'department_id' => null, 'department' => $dept, 'designation' => $title,
            'location_name' => null, 'manager_status' => 'active', 'manager_deleted_at' => null];
    }

    /** A large organisation: 3 Directors; one with 30 direct reports who each have 2 reports; a 12-level chain; one unplaced person. */
    private static function bigOrg(): array
    {
        $p = [self::person(1, null, 'Managing Director'), self::person(2, null, 'Director'), self::person(3, null, 'Finance Director')];
        $id = 10;
        for ($i = 0; $i < 30; $i++) {
            $m = $id++;
            $p[] = self::person($m, 1, 'Team Leader');
            $p[] = self::person($id++, $m, 'Driver');
            $p[] = self::person($id++, $m, 'Driver');
        }
        $prev = 2;
        for ($i = 0; $i < 12; $i++) {
            $p[] = self::person($id, $prev, 'Manager level ' . $i);
            $prev = $id++;
        }
        $p[] = self::person(999, null, 'Trainee');
        return $p;
    }

    public function testLargeHierarchiesSplitAcrossPagesWithoutLosingAnyone(): void
    {
        $people = self::bigOrg();
        foreach (['A4', 'A3'] as $format) {
            $l = OrgChartPdf::layout($format, $people);
            $this->assertGreaterThan(1, count($l['pages']), $format);
            $shown = [];
            foreach ($l['pages'] as $n => $page) {
                // Each page fits: no more reports side by side than columns, no more levels than rows.
                $this->assertLessThanOrEqual($l['cols'], count($page['kids']), "$format page $n width");
                $this->assertLessThanOrEqual($l['rows'], $page['depth'] + 1, "$format page $n depth");
                $leaves = 0;
                $walk = function (array $ids, int $d) use (&$walk, &$leaves, &$shown, $l, $n, $format) {
                    foreach ($ids as $id) {
                        $shown[$id][] = $n;
                        if ($d > 0 && !empty($l['kids'][$id])) {
                            $walk($l['kids'][$id], $d - 1);
                        } else {
                            $leaves++;
                            // A person at the bottom of the page whose team is not drawn here continues on a later page.
                            if (!empty($l['kids'][$id])) {
                                $this->assertArrayHasKey($id, $l['contPage'], "$format: team of $id has a continuation page");
                                $this->assertGreaterThan($n, $l['contPage'][$id]);
                            }
                        }
                    }
                };
                $walk($page['kids'], $page['depth'] - 1);
                $this->assertLessThanOrEqual($l['cols'], $leaves, "$format page $n: cards fit across the page");
            }
            // Everyone in the structure appears as a card exactly once (managers also head their own continuation page).
            foreach ($people as $p) {
                if ($p['id'] === 999) {
                    $this->assertContains(999, $l['tree']['loose']);   // drawn on the "Not yet placed" page
                    continue;
                }
                $this->assertCount(1, $shown[$p['id']] ?? [], "$format: person {$p['id']}");
            }
        }
        $this->assertSame('A3', OrgChartPdf::layout('auto', $people)['format']);
        $this->assertSame('A4', OrgChartPdf::layout('auto', array_slice($people, 0, 3))['format']);   // small chart fits on one A4 page
    }

    public function testPdfDownloadForSignedInUsersOnly(): void
    {
        DB::update('employees', ['line_manager_id' => self::$f['empAdmin']], 'id IN (?, ?)', [self::$f['empA'], self::$f['empB']]);
        $this->actingAs(self::$f['admin']);
        $page = $this->get('/hierarchy')->body;
        $this->assertStringContainsString('Export Hierarchy to PDF', $page);
        $this->assertStringContainsString('data-pdf-export', $page);
        $this->assertStringContainsString('class="org-company"', $page);   // the interactive chart is still there

        $res = $this->get('/hierarchy/pdf', ['format' => 'A4']);
        $this->assertSame(200, $res->status);
        $this->assertSame('application/pdf', $res->headers['Content-Type']);
        $this->assertStringStartsWith('%PDF-', $res->body);
        $this->assertSame('A4', $res->headers['X-Pdf-Format']);
        $this->assertGreaterThanOrEqual(1, (int) $res->headers['X-Pdf-Pages']);
        $this->assertStringContainsString('Menakan-Transport-Organizational-Structure-' . uk_today() . '.pdf', $res->headers['Content-Disposition']);
        $this->assertStringContainsString('Organizational Structure', $res->body);   // document title in the PDF metadata
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'hierarchy.exported'"));

        // Employees can view the chart, so they can export it too; guests cannot.
        $this->actingAs(self::$f['userA']);
        $this->assertSame(200, $this->get('/hierarchy/pdf')->status);
        $_SESSION = [];
        \App\Core\Auth::reset();
        $this->assertSame(302, $this->get('/hierarchy/pdf')->status);
    }
}
