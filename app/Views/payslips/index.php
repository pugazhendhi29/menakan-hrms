<ul class="nav nav-tabs">
  <li class="nav-item"><a class="nav-link active" href="<?= e(url('/payslips')) ?>" aria-current="page">Payslips</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= e(url('/p60')) ?>">P60</a></li>
</ul>
<form class="filters toolbar" method="get" action="<?= e(url('/payslips')) ?>">
  <div><label class="form-label" for="month">Month</label>
    <select class="form-select form-select-sm" id="month" name="month" data-autosubmit><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= selected($m, $month) ?>><?= e(month_name($m)) ?></option><?php endfor; ?></select></div>
  <div><label class="form-label" for="year">Year</label>
    <select class="form-select form-select-sm" id="year" name="year" data-autosubmit><?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 4; $y--): ?><option value="<?= $y ?>"<?= selected($y, $year) ?>><?= $y ?></option><?php endfor; ?></select></div>
  <div><label class="form-label" for="status">Status</label>
    <select class="form-select form-select-sm" id="status" name="status" data-autosubmit><option value="">All</option><option value="draft"<?= selected('draft', $status) ?>>Draft</option><option value="published"<?= selected('published', $status) ?>>Published</option></select></div>
  <div class="ms-auto"><a class="btn btn-sm btn-hivis" href="<?= e(url('/payslips/upload')) ?>"><i class="bi bi-upload"></i> Upload payslips</a></div>
</form>
<form method="post" action="<?= e(url('/payslips/publish')) ?>">
  <?= csrf_field() ?><input type="hidden" name="year" value="<?= e($year) ?>"><input type="hidden" name="month" value="<?= e($month) ?>">
  <div class="card">
    <div class="card-header"><?= e(month_name($month) . ' ' . $year) ?> · <?= count($rows) ?> payslips
      <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send"></i> Publish selected drafts</button></div>
    <div class="table-responsive"><table class="table table-hover">
      <thead><tr><th><input class="form-check-input" type="checkbox" data-check-all="ids[]" aria-label="Select all"></th><th>Employee</th><th>Department</th><th>File</th><th>Emailed</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= e($p['id']) ?>"<?= $p['status'] !== 'draft' ? ' disabled' : '' ?> aria-label="Select"></td>
          <td><span class="idc"><?= e($p['employee_code']) ?></span> <?= e(employee_name($p)) ?></td><td><?= e($p['department']) ?></td>
          <td class="small"><?= e($p['original_filename']) ?></td>
          <td class="small"><?php if ($p['emailed_at']): ?><span class="text-success" title="<?= e(uk_datetime($p['emailed_at'])) ?>"><i class="bi bi-envelope-check"></i> <?= e($p['emailed_to']) ?></span><?php elseif ($p['email_error']): ?><span class="text-danger" title="<?= e($p['email_error']) ?>"><i class="bi bi-envelope-x"></i> Not sent</span><?php else: ?><span class="text-muted-2">—</span><?php endif; ?></td>
          <td><?= status_badge($p['status']) ?></td>
          <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'])) ?>" target="_blank" rel="noopener">View</a>
            <button class="btn btn-sm btn-outline-danger" form="archive-<?= e($p['id']) ?>">Archive</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty">No payslips for this period.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</form>
<?php foreach ($rows as $p): ?>
  <form id="archive-<?= e($p['id']) ?>" method="post" action="<?= e(url('/payslips/' . $p['id'] . '/archive')) ?>" data-confirm="Archive this payslip? The employee will no longer see it."><?= csrf_field() ?></form>
<?php endforeach; ?>
<?php if ($missing): ?>
<div class="card"><div class="card-header">Employed this month but no payslip yet (<?= count($missing) ?>)</div>
  <div class="card-body small"><?php foreach ($missing as $m): ?><a class="badge text-bg-light border text-decoration-none me-1 mb-1" href="<?= e(url('/payslips/upload?employee_id=' . $m['id'])) ?>"><?= e($m['employee_code'] . ' ' . employee_name($m)) ?></a><?php endforeach; ?></div></div>
<?php endif; ?>
