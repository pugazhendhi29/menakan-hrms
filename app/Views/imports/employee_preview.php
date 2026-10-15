<?php $pending = $batch['status'] === 'validated'; $base = url('/employees/import/' . $batch['id']); ?>
<div class="steps"><span class="done">1 · Upload ✓</span><span class="done">2 · Validate ✓</span><span class="<?= $pending ? 'cur' : 'done' ?>">3 · Preview<?= $pending ? '' : ' ✓' ?></span><span class="<?= $pending ? '' : 'cur' ?>">4 · <?= $batch['status'] === 'cancelled' ? 'Cancelled' : 'Confirm' ?></span></div>
<div class="kpis">
  <a class="kpi" href="<?= e($base) ?>"><small>Rows read</small><b><?= e($batch['total_rows']) ?></b></a>
  <a class="kpi ok-k" href="<?= e($base . '?result=create') ?>"><small>New employees</small><b><?= e($batch['create_rows']) ?></b></a>
  <a class="kpi ok-k" href="<?= e($base . '?result=update') ?>"><small>Updates</small><b><?= e($batch['update_rows']) ?></b></a>
  <a class="kpi" href="<?= e($base . '?result=unchanged') ?>"><small>No change</small><b><?= e($batch['unchanged_rows']) ?></b></a>
  <a class="kpi alert-k" href="<?= e($base . '?result=error') ?>"><small>Errors</small><b><?= e($pending ? $batch['error_rows'] : (int) App\Core\DB::value("SELECT COUNT(*) FROM employee_import_rows WHERE batch_id = ? AND result = 'error'", [$batch['id']])) ?></b></a>
</div>
<?php if ($pending): ?><p class="mb-0 small text-muted-2">Nothing has been saved yet. Check the changes below, then confirm. Rows with errors are left out; fix them in the error report and upload them again.</p><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Row</th><th>Employee ID</th><th>Name</th><th>Action</th><th>Changes / problems</th><th>Result</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $cls = ['error' => 'row-err', 'unchanged' => 'text-muted-2'][$r['result']] ?? ''; ?>
    <tr class="<?= $cls ?>">
      <td><?= e($r['row_no']) ?></td>
      <td class="idc"><?php if ($r['employee_id'] && $r['result'] !== 'error'): ?><a href="<?= e(url('/employees/' . $r['employee_id'])) ?>"><?= e($r['employee_code']) ?></a><?php else: ?><?= e($r['employee_code'] ?: ($r['action'] === 'create' ? 'next ID' : '')) ?><?php endif; ?></td>
      <td><?= e((string) $r['employee_name']) ?></td>
      <td><?= $r['action'] === 'create' ? '<span class="badge text-bg-info">New</span>' : '<span class="badge text-bg-secondary">Update</span>' ?></td>
      <td class="small"><?php if ($r['messages']): ?><span class="text-danger fw-semibold"><?= e($r['messages']) ?></span>
        <?php elseif ($r['changes']): ?><?= implode('<br>', array_map('e', explode(' · ', $r['changes']))) ?>
        <?php elseif ($r['action'] === 'create'): ?>New employee record<?php else: ?>No changes<?php endif; ?></td>
      <td><?= status_badge($r['result'] === 'ready' ? 'ready' : $r['result']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">No rows for this filter.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
  <?php if ($pending): ?>
  <form method="post" action="<?= e($base . '/confirm') ?>" data-confirm="Add <?= (int) $batch['create_rows'] ?> new employees and update <?= (int) $batch['update_rows'] ?> now?">
    <?= csrf_field() ?>
    <button class="btn btn-primary" type="submit"<?= $batch['create_rows'] + $batch['update_rows'] ? '' : ' disabled' ?>>Import <?= (int) $batch['create_rows'] ?> new and <?= (int) $batch['update_rows'] ?> updated employees</button>
  </form>
  <?php else: ?><span class="text-muted-2">Status: <?= status_badge($batch['status']) ?> <?= e(uk_datetime($batch['confirmed_at'])) ?></span><?php endif; ?>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= e($base . '/errors') ?>"><i class="bi bi-download"></i> Download error report</a>
    <?php if ($pending): ?><form method="post" action="<?= e($base . '/cancel') ?>"><?= csrf_field() ?><button class="btn btn-outline-danger">Cancel import</button></form><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= e(url('/employees')) ?>">Back to employees</a>
  </div>
</div>
