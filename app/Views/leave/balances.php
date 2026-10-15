<form class="filters toolbar" method="get" action="<?= e(url('/leave/balances')) ?>">
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="date">Leave year containing</label><input class="form-control form-control-sm" type="date" id="date" name="date" value="<?= e($request->str('date') ?: uk_today()) ?>" data-autosubmit></div>
  <div class="ms-auto align-self-center text-muted-2">Leave year <?= e(uk_date($year[0])) ?> – <?= e(uk_date($year[1])) ?></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover">
  <thead><tr><th>Employee</th><th>Department</th><th class="num">Entitlement</th><th class="num">Bank holidays</th><th class="num">Remaining</th><th class="num">Pending</th><th class="num">Available</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= e(url('/employees/' . $r['e']['id'] . '?tab=leave')) ?>"><?= e(employee_name($r['e'])) ?></a> <span class="idc text-muted-2"><?= e($r['e']['employee_code']) ?></span></td><td><?= e($r['e']['department']) ?></td>
      <td class="num"><?= e(days_fmt($r['s']['entitlement'])) ?></td><td class="num"><?= e(days_fmt($r['s']['bank_holidays'])) ?></td><td class="num fw-semibold"><?= e(days_fmt($r['s']['remaining'])) ?></td>
      <td class="num"><?= e(days_fmt($r['s']['pending'])) ?></td><td class="num <?= $r['s']['available'] < 0 ? 'text-danger' : '' ?>"><?= e(days_fmt($r['s']['available'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
