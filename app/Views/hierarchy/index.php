<?php
/**
 * Organisation chart, drawn as a functional top-down hierarchy: the company ($company) is the root, every
 * Director sits on Level 1 directly beneath it, and everyone else hangs below their reporting manager,
 * joined by connector lines. Where a manager's reports belong
 * to more than one department, they are grouped under a labelled branch per function (department).
 * Each department has its own colour on the cards and in the legend.
 *
 * $tree = OrgChart::build() output; $visible = ids to show when filtering (null = all).
 * Admins get links to the employee detail page and to change the reporting manager; others are view-only.
 */
$countDesc = function (int $id) use (&$countDesc, $tree): int {
    $n = 0;
    foreach ($tree['children'][$id] ?? [] as $c) {
        $n += 1 + $countDesc($c);
    }
    return $n;
};
$isShown = fn (int $id) => $visible === null || isset($visible[$id]);

// One colour per department (function), in department-list order; people with no department are grey.
$palette = ['#2747d6', '#d9480f', '#2b8a3e', '#ae3ec9', '#0c8599', '#e67700', '#c2255c', '#5c7cfa', '#087f5b', '#862e9c'];
$deptColour = [];
foreach (array_values($departments) as $i => $d) {
    $deptColour[(int) $d['id']] = $palette[$i % count($palette)];
}
$colourOf = fn (?int $deptId) => $deptId !== null && isset($deptColour[$deptId]) ? $deptColour[$deptId] : '#8a94a6';
$deptName = array_column($departments, 'name', 'id');

$card = function (array $p, int $reports, bool $compact = false) use ($isAdmin, $myEmployeeId, $matches, $filtering, $colourOf): string {
    $id = (int) $p['id'];
    $href = $isAdmin ? url('/employees/' . $id . '?tab=personal') : ($myEmployeeId === $id ? url('/my/profile') : null);
    $name = trim(($p['preferred_name'] ?: $p['first_name']) . ' ' . $p['last_name']);
    $notes = [];
    $director = App\Services\OrgChart::isDirector($p['designation']);
    if ($director && ($p['line_manager_id'] !== null || $p['reporting_manager_name'])) {
        $notes[] = '<span class="badge text-bg-warning" title="Directors report to no one. Clear the reporting manager on this record.">Director with a reporting manager</span>';
    } elseif ($director) {
        // Directors report to the company; no note needed.
    } elseif ($p['line_manager_id'] !== null && ($p['manager_deleted_at'] !== null || $p['manager_status'] === 'left')) {
        $notes[] = '<span class="badge text-bg-warning">Manager has left</span>';
    } elseif ($p['line_manager_id'] === null && $p['reporting_manager_name']) {
        $notes[] = '<span class="badge text-bg-light border" title="Reporting manager is not an employee in the system">Reports to ' . e($p['reporting_manager_name']) . '</span>';
    } elseif ($p['line_manager_id'] === null) {
        $notes[] = '<span class="badge text-bg-light border"' . ($reports ? ' title="Not a Director, so they should normally have a reporting manager"' : '') . '>No reporting manager</span>';
    }
    if ($p['status'] !== 'active') {
        $notes[] = status_badge($p['status']);
    }
    $cls = 'org-card' . ($director ? ' is-director' : '') . ($reports ? ' is-manager' : '') . ($filtering && isset($matches[$id]) ? ' is-match' : '') . ($filtering && !isset($matches[$id]) ? ' is-context' : '') . ($compact ? ' compact' : '');
    $style = ' style="--fn:' . $colourOf($p['department_id'] !== null ? (int) $p['department_id'] : null) . '"';
    $inner = employee_avatar($p, $compact ? 40 : 56)
        . '<div class="org-text"><div class="org-name">' . e($name) . '</div>'
        . '<div class="org-role">' . e($p['designation'] ?: 'No designation') . '</div>'
        . '<div class="org-meta">' . e(trim(($p['department'] ?: 'No department') . ' · ' . $p['employee_code'])) . '</div>'
        . ($notes ? '<div class="org-notes">' . implode(' ', $notes) . '</div>' : '') . '</div>';
    $html = $href
        ? '<a class="' . $cls . '"' . $style . ' href="' . e($href) . '" title="Open ' . e($name) . '’s details">' . $inner . '</a>'
        : '<div class="' . $cls . '"' . $style . '>' . $inner . '</div>';
    if ($isAdmin) {
        $html .= '<a class="org-edit small" href="' . e(url('/employees/' . $id . '/edit#reporting_manager')) . '" title="Change reporting manager"><i class="bi bi-diagram-3"></i> Change manager</a>';
    }
    return $html;
};

