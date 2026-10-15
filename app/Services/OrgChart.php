<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Builds the organisation tree from each employee's reporting manager (employees.line_manager_id).
 * Pure tree logic lives in build() so it can be unit-tested without a database.
 *
 * Structure: the company is the root; Level 1 is every Director (designation contains "Director"),
 * who never reports to anyone; everyone else sits under their reporting manager.
 */
final class OrgChart
{
    /** A Director is anyone whose designation contains the word "Director" (e.g. Director, Managing Director). */
    public static function isDirector(?string $designation): bool
    {
        return (bool) preg_match('/\bdirector\b/i', (string) $designation);
    }

    /**
     * @param array<int, array{id:int|string, line_manager_id:int|string|null, designation?:?string}> $people
     * @return array{roots:int[], children:array<int,int[]>, cycle:int[], top:int[], loose:int[]}
     *   roots    = people with no manager in the chart (Directors, unassigned, or manager not active)
     *   children = manager id => direct report ids, in input order
     *   cycle    = people caught in a circular reporting line (A reports to B, B reports to A)
     *   top      = Level 1, directly under the company: every Director, then any other top-level person who manages people
     *   loose    = other roots with no one reporting to them (not yet placed in the structure)
     */
    public static function build(array $people): array
    {
        $ids = [];
        foreach ($people as $p) {
            $ids[(int) $p['id']] = true;
        }
        $children = [];
        $roots = [];
        foreach ($people as $p) {
            $id = (int) $p['id'];
            $m = $p['line_manager_id'] === null || $p['line_manager_id'] === '' ? null : (int) $p['line_manager_id'];
            // Directors sit directly under the company, even if a reporting manager was set by mistake.
            if ($m !== null && $m !== $id && isset($ids[$m]) && !self::isDirector($p['designation'] ?? null)) {
                $children[$m][] = $id;
            } else {
                $roots[] = $id;
            }
        }
        // Anyone not reachable from a root sits in a reporting loop.
        $seen = [];
        $stack = $roots;
        while ($stack) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $c) {
                $stack[] = $c;
            }
        }
        $cycle = array_values(array_filter(array_keys($ids), fn ($id) => !isset($seen[$id])));
        $designation = array_column($people, 'designation', 'id');
        $directors = array_values(array_filter($roots, fn ($id) => self::isDirector($designation[$id] ?? null)));
        $otherTop = array_values(array_filter($roots, fn ($id) => !self::isDirector($designation[$id] ?? null) && !empty($children[$id])));
        $loose = array_values(array_filter($roots, fn ($id) => !self::isDirector($designation[$id] ?? null) && empty($children[$id])));
        return ['roots' => $roots, 'children' => $children, 'cycle' => $cycle, 'top' => [...$directors, ...$otherTop], 'loose' => $loose];
    }

    /**
     * Ids to show for a search: every match plus the chain of managers above it, so each
     * result is shown in its place in the hierarchy.
     * @param int[] $matches
     * @param array<int,int|null> $managerOf
     * @return array<int,bool>
     */
    public static function withAncestors(array $matches, array $managerOf): array
    {
        $visible = [];
        foreach ($matches as $id) {
            $guard = 0;
            while ($id !== null && !isset($visible[$id]) && $guard++ < 500) {
                $visible[$id] = true;
                $id = $managerOf[$id] ?? null;
            }
        }
        return $visible;
    }

    /** Current employees (not archived, not left) with the fields the chart shows. */
    public static function people(): array
    {
        return DB::all(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.preferred_name, e.photo_key, e.line_manager_id, e.reporting_manager_name,
                    e.status, e.department_id, d.name AS department, j.title AS designation, l.name AS location_name,
                    m.status AS manager_status, m.deleted_at AS manager_deleted_at
               FROM employees e
               LEFT JOIN departments d ON d.id = e.department_id
               LEFT JOIN job_titles j ON j.id = e.job_title_id
               LEFT JOIN locations l ON l.id = e.location_id
               LEFT JOIN employees m ON m.id = e.line_manager_id
              WHERE e.deleted_at IS NULL AND e.status <> 'left'
              ORDER BY e.last_name, e.first_name"
        );
    }
}
