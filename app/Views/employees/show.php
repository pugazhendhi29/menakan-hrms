<?php
use App\Services\DocumentService;
use App\Services\EmployeeService;

[$cCls, $cLabel] = DocumentService::complianceLabel($compliance);
$base = url('/employees/' . $e['id']);
?>
<?php if ($e['deleted_at']): ?>
  <div class="alert alert-warning d-flex justify-content-between align-items-center">
    <span>This record was archived on <?= e(uk_datetime($e['deleted_at'])) ?>. It is kept for audit and retention.</span>
    <form method="post" action="<?= e($base . '/restore') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark">Restore</button></form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <?= employee_avatar($e, 56) ?>
    <div class="flex-grow-1">
      <div class="h4 mb-0"><?= e(employee_name($e)) ?> <span class="idc text-muted-2"><?= e($e['employee_code']) ?></span></div>
      <div class="text-muted-2"><?= e($e['job_title']) ?> · <?= e($e['department']) ?> · started <?= e(uk_date($e['start_date'])) ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?= status_badge($e['status']) ?>
      <span class="badge text-bg-<?= e($cCls) ?>">Documents <?= e($cLabel) ?></span>
    </div>
    <?php if (!$e['deleted_at']): ?>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-primary" href="<?= e($base . '/edit') ?>"><i class="bi bi-pencil"></i> Edit</a>
      <form method="post" action="<?= e($base . '/delete') ?>" data-confirm="Archive this employee? Their account will be deactivated. The record is kept and can be restored."><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Archive</button></form>
    </div>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs flex-nowrap overflow-auto">
  <?php foreach ($tabs as $k => $label): ?>
    <li class="nav-item"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" href="<?= e($base . '?tab=' . $k) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
