<?php $pending = $batch['status'] === 'validated'; $base = url('/shift-import/' . $batch['id']); ?>
<div class="steps"><span class="done">1 · Upload ✓</span><span class="done">2 · Validate ✓</span><span class="<?= $pending ? 'cur' : 'done' ?>">3 · Preview<?= $pending ? '' : ' ✓' ?></span><span class="<?= $pending ? '' : 'cur' ?>">4 · <?= $batch['status'] === 'cancelled' ? 'Cancelled' : 'Confirm' ?></span></div>
<div class="kpis">
  <a class="kpi" href="<?= e($base) ?>"><small>Rows read</small><b><?= e($batch['total_rows']) ?></b></a>
  <a class="kpi ok-k" href="<?= e($base . '?result=' . ($pending ? 'ready' : 'imported')) ?>"><small><?= $pending ? 'Valid' : 'Imported' ?></small><b><?= e($pending ? $batch['valid_rows'] : $batch['imported_rows']) ?></b></a>
  <a class="kpi warn-k" href="<?= e($base . '?result=warning') ?>"><small>Over weekly limit</small><b><?= e($warnings) ?></b></a>
  <a class="kpi warn-k" href="<?= e($base . '?result=duplicate') ?>"><small>Already in the system</small><b><?= e($batch['duplicate_rows']) ?></b></a>
  <a class="kpi alert-k" href="<?= e($base . '?result=error') ?>"><small>Errors</small><b><?= e($batch['error_rows']) ?></b></a>
</div>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Row</th><th>Employee</th><th>Date</th><th>Start</th><th>End</th><th class="num">Break</th><th class="num">Net hrs</th><th>Type</th><th>Location</th><th>Status</th><th>Result</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $raw = json_decode($r['raw_json'], true) ?: [];
      $cls = ['error' => 'row-err', 'duplicate' => 'row-dup', 'warning' => 'row-warn'][$r['result']] ?? '';
      $rawv = fn ($k) => is_scalar($raw[$k] ?? null) ? (string) $raw[$k] : ''; ?>
    <tr class="<?= $cls ?>">
      <td><?= e($r['row_no']) ?></td><td><span class="idc"><?= e($r['employee_code']) ?></span> <?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></td>
      <td><?= e($r['start_at'] ? uk_date(\App\Services\WeekBoundary::ukDateOfUtc($r['start_at'])) : $rawv('date')) ?></td>
      <td><?= e($r['start_at'] ? uk_time($r['start_at']) : $rawv('start')) ?></td>
      <td><?= e($r['end_at'] ? uk_time($r['end_at']) : $rawv('end')) ?><?= $r['start_at'] && from_utc($r['end_at'])->format('Y-m-d') !== from_utc($r['start_at'])->format('Y-m-d') ? ' <small>(+1)</small>' : '' ?></td>
      <td class="num"><?= e($r['break_minutes']) ?></td><td class="num"><?= e($r['net_minutes'] === null ? '—' : hours((int) $r['net_minutes'])) ?></td>
      <td><?= e($r['type_code'] ?: $rawv('shift_type')) ?></td><td><?= e($r['location_name'] ?: $rawv('location')) ?></td><td><?= e(ucfirst((string) $r['status'])) ?></td>
      <td><?php if ($r['result'] === 'error'): ?><span class="text-danger small fw-semibold"><?= e($r['messages']) ?></span><?php elseif ($r['shift_id'] && in_array($r['result'], ['ready', 'warning'], true)): ?><span class="badge rounded-pill text-bg-info">Update</span><?= $r['result'] === 'warning' ? ' ' . status_badge('warning') : '' ?> <?= $r['messages'] ? '<small>' . e($r['messages']) . '</small>' : '' ?><?php else: ?><?= status_badge($r['result'] === 'duplicate' ? 'unchanged' : $r['result']) ?> <?= $r['messages'] ? '<small>' . e($r['messages']) . '</small>' : '' ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="11" class="empty">No rows for this filter.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
  <?php if ($pending): ?>
  <form method="post" action="<?= e($base . '/confirm') ?>" class="d-flex flex-column gap-2" data-confirm="Import these shifts now?">
    <?= csrf_field() ?>
    <?php if ($warnings): ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="approve_over_limit" name="approve_over_limit" value="1" data-reveal="#reason-box">
        <label class="form-check-label" for="approve_over_limit">Also import the <?= e($warnings) ?> rows that go over the weekly limit, and record an approval</label></div>
      <div id="reason-box"><input class="form-control form-control-sm" name="reason" placeholder="Reason for approving the extra hours" maxlength="200" aria-label="Reason"></div>
      <small class="text-muted-2">Without approval, over-limit rows are skipped.</small>
    <?php endif; ?>
    <small class="text-muted-2">Shifts already in the system are updated where the file differs; unchanged ones are left as they are.</small>
    <div><button class="btn btn-primary" type="submit">Import valid shifts</button></div>
  </form>
  <?php else: ?><span class="text-muted-2">Status: <?= status_badge($batch['status']) ?> <?= e(uk_datetime($batch['confirmed_at'])) ?></span><?php endif; ?>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= e($base . '/errors') ?>"><i class="bi bi-download"></i> Download error report</a>
    <?php if ($pending): ?><form method="post" action="<?= e($base . '/cancel') ?>"><?= csrf_field() ?><button class="btn btn-outline-danger">Cancel import</button></form><?php endif; ?>
  </div>
</div>
