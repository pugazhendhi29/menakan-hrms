<?php
/**
 * Circular organisation chart: the company in the centre, Directors on the first ring, and each person's
 * reports on the next ring out, close to their manager. The space around the circle is shared out by the
 * number of people in each branch. Drawn as one SVG, so it scales to any screen.
 *
 * Uses the hierarchy view's variables: $tree, $byId, $shownRoots, $isShown, $colourOf, $company, $isAdmin,
 * $myEmployeeId, $filtering, $matches.
 */
$kids = fn (int $id) => array_values(array_filter($tree['children'][$id] ?? [], $isShown));

// Leaves under each person, so each branch gets a share of the circle that fits it.
$weight = [];
$depthMax = 1;
$measure = function (int $id, int $depth) use (&$measure, &$weight, &$depthMax, $kids): int {
    $depthMax = max($depthMax, $depth);
    $c = $kids($id);
    $w = 0;
    foreach ($c as $k) {
        $w += $measure($k, $depth + 1);
    }
    return $weight[$id] = max(1, $w);
};
$total = 0;
foreach ($shownRoots as $r) {
    $total += $measure($r, 1);
}
$total = max(1, $total);
// Every Director gets at least half an even share of the circle, so a Director with a small team is not squeezed.
$share = [];
foreach ($shownRoots as $r) {
    $share[$r] = max($weight[$r], $total / count($shownRoots) / 2);
}
$shareTotal = array_sum($share);

// Ring spacing: wide enough for the outer ring to hold everyone side by side.
$gap = max(150, (int) ceil($total * 160 / (2 * M_PI * $depthMax)));
$first = max(225, (int) ceil(count($shownRoots) * 220 / (2 * M_PI)));   // the Directors' ring, clear of the centre
$ring = fn (int $depth) => $first + ($depth - 1) * $gap;
$outer = $ring($depthMax) + 95;
$size = 2 * $outer;
$cx = $cy = $outer;

// Place everyone: angle = middle of their share, radius = level x ring gap.
$pos = [];
$place = function (int $id, int $depth, float $from, float $to) use (&$place, &$pos, $kids, $weight, $ring): void {
    $mid = ($from + $to) / 2;
    $pos[$id] = ['a' => $mid, 'r' => $ring($depth), 'd' => $depth];
    $start = $from;
    foreach ($kids($id) as $k) {
        $span = ($to - $from) * $weight[$k] / $weight[$id];
        $place($k, $depth + 1, $start, $start + $span);
        $start += $span;
    }
};
$start = -M_PI / 2;   // the first Director at the top
foreach ($shownRoots as $r) {
    $span = 2 * M_PI * $share[$r] / $shareTotal;
    $place($r, 1, $start, $start + $span);
    $start += $span;
}
$xy = fn (float $a, float $r) => [$cx + $r * cos($a), $cy + $r * sin($a)];
$f = fn (float $n) => number_format($n, 1, '.', '');

// Curved links: leave the parent outward, arrive at the child from the inside.
$links = '';
$link = function (float $a1, float $r1, float $a2, float $r2, string $colour) use ($xy, $f): string {
    [$x1, $y1] = $xy($a1, $r1);
    [$x2, $y2] = $xy($a2, $r2);
    $rm = ($r1 + $r2) / 2;
    [$c1x, $c1y] = $xy($a1, $rm);
    [$c2x, $c2y] = $xy($a2, $rm);
    return '<path d="M' . $f($x1) . ' ' . $f($y1) . ' C' . $f($c1x) . ' ' . $f($c1y) . ' ' . $f($c2x) . ' ' . $f($c2y) . ' ' . $f($x2) . ' ' . $f($y2) . '" stroke="' . e($colour) . '"/>';
};
foreach ($shownRoots as $r) {
    $links .= $link($pos[$r]['a'], 84, $pos[$r]['a'], $pos[$r]['r'], '#9fb3c8');
}
foreach ($pos as $id => $p) {
    foreach ($kids($id) as $k) {
        $links .= $link($p['a'], $p['r'], $pos[$k]['a'], $pos[$k]['r'], $colourOf($byId[$k]['department_id'] !== null ? (int) $byId[$k]['department_id'] : null));
    }
}

