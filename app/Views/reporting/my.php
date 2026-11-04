<?php
use App\Core\View;
use App\Services\ReportPeriod;
use App\Services\ReportService;

$periodName = ['daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly'][$period];
$currentRow = null;
foreach ($rows as $row) {
    if ($row['start'] === $current) {
        $currentRow = $row;
    }
}
$open = fn (string $start, string $label, string $class = 'btn btn-hivis') => '<form method="post" action="' . e(url('/reporting/start')) . '" class="d-inline">' . csrf_field()
    . '<input type="hidden" name="period" value="' . e($period) . '"><input type="hidden" name="date" value="' . e($start) . '"><button class="' . e($class) . '" type="submit">' . $label . '</button></form>';
?>
<div class="rp-bar">
  <?= View::partial('reporting_tabs', ['path' => '/reporting/my', 'period' => $period]) ?>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($isManager): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('/reporting/team?period=' . $period)) ?>"><i class="bi bi-people"></i> Team reports</a><?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/reporting/reports?employee_id=' . App\Core\Auth::employeeId())) ?>"><i class="bi bi-clock-history"></i> My report history</a>
  </div>
</div>

<?php if (!$role): ?>
  <div class="alert alert-warning"><b>No reporting form for your job title yet.</b> Reports are written on the form for one of the 12 approved job titles. Please ask HR to update your job title.</div>
<?php else: ?>

<?php if (!$manager && $role !== 'director'): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> You do not have a line manager assigned, so your reports cannot be submitted yet. Please ask HR to set your line manager.</div>
<?php endif; ?>

<?php if ($returned): ?>
  <div class="alert alert-warning">
    <b><i class="bi bi-arrow-return-left"></i> Reports needing your attention:</b>
    <?php foreach ($returned as $x): ?>
      <a class="ms-2" href="<?= e(url('/reporting/reports/' . $x['id'] . '/edit')) ?>"><?= e($x['report_no']) ?> · <?= e(ReportPeriod::shortLabel($x['period'], $x['period_start'])) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php $cr = $currentRow['report'] ?? null; ?>
<section class="rp-current" aria-label="Current <?= e($periodName) ?> report">
  <div>
    <h2><?= e($tpl['title'] ?? '') ?></h2>
    <p><?= e(ReportPeriod::label($period, $current)) ?> · due <?= e(uk_datetime(ReportPeriod::dueAt($period, ReportPeriod::bounds($period, $current)[1]))) ?>
      · goes to <?= $manager ? e(employee_name($manager)) : ($role === 'director' ? 'no one: Directors complete the chain' : 'no line manager yet') ?></p>
  </div>
  <div class="ms-auto">
    <?= report_status_badge($cr ? $cr['status'] : 'missing') ?>
    <?php if (!$cr || in_array($cr['status'], App\Services\ReportAccess::EDITABLE, true)): ?>
      <?= $open($current, '<i class="bi bi-pencil-square"></i> ' . ($cr ? 'Continue report' : 'Start ' . $periodName . ' report')) ?>
    <?php else: ?>
      <a class="btn btn-light" href="<?= e(url('/reporting/reports/' . $cr['id'])) ?>"><i class="bi bi-eye"></i> View report</a>
    <?php endif; ?>
  </div>
</section>

