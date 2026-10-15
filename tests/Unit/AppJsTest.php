<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards public/assets/js/app.js against structural breakage (e.g. a line of code swallowed into a
 * comment), which would stop every script in the portal. The PHP test suite never runs a browser, so
 * this checks that brackets balance once strings, comments and regular expressions are skipped.
 */
final class AppJsTest extends TestCase
{
    public function testAppJsBracketsBalance(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
        $stack = [];
        $pairs = [')' => '(', ']' => '[', '}' => '{'];
        $line = 1;
        $prev = '';   // previous significant character, to tell a regex literal from division
        $n = strlen($src);
        for ($i = 0; $i < $n; $i++) {
            $c = $src[$i];
            if ($c === "\n") {
                $line++;
                continue;
            }
            if ($c === '/' && ($src[$i + 1] ?? '') === '/') {          // line comment
                while ($i < $n && $src[$i] !== "\n") {
                    $i++;
                }
                $line++;
                continue;
            }
            if ($c === '/' && ($src[$i + 1] ?? '') === '*') {          // block comment
                $end = strpos($src, '*/', $i + 2);
                $this->assertNotFalse($end, "Unclosed comment at line $line");
                $line += substr_count(substr($src, $i, $end - $i), "\n");
                $i = $end + 1;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`' || ($c === '/' && ($prev === '' || str_contains('(,=:[!&|?{};', $prev)))) {
                $quote = $c;                                             // string or regex literal
                for ($i++; $i < $n && $src[$i] !== $quote; $i++) {
                    if ($src[$i] === '\\') {
                        $i++;
                    } elseif ($quote === '/' && $src[$i] === '[') {     // character class may contain "/"
                        while ($i < $n && $src[$i] !== ']') {
                            $i += $src[$i] === '\\' ? 2 : 1;
                        }
                    }
                    $this->assertFalse($quote !== '`' && ($src[$i] ?? '') === "\n", "Unterminated $quote literal at line $line");
                }
                $prev = 'x';
                continue;
            }
            if (in_array($c, ['(', '[', '{'], true)) {
                $stack[] = [$c, $line];
            } elseif (isset($pairs[$c])) {
                $open = array_pop($stack);
                $this->assertNotNull($open, "Unexpected '$c' at line $line");
                $this->assertSame($pairs[$c], $open[0], "'$c' at line $line closes '{$open[0]}' opened at line {$open[1]}");
            }
            if (!ctype_space($c)) {
                $prev = $c;
            }
        }
        $this->assertSame([], $stack, 'Unclosed brackets: ' . json_encode($stack));
    }
}
