<?php $icons = ['verified' => ['check-circle-fill', 'text-success'], 'pending' => ['hourglass-split', 'text-info'], 'expiring' => ['exclamation-triangle-fill', 'text-warning'],
    'expired' => ['x-octagon-fill', 'text-danger'], 'missing' => ['dash-circle', 'text-danger'], 'rejected' => ['x-circle-fill', 'text-danger']]; ?>
<form class="filters toolbar" method="get" action="<?= e(url('/documents/matrix')) ?>">
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="only">Show</label>
    <select class="form-select form-select-sm" id="only" name="only" data-autosubmit><option value="">Everyone</option><option value="missing"<?= selected('missing', $request->str('only')) ?>>Missing or expired only</option></select></div>
  <div class="ms-auto small text-muted-2">
    <?php foreach ($icons as $k => [$i, $c]): ?><span class="me-2"><i class="bi bi-<?= $i ?> <?= $c ?>"></i> <?= e(ucfirst($k)) ?></span><?php endforeach; ?> · blank = not required
  </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover">
  <thead><tr><th>Employee</th><th>Department</th><?php foreach ($categories as $c): ?><th class="text-center"><?= e($c['name']) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= e(url('/employees/' . $r['e']['id'] . '?tab=documents')) ?>"><?= e(employee_name($r['e'])) ?></a></td><td><?= e($r['e']['department']) ?></td>
    <?php foreach ($categories as $c): $s = $r['cells'][$c['id']] ?? null; ?>
      <td class="text-center"><?php if ($s): [$i, $cls] = $icons[$s]; ?><i class="bi bi-<?= $i ?> <?= $cls ?>" title="<?= e(ucfirst($s)) ?>"></i><span class="visually-hidden"><?= e($s) ?></span><?php endif; ?></td>
    <?php endforeach; ?></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="<?= 2 + count($categories) ?>" class="empty">Everyone has their required documents.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
