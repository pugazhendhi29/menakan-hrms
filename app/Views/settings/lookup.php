<?php
$val = function (array $row, string $col, string $type) use ($refs) {
    $v = $row[$col] ?? null;
    if ($type === 'bool') {
        return $v ? '<i class="bi bi-check-lg text-success"></i>' : '';
    }
    if (str_starts_with($type, 'ref:')) {
        return e($v ? ($refs[$col][$v] ?? '#' . $v) : '—');
    }
    if ($type === 'date') {
        return e(uk_date($v));
    }
    if ($type === 'colour' && $v) {
        return '<span class="badge" style="background:' . e($v) . '">' . e($v) . '</span>';
    }
    if ($type === 'time' && $v) {
        return e(substr($v, 0, 5));
    }
    return e($v ?? '—');
};
?>
<a class="btn btn-sm btn-link px-0" href="<?= e(url('/settings')) ?>">‹ Back to settings</a>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover">
      <thead><tr><?php foreach ($fields as $col => [$name]): ?><th><?= e($name) ?></th><?php endforeach; ?><?php if ($toggle): ?><th><?= $toggle === 'is_mandatory' ? 'Mandatory' : 'Active' ?></th><?php endif; ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $toggle && !$r[$toggle] ? 'text-muted-2' : '' ?>">
          <?php foreach ($fields as $col => [, $type]): ?><td><?= $val($r, $col, $type) ?></td><?php endforeach; ?>
          <?php if ($toggle): ?><td><?= $r[$toggle] ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td><?php endif; ?>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/settings/' . $table . '?edit=' . $r['id'])) ?>">Edit</a>
            <form class="d-inline" method="post" action="<?= e(url('/settings/' . $table . '/' . $r['id'] . '/toggle')) ?>"<?= $toggle ? '' : ' data-confirm="Delete this entry?"' ?>><?= csrf_field() ?>
              <button class="btn btn-sm btn-outline-<?= $toggle ? 'secondary' : 'danger' ?>"><?= $toggle ? ($r[$toggle] ? ($toggle === 'is_mandatory' ? 'Make optional' : 'Deactivate') : ($toggle === 'is_mandatory' ? 'Make mandatory' : 'Activate')) : 'Delete' ?></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= count($fields) + 2 ?>" class="empty">Nothing here yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div></div>
  </div>
  <div class="col-xl-4">
    <div class="card"><div class="card-header"><?= $edit ? 'Edit entry' : 'Add entry' ?></div><div class="card-body">
      <form method="post" action="<?= e(url('/settings/' . $table)) ?>" class="d-flex flex-column gap-2" novalidate>
        <?= csrf_field() ?>
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= e($edit['id']) ?>"><?php endif; ?>
        <?php foreach ($fields as $col => [$name, $type, $required]): $cur = old($col, $edit[$col] ?? ''); $fid = 'f_' . $col; ?>
          <?php if ($type === 'bool'): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="<?= $fid ?>" name="<?= e($col) ?>" value="1"<?= checked($edit ? $edit[$col] : in_array($col, ['mon', 'tue', 'wed', 'thu', 'fri', 'is_paid', 'employee_can_request', 'visible_to_employee'], true)) ?>><label class="form-check-label" for="<?= $fid ?>"><?= e($name) ?></label></div>
          <?php elseif (str_starts_with($type, 'ref:')): ?>
            <div><label class="form-label<?= $required ? ' req' : '' ?>" for="<?= $fid ?>"><?= e($name) ?></label>
              <select class="form-select form-select-sm<?= is_invalid($col) ?>" id="<?= $fid ?>" name="<?= e($col) ?>"><option value="">—</option>
                <?php foreach ($refs[$col] as $rid => $rl): ?><option value="<?= e($rid) ?>"<?= selected($rid, $cur) ?>><?= e($rl) ?></option><?php endforeach; ?>
              </select><?= field_error($col) ?></div>
          <?php else: ?>
            <div><label class="form-label<?= $required ? ' req' : '' ?>" for="<?= $fid ?>"><?= e($name) ?></label>
              <input class="form-control form-control-sm<?= is_invalid($col) ?>" id="<?= $fid ?>" name="<?= e($col) ?>" type="<?= ['time' => 'time', 'date' => 'date', 'colour' => 'color'][$type] ?? 'text' ?>" value="<?= e($type === 'time' ? substr((string) $cur, 0, 5) : ($cur ?: ($type === 'colour' ? '#1f5fa8' : ($col === 'region' ? 'england-and-wales' : '')))) ?>"><?= field_error($col) ?></div>
          <?php endif; ?>
        <?php endforeach; ?>
        <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary" type="submit">Save</button><?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/settings/' . $table)) ?>">Cancel</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
