<form class="filters toolbar" method="get" action="<?= e(url('/shifts/exceptions')) ?>">
  <div><label class="form-label" for="week">Week (any date in it)</label><input class="form-control form-control-sm" type="date" id="week" name="week" value="<?= e($week) ?>" data-autosubmit></div>
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts/exceptions?week=' . date('Y-m-d', strtotime("$week -7 days")))) ?>">‹ Previous week</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts/exceptions?week=' . date('Y-m-d', strtotime("$week +7 days")))) ?>">Next week ›</a>
  </div>
</form>
<div class="card">
  <div class="card-header">Over the weekly limit · week commencing <?= e(uk_date($week)) ?></div>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Employee</th><th>Department</th><th class="num">Scheduled</th><th class="num">Limit</th><th class="num">Excess</th><th>Approval</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><span class="idc"><?= e($r['employee_code']) ?></span> <a href="<?= e(url('/shifts?view=week&employee_id=' . $r['id'] . '&date=' . $week)) ?>"><?= e(employee_name($r)) ?></a></td>
        <td><?= e($r['department']) ?></td><td class="num"><?= e(hours($r['scheduled'], 1)) ?>h</td><td class="num"><?= e(hours($r['limit'], 1)) ?>h</td>
        <td class="num"><span class="badge text-bg-<?= $r['exception'] ? 'warning' : 'danger' ?>">+<?= e(hours($r['excess'], 1)) ?>h</span></td>
        <td>
          <?php if ($r['exception']): ?>
            <span class="badge text-bg-warning">Approved</span> <small><?= e($r['exception']['reason']) ?></small>
          <?php else: ?>
            <form method="post" action="<?= e(url('/shifts/exceptions')) ?>" class="d-flex gap-2">
              <?= csrf_field() ?><input type="hidden" name="week" value="<?= e($week) ?>"><input type="hidden" name="employee_id" value="<?= e($r['id']) ?>">
              <input class="form-control form-control-sm" name="reason" placeholder="Reason" required maxlength="255" aria-label="Reason for approving">
              <button class="btn btn-sm btn-primary text-nowrap">Approve</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No one is over their weekly limit this week.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<div class="card">
  <div class="card-header">Approvals recorded for this week</div>
  <table class="table table-sm"><thead><tr><th>Employee</th><th class="num">Approved hours</th><th class="num">Limit</th><th>Reason</th><th>By</th><th>When</th></tr></thead><tbody>
  <?php foreach ($approved as $a): ?>
    <tr><td><?= e($a['employee_code'] . ' ' . employee_name($a)) ?></td><td class="num"><?= e(hours((int) $a['approved_minutes'], 1)) ?></td><td class="num"><?= e(hours((int) $a['limit_minutes'], 1)) ?></td><td><?= e($a['reason']) ?></td><td><?= e($a['username']) ?></td><td><?= e(uk_datetime($a['approved_at'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$approved): ?><tr><td colspan="6" class="empty">None.</td></tr><?php endif; ?>
  </tbody></table>
</div>
