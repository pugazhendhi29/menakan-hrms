<?php $has = fn ($k) => in_array($k, $filterKeys, true); ?>
<p class="text-muted-2 mb-0"><?= e($description) ?></p>
<form class="filters toolbar no-print" method="get" action="<?= e(url('/reports/' . $key)) ?>">
  <?php if ($has('period')): ?>
    <div><label class="form-label" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
    <div><label class="form-label" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
  <?php endif; ?>
  <?php if ($has('week')): ?><div><label class="form-label" for="week">Week (any date in it)</label><input class="form-control form-control-sm" type="date" id="week" name="week" value="<?= e($f['week']) ?>"></div><?php endif; ?>
  <?php if ($has('month')): ?><div><label class="form-label" for="month">Month</label><input class="form-control form-control-sm" type="month" id="month" name="month" value="<?= e($f['month']) ?>"></div><?php endif; ?>
  <?php if ($has('days')): ?><div><label class="form-label" for="days">Within days</label><input class="form-control form-control-sm" type="number" min="1" max="365" id="days" name="days" value="<?= e($f['days']) ?>" style="width:100px"></div><?php endif; ?>
  <?php if ($has('department')): ?>
    <div><label class="form-label" for="department_id">Department</label><select class="form-select form-select-sm" id="department_id" name="department_id"><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $f['department']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($has('employee')): ?>
    <div><label class="form-label" for="employee_id">Employee</label><select class="form-select form-select-sm" id="employee_id" name="employee_id"><option value="">All</option>
      <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= selected($emp['id'], $f['employee']) ?>><?= e($emp['employee_code'] . ' ' . employee_name($emp)) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($has('status')): ?>
    <div><label class="form-label" for="status">Status</label><select class="form-select form-select-sm" id="status" name="status">
      <option value="current"<?= selected('current', $f['status']) ?>>Current</option><option value="all"<?= selected('all', $f['status']) ?>>All incl. leavers</option>
      <?php foreach (App\Services\EmployeeService::statuses(true) as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($k, $f['status']) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($has('pay')): ?><div class="form-check align-self-center mt-3"><input class="form-check-input" type="checkbox" id="pay" name="pay" value="1"<?= checked($f['pay']) ?>><label class="form-check-label" for="pay">Include pay data <span class="badge text-bg-danger">logged</span></label></div><?php endif; ?>
  <button class="btn btn-sm btn-primary">Run report</button>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reports/' . $key . '?' . http_build_query(array_merge($request->query, ['format' => 'csv'])))) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reports/' . $key . '?' . http_build_query(array_merge($request->query, ['format' => 'xlsx'])))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
  </div>
</form>
<div class="card">
  <div class="card-header"><?= e($title) ?> <small class="text-muted-2 fw-normal"><?= count($rows) ?> rows · generated <?= e(uk_now()->format('d/m/Y H:i')) ?></small></div>
  <div class="table-responsive"><table class="table table-sm table-hover">
    <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td class="<?= is_int($v) || is_float($v) ? 'num' : '' ?>"><?= e(is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : $v) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($headers) ?>" class="empty">No data for these filters.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
