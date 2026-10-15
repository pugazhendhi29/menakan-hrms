<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * "Menakan Transport Ltd – Organizational Structure" as a landscape PDF (TCPDF), built from the current
 * reporting-manager relationships (the same tree as the interactive chart: company, then Directors, then
 * everyone under their reporting manager).
 *
 * Paging: each page shows one manager (or the company) and as many levels below as fit. When a branch is
 * too wide or too deep for the page, it continues on a later page; the card says "continued on page N"
 * and the continuation page says where that person sits. Cards and connector lines are never split.
 * Each card shows only the photo, name, designation and department; nothing else from the record.
 */
final class OrgChartPdf
{
    public const FORMATS = ['A4' => [297.0, 210.0], 'A3' => [420.0, 297.0]];

    // Geometry in mm.
    private const MARGIN = 10.0;
    private const HEADER = 21.0;
    private const FOOTER = 9.0;
    private const CARD_W = 44.0;
    private const CARD_H = 33.0;
    private const GAP_X = 5.0;
    private const GAP_Y = 12.0;
    private const COMPANY = 0;   // the virtual root node

    private \TCPDF $pdf;
    private array $byId = [];
    private array $kids = [];
    private array $deptColour = [];
    private float $pageW;
    private float $pageH;
    private int $cols;
    private int $rows;
    private array $photoCache = [];

    /** @return array{pdf:string, pages:int, people:int, format:string} */
    /** $people: for previews and tests; the export always uses the current employees (null). */
    public static function generate(string $format = 'auto', ?\DateTimeImmutable $at = null, ?array $people = null): array
    {
        return (new self())->build($format, $at ?? uk_now(), $people);
    }

    /**
     * The page plan without drawing anything (for tests and checks): which manager heads each page, which
     * of their reports it shows and how many levels deep. $people defaults to the current employees.
     * @return array{format:string, cols:int, rows:int, pages:array, contPage:array, shownOn:array, tree:array}
     */
    public static function layout(string $format = 'auto', ?array $people = null): array
    {
        $o = new self();
        [$tree, $plan] = $o->prepare($people ?? OrgChart::people(), $format);
        return ['format' => $o->format, 'cols' => $o->cols, 'rows' => $o->rows, 'pages' => $plan, 'contPage' => $o->contPage, 'shownOn' => $o->shownOn, 'tree' => $tree, 'kids' => $o->kids];
    }

    private function build(string $format, \DateTimeImmutable $at, ?array $people): array
    {
        $people ??= OrgChart::people();
        [$tree, $plan] = $this->prepare($people, $format);
        $format = $this->format;
        $extra = array_values(array_merge($tree['loose'], $tree['cycle']));
        $perExtraPage = $this->cols * $this->rows;
        $extraPages = $extra ? (int) ceil(count($extra) / $perExtraPage) : 0;
        $total = max(1, count($plan) + $extraPages);
        $this->startPdf($at);

        $pageNo = 0;
        if (!$people) {
            $this->newPage(++$pageNo, $total, $at, 0);
            $this->pdf->SetFont('dejavusans', '', 12);
            $this->pdf->SetXY(self::MARGIN, self::MARGIN + self::HEADER + 20);
            $this->pdf->Cell($this->pageW - 2 * self::MARGIN, 10, 'There are no current employees to show.', 0, 0, 'C');
        }
        foreach ($plan as $page) {
            $this->newPage(++$pageNo, $total, $at, count($people));
            $this->drawTreePage($page, $plan);
        }
        for ($p = 0; $p < $extraPages; $p++) {
            $this->newPage(++$pageNo, $total, $at, count($people));
            $this->drawGridPage(array_slice($extra, $p * $perExtraPage, $perExtraPage), $tree['loose'], $p + 1, $extraPages);
        }
        return ['pdf' => $this->pdf->Output('', 'S'), 'pages' => $pageNo, 'people' => count($people), 'format' => $format];
    }

