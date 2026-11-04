<?php
use App\Services\ReportCatalog;
use App\Services\ReportPeriod;
use App\Services\ReportService;

$exportUrl = url('/reporting/reports/export') . '?' . http_build_query(array_filter($request->query, fn ($k) => $k !== 'page', ARRAY_FILTER_USE_KEY));
?>
<form class="filters card card-body mb-3" method="get" action="<?= e(url('/reporting/reports')) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, employee ID or report ID"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="period">Period</label><select class="form-select" id="period" name="period"><option value="">All</option>
      <?php foreach (ReportCatalog::PERIODS as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $filters['period']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All</option><option value="awaiting"<?= selected('awaiting', $filters['status']) ?>>Awaiting approval</option>
      <?php foreach (ReportService::STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $filters['status']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $filters['department_id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label" for="employee_id">Employee</label><select class="form-select" id="employee_id" name="employee_id"><option value="">All</option>
      <?php foreach ($employees as $id => $p): ?><option value="<?= (int) $id ?>"<?= selected($id, $filters['employee_id']) ?>><?= e(employee_name($p)) ?> · <?= e($p['employee_code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label" for="role">Job title</label><select class="form-select" id="role" name="role"><option value="">All</option>
      <?php foreach (ReportCatalog::ROLES as $k => [$title]): ?><option value="<?= $k ?>"<?= selected($k, $filters['role']) ?>><?= e($title) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
    <div class="col-6 col-md-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="critical" name="critical" value="1"<?= checked($filters['critical']) ?>><label class="form-check-label" for="critical">Critical issues only</label></div></div>
    <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button><a class="btn btn-outline-secondary" href="<?= e(url('/reporting/reports')) ?>">Clear</a>
      <a class="btn btn-outline-secondary" href="<?= e($exportUrl) ?>" title="Export these results to Excel"><i class="bi bi-file-earmark-excel"></i></a></div>
  </div>
</form>

<div class="card">
  <div class="card-header">Reports <span class="dash-count"><?= (int) $pg['total'] ?></span></div>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Report ID</th><th>Employee</th><th>Job title</th><th>Period</th><th>Status</th><th>Submitted</th><th>Line manager</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['status'] === 'overdue' ? 'rp-row-late' : '' ?>">
          <td><span class="idc"><?= e($r['report_no']) ?></span></td>
          <td><div class="dash-who"><?= employee_avatar($r, 28) ?><span><?= e(employee_name($r)) ?><small class="d-block idc-plain"><?= e($r['employee_code']) ?></small></span></div></td>
          <td><?= e($r['job_title']) ?><small class="d-block text-muted-2"><?= e($r['department'] ?? '') ?></small></td>
          <td><?= e(ReportCatalog::PERIODS[$r['period']]) ?><small class="d-block text-muted-2"><?= e(ReportPeriod::shortLabel($r['period'], $r['period_start'])) ?></small></td>
          <td><?= report_status_badge($r['status']) ?><?= $r['has_critical'] && (int) $r['current_version'] > 0 ? ' <i class="bi bi-exclamation-octagon text-danger" title="Critical issue"></i>' : '' ?><?= $r['is_late'] ? ' <small class="text-danger">late</small>' : '' ?><?= (int) $r['current_version'] > 1 ? ' <small class="text-muted-2">v' . (int) $r['current_version'] . '</small>' : '' ?></td>
          <td><small><?= e(uk_datetime($r['submitted_at'])) ?></small></td>
          <td><?= e(trim($r['m_first_name'] . ' ' . $r['m_last_name']) ?: '—') ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reporting/reports/' . $r['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="empty">No reports match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= App\Core\View::partial('pagination', ['p' => $pg, 'request' => $request]) ?>
</div>
