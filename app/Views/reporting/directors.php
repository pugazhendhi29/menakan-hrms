<?php
use App\Core\View;
use App\Services\ReportAccess;
use App\Services\ReportCatalog;
use App\Services\ReportPeriod;

$t = $comp['totals'];
$q = ['date' => $date] + ($dept ? ['department_id' => $dept] : []);
$search = fn (array $extra) => url('/reporting/reports') . '?' . http_build_query(['period' => $period, 'from' => $start, 'to' => $end] + ($dept ? ['department_id' => $dept] : []) + $extra);
$tile = function (string $icon, string $label, int|string $value, string $note, string $tone, string $href): string {
    return '<a class="stat stat-' . e($tone) . '" href="' . e($href) . '"><span class="stat-icon"><i class="bi bi-' . e($icon) . '"></i></span><span class="stat-body"><small>' . e($label)
        . '</small><b>' . e($value) . '</b><span class="stat-note">' . $note . '</span></span></a>';
};
$areaIcons = ['transport_manager' => 'truck', 'logistics_coordinator' => 'diagram-2', 'delivery_service_manager' => 'box-seam', 'fleet_manager' => 'car-front', 'business_support_manager' => 'briefcase',
    'freight_administrator' => 'file-earmark-text', 'distribution_service_manager' => 'boxes', 'route_planning_manager' => 'signpost-split', 'business_development_manager' => 'graph-up-arrow',
    'operations_supervisor' => 'person-workspace', 'financial_accounts_manager' => 'currency-pound'];
$exp = fn (string $scope, string $format, array $extra = []) => url('/reporting/export') . '?' . http_build_query(['scope' => $scope, 'period' => $period, 'date' => $date, 'format' => $format] + $extra);
?>
<div class="rp-bar">
  <?= View::partial('reporting_tabs', ['path' => '/reporting/directors', 'period' => $period, 'query' => $q]) ?>
  <form method="get" action="<?= e(url('/reporting/directors')) ?>">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <div><label class="form-label" for="department_id">Department</label><select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit>
      <option value="">Whole company</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $dept) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="date">Date in period</label><input class="form-control form-control-sm" type="date" id="date" name="date" value="<?= e($date) ?>" max="<?= e(uk_today()) ?>" data-autosubmit></div>
    <div class="dropdown"><button class="btn btn-sm btn-hivis dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download"></i> Export</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header"><?= e(ReportCatalog::PERIODS[$period]) ?> company report</h6></li>
        <li><a class="dropdown-item" href="<?= e($exp('company', 'pdf')) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></li>
        <li><a class="dropdown-item" href="<?= e($exp('company', 'xlsx')) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a></li>
        <?php if ($dept): ?>
          <li><hr class="dropdown-divider"></li><li><h6 class="dropdown-header">Department management report</h6></li>
          <li><a class="dropdown-item" href="<?= e($exp('department', 'pdf', ['department_id' => $dept])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></li>
          <li><a class="dropdown-item" href="<?= e($exp('department', 'xlsx', ['department_id' => $dept])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a></li>
        <?php endif; ?>
      </ul></div>
  </form>
</div>

<section aria-label="Company overview">
  <h2 class="dash-label"><?= e(ReportPeriod::label($period, $start)) ?><?= $period !== 'daily' ? ' · ' . e(uk_date($start)) . ' – ' . e(uk_date($end)) : '' ?></h2>
  <div class="stats mb-3">
    <?= $tile('clipboard2-check', 'Reporting compliance', $t['rate'] === null ? '—' : $t['rate'] . '%', e($t['submitted']) . ' of ' . e($t['expected']) . ' reports submitted', ($t['rate'] ?? 100) >= 90 ? 'ok' : (($t['rate'] ?? 100) >= 70 ? 'warn' : 'alert'), '#compliance') ?>
    <?= $tile('patch-check', 'Approved', $t['approved'], 'reports this period', 'ok', $search(['status' => 'approved'])) ?>
    <?= $tile('hourglass-split', 'Awaiting approval', $awaiting, 'company-wide, all periods', $awaiting ? 'warn' : 'calm', url('/reporting/reports?status=awaiting')) ?>
    <?= $tile('alarm', 'Overdue', $t['overdue'], 'past the deadline, not submitted', $t['overdue'] ? 'alert' : 'calm', $search(['status' => 'overdue'])) ?>
    <?= $tile('arrow-up-circle', 'Escalated', $escalated, 'waiting for a senior decision', $escalated ? 'alert' : 'calm', url('/reporting/reports?status=escalated')) ?>
    <?= $tile('exclamation-octagon', 'Critical issues', count($critical), 'last 30 days', count($critical) ? 'alert' : 'calm', '#critical') ?>
    <?= $tile('list-check', 'Outstanding actions', count($actions), 'open management actions', count($actions) ? 'warn' : 'calm', url('/reporting/actions')) ?>
  </div>
