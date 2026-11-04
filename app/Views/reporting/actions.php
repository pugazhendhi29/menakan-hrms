<?php
$labels = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];
?>
<div class="rp-bar">
  <nav class="period-tabs" aria-label="Filter actions">
    <?php foreach (['outstanding' => 'Outstanding', 'done' => 'Finished', 'all' => 'All'] as $k => $l): ?><a class="<?= $status === $k ? 'on' : '' ?>" href="<?= e(url('/reporting/actions?status=' . $k)) ?>"><?= e($l) ?></a><?php endforeach; ?>
  </nav>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Action</th><th>Assigned to</th><th>Raised by</th><th>Priority</th><th>Due</th><th>Report</th><th style="min-width:280px">Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): $overdue = $a['due_date'] && $a['due_date'] < uk_today() && in_array($a['status'], ['open', 'in_progress'], true); ?>
        <tr class="<?= $overdue ? 'rp-row-late' : '' ?>">
          <td><b><?= e($a['title']) ?></b><?php if ($a['details']): ?><small class="d-block text-muted-2" style="white-space:pre-line"><?= e($a['details']) ?></small><?php endif; ?>
            <?php if ($a['resolution_note']): ?><small class="d-block"><i class="bi bi-chat-left-text"></i> <?= e($a['resolution_note']) ?></small><?php endif; ?></td>
          <td><?= e(employee_name($a)) ?><?= (int) $a['assigned_to_employee_id'] === (int) $me ? ' <span class="badge text-bg-light">you</span>' : '' ?></td>
          <td><?= e(trim(($a['rb_first_name'] ?? '') . ' ' . ($a['rb_last_name'] ?? '')) ?: 'HR') ?></td>
          <td><span class="badge rounded-pill <?= ['critical' => 'rs rs-escalated', 'high' => 'rs rs-returned', 'medium' => 'rs rs-submitted', 'low' => 'rs rs-draft'][$a['priority']] ?>"><?= e(ucfirst($a['priority'])) ?></span></td>
          <td><?= e(uk_date($a['due_date'])) ?><?= $overdue ? ' <small class="text-danger">overdue</small>' : '' ?></td>
          <td><?= $a['report_id'] ? '<a href="' . e(url('/reporting/reports/' . $a['report_id'])) . '">' . e($a['report_no']) . '</a>' : '—' ?></td>
          <td>
            <?php if (App\Services\ReportService::canUpdateAction($a)): ?>
              <form method="post" action="<?= e(url('/reporting/actions/' . $a['id'])) ?>" class="d-flex flex-wrap gap-1">
                <?= csrf_field() ?>
                <select class="form-select form-select-sm" name="status" style="max-width:140px" aria-label="Status"><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $a['status']) ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <input class="form-control form-control-sm" name="resolution_note" placeholder="Note (required when done)" maxlength="1000" style="min-width:140px;flex:1">
                <button class="btn btn-sm btn-outline-primary" type="submit">Update</button>
              </form>
            <?php else: ?><?= e($labels[$a['status']]) ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty">No actions here.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