$node = function (int $id, int $level) use (&$node, $byId, $tree, $card, $isShown, $filtering, $countDesc, $colourOf, $deptName): string {
    if (!$isShown($id)) {
        return '';
    }
    $kids = array_values(array_filter($tree['children'][$id] ?? [], $isShown));
    $direct = count($tree['children'][$id] ?? []);
    $collapsed = $kids && !$filtering && $level >= 4 && count($byId) > 40;   // large organisations open three levels deep
    $html = '<li class="' . ($collapsed ? 'collapsed' : '') . '"><div class="org-node">' . $card($byId[$id], $direct);
    if ($kids) {
        $html .= '<button type="button" class="org-toggle" data-org-toggle aria-expanded="' . ($collapsed ? 'false' : 'true') . '" title="Show or hide reports">'
            . '<i class="bi bi-chevron-down"></i><span>' . $direct . '</span><span class="visually-hidden"> direct reports, ' . $countDesc($id) . ' in total</span></button>';
    }
    $html .= '</div>';
    if ($kids) {
        // Functional grouping: reports from more than one department are split into a branch per department.
        $groups = [];
        foreach ($kids as $k) {
            $groups[(int) ($byId[$k]['department_id'] ?? 0)][] = $k;
        }
        $html .= '<ul>';
        if (count($groups) > 1) {
            uksort($groups, fn ($a, $b) => strcasecmp($deptName[$a] ?? '~', $deptName[$b] ?? '~'));
            foreach ($groups as $deptId => $members) {
                $label = $deptId ? ($deptName[$deptId] ?? 'Department') : 'No department';
                $html .= '<li class="org-fn"><div class="org-node"><div class="org-fn-label" style="--fn:' . $colourOf($deptId ?: null) . '">' . e($label)
                    . ' <span>' . count($members) . '</span></div></div><ul>';
                foreach ($members as $k) {
                    $html .= $node($k, $level + 1);
                }
                $html .= '</ul></li>';
            }
        } else {
            foreach ($kids as $k) {
                $html .= $node($k, $level + 1);
            }
        }
        $html .= '</ul>';
    }
    return $html . '</li>';
};

// Level 1, directly under the company: Directors (by Employee ID), then any other top-level person who manages people.
$level1 = $tree['top'];
usort($level1, function ($a, $b) use ($byId) {
    $da = App\Services\OrgChart::isDirector($byId[$a]['designation']);
    $db = App\Services\OrgChart::isDirector($byId[$b]['designation']);
    return $da !== $db ? ($da ? -1 : 1) : strnatcmp($byId[$a]['employee_code'], $byId[$b]['employee_code']);
});
$shownRoots = array_values(array_filter($level1, $isShown));
$loose = array_values(array_filter($tree['loose'], $isShown));
$cycle = array_values(array_filter($tree['cycle'], $isShown));
$usedDepts = [];
foreach ($byId as $p) {
    $usedDepts[(int) ($p['department_id'] ?? 0)] = true;
}
?>
<form class="filters toolbar" method="get" action="<?= e(url('/hierarchy')) ?>" role="search">
  <div class="flex-grow-1" style="min-width:220px"><label class="form-label" for="q">Search by name</label>
    <input class="form-control form-control-sm" id="q" name="q" value="<?= e($request->str('q')) ?>" placeholder="Employee name or ID"></div>
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit><option value="">All departments</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
  <?php if ($filtering): ?><a class="btn btn-sm btn-link" href="<?= e(url('/hierarchy')) ?>">Clear</a><?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-org-all="expand"><i class="bi bi-arrows-expand"></i> Expand all</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-org-all="collapse"><i class="bi bi-arrows-collapse"></i> Collapse all</button>
    <div class="btn-group">
      <a class="btn btn-sm btn-primary" href="<?= e(url('/hierarchy/pdf')) ?>" data-pdf-export data-pdf-status="#pdf-status"><i class="bi bi-file-earmark-pdf"></i> <span>Export Hierarchy to PDF</span></a>
      <button type="button" class="btn btn-sm btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"><span class="visually-hidden">Choose paper size</span></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= e(url('/hierarchy/pdf?format=A4')) ?>" data-pdf-export data-pdf-status="#pdf-status">A4 landscape</a></li>
        <li><a class="dropdown-item" href="<?= e(url('/hierarchy/pdf?format=A3')) ?>" data-pdf-export data-pdf-status="#pdf-status">A3 landscape</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><span class="dropdown-item-text small text-muted-2">Default: A4 if it fits on one page, otherwise A3. Large charts continue across pages.</span></li>
      </ul>
    </div>
  </div>