    /** Loads the people into the tree and plans the pages. @return array{0:array,1:array} [tree, plan] */
    private function prepare(array $people, string $format): array
    {
        foreach ($people as $p) {
            $this->byId[(int) $p['id']] = $p;
        }
        $tree = OrgChart::build($people);

        // Children in a stable, readable order: by department, then name. Level 1 = Directors first (as on screen).
        $sortKey = fn (int $id) => mb_strtolower(($this->byId[$id]['department'] ?? '~') . '|' . $this->byId[$id]['first_name'] . ' ' . $this->byId[$id]['last_name']);
        foreach ($tree['children'] as $m => $list) {
            usort($list, fn ($a, $b) => strcmp($sortKey($a), $sortKey($b)));
            $this->kids[(int) $m] = $list;
        }
        $top = $tree['top'];
        usort($top, function ($a, $b) {
            $da = OrgChart::isDirector($this->byId[$a]['designation']);
            $db = OrgChart::isDirector($this->byId[$b]['designation']);
            return $da !== $db ? ($da ? -1 : 1) : strnatcmp($this->byId[$a]['employee_code'], $this->byId[$b]['employee_code']);
        });
        $this->kids[self::COMPANY] = $top;
        $palette = ['#2747d6', '#d9480f', '#2b8a3e', '#ae3ec9', '#0c8599', '#e67700', '#c2255c', '#5c7cfa', '#087f5b', '#862e9c'];
        foreach (DB::all('SELECT id, name FROM departments ORDER BY name') as $i => $d) {
            $this->deptColour[(int) $d['id']] = $palette[$i % count($palette)];
        }

        // 'auto': A4 when the whole structure fits on one A4 page, otherwise A3 (fewer, fuller pages).
        $auto = !isset(self::FORMATS[$format]);
        $this->useFormat($auto ? 'A4' : $format);
        $plan = $people ? $this->plan() : [];
        if ($auto && (count($plan) > 1 || ($tree['loose'] || $tree['cycle']))) {
            $this->useFormat('A3');
            $plan = $this->plan();
        }
        return [$tree, $plan];
    }

    private function startPdf(\DateTimeImmutable $at): void
    {
        // TCPDF adds a hidden "Powered by TCPDF" link to the last page unless told not to.
        $this->pdf = new class('L', 'mm', [$this->pageW, $this->pageH], true, 'UTF-8', false) extends \TCPDF {
            public function __construct(...$args)
            {
                parent::__construct(...$args);
                $this->tcpdflink = false;
            }
        };
        $this->pdf->SetCreator('Menakan Transport HR Portal');
        $this->pdf->SetAuthor('Menakan Transport Ltd');
        $this->pdf->SetTitle('Menakan Transport Ltd – Organizational Structure');
        $this->pdf->SetSubject('Organizational structure generated ' . $at->format('d/m/Y H:i'));
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setFontSubsetting(true);
    }

    private string $format = 'A4';

    private function useFormat(string $format): void
    {
        $this->format = $format;
        [$this->pageW, $this->pageH] = self::FORMATS[$format];
        $this->cols = max(1, (int) floor(($this->pageW - 2 * self::MARGIN + self::GAP_X) / (self::CARD_W + self::GAP_X)));
        $this->rows = max(2, (int) floor(($this->pageH - 2 * self::MARGIN - self::HEADER - self::FOOTER + self::GAP_Y) / (self::CARD_H + self::GAP_Y)));
    }

    /* ------------------------------------------------------------------ planning */

    /** Leaf width of a subtree shown $depth levels deep. */
    private function width(int $id, int $depth): int
    {
        if ($depth <= 0 || empty($this->kids[$id])) {
            return 1;
        }
        $w = 0;
        foreach ($this->kids[$id] as $k) {
            $w += $this->width($k, $depth - 1);
        }
        return $w;
    }

