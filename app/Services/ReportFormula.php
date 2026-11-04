<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Evaluates a calculated template field, e.g. "success(sum(jobs_completed),sum(failed_deliveries))".
 *
 * Grammar:  expr := term (("+" | "-") term)*      term := name "(" args ")"
 * Aggregates take one field key: sum avg last (daily data) and wsum wavg wlast (weekly reports).
 * Combinators take two expressions: pct(a,b) = a/b*100, ratio(a,b) = a/b, success(ok,failed) = ok/(ok+failed)*100,
 * accuracy(total,errors) = (1 - errors/total)*100. A division by zero, or no data at all, gives null.
 */
final class ReportFormula
{
    public const AGGREGATES = ['sum', 'avg', 'last', 'wsum', 'wavg', 'wlast'];
    private const COMBINATORS = ['pct', 'ratio', 'success', 'accuracy'];

    private array $tokens = [];
    private int $pos = 0;

    /** @param callable(string $aggregate, string $key): ?float $resolve */
    private function __construct(string $formula, private $resolve)
    {
        preg_match_all('/[a-z_][a-z0-9_]*|[(),+\-]/', $formula, $m);
        if (preg_replace('/\s+/', '', $formula) !== implode('', $m[0])) {
            throw new \InvalidArgumentException("Invalid formula: $formula");
        }
        $this->tokens = $m[0];
    }

    /** @param callable(string $aggregate, string $key): ?float $resolve */
    public static function evaluate(string $formula, callable $resolve): ?float
    {
        $p = new self($formula, $resolve);
        $v = $p->expr();
        if ($p->pos !== count($p->tokens)) {
            throw new \InvalidArgumentException("Invalid formula: $formula");
        }
        return $v === null ? null : round($v, 4);
    }

    /** Field keys a formula reads, grouped by source: ['daily' => [...], 'weekly' => [...]]. */
    public static function sources(string $formula): array
    {
        $out = ['daily' => [], 'weekly' => []];
        preg_match_all('/\b(w?)(?:sum|avg|last)\(([a-z0-9_]+)\)/', $formula, $m, PREG_SET_ORDER);
        foreach ($m as [, $w, $key]) {
            $out[$w ? 'weekly' : 'daily'][$key] = true;
        }
        return array_map('array_keys', $out);
    }

    private function expr(): ?float
    {
        $v = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->next();
            $r = $this->term();
            if ($v === null && $r === null) {
                continue;
            }
            $v = $op === '+' ? (float) $v + (float) $r : (float) $v - (float) $r;
        }
        return $v;
    }

    private function term(): ?float
    {
        $name = $this->next();
        $this->expect('(');
        if (in_array($name, self::AGGREGATES, true)) {
            $key = $this->next();
            $this->expect(')');
            return ($this->resolve)($name, $key);
        }
        if (!in_array($name, self::COMBINATORS, true)) {
            throw new \InvalidArgumentException("Unknown function $name");
        }
        $a = $this->expr();
        $this->expect(',');
        $b = $this->expr();
        $this->expect(')');
        if ($a === null || $b === null) {
            return null;
        }
        return match ($name) {
            'pct' => $b == 0.0 ? null : $a / $b * 100,
            'ratio' => $b == 0.0 ? null : $a / $b,
            'success' => ($a + $b) == 0.0 ? null : $a / ($a + $b) * 100,
            'accuracy' => $a == 0.0 ? null : max(0.0, (1 - $b / $a) * 100),
        };
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function next(): string
    {
        return $this->tokens[$this->pos++] ?? throw new \InvalidArgumentException('Unexpected end of formula');
    }

    private function expect(string $t): void
    {
        if ($this->next() !== $t) {
            throw new \InvalidArgumentException("Expected $t in formula");
        }
    }
}