</form>
<div id="pdf-status" class="pdf-status" role="status" aria-live="polite"></div>

<div class="kpis">
  <div class="kpi"><small>People in the structure</small><b><?= e($stats['people']) ?></b></div>
  <div class="kpi"><small>Directors</small><b><?= e($stats['directors']) ?></b></div>
  <div class="kpi"><small>Managers</small><b><?= e($stats['managers']) ?></b></div>
  <div class="kpi"><small>Reporting levels</small><b><?= e($stats['levels']) ?></b></div>
  <div class="kpi<?= $stats['unassigned'] ? ' warn-k' : '' ?>"><small>Without a manager</small><b><?= e($stats['unassigned']) ?></b><?php if ($stats['external']): ?><span class="badge text-bg-light border"><?= e($stats['external']) ?> report outside the system</span><?php endif; ?></div>
</div>

<?php if ($filtering): ?>
  <p class="mb-0 text-muted-2"><?= count($matches) ?> matching <?= count($matches) === 1 ? 'person' : 'people' ?>, shown in place with their reporting line. Matches are highlighted.</p>
<?php endif; ?>
<?php if (!$isAdmin): ?><p class="mb-0 small text-muted-2">View only. Reporting lines are maintained by HR.</p><?php endif; ?>

<?php if ($stats['people'] === 0): ?>
  <div class="card"><div class="empty">There are no employees yet. <?php if ($isAdmin): ?><a href="<?= e(url('/employees/create')) ?>">Add the first employee</a>.<?php endif; ?></div></div>
<?php endif; ?>

<?php if ($stats['people'] > 0 && (!$filtering || $shownRoots)): ?>
<div class="card org-chart">
  <div class="card-header d-flex flex-wrap gap-3 align-items-center justify-content-start">
    <span class="me-2">Functional hierarchy, top down</span>
    <?php foreach ($departments as $d): if (!isset($usedDepts[(int) $d['id']])) continue; ?>
      <span class="org-legend" style="--fn:<?= e($colourOf((int) $d['id'])) ?>"><?= e($d['name']) ?></span>
    <?php endforeach; ?>
    <?php if (isset($usedDepts[0])): ?><span class="org-legend" style="--fn:<?= e($colourOf(null)) ?>">No department</span><?php endif; ?>
  </div>
  <div class="card-body org-scroll">
    <ul class="org-tree" data-org-tree>
      <li class="org-root"><div class="org-node"><div class="org-company" role="img" aria-label="<?= e($company) ?>, top of the structure">
        <span class="org-company-mark"><img src="<?= e(asset('images/logo-mark.png')) ?>" alt=""></span>
        <div><div class="org-company-name"><?= e($company) ?></div><div class="org-company-sub"><?= e($stats['directors']) ?> director<?= $stats['directors'] === 1 ? '' : 's' ?> · <?= e($stats['people']) ?> people</div></div>
      </div></div>
        <?php if ($shownRoots): ?>
        <ul>
          <?php foreach ($shownRoots as $r) echo $node($r, 1); ?>
        </ul>
        <?php else: ?>
        <p class="small text-muted-2 mt-3 mb-0 text-center">No Directors yet. Give the top people the designation "Director" and leave their reporting manager empty.</p>
        <?php endif; ?>
      </li>
    </ul>
  </div>
  <div class="card-footer small text-muted-2">Read from the top: <?= e($company) ?>, then its Directors (anyone whose designation includes "Director"; they report to no one), then each person's reports on the row below them. Where a manager's team spans several departments, the team is split into a coloured branch per function. Scroll sideways on a small screen.</div>
</div>
<?php endif; ?>

<?php if ($cycle): ?>
<div class="card border-danger"><div class="card-header text-danger">Circular reporting lines <small class="fw-normal">these people report to each other, so they cannot be placed</small></div>
  <div class="card-body org-grid"><?php foreach ($cycle as $id) echo '<div>' . $card($byId[$id], 0, true) . '</div>'; ?></div></div>
<?php endif; ?>

<?php if ($loose): ?>
<div class="card"><div class="card-header">Not yet placed in the structure <small class="fw-normal text-muted-2">not a Director, no reporting manager and no direct reports<?= $isAdmin ? ' · use "Change manager" to place them' : '' ?></small></div>
  <div class="card-body org-grid"><?php foreach ($loose as $id) echo '<div>' . $card($byId[$id], 0, true) . '</div>'; ?></div></div>
<?php endif; ?>

<?php if ($filtering && !$matches): ?><div class="card"><div class="empty">No one matches this search.</div></div><?php endif; ?>
