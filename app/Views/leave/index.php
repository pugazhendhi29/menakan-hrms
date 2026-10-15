<div class="d-flex flex-wrap gap-2 justify-content-between">
  <ul class="nav nav-tabs border-0">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $v): ?>
      <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/leave?status=' . $k)) ?>"><?= $v ?></a></li>
    <?php endforeach; ?>
  </ul>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/leave/calendar')) ?>"><i class="bi bi-calendar3"></i> Who's off</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/leave/balances')) ?>">Balances</a>
    <a class="btn btn-sm btn-hivis" href="<?= e(url('/leave/create')) ?>">+ Book leave</a>
  </div>
</div>
<form class="filters toolbar" method="get" action="<?= e(url('/leave')) ?>">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <div><label class="form-label" for="employee_id">Employee</label>
    <select class="form-select form-select-sm" id="employee_id" name="employee_id" data-autosubmit><option value="">All</option>
      <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], $request->str('employee_id')) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="leave_type_id">Type</label>
    <select class="form-select form-select-sm" id="leave_type_id" name="leave_type_id" data-autosubmit><option value="">All</option>
      <?php foreach ($types as $t): ?><option value="<?= e($t['id']) ?>"<?= selected($t['id'], $request->str('leave_type_id')) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover">
  <thead><tr><th>Employee</th><th>Department</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Requested date</th><th>Approved date</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><span class="idc"><?= e($r['employee_code']) ?></span> <?= e(employee_name($r)) ?></td><td><?= e($r['department']) ?></td><td><?= e($r['type_name']) ?></td>
      <td class="text-nowrap"><?= e(uk_date($r['start_date'])) ?><?= $r['start_half'] ? ' (pm)' : '' ?> – <?= e(uk_date($r['end_date'])) ?><?= $r['end_half'] ? ' (am)' : '' ?></td>
      <td class="num"><?= e(days_fmt($r['days'])) ?></td><td class="text-nowrap"><?= e(uk_date($r['requested_on'])) ?></td><td class="text-nowrap"><?= e(uk_date($r['approved_on'])) ?></td>
      <td><?= status_badge($r['status']) ?><?= $r['shift_clashes'] && $r['status'] !== 'rejected' && $r['status'] !== 'cancelled' ? ' <span class="badge text-bg-warning">Shift clash</span>' : '' ?></td>
      <td class="text-end"><a class="btn btn-sm btn-<?= $r['status'] === 'pending' ? 'primary' : 'outline-secondary' ?>" href="<?= e(url('/leave/' . $r['id'])) ?>"><?= $r['status'] === 'pending' ? 'Review' : 'View' ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">No leave requests here.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
</div>