    /**
     * Pages in reading order. Each: root, the root's children shown (all or a chunk), levels shown below the root,
     * part/parts when a manager's direct reports are split across pages.
     */
    private function plan(): array
    {
        $pages = [];
        $queue = [self::COMPANY];
        $this->contPage = [];
        while ($queue) {
            $root = array_shift($queue);
            $kids = $this->kids[$root] ?? [];
            if (!$kids && $root !== self::COMPANY) {
                continue;
            }
            // A team too wide for one page is split into groups of whole direct reports (with their own teams).
            $chunks = $this->pack($kids);
            foreach ($chunks as $n => $chunk) {
                $depth = 1;   // levels below the root's children
                for ($d = $this->rows - 2; $d >= 1; $d--) {
                    $w = 0;
                    foreach ($chunk as $k) {
                        $w += $this->width($k, $d);
                    }
                    if ($w <= $this->cols) {
                        $depth = $d + 1;
                        break;
                    }
                }
                $index = count($pages);
                $pages[] = ['root' => $root, 'kids' => $chunk, 'depth' => $depth, 'part' => $n + 1, 'parts' => count($chunks)];
                if ($n === 0) {
                    $this->contPage[$root] ??= $index;
                }
                // People at the deepest level shown who have reports continue on their own page.
                foreach ($this->frontier($chunk, $depth - 1) as $id) {
                    $queue[] = $id;
                }
            }
        }
        // Where each person appears as a card (for "continued from" notes).
        $this->shownOn = [];
        foreach ($pages as $i => $p) {
            $walk = function (array $ids, int $d) use (&$walk, $i) {
                foreach ($ids as $id) {
                    $this->shownOn[$id] ??= $i;
                    if ($d > 0) {
                        $walk($this->kids[$id] ?? [], $d - 1);
                    }
                }
            };
            $walk($p['kids'], $p['depth'] - 1);
        }
        return $pages;
    }

    /**
     * Splits a manager's direct reports into page-sized groups. For each possible depth it packs the reports
     * (each with that many levels of their own team) greedily into pages, and keeps the depth that needs the
     * fewest pages overall (these pages plus one continuation page per team still left to draw).
     * @return array<int, int[]>
     */
    private function pack(array $kids): array
    {
        if (count($kids) <= $this->cols) {
            return [$kids];
        }
        $best = null;
        for ($d = 0; $d <= $this->rows - 2; $d++) {
            $widths = array_map(fn ($k) => $this->width($k, $d), $kids);
            if (max($widths) > $this->cols) {
                break;   // one report's team no longer fits on a page at this depth
            }
            $chunks = [];
            $cur = [];
            $used = 0;
            foreach ($kids as $i => $k) {
                if ($cur && $used + $widths[$i] > $this->cols) {
                    $chunks[] = $cur;
                    $cur = [];
                    $used = 0;
                }
                $cur[] = $k;
                $used += $widths[$i];
            }
            $chunks[] = $cur;
            $cost = count($chunks) + count($this->frontier($kids, $d));
            if ($best === null || $cost <= $best[0]) {
                $best = [$cost, $chunks];
            }
        }
        return $best[1];
    }

    private array $contPage = [];
    private array $shownOn = [];

