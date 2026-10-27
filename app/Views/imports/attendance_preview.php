<?php $pending = $batch['status'] === 'validated'; $base = url('/attendance-import/' . $batch['id']); ?>
<div class="steps"><span class="done">1 · Upload ✓</span><span class="done">2 · Validate ✓</span><span class="<?= $pending ? 'cur' : 'done' ?>">3 · Preview<?= $pending ? '' : ' ✓' ?></span><span class="<?= $pending ? '' : 'cur' ?>">4 · <?= $batch['status'] === 'cancelled' ? 'Cancelled' : 'Confirm' ?></span></div>
<div class="kpis">
  <a class="kpi" href="<?= e($base) ?>"><small>Rows read</small><b><?= e($batch['total_rows']) ?></b></a>
  <a class="kpi ok-k" href="<?= e($base . '?result=' . ($pending ? 'ready' : 'imported')) ?>"><small><?= $pending ? 'Valid' : 'Imported' ?></small><b><?= e($pending ? $batch['valid_rows'] : $batch['imported_rows']) ?></b></a>
  <a class="kpi warn-k" href="<?= e($base . '?result=duplicate') ?>"><small>Already in the system</small><b><?= e($batch['duplicate_rows']) ?></b></a>
  <a class="kpi alert-k" href="<?= e($base . '?result=error') ?>"><small>Errors</small><b><?= e($batch['error_rows']) ?></b></a>
  <?php if (!$pending && $batch['status'] === 'imported'): ?><div class="kpi"><small>Updated</small><b><?= e($batch['updated_rows']) ?></b></div><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Row</th><th>Employee ID</th><th>Name</th><th>Work date</th><th>In</th><th>Out</th><th class="num">Break</th><th class="num">Net hrs</th><th>Result</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $raw = json_decode($r['raw_json'], true) ?: [];
      $cls = $r['result'] === 'error' ? 'row-err' : (in_array($r['result'], ['duplicate', 'duplicate_approved'], true) ? 'row-dup' : '');
      $show = fn ($k, $parsed) => $parsed ?? (is_scalar($raw[$k] ?? null) ? (string) $raw[$k] : ''); ?>
    <tr class="<?= $cls ?>">
      <td><?= e($r['row_no']) ?></td><td class="idc"><?= e($r['employee_code']) ?></td><td><?= e($r['employee_name']) ?></td>
      <td><?= e($r['work_date'] ? uk_date($r['work_date']) : $show('work_date', null)) ?></td>
      <td><?= e($r['check_in_at'] ? uk_time($r['check_in_at']) : $show('check_in', null)) ?></td>
      <td><?= e($r['check_out_at'] ? uk_time($r['check_out_at']) : $show('check_out', null)) ?><?= $r['messages'] === 'overnight' || ($r['check_out_at'] && $r['work_date'] && from_utc($r['check_out_at'])->format('Y-m-d') !== $r['work_date']) ? ' <small>(+1)</small>' : '' ?></td>
      <td class="num"><?= e($r['break_minutes']) ?></td><td class="num"><?= e($r['net_minutes'] === null ? '—' : hours((int) $r['net_minutes'])) ?></td>
      <td><?php if ($r['result'] === 'error'): ?><span class="text-danger small fw-semibold"><?= e($r['messages']) ?></span>
        <?php else: ?><?= status_badge($r['result']) ?><?= $r['result'] === 'ready' && $r['messages'] === 'overnight' ? ' <small>overnight</small>' : '' ?><?= in_array($r['result'], ['skipped', 'duplicate', 'duplicate_approved', 'updated'], true) && $r['messages'] ? ' <small>' . e($r['messages']) . '</small>' : '' ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">No rows for this filter.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
  <?php if ($pending): ?>
  <form method="post" action="<?= e($base . '/confirm') ?>" class="d-flex flex-wrap gap-2 align-items-center" data-confirm="Import the valid rows now?">
    <?= csrf_field() ?>
    <input type="hidden" name="duplicate_mode" value="update">
    <small class="text-muted-2">Records already in the system are updated where the file differs; unchanged ones are left as they are. Approved records are never overwritten.</small>
    <button class="btn btn-primary" type="submit">Import <?= e($batch['valid_rows']) ?> valid rows</button>
  </form>
  <?php else: ?><span class="text-muted-2">Status: <?= status_badge($batch['status']) ?> <?= e(uk_datetime($batch['confirmed_at'])) ?></span><?php endif; ?>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= e($base . '/errors') ?>"><i class="bi bi-download"></i> Download error report</a>
    <?php if ($pending): ?><form method="post" action="<?= e($base . '/cancel') ?>"><?= csrf_field() ?><button class="btn btn-outline-danger">Cancel import</button></form><?php endif; ?>
  </div>
</div>
