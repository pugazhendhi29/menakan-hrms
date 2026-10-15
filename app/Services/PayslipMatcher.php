<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Suggests the employee and pay period for a payslip filename such as A001_2026-09.pdf
 * or A12-2026_09.pdf. Codes are matched using the configured prefix, and short numbers are
 * padded to the configured width (A12 -> A012).
 * The admin always confirms each match before anything is saved.
 */
final class PayslipMatcher
{
    public const MATCHED = 'matched';
    public const AMBIGUOUS = 'ambiguous';
    public const UNKNOWN = 'unknown_employee';
    public const DUPLICATE = 'duplicate_period';
    public const INVALID = 'invalid_name';

    public const LABELS = [
        self::MATCHED => ['Matched', 'success'],
        self::AMBIGUOUS => ['Ambiguous', 'warning'],
        self::UNKNOWN => ['Unknown employee', 'danger'],
        self::DUPLICATE => ['Duplicate period', 'warning'],
        self::INVALID => ['Period not found in filename', 'danger'],
    ];

    /** @return array{code:?string, year:?int, month:?int, tokens:string[]} */
    public static function parse(string $filename, string $prefix = 'A', int $pad = 3): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $code = null;
        $codeRe = '(?<![A-Za-z])' . preg_quote($prefix, '/') . '[\s_-]?(\d{1,6})(?!\d)';
        if (preg_match('/' . $codeRe . '/i', $base, $m)) {
            $code = strtoupper($prefix) . str_pad(ltrim($m[1], '0') === '' ? '0' : ltrim($m[1], '0'), $pad, '0', STR_PAD_LEFT);
        }
        $year = $month = null;
        $rest = (string) preg_replace('/' . $codeRe . '/i', ' ', $base);
        if (preg_match('/(?<!\d)(20\d{2})[\s_.-]?(0[1-9]|1[0-2])(?!\d)/', $rest, $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/(?<!\d)(0[1-9]|1[0-2])[\s_.-](20\d{2})(?!\d)/', $rest, $m)) {
            [$year, $month] = [(int) $m[2], (int) $m[1]];
        }
        $tokens = array_values(array_filter(
            preg_split('/[^a-z]+/i', strtolower(preg_replace('/' . $codeRe . '|20\d{2}|\d+/i', ' ', $base))),
            fn ($t) => strlen($t) >= 3 && !in_array($t, ['payslip', 'pay', 'slip', 'pdf', 'final'], true)
        ));
        return ['code' => $code, 'year' => $year, 'month' => $month, 'tokens' => $tokens];
    }

    /**
     * @param array<int, array{id:int, employee_code:string, first_name:string, last_name:string}> $employees
     * @param callable(int $employeeId, int $year, int $month): bool $hasCurrent whether a current payslip exists
     * @return array{status:string, employee_id:?int, candidates:int[], year:?int, month:?int}
     */
    public static function match(string $filename, array $employees, callable $hasCurrent, string $prefix = 'A', int $pad = 3): array
    {
        $p = self::parse($filename, $prefix, $pad);
        $candidates = [];
        if ($p['code'] !== null) {
            foreach ($employees as $e) {
                if (strcasecmp($e['employee_code'], $p['code']) === 0) {
                    $candidates[] = (int) $e['id'];
                }
            }
        } elseif ($p['tokens']) {
            foreach ($employees as $e) {
                $last = strtolower($e['last_name']);
                $first = strtolower($e['first_name']);
                if (in_array($last, $p['tokens'], true) && (count($p['tokens']) === 1 || in_array($first, $p['tokens'], true) || count(array_filter($employees, fn ($x) => strtolower($x['last_name']) === $last)) === 1)) {
                    $candidates[] = (int) $e['id'];
                }
            }
        }
        $result = ['employee_id' => null, 'candidates' => $candidates, 'year' => $p['year'], 'month' => $p['month']];
        if (count($candidates) > 1) {
            return ['status' => self::AMBIGUOUS] + $result;
        }
        if (count($candidates) === 0) {
            return ['status' => self::UNKNOWN] + $result;
        }
        $result['employee_id'] = $candidates[0];
        if ($p['year'] === null) {
            return ['status' => self::INVALID] + $result;
        }
        if ($hasCurrent($candidates[0], $p['year'], $p['month'])) {
            return ['status' => self::DUPLICATE] + $result;
        }
        return ['status' => self::MATCHED] + $result;
    }
}
