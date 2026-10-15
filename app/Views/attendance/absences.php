<form class="filters toolbar" method="get" action="<?= e(url('/attendance/absences')) ?>">
  <div><label class="form-label" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
  <div><label class="form-label" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id"><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="show">Show</label>
    <select class="form-select form-select-sm" id="show" name="show"><option value="">Unexplained absences</option><option value="all"<?= selected('all', $request->str('show')) ?>>Include approved leave</option></select></div>
  <button class="btn btn-sm btn-primary">Filter</button>
</form>
<p class="text-muted-2 mb-0">Absent means a published shift has started with no check-in on that day and no approved leave.</p>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Date</th><th>Employee</th><th>Department</th><th>Shift</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e(uk_date($r['uk_date'])) ?></td><td><span class="idc"><?= e($r['employee_code']) ?></span> <?= e(employee_name($r)) ?></td><td><?= e($r['department']) ?></td>
      <td><?= e(uk_time($r['start_at']) . '–' . uk_time($r['end_at'])) ?></td><td><?= status_badge($r['day_status']) ?><?= $r['leave'] ? ' <small>' . e($r['leave']['type_name']) . '</small>' : '' ?></td>
      <td class="text-end"><?php if ($r['day_status'] === 'absent'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/attendance/create?employee_id=' . $r['employee_id'])) ?>">Record attendance</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">No absences in this period.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