<div class="stats mb-3">
  <div class="stat stat-ok"><span class="stat-icon"><i class="bi bi-send-check"></i></span><span class="stat-body"><small>Submitted this month</small><b><?= (int) $stats['submitted'] ?></b><span class="stat-note">all periods</span></span></div>
  <div class="stat stat-ok"><span class="stat-icon"><i class="bi bi-patch-check"></i></span><span class="stat-body"><small>Approved</small><b><?= (int) $stats['approved'] ?></b><span class="stat-note">this month</span></span></div>
  <div class="stat <?= (int) $stats['returned'] ? 'stat-warn' : 'stat-calm' ?>"><span class="stat-icon"><i class="bi bi-arrow-return-left"></i></span><span class="stat-body"><small>Returned for correction</small><b><?= (int) $stats['returned'] ?></b><span class="stat-note">to correct and resubmit</span></span></div>
  <div class="stat <?= (int) $stats['overdue'] ? 'stat-alert' : 'stat-calm' ?>"><span class="stat-icon"><i class="bi bi-alarm"></i></span><span class="stat-body"><small>Overdue</small><b><?= (int) $stats['overdue'] ?></b><span class="stat-note">past the deadline</span></span></div>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card h-100">
      <div class="card-header"><?= e(ucfirst($periodName)) ?> reports <small class="text-muted-2"><?= e($tpl['title'] ?? '') ?></small></div>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Period</th><th>Report ID</th><th>Status</th><th>Deadline</th><th class="text-end">Action</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): $r = $row['report']; ?>
            <tr class="<?= $row['late'] ? 'rp-row-late' : '' ?>">
              <td><b><?= e(ReportPeriod::label($period, $row['start'])) ?></b><?= $row['expected'] ? '' : ' <small class="text-muted-2">(not a report day)</small>' ?></td>
              <td><?= $r ? '<span class="idc">' . e($r['report_no']) . '</span>' : '—' ?></td>
              <td><?= report_status_badge($r ? $r['status'] : ($row['late'] ? 'overdue' : 'missing')) ?><?= $r && $r['is_late'] ? ' <small class="text-danger">late</small>' : '' ?><?= $r && $r['has_critical'] ? ' <i class="bi bi-exclamation-octagon text-danger" title="Critical issue raised"></i>' : '' ?></td>
              <td><small><?= e(uk_datetime($row['due'])) ?></small></td>
              <td class="text-end">
                <?php if (!$r || in_array($r['status'], App\Services\ReportAccess::EDITABLE, true)): ?>
                  <?php if (!$r && $row['start'] !== $current && !$row['expected'] && !$canBackdate): ?>
                    <small class="text-muted-2" title="Starting a report for an earlier period needs access from HR">Not started</small>
                  <?php else: ?>
                  <?= $open($row['start'], $r ? 'Continue' : 'Start', 'btn btn-sm ' . ($row['late'] ? 'btn-danger' : 'btn-outline-primary')) ?>
                  <?php endif; ?>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reporting/reports/' . $r['id'])) ?>">View</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="5" class="empty"><?= $period === 'daily' ? 'No daily reports are expected yet: a daily report is due on each day you have a shift on the rota.' : 'No ' . e($periodName) . ' reports are expected yet (reporting starts ' . e(uk_date(ReportPeriod::startDate())) . ').' ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <?php if ($canBackdate): ?>
    <div class="card mb-3" id="backdated">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-calendar2-minus"></i></span><h2>Report for an earlier date</h2></div>
      <div class="card-body">
        <p class="small text-muted-2 mb-2">Write a report for an earlier day, week or month and, if you need to, choose its submission date and time. It goes to your line manager like any other report.</p>
        <form method="post" action="<?= e(url('/reporting/start')) ?>" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <div class="col-sm-5 col-xl-12 col-xxl-5"><label class="form-label" for="bd_period">Report</label>
            <select class="form-select" id="bd_period" name="period"><?php foreach (App\Services\ReportCatalog::PERIODS as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $period) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-7 col-xl-12 col-xxl-7"><label class="form-label" for="bd_date">Any date in the period</label>
            <input class="form-control" type="date" id="bd_date" name="date" max="<?= e(uk_today()) ?>" min="<?= e($startDate) ?>" required></div>
          <div class="col-12"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-clock-history"></i> Open report</button></div>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <div class="card mb-3">
      <div class="dash-head"><span class="dash-ico dash-ico-warn"><i class="bi bi-list-check"></i></span><h2>My actions</h2><span class="dash-count"><?= count($actions) ?></span><a class="ms-auto" href="<?= e(url('/reporting/actions')) ?>">All <i class="bi bi-arrow-right"></i></a></div>
      <?php if ($actions): ?>
        <ul class="dash-list">
          <?php foreach ($actions as $a): ?>
            <li><div class="dash-main"><b><?= e($a['title']) ?></b><small><?= e(ucfirst($a['priority'])) ?> priority<?= $a['due_date'] ? ' · due ' . e(uk_date($a['due_date'])) : '' ?><?= $a['report_no'] ? ' · ' . e($a['report_no']) : '' ?></small></div></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><div class="dash-empty"><i class="bi bi-check2-circle"></i><span>No outstanding actions.</span></div><?php endif; ?>
    </div>
    <div class="card">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-info-circle"></i></span><h2>How it works</h2></div>
      <div class="card-body small">
        <ol class="ps-3 mb-0">
          <li>Complete the form for your job title and submit it by the deadline.</li>
          <li>It goes to <?= $manager ? '<b>' . e(employee_name($manager)) . '</b>' : 'your line manager' ?>, who approves it or returns it with comments.</li>
          <li>Weekly and monthly figures are filled in for you from your approved daily (and weekly) reports.</li>
          <li>Once you submit, the report cannot be changed. If it is returned, your correction is recorded as an amendment; the original is kept.</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
