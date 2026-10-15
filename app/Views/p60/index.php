<?php use App\Services\P60Service; ?>
<ul class="nav nav-tabs">
  <li class="nav-item"><a class="nav-link" href="<?= e(url('/payslips')) ?>">Payslips</a></li>
  <li class="nav-item"><a class="nav-link active" href="<?= e(url('/p60')) ?>" aria-current="page">P60</a></li>
</ul>
<form class="filters toolbar" method="get" action="<?= e(url('/p60')) ?>" role="search">
  <div class="flex-grow-1" style="min-width:220px"><label class="form-label" for="q">Search</label>
    <input class="form-control form-control-sm" id="q" name="q" value="<?= e($request->str('q')) ?>" placeholder="Employee name or Employee ID"></div>
  <div><label class="form-label" for="tax_year">Tax year</label>
    <select class="form-select form-select-sm" id="tax_year" name="tax_year" data-autosubmit><option value="">All</option>
      <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= selected($y, $request->str('tax_year')) ?>><?= e(P60Service::label($y)) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="status">Status</label>
    <select class="form-select form-select-sm" id="status" name="status" data-autosubmit>
      <?php foreach (['current' => 'Current', 'replaced' => 'Replaced', 'deleted' => 'Deleted', 'all' => 'All'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $status) ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
  <div class="ms-auto"><a class="btn btn-sm btn-hivis" href="<?= e(url('/p60/upload')) ?>"><i class="bi bi-upload"></i> Upload P60</a></div>
</form>
<p class="text-muted-2 small mb-0">A P60 covers one UK tax year (6 April to 5 April). These are the official documents from payroll; nothing here is generated automatically.</p>

<div class="card"><div class="table-responsive"><table class="table table-hover">
  <thead><tr><th>Employee Name</th><th>Employee ID</th><th>Tax Year</th><th>Emailed</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] === 'current' ? '' : 'text-muted-2' ?>">
      <td class="fw-semibold"><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['employee_name']) ?></td>
      <td class="idc"><?= e($r['employee_code']) ?></td>
      <td title="<?= e(P60Service::range((int) $r['tax_year_start'])) ?>"><?= e(P60Service::label((int) $r['tax_year_start'])) ?></td>
      <td class="small"><?php if ($r['emailed_at']): ?><span class="text-success" title="<?= e(uk_datetime($r['emailed_at'])) ?>"><i class="bi bi-envelope-check"></i> <?= e($r['emailed_to']) ?></span><?php elseif ($r['email_error']): ?><span class="text-danger" title="<?= e($r['email_error']) ?>"><i class="bi bi-envelope-x"></i> Not sent</span><?php else: ?><span class="text-muted-2">—</span><?php endif; ?></td>
      <td><?php if ($r['status'] === 'current'): ?><span class="badge text-bg-success">Current</span>
        <?php elseif ($r['status'] === 'replaced'): ?><span class="badge text-bg-secondary" title="Replaced <?= e(uk_datetime($r['replaced_at'])) ?>">Replaced</span>
        <?php else: ?><span class="badge text-bg-danger" title="<?= e($r['deleted_reason']) ?>">Deleted</span><?php endif; ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/p60/' . $r['id'])) ?>" target="_blank" rel="noopener">View</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/p60/' . $r['id'] . '?download=1')) ?>">Download</a>
        <?php if ($r['status'] === 'current'): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/p60/' . $r['id'] . '/replace')) ?>">Replace</a>
          <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#del-<?= e($r['id']) ?>" aria-expanded="false">Delete</button>
        <?php endif; ?>
      </td>
    </tr>
    <?php if ($r['status'] === 'current'): ?>
    <tr class="collapse" id="del-<?= e($r['id']) ?>"><td colspan="6" class="bg-light">
      <form method="post" action="<?= e(url('/p60/' . $r['id'] . '/delete')) ?>" class="d-flex flex-wrap gap-2 align-items-center" data-confirm="Delete this P60? The employee will no longer see it.">
        <?= csrf_field() ?>
        <label class="form-label mb-0" for="reason-<?= e($r['id']) ?>">Reason for deleting</label>
        <input class="form-control form-control-sm" style="max-width:360px" id="reason-<?= e($r['id']) ?>" name="reason" maxlength="255" required placeholder="e.g. Uploaded to the wrong employee">
        <button class="btn btn-sm btn-danger">Confirm delete</button>
      </form>
    </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">No P60 documents found<?= $request->str('q') || $request->str('tax_year') ? ' for this search' : '' ?>. <a href="<?= e(url('/p60/upload')) ?>">Upload a P60</a>.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
</div>