    /** Ids shown at the last level that still have reports (they continue on another page). */
    private function frontier(array $ids, int $depth): array
    {
        $out = [];
        foreach ($ids as $id) {
            if ($depth === 0) {
                if (!empty($this->kids[$id])) {
                    $out[] = $id;
                }
            } else {
                $out = array_merge($out, $this->frontier($this->kids[$id] ?? [], $depth - 1));
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ drawing */

    private function newPage(int $no, int $total, \DateTimeImmutable $at, int $people): void
    {
        $pdf = $this->pdf;
        $pdf->AddPage('L', [$this->pageW, $this->pageH]);
        $logo = BASE_PATH . '/public/assets/images/logo-mark.png';
        $x = self::MARGIN;
        if (is_file($logo)) {
            $pdf->Image($logo, $x, self::MARGIN - 1, 13, 13, 'PNG');
            $x += 16;
        }
        $pdf->SetTextColor(15, 31, 51);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetXY($x, self::MARGIN - 1);
        $pdf->Cell(0, 7, 'Menakan Transport Ltd – Organizational Structure', 0, 1);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(91, 103, 133);
        $pdf->SetX($x);
        $pdf->Cell(0, 5, sprintf('Generated %s (UK time) from the reporting managers in the HR system · %d %s', $at->format('d/m/Y \a\t H:i'), $people, $people === 1 ? 'person' : 'people'), 0, 1);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY($this->pageW - self::MARGIN - 40, self::MARGIN - 1);
        $pdf->Cell(40, 6, "Page $no of $total", 0, 0, 'R');
        $pdf->SetDrawColor(227, 38, 43);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(self::MARGIN, self::MARGIN + 14, $this->pageW - self::MARGIN, self::MARGIN + 14);
        // Footer
        $pdf->SetFont('dejavusans', '', 6.5);
        $pdf->SetTextColor(120, 130, 150);
        $pdf->SetXY(self::MARGIN, $this->pageH - self::MARGIN - 4);
        $pdf->Cell($this->pageW - 2 * self::MARGIN, 4, 'Confidential – for internal use. Shows each person\'s name, photo, designation and department only.   www.menakantransport.com', 0, 0, 'C');
    }

    /** One tree page: the root at the top, its (chunk of) reports below, as deep as planned. */
    private function drawTreePage(array $page, array $plan): void
    {
        $slot = 0;
        $pos = [];   // id => [x centre, row]
        $place = function (int $id, int $row, int $depthLeft) use (&$place, &$slot, &$pos) {
            $kids = $depthLeft > 0 ? ($this->kids[$id] ?? []) : [];
            if ($kids) {
                $xs = [];
                foreach ($kids as $k) {
                    $xs[] = $place($k, $row + 1, $depthLeft - 1);
                }
                $x = (min($xs) + max($xs)) / 2;
            } else {
                $x = $slot++;
            }
            $pos[$id] = [$x, $row];
            return $x;
        };
        $xs = [];
        foreach ($page['kids'] as $k) {
            $xs[] = $place($k, 1, $page['depth'] - 1);
        }
        $rootX = $xs ? (min($xs) + max($xs)) / 2 : 0;
        $slots = max(1, $slot);
        $slotW = self::CARD_W + self::GAP_X;
        $left = self::MARGIN + ($this->pageW - 2 * self::MARGIN - ($slots * $slotW - self::GAP_X)) / 2;
        $top = self::MARGIN + self::HEADER;
        $cx = fn (float $s) => $left + $s * $slotW + self::CARD_W / 2;
        $cy = fn (int $row) => $top + $row * (self::CARD_H + self::GAP_Y);

        // Connector lines first, so cards sit on top of them.
        $this->pdf->SetDrawColor(150, 160, 180);
        $this->pdf->SetLineWidth(0.35);
        $connect = function (float $px, float $pBottom, array $childIds) use ($pos, $cx, $cy) {
            if (!$childIds) {
                return;
            }
            $mid = $pBottom + self::GAP_Y / 2;
            $this->pdf->Line($px, $pBottom, $px, $mid);
            $xs = array_map(fn ($c) => $cx($pos[$c][0]), $childIds);
            $this->pdf->Line(min(array_merge($xs, [$px])), $mid, max(array_merge($xs, [$px])), $mid);
            foreach ($childIds as $c) {
                $this->pdf->Line($cx($pos[$c][0]), $mid, $cx($pos[$c][0]), $cy($pos[$c][1]));
            }
        };
        $connect($cx($rootX), $cy(0) + self::CARD_H, $page['kids']);
        foreach ($pos as $id => [$x, $row]) {
            $shown = array_values(array_filter($this->kids[$id] ?? [], fn ($k) => isset($pos[$k])));
            $connect($cx($x), $cy($row) + self::CARD_H, $shown);
        }

        // Root card: the company, or the manager this page continues.
        if ($page['root'] === self::COMPANY) {
            $this->companyCard($cx($rootX), $cy(0));
            $note = $page['parts'] > 1 ? sprintf('Directors and top level, part %d of %d', $page['part'], $page['parts']) : null;
        } else {
            $this->personCard($page['root'], $cx($rootX) - self::CARD_W / 2, $cy(0));
            $from = $this->shownOn[$page['root']] ?? null;
            $manager = $this->byId[$page['root']]['line_manager_id'] ?? null;
            $note = 'Team of ' . $this->name($page['root']) . ($page['parts'] > 1 ? sprintf(' (part %d of %d)', $page['part'], $page['parts']) : '')
                . ($from !== null ? ' · continued from page ' . ($from + 1) : '')
                . ($manager && isset($this->byId[(int) $manager]) && !OrgChart::isDirector($this->byId[$page['root']]['designation']) ? ' · reports to ' . $this->name((int) $manager) : '');
        }
        if ($note) {
            $this->pdf->SetFont('dejavusans', 'I', 7.5);
            $this->pdf->SetTextColor(91, 103, 133);
            $this->pdf->SetXY(self::MARGIN, $top - 5.5);
            $this->pdf->Cell($this->pageW - 2 * self::MARGIN, 4, $note, 0, 0, 'C');
        }
        foreach ($pos as $id => [$x, $row]) {
            $continues = $row === $page['depth'] && !empty($this->kids[$id]) ? ($this->contPage[$id] ?? null) : null;
            $this->personCard($id, $cx($x) - self::CARD_W / 2, $cy($row), $continues === null ? null : [count($this->kids[$id]), $continues + 1]);
        }
    }

    /** People not placed in the tree (no reporting manager, no reports) or caught in a reporting loop. */
    private function drawGridPage(array $ids, array $loose, int $part, int $parts): void
    {
        $this->pdf->SetFont('dejavusans', 'B', 10);
        $this->pdf->SetTextColor(15, 31, 51);
        $this->pdf->SetXY(self::MARGIN, self::MARGIN + 16);
        $this->pdf->Cell(0, 5, 'Not yet placed in the structure' . ($parts > 1 ? " ($part of $parts)" : ''), 0, 1);
        $this->pdf->SetFont('dejavusans', '', 7.5);
        $this->pdf->SetTextColor(91, 103, 133);
        $this->pdf->Cell(0, 4, 'Not a Director, no reporting manager in the system and no direct reports' . (count($ids) > count(array_intersect($ids, $loose)) ? ', or caught in a circular reporting line' : '') . '.', 0, 1);
        $top = self::MARGIN + self::HEADER + 6;
        $rowH = self::CARD_H + 5;
        foreach (array_values($ids) as $i => $id) {
            $c = $i % $this->cols;
            $r = intdiv($i, $this->cols);
            if ($top + $r * $rowH + self::CARD_H > $this->pageH - self::MARGIN - self::FOOTER) {
                break;   // pages are sized so this never happens; guard against overflow anyway
            }
            $this->personCard($id, self::MARGIN + $c * (self::CARD_W + self::GAP_X), $top + $r * $rowH);
        }
    }

    private function companyCard(float $centreX, float $y): void
    {
        $w = 78.0;
        $h = self::CARD_H - 6;
        $x = $centreX - $w / 2;
        $y += 3;
        $pdf = $this->pdf;
        $pdf->SetFillColor(15, 31, 51);
        $pdf->RoundedRect($x, $y, $w, $h, 3, '1111', 'F');
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($x + 4, $y + ($h - 17) / 2, 17, 17, 2.5, '1111', 'F');
        $logo = BASE_PATH . '/public/assets/images/logo-mark.png';
        if (is_file($logo)) {
            $pdf->Image($logo, $x + 5, $y + ($h - 15) / 2, 15, 15, 'PNG');
        }
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetXY($x + 24, $y + $h / 2 - 6);
        $pdf->Cell($w - 26, 6, (string) setting('company_name', 'Menakan Transport Ltd'), 0, 2);
        $directors = count(array_filter($this->kids[self::COMPANY], fn ($id) => OrgChart::isDirector($this->byId[$id]['designation'])));
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->Cell($w - 26, 5, sprintf('%d director%s · %d people', $directors, $directors === 1 ? '' : 's', count($this->byId)), 0, 0);
    }

    /** @param array{0:int,1:int}|null $continues [direct reports, page] when the team is drawn on another page */
    private function personCard(int $id, float $x, float $y, ?array $continues = null): void
    {
        $p = $this->byId[$id];
        $pdf = $this->pdf;
        $director = OrgChart::isDirector($p['designation']);
        [$r, $g, $b] = $this->rgb($this->deptColour[(int) ($p['department_id'] ?? 0)] ?? '#8a94a6');

        $pdf->SetLineWidth($director ? 0.5 : 0.25);
        $director ? $pdf->SetDrawColor(15, 31, 51) : $pdf->SetDrawColor(205, 212, 226);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($x, $y, self::CARD_W, self::CARD_H, 2, '1111', 'DF');
        $pdf->SetFillColor($r, $g, $b);
        $pdf->RoundedRect($x, $y, self::CARD_W, 2.2, 1.2, '1100', 'F');   // department colour strip

        // Photo (or initials) in a circle.
        $d = 12.0;
        $px = $x + (self::CARD_W - $d) / 2;
        $py = $y + 3.6;
        $photo = $this->photo($p);
        if ($photo !== null) {
            $pdf->StartTransform();
            $pdf->Circle($px + $d / 2, $py + $d / 2, $d / 2, 0, 360, 'CNZ');
            $pdf->Image('@' . $photo, $px, $py, $d, $d, 'JPG');
            $pdf->StopTransform();
        } else {
            $pdf->SetFillColor(15, 31, 51);
            $pdf->Circle($px + $d / 2, $py + $d / 2, $d / 2, 0, 360, 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetXY($px, $py + 3.1);
            $pdf->Cell($d, 6, mb_strtoupper(mb_substr($p['first_name'], 0, 1) . mb_substr($p['last_name'], 0, 1)), 0, 0, 'C');
        }

        // Name (up to 2 lines, shrinks to fit), designation, department.
        $pdf->SetTextColor(15, 31, 51);
        $pdf->SetFont('dejavusans', 'B', 7.6);
        $pdf->MultiCell(self::CARD_W - 3, 6.4, $this->name($id), 0, 'C', false, 1, $x + 1.5, $py + $d + 0.8, true, 0, false, true, 6.4, 'M', true);
        $pdf->SetTextColor(51, 64, 95);
        $pdf->SetFont('dejavusans', $director ? 'B' : '', 6.3);
        $pdf->MultiCell(self::CARD_W - 3, 5.2, (string) ($p['designation'] ?: 'No designation'), 0, 'C', false, 1, $x + 1.5, $py + $d + 7.1, true, 0, false, true, 5.2, 'T', true);
        $pdf->SetTextColor(120, 130, 150);
        $pdf->SetFont('dejavusans', '', 5.4);
        $pdf->MultiCell(self::CARD_W - 3, 2.8, (string) ($p['department'] ?: 'No department'), 0, 'C', false, 1, $x + 1.5, $y + self::CARD_H - 3.6, true, 0, false, true, 2.8, 'M', true);

        if ($continues) {
            // A tab under the card pointing to the page where this person's team is drawn.
            [$count, $page] = $continues;
            $label = sprintf('%d report%s · see page %d', $count, $count === 1 ? '' : 's', $page);
            $pdf->SetFont('dejavusans', 'B', 5.6);
            $w = $pdf->GetStringWidth($label) + 4;
            $pdf->SetFillColor(255, 244, 214);
            $pdf->SetDrawColor(230, 170, 30);
            $pdf->SetLineWidth(0.25);
            $pdf->RoundedRect($x + (self::CARD_W - $w) / 2, $y + self::CARD_H + 1, $w, 3.8, 1.5, '1111', 'DF');
            $pdf->SetTextColor(110, 70, 0);
            $pdf->SetXY($x + (self::CARD_W - $w) / 2, $y + self::CARD_H + 1);
            $pdf->Cell($w, 3.8, $label, 0, 0, 'C');
        }
    }

    private function name(int $id): string
    {
        $p = $this->byId[$id];
        return trim(($p['preferred_name'] ?: $p['first_name']) . ' ' . $p['last_name']);
    }

    /** Square, centre-cropped 240px JPEG of the employee photo (null if there is none or it cannot be read). */
    private function photo(array $p): ?string
    {
        $id = (int) $p['id'];
        if (array_key_exists($id, $this->photoCache)) {
            return $this->photoCache[$id];
        }
        $out = null;
        $key = (string) ($p['photo_key'] ?? '');
        if ($key !== '' && FileStore::exists($key)) {
            $src = @imagecreatefromstring((string) file_get_contents(FileStore::path($key)));
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                $s = min($w, $h);
                $dst = imagecreatetruecolor(240, 240);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, intdiv($w - $s, 2), intdiv($h - $s, 2), 240, 240, $s, $s);
                ob_start();
                imagejpeg($dst, null, 85);
                $out = (string) ob_get_clean();
            }
        }
        return $this->photoCache[$id] = $out;
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