</section>
<div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card h-100"><div class="dash-head"><span class="dash-ico"><i class="bi bi-graph-up"></i></span><h2>Daily reporting · last 14 days</h2></div>
    <div class="card-body" style="height:270px"><canvas data-chart="<?= e(json_encode($chartData['trend'])) ?>" role="img" aria-label="Daily reports expected, submitted and approved per day"></canvas></div></div></div>
  <div class="col-xl-4"><div class="card h-100"><div class="dash-head"><span class="dash-ico"><i class="bi bi-pie-chart"></i></span><h2>Report status · this period</h2></div>
    <div class="card-body" style="height:270px"><?php if ($statusCounts): ?><canvas data-chart="<?= e(json_encode($chartData['status'])) ?>" role="img" aria-label="Reports by status"></canvas><?php else: ?><div class="dash-empty"><i class="bi bi-inbox"></i><span>No reports yet for this period.</span></div><?php endif; ?></div></div></div>
</div>

<h2 class="dash-label">Performance by area · approved <?= e(strtolower(ReportCatalog::PERIODS[$period])) ?> reports</h2>
<div class="rp-areas mb-3">
  <?php foreach ($areas as $role => $a): ?>
    <div class="rp-area">
      <h3><i class="bi bi-<?= e($areaIcons[$role] ?? 'graph-up') ?> text-primary"></i><?= e($a['area']) ?><small><?= (int) $a['approved'] ?> approved<?= $a['pending'] ? ' · ' . (int) $a['pending'] . ' pending' : '' ?></small></h3>
      <?php $k = array_filter($a['kpis'], fn ($x) => $x['value'] !== null); ?>
      <?php if ($k): ?><div class="rp-kpis"><?php foreach (array_slice($k, 0, 6) as $x): ?><div class="rp-kpi"><small><?= e($x['label']) ?></small><b><?= e(report_value($x['display'], $x['value'])) ?></b></div><?php endforeach; ?></div>
      <?php else: ?><div class="empty-note">No approved <?= e($a['title']) ?> reports for this period.</div><?php endif; ?>
      <a class="small mt-auto" href="<?= e($search(['role' => $role])) ?>">Open <?= e($a['title']) ?> reports <i class="bi bi-arrow-right"></i></a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-12">
    <div class="card" id="compliance">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-buildings"></i></span><h2>Department summaries</h2></div>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Department</th><th class="num">People</th><th class="num">Submitted</th><th class="num">Approved</th><th class="num">Awaiting</th><th class="num">Overdue</th><th class="num">Critical</th><th class="num">Compliance</th><th></th></tr></thead>
        <tbody><?php foreach ($deptRows as $d): ?>
          <tr><td><b><?= e($d['name']) ?></b></td><td class="num"><?= (int) $d['people'] ?></td><td class="num"><?= (int) $d['submitted'] ?>/<?= (int) $d['expected'] ?></td><td class="num"><?= (int) $d['approved'] ?></td>
            <td class="num"><?= (int) $d['awaiting'] ?></td><td class="num<?= $d['overdue'] ? ' text-danger fw-bold' : '' ?>"><?= (int) $d['overdue'] ?></td><td class="num<?= $d['critical'] ? ' text-danger fw-bold' : '' ?>"><?= (int) $d['critical'] ?></td>
            <td class="num"><?= $d['rate'] === null ? '—' : (int) $d['rate'] . '%' ?></td>
            <td class="text-end text-nowrap"><?php if ($d['id']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reporting/directors?' . http_build_query(['period' => $period, 'date' => $date, 'department_id' => $d['id']]))) ?>">Drill down</a>
              <a class="btn btn-sm btn-link" href="<?= e($search(['department_id' => $d['id']])) ?>">Reports</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$deptRows): ?><tr><td colspan="9" class="empty">No one is expected to report for this period.</td></tr><?php endif; ?></tbody>
      </table></div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico dash-ico-warn"><i class="bi bi-person-exclamation"></i></span><h2>Employee reporting compliance</h2><span class="dash-count"><?= count($lowest) ?></span></div>
      <?php if ($lowest): ?><ul class="dash-list dash-scroll"><?php foreach ($lowest as $l): ?>
        <li><?= employee_avatar($l['person'], 30) ?><div class="dash-main"><a href="<?= e(url('/reporting/reports?' . http_build_query(['employee_id' => $l['person']['id'], 'period' => $period]))) ?>"><b><?= e(employee_name($l['person'])) ?></b></a><small><?= e($l['person']['job_title']) ?> · <?= (int) $l['c']['submitted'] ?>/<?= (int) $l['c']['expected'] ?> submitted<?= $l['c']['overdue'] ? ' · ' . (int) $l['c']['overdue'] . ' overdue' : '' ?></small></div>
          <span class="badge rounded-pill <?= $l['c']['rate'] < 70 ? 'rs rs-overdue' : 'rs rs-returned' ?>"><?= (int) $l['c']['rate'] ?>%</span></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-check2-circle"></i><span>Everyone expected to report has submitted.</span></div><?php endif; ?>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card h-100" id="critical">
      <div class="dash-head"><span class="dash-ico dash-ico-alert"><i class="bi bi-exclamation-octagon"></i></span><h2>Critical operational issues · 30 days</h2><span class="dash-count"><?= count($critical) ?></span><a class="ms-auto" href="<?= e(url('/reporting/reports?critical=1')) ?>">All <i class="bi bi-arrow-right"></i></a></div>
      <?php if ($critical): ?><ul class="dash-list dash-scroll"><?php foreach ($critical as $x): ?>
        <li><div class="dash-main"><a href="<?= e(url('/reporting/reports/' . $x['id'])) ?>"><b><?= e(employee_name($x)) ?> · <?= e($x['job_title']) ?></b></a><small><?= e(ReportPeriod::shortLabel($x['period'], $x['period_start'])) ?> · <?= e($x['critical_note']) ?></small></div><?= report_status_badge($x['status']) ?></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-shield-check"></i><span>No critical issues raised.</span></div><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico dash-ico-warn"><i class="bi bi-list-check"></i></span><h2>Outstanding management actions</h2><span class="dash-count"><?= count($actions) ?></span><a class="ms-auto" href="<?= e(url('/reporting/actions')) ?>">All <i class="bi bi-arrow-right"></i></a></div>
      <?php if ($actions): ?><ul class="dash-list dash-scroll"><?php foreach ($actions as $a): ?>
        <li><div class="dash-main"><b><?= e($a['title']) ?></b><small><?= e(employee_name($a)) ?> · <?= e(ucfirst($a['priority'])) ?><?= $a['due_date'] ? ' · due ' . e(uk_date($a['due_date'])) : '' ?></small></div>
          <?php if ($a['due_date'] && $a['due_date'] < uk_today()): ?><span class="badge rounded-pill rs rs-overdue">Overdue</span><?php endif; ?></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-check2-circle"></i><span>No outstanding actions.</span></div><?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico dash-ico-ok"><i class="bi bi-diagram-3"></i></span><h2>Consolidated management reports</h2><span class="dash-count"><?= count($managerReports) ?></span></div>
      <?php if ($managerReports): ?><ul class="dash-list dash-scroll"><?php foreach ($managerReports as $x): ?>
        <li><?= employee_avatar($x, 30) ?><div class="dash-main"><a href="<?= e(url('/reporting/reports/' . $x['id'])) ?>"><b><?= e(employee_name($x)) ?> · <?= e($x['job_title']) ?></b></a><small><span class="idc"><?= e($x['report_no']) ?></span> <?= e(ReportPeriod::shortLabel($x['period'], $x['period_start'])) ?> · <a href="<?= e(url('/reporting/team?' . http_build_query(['manager_id' => $x['employee_id'], 'period' => $period, 'date' => $date]))) ?>">team</a></small></div><?= report_status_badge($x['status']) ?></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-inbox"></i><span>No management reports for this period yet.</span></div><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-lightbulb"></i></span><h2>Management recommendations · <?= e(date('F Y', strtotime($start))) ?></h2></div>
      <?php if ($recommendations): ?><ul class="dash-list dash-scroll"><?php foreach ($recommendations as $x): ?>
        <li><div class="dash-main"><a href="<?= e(url('/reporting/reports/' . $x['report_id'])) ?>"><b><?= e($x['name']) ?> · <?= e($x['label']) ?></b></a><span class="small" style="white-space:pre-line"><?= e(mb_strimwidth($x['text'], 0, 400, '…')) ?></span></div></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-chat-square-text"></i><span>Recommendations appear here from submitted monthly reports.</span></div><?php endif; ?>
    </div>
  </div>
</div>
