<?php
use App\Core\View;
use App\Services\ReportAccess;
use App\Services\ReportPeriod;

$node = function (int $id, int $depth) use (&$node, $tree, $byId, $status, $me): string {
    $p = $byId[$id];
    $kids = $tree['children'][$id] ?? [];
    $s = $status[$id] ?? null;
    $badge = $s ? ($s['id'] ? '<a href="' . e(url('/reporting/reports/' . $s['id'])) . '" class="text-decoration-none">' . report_status_badge($s['status']) . '</a>' : report_status_badge($s['status'])) : '';
    $crit = $s && $s['critical'] ? ' <i class="bi bi-exclamation-octagon text-danger" title="Critical issue"></i>' : '';
    $role = ReportAccess::roleOf($id);
    $card = '<div class="rp-node' . ($id === $me ? ' me' : '') . '">' . ($kids ? '<span class="tog"><i class="bi bi-chevron-right"></i></span>' : '<span class="tog leaf"></span>')
        . employee_avatar($p, 36) . '<span class="who"><b>' . e(employee_name($p)) . '</b><small>' . e($p['designation'] ?? 'No job title')
        . ($role ? '' : ' · <span class="text-warning">not an approved title</span>') . ' · ' . e($p['department'] ?? '—') . '</small></span>'
        . ($kids ? '<span class="badge rounded-pill text-bg-light">' . count($kids) . ' report' . (count($kids) === 1 ? '' : 's') . '</span>' : '') . $badge . $crit . '</div>';
    if (!$kids) {
        return '<li>' . $card . '</li>';
    }
    $html = '<li><details' . ($depth < 2 ? ' open' : '') . '><summary>' . $card . '</summary><ul>';
    foreach ($kids as $k) {
        $html .= $node((int) $k, $depth + 1);
    }
    return $html . '</ul></details></li>';
};
?>
<div class="rp-bar">
  <?= View::partial('reporting_tabs', ['path' => '/reporting/hierarchy', 'period' => $period, 'query' => ['date' => $date]]) ?>
  <form method="get" action="<?= e(url('/reporting/hierarchy')) ?>">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <div><label class="form-label" for="date">Date in period</label><input class="form-control form-control-sm" type="date" id="date" name="date" value="<?= e($date) ?>" max="<?= e(uk_today()) ?>" data-autosubmit></div>
    <?php if ($isAdmin): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/reporting/lines')) ?>"><i class="bi bi-pencil"></i> Edit reporting lines</a><?php endif; ?>
  </form>
</div>

<p class="text-muted-2 small">Reports go from each person to their own line manager, then up the chain to the Directors. The status shows each person's <?= e(strtolower(\App\Services\ReportCatalog::PERIODS[$period])) ?> report for <?= e(ReportPeriod::label($period, $start)) ?> (only for the people whose reports you can see).</p>

<?php if ($unassigned): ?>
  <div class="alert alert-warning"><b><i class="bi bi-exclamation-triangle"></i> No line manager:</b> <?= e(implode(', ', array_map(fn ($p) => employee_name($p), $unassigned))) ?>. Their reports have nowhere to go<?= $isAdmin ? ' until you <a href="' . e(url('/reporting/lines')) . '">set their line manager</a>' : '' ?>.</div>
<?php endif; ?>
<?php if ($unapproved): ?>
  <div class="alert alert-info small"><i class="bi bi-info-circle"></i> Not on an approved job title (no report form): <?= e(implode(', ', array_map(fn ($p) => employee_name($p) . ' (' . ($p['designation'] ?? 'none') . ')', $unapproved))) ?>.</div>
<?php endif; ?>

<div class="card"><div class="card-body">
  <ul class="rp-tree">
    <?php foreach ($tree['top'] as $id): echo $node((int) $id, 0); endforeach; ?>
    <?php foreach (array_merge($tree['loose'], $tree['cycle']) as $id): echo $node((int) $id, 0); endforeach; ?>
  </ul>
</div></div>
