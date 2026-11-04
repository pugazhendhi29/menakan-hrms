<?php
use App\Core\View;
use App\Services\ReportAccess;
use App\Services\ReportCatalog;
use App\Services\ReportPeriod;

$t = $comp['totals'];
$q = ['date' => $date, 'scope' => $scope] + ($managerId !== App\Core\Auth::employeeId() ? ['manager_id' => $managerId] : []);
$tile = function (string $icon, string $label, int|string $value, string $note, string $tone = '', string $href = '#'): string {
    return '<a class="stat stat-' . e($tone ?: 'calm') . '" href="' . e($href) . '"><span class="stat-icon"><i class="bi bi-' . e($icon) . '"></i></span><span class="stat-body"><small>' . e($label)
        . '</small><b>' . e($value) . '</b><span class="stat-note">' . $note . '</span></span></a>';
};
$exp = fn (string $scopeName, string $format) => url('/reporting/export') . '?' . http_build_query(['scope' => $scopeName, 'period' => $period, 'date' => $date, 'format' => $format] + ($managerId !== App\Core\Auth::employeeId() ? ['manager_id' => $managerId] : []));
?>
<div class="rp-bar">
  <?= View::partial('reporting_tabs', ['path' => '/reporting/team', 'period' => $period, 'query' => $q]) ?>
  <form method="get" action="<?= e(url('/reporting/team')) ?>">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <?php if ($managers): ?>
      <div><label class="form-label" for="manager_id">Manager</label><select class="form-select form-select-sm" id="manager_id" name="manager_id" data-autosubmit>
        <?php foreach ($managers as $id => $p): ?><option value="<?= (int) $id ?>"<?= selected($id, $managerId) ?>><?= e(employee_name($p)) ?> · <?= e($p['job_title']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div><label class="form-label" for="date">Date in period</label><input class="form-control form-control-sm" type="date" id="date" name="date" value="<?= e($date) ?>" max="<?= e(uk_today()) ?>" data-autosubmit></div>
    <div><label class="form-label" for="scope">People</label><select class="form-select form-select-sm" id="scope" name="scope" data-autosubmit>
      <option value="direct"<?= selected('direct', $scope) ?>>Direct reports</option><option value="all"<?= selected('all', $scope) ?>>Everyone below me</option></select></div>
    <div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download"></i> Export</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= e($exp('team', 'pdf')) ?>"><i class="bi bi-filetype-pdf"></i> <?= e(ReportCatalog::PERIODS[$period]) ?> team report (PDF)</a></li>
        <li><a class="dropdown-item" href="<?= e($exp('team', 'xlsx')) ?>"><i class="bi bi-file-earmark-excel"></i> <?= e(ReportCatalog::PERIODS[$period]) ?> team report (Excel)</a></li>
      </ul></div>
  </form>
</div>

<section aria-label="Team reporting overview">
  <h2 class="dash-label"><?= e(ReportPeriod::label($period, $start)) ?> · <?= count($members) ?> <?= $scope === 'all' ? 'people below you' : 'direct reports' ?></h2>
  <div class="stats mb-3">
    <?= $tile('people', 'Reporting to you', count($members), $scope === 'all' ? 'at every level' : 'direct reports', 'ok', '#team') ?>
    <?= $tile('calendar-day', 'Daily reports today', $counts['daily_today']['submitted'] . '/' . $counts['daily_today']['expected'], e($counts['daily_today']['expected'] - $counts['daily_today']['submitted']) . ' outstanding', $counts['daily_today']['submitted'] < $counts['daily_today']['expected'] ? 'warn' : 'ok', url('/reporting/team?' . http_build_query(['period' => 'daily', 'date' => uk_today()] + $q))) ?>
    <?= $tile('calendar-week', 'Weekly reports', $counts['weekly']['submitted'] . '/' . $counts['weekly']['expected'], 'received · w/c ' . e(uk_date($week[0])), $counts['weekly']['overdue'] ? 'alert' : 'ok', url('/reporting/team?' . http_build_query(['period' => 'weekly'] + $q))) ?>
    <?= $tile('calendar3', 'Monthly reports', $counts['monthly']['submitted'] . '/' . $counts['monthly']['expected'], 'received', $counts['monthly']['overdue'] ? 'alert' : 'ok', url('/reporting/team?' . http_build_query(['period' => 'monthly'] + $q))) ?>
    <?= $tile('hourglass-split', 'Awaiting your approval', count($awaiting), 'all periods', count($awaiting) ? 'warn' : '', '#awaiting') ?>
    <?= $tile('arrow-return-left', 'Returned for correction', count($returned), 'waiting for the employee', count($returned) ? 'warn' : '', '#returned') ?>
    <?= $tile('list-check', 'Outstanding actions', count($actions), 'open management actions', count($actions) ? 'warn' : '', url('/reporting/actions')) ?>
    <?= $tile('send-check', 'Forwarded to senior management', count(array_filter($mine, fn ($m) => $m['status'] !== 'returned')), 'your recent reports', 'ok', '#mine') ?>
  </div>
</section>

<?php if ($noAccount): ?>
  <div class="alert alert-info small"><i class="bi bi-person-lock"></i> <?= count($noAccount) ?> of your team cannot report yet because they have no login account: <?= e(implode(', ', array_map(fn ($id) => employee_name(ReportAccess::person($id)), $noAccount))) ?>. Ask HR to create their accounts.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card h-100" id="awaiting">
      <div class="dash-head"><span class="dash-ico dash-ico-warn"><i class="bi bi-hourglass-split"></i></span><h2>Awaiting your approval</h2><span class="dash-count"><?= count($awaiting) ?></span></div>
      <?php if ($awaiting): ?>
      <ul class="dash-list dash-scroll">
        <?php foreach ($awaiting as $a): ?>
          <li><?= employee_avatar($a, 34) ?><div class="dash-main"><a href="<?= e(url('/reporting/reports/' . $a['id'])) ?>"><b><?= e(employee_name($a)) ?> · <?= e(ReportCatalog::PERIODS[$a['period']]) ?> report</b></a>
            <small><span class="idc"><?= e($a['report_no']) ?></span> <?= e(ReportPeriod::label($a['period'], $a['period_start'])) ?> · submitted <?= e(uk_datetime($a['submitted_at'])) ?><?= $a['is_late'] ? ' · late' : '' ?></small></div>
            <?= $a['has_critical'] ? '<i class="bi bi-exclamation-octagon text-danger" title="Critical issue"></i>' : '' ?><?= report_status_badge($a['status']) ?>
            <a class="btn btn-sm btn-primary" href="<?= e(url('/reporting/reports/' . $a['id'] . '#review')) ?>">Review</a></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><div class="dash-empty"><i class="bi bi-check2-circle"></i><span>Nothing is waiting for you.</span></div><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-bar-chart-line"></i></span><h2>Daily reporting · last 14 days</h2></div>
      <div class="card-body" style="height:260px"><canvas data-chart="<?= e(json_encode($chart)) ?>" role="img" aria-label="Daily reports expected, submitted and approved per day for the last 14 days"></canvas></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card" id="team">
      <div class="dash-head"><span class="dash-ico dash-ico-ok"><i class="bi bi-people"></i></span><h2><?= e(ReportCatalog::PERIODS[$period]) ?> reports · <?= e(ReportPeriod::label($period, $start)) ?></h2>
        <span class="dash-count"><?= (int) $t['submitted'] ?>/<?= (int) $t['expected'] ?> submitted<?= $t['rate'] !== null ? ' · ' . (int) $t['rate'] . '%' : '' ?></span></div>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Employee</th><th>Job title</th><?php if ($scope === 'all'): ?><th>Reports to</th><?php endif; ?><th>Report</th><th>Status</th><th class="text-end"></th></tr></thead>
          <tbody>
          <?php foreach ($members as $m): $r = $m['report']; ?>
            <tr>
              <td><div class="dash-who"><?= employee_avatar($m['person'], 30) ?><span><?= e(employee_name($m['person'])) ?><small class="d-block idc-plain"><?= e($m['person']['employee_code']) ?></small></span></div></td>
              <td><?= e($m['person']['job_title'] ?? '—') ?><?= $m['role'] ? '' : ' <span class="badge text-bg-warning">not an approved title</span>' ?></td>
              <?php if ($scope === 'all'): ?><td><?= $m['manager'] ? e(employee_name(ReportAccess::person($m['manager']))) : '—' ?></td><?php endif; ?>
              <td><?= $r ? '<span class="idc">' . e($r['report_no']) . '</span>' : '—' ?><?= $r && $r['has_critical'] ? ' <i class="bi bi-exclamation-octagon text-danger" title="Critical issue"></i>' : '' ?></td>
              <td><?= $r ? report_status_badge($r['status']) : ($m['expected'] ? report_status_badge('missing') : '<small class="text-muted-2">Not expected</small>') ?><?= $r && $r['is_late'] ? ' <small class="text-danger">late</small>' : '' ?></td>
              <td class="text-end"><?php if ($r): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reporting/reports/' . $r['id'])) ?>">Open</a><?php endif; ?>
                <a class="btn btn-sm btn-link" href="<?= e(url('/reporting/reports?employee_id=' . $m['id'])) ?>">History</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($areas): ?>
  <div class="col-12">
    <h2 class="dash-label mt-2">Team performance · approved <?= e(strtolower(ReportCatalog::PERIODS[$period])) ?> reports</h2>
    <div class="rp-areas">
      <?php foreach ($areas as $a): ?>
        <div class="rp-area"><h3><i class="bi bi-graph-up-arrow text-primary"></i><?= e($a['area']) ?><small><?= (int) $a['approved'] ?> approved<?= $a['pending'] ? ' · ' . (int) $a['pending'] . ' pending' : '' ?></small></h3>
          <?php $k = array_filter($a['kpis'], fn ($x) => $x['value'] !== null); ?>
          <?php if ($k): ?><div class="rp-kpis"><?php foreach ($k as $x): ?><div class="rp-kpi"><small><?= e($x['label']) ?></small><b><?= e(report_value($x['display'], $x['value'])) ?></b></div><?php endforeach; ?></div>
          <?php else: ?><div class="empty-note">No approved figures yet.</div><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="card">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-calendar-week"></i></span><h2>Weekly performance summary · w/c <?= e(uk_date($week[0])) ?></h2><small class="text-muted-2 ms-2">from approved daily reports</small></div>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Employee</th><th class="num">Daily reports</th><th class="num">Approved</th><th>Key figures</th><th>Weekly report</th></tr></thead>
          <tbody>
          <?php foreach ($weekly as $w): $c = $w['comp']; ?>
            <tr>
              <td><b><?= e(employee_name($w['person'])) ?></b><small class="d-block text-muted-2"><?= e(ReportCatalog::ROLES[$w['role']][0]) ?></small></td>
              <td class="num"><?= $c ? (int) $c['submitted'] . '/' . (int) $c['expected'] : '—' ?></td>
              <td class="num"><?= $c ? (int) $c['approved'] : '—' ?></td>
              <td class="small"><?= $w['kpis'] ? e(implode(' · ', array_map(fn ($k) => $k['label'] . ' ' . report_value($k['display'], $k['value']), $w['kpis']))) : '<span class="text-muted-2">No approved daily figures yet</span>' ?></td>
              <td><?= $w['weekly'] ? '<a href="' . e(url('/reporting/reports/' . $w['weekly']['id'])) . '">' . e($w['weekly']['report_no']) . '</a> ' . report_status_badge($w['weekly']['status']) : report_status_badge('missing') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$weekly): ?><tr><td colspan="5" class="empty">No one in this team has an approved reporting role.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card h-100" id="returned">
      <div class="dash-head"><span class="dash-ico dash-ico-warn"><i class="bi bi-arrow-return-left"></i></span><h2>Returned for correction</h2><span class="dash-count"><?= count($returned) ?></span></div>
      <?php if ($returned): ?><ul class="dash-list"><?php foreach ($returned as $x): ?>
        <li><div class="dash-main"><a href="<?= e(url('/reporting/reports/' . $x['id'])) ?>"><b><?= e(employee_name($x)) ?></b></a><small><?= e($x['report_no']) ?> · <?= e(ReportPeriod::shortLabel($x['period'], $x['period_start'])) ?></small></div></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-check2-circle"></i><span>None.</span></div><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico dash-ico-alert"><i class="bi bi-exclamation-octagon"></i></span><h2>Critical issues · 30 days</h2><span class="dash-count"><?= count($critical) ?></span></div>
      <?php if ($critical): ?><ul class="dash-list dash-scroll"><?php foreach ($critical as $x): ?>
        <li><div class="dash-main"><a href="<?= e(url('/reporting/reports/' . $x['id'])) ?>"><b><?= e(employee_name($x)) ?></b></a><small><?= e($x['critical_note']) ?></small></div></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-shield-check"></i><span>No critical issues.</span></div><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100" id="mine">
      <div class="dash-head"><span class="dash-ico dash-ico-ok"><i class="bi bi-send-check"></i></span><h2>Your reports to senior management</h2></div>
      <?php if ($mine): ?><ul class="dash-list"><?php foreach ($mine as $x): ?>
        <li><div class="dash-main"><a href="<?= e(url('/reporting/reports/' . $x['id'])) ?>"><b><?= e($x['report_no']) ?> · <?= e(ReportPeriod::shortLabel($x['period'], $x['period_start'])) ?></b></a><small>to <?= e(trim($x['m_first_name'] . ' ' . $x['m_last_name']) ?: 'no one (Director)') ?></small></div><?= report_status_badge($x['status']) ?></li>
      <?php endforeach; ?></ul><?php else: ?><div class="dash-empty"><i class="bi bi-inbox"></i><span>You have not submitted a report yet.</span></div><?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="dash-head"><span class="dash-ico dash-ico-warn"><i class="bi bi-list-check"></i></span><h2>Outstanding management actions</h2><span class="dash-count"><?= count($actions) ?></span><a class="ms-auto" href="<?= e(url('/reporting/actions')) ?>">All actions <i class="bi bi-arrow-right"></i></a></div>
      <?php if ($actions): ?>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Action</th><th>Assigned to</th><th>Priority</th><th>Due</th><th>Report</th></tr></thead>
        <tbody><?php foreach ($actions as $a): ?>
          <tr class="<?= $a['due_date'] && $a['due_date'] < uk_today() ? 'rp-row-late' : '' ?>"><td><?= e($a['title']) ?></td><td><?= e(employee_name($a)) ?></td><td><?= e(ucfirst($a['priority'])) ?></td><td><?= e(uk_date($a['due_date'])) ?></td>
            <td><?= $a['report_id'] ? '<a href="' . e(url('/reporting/reports/' . $a['report_id'])) . '">' . e($a['report_no']) . '</a>' : '—' ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?><div class="dash-empty"><i class="bi bi-check2-circle"></i><span>No outstanding actions.</span></div><?php endif; ?>
    </div>
  </div>
</div>
