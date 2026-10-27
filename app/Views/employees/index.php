<?php use App\Services\DocumentService; use App\Services\EmployeeService; ?>
<form class="filters toolbar" method="get" action="<?= e(url('/employees')) ?>">
  <div class="flex-grow-1" style="min-width:220px">
    <label class="form-label" for="q">Search</label>
    <input class="form-control form-control-sm" id="q" name="q" value="<?= e($request->str('q')) ?>" placeholder="Name, employee ID, email, phone">
  </div>
  <div>
    <label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit>
      <option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label" for="status">Status</label>
    <select class="form-select form-select-sm" id="status" name="status" data-autosubmit>
      <option value="current"<?= selected('current', $request->str('status', 'current')) ?>>Current (not left)</option>
      <option value="all"<?= selected('all', $request->str('status')) ?>>All</option>
      <?php foreach (EmployeeService::statuses(true) as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($k, $request->str('status')) ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label" for="job_title_id">Job title</label>
    <select class="form-select form-select-sm" id="job_title_id" name="job_title_id" data-autosubmit>
      <option value="">All</option>
      <?php foreach ($jobTitles as $j): ?><option value="<?= e($j['id']) ?>"<?= selected($j['id'], $request->str('job_title_id')) ?>><?= e($j['title']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label" for="documents">Documents</label>
    <select class="form-select form-select-sm" id="documents" name="documents" data-autosubmit>
      <option value="">Any</option>
      <option value="incomplete"<?= selected('incomplete', $request->str('documents')) ?>>Incomplete / expiring</option>
      <option value="complete"<?= selected('complete', $request->str('documents')) ?>>Complete</option>
    </select>
  </div>
  <div>
    <label class="form-label" for="sort">Sort</label>
    <select class="form-select form-select-sm" id="sort" name="sort" data-autosubmit>
      <?php foreach (['code' => 'Employee ID', 'name' => 'Name', 'department' => 'Department', 'start' => 'Start date', 'status' => 'Status'] as $k => $v): ?>
        <option value="<?= e($k) ?>"<?= selected($k, $request->str('sort', 'code')) ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($request->str('archived') === '1'): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
  <button class="btn btn-sm btn-primary" type="submit">Filter</button>
  <a class="btn btn-sm btn-link" href="<?= e(url('/employees')) ?>">Reset</a>
</form>

<div class="d-flex flex-wrap gap-2 justify-content-between">
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary<?= $request->str('archived') === '1' ? '' : ' active' ?>" href="<?= e(url('/employees')) ?>">Employees</a>
    <a class="btn btn-outline-secondary<?= $request->str('archived') === '1' ? ' active' : '' ?>" href="<?= e(url('/employees?archived=1&status=all')) ?>">Archived records</a>
  </div>
  <div class="d-flex gap-2">
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Export</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header">All fields, employees in this list</h6></li>
        <li><a class="dropdown-item" href="<?= e(url('/employees/export?' . http_build_query($request->query + ['format' => 'xlsx']))) ?>">Excel (.xlsx), re-importable</a></li>
        <li><a class="dropdown-item" href="<?= e(url('/employees/export?' . http_build_query($request->query + ['format' => 'csv']))) ?>">CSV</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><span class="dropdown-item-text small text-muted-2">Includes identity and pay data. Exports are logged.</span></li>
      </ul>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/employees/import')) ?>"><i class="bi bi-upload"></i> Import</a>
    <a class="btn btn-sm btn-hivis" href="<?= e(url('/employees/create')) ?>"><i class="bi bi-plus-lg"></i> New employee</a>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
  <table class="table table-hover">
    <thead><tr><th>Employee ID</th><th>Name</th><th>Designation</th><th>Department</th><th>Start date</th><th>Status</th><th>Manager</th><th>Documents</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$cls, $label] = DocumentService::complianceLabel($r['compliance']); ?>
      <tr>
        <td class="idc"><?= e($r['employee_code']) ?></td>
        <td><a class="fw-semibold text-decoration-none d-inline-flex align-items-center gap-2" href="<?= e(url('/employees/' . $r['id'])) ?>"><?= employee_avatar($r, 28) ?><?= e(employee_name($r)) ?></a></td>
        <td><?= e($r['job_title']) ?></td>
        <td><?= e($r['department']) ?></td>
        <td class="num"><?= e(uk_date($r['start_date'])) ?></td>
        <td><?= $r['status'] === 'left' ? '<span class="badge text-bg-secondary">Left ' . e(uk_date($r['end_date'])) . '</span>' : employee_status_badge($r['status']) ?></td>
        <td><?= e(trim(($r['m_first_name'] ?? '') . ' ' . ($r['m_last_name'] ?? '')) ?: '—') ?></td>
        <td><?= $r['status'] === 'left' ? '<span class="badge text-bg-light border">Archived</span>' : '<span class="badge text-bg-' . $cls . '">' . e($label) . '</span>' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/employees/' . $r['id'])) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">No employees match these filters.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
</div>