<div class="kpis">
  <div class="kpi<?= $weekMinutes > $limitMinutes ? ' alert-k' : '' ?>"><small>Scheduled this week</small><b><?= e(hours($weekMinutes, 1)) ?>h</b><span class="badge text-bg-light border">limit <?= e(hours($limitMinutes, 1)) ?>h</span></div>
  <?php if ($leaveSummary): ?>
  <div class="kpi"><small>Annual leave remaining</small><b><?= e(days_fmt($leaveSummary['remaining'])) ?></b><span class="badge text-bg-light border">of <?= e(days_fmt($leaveSummary['entitlement'])) ?> · <?= e(days_fmt($leaveSummary['pending'])) ?> pending</span></div>
  <?php endif; ?>
  <div class="kpi"><small>Line manager</small><b style="font-size:1.1rem"><?= e(trim(($e['m_first_name'] ?? '') . ' ' . ($e['m_last_name'] ?? '')) ?: '—') ?></b></div>
  <div class="kpi"><small>Portal account</small><b style="font-size:1.1rem"><?= $account ? e($account['username']) : 'None' ?></b><?= $account ? ($account['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>') : '' ?></div>
</div>
<div class="card">
  <div class="card-header">Document checklist <a href="<?= e($base . '?tab=documents') ?>">Manage documents →</a></div>
  <table class="table"><thead><tr><th>Required document</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($compliance['rows'] as $row): ?>
    <tr><td><?= e($row['category']['name']) ?></td><td><?= e($row['document'] ? uk_date($row['document']['expiry_date']) : '—') ?></td><td><?= status_badge($row['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$compliance['rows']): ?><tr><td colspan="3" class="empty">No documents are required for this role.</td></tr><?php endif; ?>
  </tbody></table>
</div>

<?php elseif ($tab === 'personal'): ?>
<?= App\Core\View::render('employees/_personal', get_defined_vars(), null) ?>

<?php elseif ($tab === 'employment'): ?>
<div class="card"><div class="card-body"><dl class="dl-grid">
  <dt>Employee ID</dt><dd class="idc"><?= e($e['employee_code']) ?></dd>
  <dt>Department</dt><dd><?= e($e['department']) ?></dd>
  <dt>Designation</dt><dd><?= e($e['job_title']) ?></dd>
  <dt>Location</dt><dd><?= e($e['location_name'] ?: '—') ?></dd>
  <dt>Reporting manager</dt><dd><?= e($e['m_code'] ? $e['m_code'] . ' ' . $e['m_first_name'] . ' ' . $e['m_last_name'] : ($e['reporting_manager_name'] ?: '—')) ?></dd>
  <dt>Employment type</dt><dd><?= e(EmployeeService::TYPES[$e['employment_type']] ?? $e['employment_type']) ?></dd>
  <dt>Contract hours</dt><dd><?= e($e['contract_hours'] !== null ? $e['contract_hours'] . ' per week' : '—') ?></dd>
  <dt>Weekly hours limit</dt><dd><?= e(hours($limitMinutes, 1)) ?>h <small class="text-muted-2"><?= $e['weekly_hours_limit'] !== null ? '(employee override)' : ($e['contract_hours'] !== null ? '(from contract)' : '(company default)') ?></small></dd>
  <dt>Date of joining</dt><dd><?= e(uk_date($e['start_date'])) ?></dd>
  <dt>Probation ends</dt><dd><?= e(uk_date($e['probation_end_date'])) ?></dd>
  <dt>Date of exit</dt><dd><?= e(uk_date($e['end_date'])) ?><?= $e['leaving_reason'] ? ' · ' . e($e['leaving_reason']) : '' ?></dd>
  <dt>Status</dt><dd><?= status_badge($e['status']) ?></dd>
  <dt>Salary <span class="badge text-bg-danger">Admin</span></dt><dd><?= e(money($e['salary'])) ?> <?= e(EmployeeService::PAY_FREQ[$e['pay_frequency']] ?? '') ?></dd>
  <dt>Payroll reference</dt><dd><?= e($e['payroll_reference'] ?: '—') ?></dd>
  <dt>Notes</dt><dd><?= nl2br(e($e['notes'] ?: '—')) ?></dd>
</dl></div></div>

<?php elseif ($tab === 'documents'): ?>
<div class="d-flex justify-content-end"><a class="btn btn-sm btn-hivis" href="<?= e(url('/documents/upload?employee_id=' . $e['id'])) ?>"><i class="bi bi-upload"></i> Upload document</a></div>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Category</th><th>Title</th><th>Number</th><th>Expiry</th><th>Status</th><th>Uploaded</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($documents as $d): $status = $d['is_current'] ? DocumentService::status($d) : 'archived'; ?>
    <tr class="<?= $d['is_current'] ? '' : 'text-muted-2' ?>">
      <td><?= e($d['category_name']) ?></td><td><?= e($d['title']) ?></td><td><?= e($d['document_number'] ?: '—') ?></td>
      <td><?= e(uk_date($d['expiry_date'])) ?></td><td><?= status_badge($status) ?></td><td><?= e(uk_datetime($d['created_at'])) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/document/' . $d['id'])) ?>" target="_blank" rel="noopener">View</a>
        <?php if ($d['is_current']): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/documents/' . $d['id'] . '/replace')) ?>">Replace</a>
          <?php if ($d['verification'] !== 'verified'): ?>
          <form class="d-inline" method="post" action="<?= e(url('/documents/' . $d['id'] . '/verify')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="verified"><input type="hidden" name="back" value="employee"><button class="btn btn-sm btn-outline-success">Verify</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$documents): ?><tr><td colspan="7" class="empty">No documents uploaded yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($tab === 'attendance'): ?>
<div class="d-flex justify-content-end gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/attendance?employee_id=' . $e['id'])) ?>">Open in attendance</a><a class="btn btn-sm btn-hivis" href="<?= e(url('/attendance/create?employee_id=' . $e['id'])) ?>">+ Add record</a></div>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Date</th><th>In</th><th>Out</th><th class="num">Break</th><th class="num">Net hrs</th><th>Status</th><th>Source</th><th>Approved</th></tr></thead>
  <tbody>
  <?php foreach ($attendance as $a): ?>
    <tr><td><a href="<?= e(url('/attendance/' . $a['id'])) ?>"><?= e(uk_date($a['work_date'])) ?></a></td><td><?= e(uk_time($a['check_in_at'])) ?></td><td><?= e(uk_time($a['check_out_at'])) ?></td>
      <td class="num"><?= e($a['break_minutes']) ?></td><td class="num"><?= e(hours($a['net_minutes'])) ?></td><td><?= status_badge($a['status']) ?></td><td><?= e(ucfirst($a['source'])) ?></td><td><?= $a['is_approved'] ? '<i class="bi bi-check-circle text-success"></i>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$attendance): ?><tr><td colspan="8" class="empty">No attendance recorded.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($tab === 'shifts'): ?>
<div class="d-flex justify-content-end gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts?view=month&employee_id=' . $e['id'])) ?>">Calendar</a><a class="btn btn-sm btn-hivis" href="<?= e(url('/shifts/create?employee_id=' . $e['id'])) ?>">+ Add shift</a></div>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Location</th><th class="num">Net hrs</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach (array_reverse($shifts) as $s): ?>
    <tr><td><?= e(uk_date(from_utc($s['start_at'])->format('Y-m-d'))) ?></td><td><?= e(uk_time($s['start_at'])) ?>–<?= e(uk_time($s['end_at'])) ?><?= $s['is_night'] ? ' ☾' : '' ?></td><td><?= e($s['type_name'] ?: '—') ?></td><td><?= e($s['location_name'] ?: '—') ?></td>
      <td class="num"><?= e(hours((int) $s['net_minutes'])) ?></td><td><?= status_badge($s['status']) ?></td><td class="text-end"><?php if ($s['status'] !== 'cancelled'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts/' . $s['id'] . '/edit')) ?>">Edit</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$shifts): ?><tr><td colspan="7" class="empty">No shifts in the last 4 weeks or next 5 weeks.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($tab === 'leave'): ?>
<?php if ($leaveSummary): ?>
<div class="kpis">
  <div class="kpi"><small>Entitlement <?= e($leaveSummary['year']) ?></small><b><?= e(days_fmt($leaveSummary['entitlement'])) ?></b><span class="badge text-bg-light border">incl. <?= e(days_fmt($leaveSummary['bank_holidays'])) ?> bank holidays</span></div>
  <div class="kpi ok-k"><small>Remaining</small><b><?= e(days_fmt($leaveSummary['remaining'])) ?></b></div>
  <div class="kpi warn-k"><small>Pending</small><b><?= e(days_fmt($leaveSummary['pending'])) ?></b></div>
  <div class="kpi"><small>Available to book</small><b><?= e(days_fmt($leaveSummary['available'])) ?></b></div>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card"><div class="card-header">Requests <a href="<?= e(url('/leave/create?employee_id=' . $e['id'])) ?>">+ Book leave</a></div><div class="table-responsive"><table class="table">
      <thead><tr><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($requests as $r): ?>
        <tr><td><a href="<?= e(url('/leave/' . $r['id'])) ?>"><?= e($r['type_name']) ?></a></td><td><?= e(uk_date($r['start_date'])) ?>–<?= e(uk_date($r['end_date'])) ?></td><td class="num"><?= e(days_fmt($r['days'])) ?></td><td><?= status_badge($r['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="4" class="empty">No leave requests.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header">Adjust balance</div><div class="card-body">
      <form method="post" action="<?= e($base . '/leave/adjust') ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-4"><label class="form-label" for="adj_days">Days (+/−)</label><input class="form-control form-control-sm" id="adj_days" name="days" placeholder="e.g. 2 or -0.5" required></div>
        <div class="col-8"><label class="form-label" for="adj_note">Reason</label><input class="form-control form-control-sm" id="adj_note" name="note" maxlength="255" required></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Add adjustment</button></div>
      </form>
      <form method="post" action="<?= e($base . '/leave/recalculate') ?>" class="mt-2" data-confirm="Recalculate this year's entitlement and bank holiday entries from the current settings?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Recalculate entitlement</button></form>
    </div></div>
  </div>
</div>
<div class="card"><div class="card-header">Leave ledger (every balance change)</div><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>Year</th><th>Type</th><th>Entry</th><th class="num">Days</th><th>Note</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($ledger as $l): ?>
    <tr><td><?= e($l['leave_year']) ?></td><td><?= e($l['type_name']) ?></td><td><?= e(ucfirst(str_replace('_', ' ', $l['entry_type']))) ?></td><td class="num <?= $l['days'] < 0 ? 'text-danger' : 'text-success' ?>"><?= e(($l['days'] > 0 ? '+' : '') . days_fmt($l['days'])) ?></td><td><?= e($l['note']) ?></td><td><?= e(uk_datetime($l['created_at'])) ?></td></tr>
  <?php endforeach; ?>
</tbody></table></div></div>

<?php elseif ($tab === 'payslips'): ?>
<div class="d-flex justify-content-end"><a class="btn btn-sm btn-hivis" href="<?= e(url('/payslips/upload?employee_id=' . $e['id'])) ?>"><i class="bi bi-upload"></i> Upload payslip</a></div>
<div class="card"><table class="table">
  <thead><tr><th>Pay period</th><th>Status</th><th>Uploaded</th><th>Published</th><th></th></tr></thead><tbody>
  <?php foreach ($payslips as $p): ?>
    <tr class="<?= $p['is_current'] ? '' : 'text-muted-2' ?>"><td><?= e(month_name((int) $p['period_month']) . ' ' . $p['period_year']) ?></td><td><?= status_badge($p['status']) ?></td><td><?= e(uk_datetime($p['created_at'])) ?></td><td><?= e(uk_datetime($p['published_at'])) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'])) ?>" target="_blank" rel="noopener">View</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$payslips): ?><tr><td colspan="5" class="empty">No payslips uploaded.</td></tr><?php endif; ?>
</tbody></table></div>

<?php elseif ($tab === 'account'): ?>
<div class="card"><div class="card-header">Portal accounts</div><table class="table">
  <thead><tr><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>
  <?php foreach ($accounts as $a): ?>
    <tr><td><?= e($a['username']) ?></td><td><?= e(ucfirst($a['role'])) ?></td><td><?= $a['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?><?= $a['password_hash'] ? '' : ' <span class="badge text-bg-warning">Not activated</span>' ?></td>
      <td><?= e(uk_datetime($a['last_login_at'])) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/users/' . $a['id'] . '/edit')) ?>">Manage</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$accounts): ?><tr><td colspan="5" class="empty">This employee has no portal account.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if (!array_filter($accounts, fn ($a) => $a['is_active']) && $e['status'] !== 'left' && !$e['deleted_at']): ?>
<div class="card"><div class="card-header">Create portal account</div><div class="card-body">
  <form method="post" action="<?= e($base . '/account') ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-sm-5"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e(strtolower(mb_substr($e['first_name'], 0, 1) . '.' . preg_replace('/[^a-z]/i', '', $e['last_name']))) ?>" required></div>
    <div class="col-sm-7"><button class="btn btn-primary">Create account and activation link</button></div>
    <div class="col-12 form-text">The employee sets their own password with a one-time link (valid 48 hours). In development the link is shown here and written to storage/logs/mail.log.</div>
  </form>
</div></div>
<?php endif; ?>

<?php elseif ($tab === 'idcard'): ?>
<?= App\Core\View::render('employees/_idcard', ['e' => $e, 'card' => $card, 'cardHistory' => $cardHistory], null) ?>
<?php endif; ?>