$nodes = '';
foreach ($pos as $id => $p) {
    $e = $byId[$id];
    [$x, $y] = $xy($p['a'], $p['r']);
    $director = App\Services\OrgChart::isDirector($e['designation']);
    $rad = $director ? 62 : 50;
    $colour = $colourOf($e['department_id'] !== null ? (int) $e['department_id'] : null);
    $name = trim($e['first_name'] . ' ' . $e['last_name']);
    $role = (string) ($e['designation'] ?: 'No designation');
    $short = fn (string $s, int $n) => mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
    $label = $short($name, 22);
    $roleLabel = $short($role, 26);
    $w = max(70, (int) (mb_strlen($label) * 7.6 + 22));
    $match = $filtering && isset($matches[$id]);
    $faded = $filtering && !isset($matches[$id]);
    $clip = 'oc' . $id;
    $initials = strtoupper(mb_substr((string) $e['first_name'], 0, 1) . mb_substr((string) $e['last_name'], 0, 1));
    $face = !empty($e['photo_key'])
        ? '<clipPath id="' . $clip . '"><circle cx="' . $f($x) . '" cy="' . $f($y) . '" r="' . ($rad - 4) . '"/></clipPath>'
          . '<image href="' . e(employee_photo_url($e + ['id' => $id], 128)) . '" x="' . $f($x - $rad + 4) . '" y="' . $f($y - $rad + 4) . '" width="' . (2 * $rad - 8) . '" height="' . (2 * $rad - 8) . '" clip-path="url(#' . $clip . ')" preserveAspectRatio="xMidYMid slice"/>'
        : '<text x="' . $f($x) . '" y="' . $f($y + ($director ? 10 : 8)) . '" class="rc-initials" style="font-size:' . ($director ? 30 : 24) . 'px">' . e($initials) . '</text>';
    $g = '<g class="rc-node' . ($match ? ' is-match' : '') . ($faded ? ' is-faded' : '') . '">'
        . ($match ? '<circle cx="' . $f($x) . '" cy="' . $f($y) . '" r="' . ($rad + 7) . '" class="rc-halo"/>' : '')
        . '<circle cx="' . $f($x) . '" cy="' . $f($y) . '" r="' . $rad . '" fill="' . e($colour) . '" class="rc-ring"/>'
        . '<circle cx="' . $f($x) . '" cy="' . $f($y) . '" r="' . ($rad - 4) . '" fill="' . e($colour) . '" class="rc-face"/>'
        . '<circle cx="' . $f($x) . '" cy="' . $f($y) . '" r="' . ($rad - 4) . '" fill="url(#rcShine)"/>'
        . $face
        . '<rect x="' . $f($x - $w / 2) . '" y="' . $f($y + $rad + 6) . '" width="' . $w . '" height="23" rx="11.5" fill="' . ($director ? e($colour) : '#ffffff') . '" stroke="' . e($colour) . '" class="rc-pill"/>'
        . '<text x="' . $f($x) . '" y="' . $f($y + $rad + 22) . '" class="rc-name' . ($director ? ' is-director' : '') . '">' . e($label) . '</text>'
        . '<text x="' . $f($x) . '" y="' . $f($y + $rad + 43) . '" class="rc-role">' . e($roleLabel) . '</text>'
        . '<title>' . e($name . ' · ' . $role . ' · ' . ($e['department'] ?: 'No department')) . '</title>'
        . '</g>';
    $href = $isAdmin ? url('/employees/' . $id . '?tab=personal') : ((int) $myEmployeeId === $id ? url('/my/profile') : null);
    $nodes .= $href ? '<a href="' . e($href) . '" aria-label="' . e($name . ', ' . $role) . '">' . $g . '</a>' : $g;
}
?>
<div class="card-body org-radial-wrap">
  <svg class="org-radial" viewBox="0 0 <?= $size ?> <?= $size ?>" style="max-width:<?= min($size, 1400) ?>px" role="img"
       aria-label="<?= e($company) ?> organisation chart, drawn as circles: Directors on the inner ring, their teams further out">
    <defs>
      <radialGradient id="rcBg" cx="50%" cy="50%" r="50%">
        <stop offset="0%" stop-color="#ffffff"/><stop offset="70%" stop-color="#f1f5fb"/><stop offset="100%" stop-color="#e3eaf5"/>
      </radialGradient>
      <radialGradient id="rcShine" cx="35%" cy="28%" r="75%">
        <stop offset="0%" stop-color="#ffffff" stop-opacity=".45"/><stop offset="60%" stop-color="#ffffff" stop-opacity="0"/><stop offset="100%" stop-color="#000000" stop-opacity=".18"/>
      </radialGradient>
      <radialGradient id="rcCore" cx="50%" cy="40%" r="60%">
        <stop offset="0%" stop-color="#1d4ed8"/><stop offset="100%" stop-color="#0b1a2e"/>
      </radialGradient>
    </defs>
    <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $outer - 20 ?>" fill="url(#rcBg)"/>
    <?php for ($d = 1; $d <= $depthMax; $d++): ?>
      <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $ring($d) ?>" class="rc-orbit"/>
    <?php endfor; ?>
    <g class="rc-links"><?= $links ?></g>
    <g class="rc-core">
      <?php
      // The company name on up to two lines inside the centre circle.
      $words = preg_split('/\s+/', trim($company)) ?: [];
      $line1 = $line2 = '';
      foreach ($words as $wd) {
          if ($line2 === '' && mb_strlen(trim($line1 . ' ' . $wd)) <= 14) { $line1 = trim($line1 . ' ' . $wd); } else { $line2 = trim($line2 . ' ' . $wd); }
      }
      $line2 = mb_strimwidth($line2, 0, 16, '…');
      ?>
      <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="84" fill="#ffffff" opacity=".7"/>
      <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="76" fill="url(#rcCore)"/>
      <circle cx="<?= $cx ?>" cy="<?= $cy - 26 ?>" r="24" fill="#ffffff"/>
      <image href="<?= e(asset('images/logo-mark.png')) ?>" x="<?= $cx - 19 ?>" y="<?= $cy - 45 ?>" width="38" height="38"/>
      <text x="<?= $cx ?>" y="<?= $cy + 16 ?>" class="rc-company"><?= e($line1) ?></text>
      <?php if ($line2 !== ''): ?><text x="<?= $cx ?>" y="<?= $cy + 32 ?>" class="rc-company"><?= e($line2) ?></text><?php endif; ?>
    </g>
    <g class="rc-nodes"><?= $nodes ?></g>
  </svg>
</div>
