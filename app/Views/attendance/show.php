<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><?= e($a['employee_code'] . ' ' . employee_name($a)) ?> · <?= e(uk_date($a['work_date'])) ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/attendance/' . $a['id'] . '/edit')) ?>">Correct</a></div>
      <div class="card-body"><dl class="dl-grid">
        <dt>Check in</dt><dd><?= e(uk_datetime($a['check_in_at'])) ?></dd>
        <dt>Check out</dt><dd><?= e(uk_datetime($a['check_out_at'])) ?></dd>
        <dt>Unpaid break</dt><dd><?= e($a['break_minutes']) ?> minutes</dd>
        <dt>Net hours</dt><dd><?= e(hours($a['net_minutes'] === null ? null : (int) $a['net_minutes'])) ?></dd>
        <dt>Status</dt><dd><?= status_badge($a['status']) ?></dd>
        <dt>Linked shift</dt><dd><?= $a['shift_start'] ? e(uk_datetime($a['shift_start']) . '–' . uk_time($a['shift_end'])) : 'None' ?></dd>
        <dt>Source</dt><dd><?= e(ucfirst($a['source'])) ?><?= $a['import_batch_id'] ? ' · <a href="' . e(url('/attendance-import/' . $a['import_batch_id'])) . '">batch #' . e($a['import_batch_id']) . '</a>' : '' ?></dd>
        <dt>Approved</dt><dd><?= $a['is_approved'] ? 'Yes · ' . e($a['approved_by_name'] ?? '') . ' ' . e(uk_datetime($a['approved_at'])) : 'No' ?></dd>
        <dt>Notes</dt><dd><?= e($a['notes'] ?: '—') ?></dd>
      </dl></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">Change history</div>
      <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>When</th><th>Field</th><th>Old</th><th>New</th><th>Reason</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($corrections as $c):
            $fmt = fn ($v) => in_array($c['field'], ['check_in_at', 'check_out_at'], true) && $v ? uk_datetime($v) : ($c['field'] === 'work_date' && $v ? uk_date($v) : $v); ?>
          <tr><td class="text-nowrap"><?= e(uk_datetime($c['changed_at'])) ?></td><td><?= e(str_replace('_', ' ', $c['field'])) ?></td><td><?= e($fmt($c['old_value']) ?? '—') ?></td><td><?= e($fmt($c['new_value']) ?? '—') ?></td><td><?= e($c['reason']) ?></td><td><?= e($c['username']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$corrections): ?><tr><td colspan="6" class="empty">No corrections.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
