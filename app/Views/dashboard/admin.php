<form class="toolbar no-print" method="get" action="<?= e(url('/dashboard')) ?>">
  <div>
    <label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit>
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $dept) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label" for="week">Hours week (any date in it)</label>
    <input class="form-control form-control-sm" type="date" id="week" name="week" value="<?= e($week) ?>" data-autosubmit>
  </div>
</form>

<div class="kpis">
  <a class="kpi" href="<?= e(url('/employees')) ?>"><small>Employees</small><b><?= e((int) $headcount['total']) ?></b><span class="badge text-bg-success"><?= e((int) $headcount['active']) ?> active</span></a>
  <a class="kpi ok-k" href="<?= e(url('/attendance?from=' . uk_today() . '&to=' . uk_today())) ?>"><small>Present today</small><b><?= e($present) ?></b><span class="badge text-bg-light border">of <?= e($rostered) ?> rostered</span></a>
  <a class="kpi alert-k" href="<?= e(url('/attendance/absences')) ?>"><small>Absent today</small><b><?= e($absentToday) ?></b><span class="badge text-bg-danger">unexplained</span></a>
  <a class="kpi warn-k" href="<?= e(url('/shifts/exceptions?week=' . $week)) ?>"><small>Over <?= e(setting('weekly_hours_limit')) ?>h</small><b><?= e(count($overLimit)) ?></b><span class="badge text-bg-warning">w/c <?= e(uk_date($week)) ?></span></a>
  <a class="kpi warn-k" href="<?= e(url('/leave?status=pending')) ?>"><small>Pending leave</small><b><?= e($pendingCount) ?></b><span class="badge text-bg-warning">to review</span></a>
  <a class="kpi alert-k" href="<?= e(url('/documents?status=expiring')) ?>"><small>Docs expiring</small><b><?= e(count($expiring)) ?></b><span class="badge text-bg-danger">≤ <?= e($warnDays) ?> days or expired</span></a>
  <a class="kpi alert-k" href="<?= e(url('/documents/matrix?only=missing')) ?>"><small>Docs missing</small><b><?= e($missingDocs) ?></b><span class="badge text-bg-secondary">required</span></a>
  <a class="kpi warn-k" href="<?= e(url('/attendance?status=incomplete')) ?>"><small>Incomplete sessions</small><b><?= e($incomplete) ?></b><span class="badge text-bg-warning">last 14 days</span></a>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card h-100">
      <div class="card-header">Weekly hours exceptions · w/c <?= e(uk_date($week)) ?> <a href="<?= e(url('/shifts/exceptions?week=' . $week)) ?>">Open report →</a></div>
      <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Employee</th><th>Dept</th><th class="num">Scheduled</th><th class="num">Limit</th><th class="num">Excess</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($overLimit, 0, 8) as $o): ?>
          <tr>
            <td><span class="idc"><?= e($o['employee_code']) ?></span> <?= e(employee_name($o)) ?></td>
            <td><?= e($o['department']) ?></td>
            <td class="num"><?= e(hours($o['scheduled'], 1)) ?>h</td>
            <td class="num"><?= e(hours($o['limit'], 1)) ?>h</td>
            <td class="num"><?= $o['exception'] ? '<span class="badge text-bg-warning">+' . e(hours($o['excess'], 1)) . 'h · approved</span>' : '<span class="badge text-bg-danger">+' . e(hours($o['excess'], 1)) . 'h</span>' ?></td>
            <td><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts?view=week&employee_id=' . $o['id'] . '&date=' . $week)) ?>"><?= $o['exception'] ? 'View' : 'Review' ?></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$overLimit): ?><tr><td colspan="6" class="empty">No one is over their weekly limit this week.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="card-header">Attendance · last 7 days</div>
      <div class="card-body" style="height:260px"><canvas data-chart="<?= e(json_encode($chart)) ?>" aria-label="Rostered versus attended employees per day for the last 7 days" role="img"></canvas></div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card h-100">
      <div class="card-header">Documents expiring soon <a href="<?= e(url('/documents?status=expiring')) ?>">All documents →</a></div>
      <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Employee</th><th>Document</th><th>Expires</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($expiring, 0, 8) as $d):
            $days = (int) floor((strtotime($d['expiry_date']) - strtotime(uk_today())) / 86400); ?>
          <tr>
            <td><a href="<?= e(url('/employees/' . $d['employee_id'] . '?tab=documents')) ?>"><?= e(employee_name($d)) ?></a></td>
            <td><?= e($d['category_name']) ?></td>
            <td class="num"><?= e(uk_date($d['expiry_date'])) ?></td>
            <td><?= $days < 0 ? '<span class="badge text-bg-danger">Expired ' . e(-$days) . ' days ago</span>' : '<span class="badge text-bg-' . ($days <= 14 ? 'danger' : 'warning') . '">' . e($days) . ' days</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$expiring): ?><tr><td colspan="4" class="empty">No documents expire in the next <?= e($warnDays) ?> days.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="card-header">Pending leave <a href="<?= e(url('/leave?status=pending')) ?>">Approve →</a></div>
      <table class="table">
        <tbody>
        <?php foreach ($pendingLeave as $l): ?>
          <tr>
            <td><a href="<?= e(url('/leave/' . $l['id'])) ?>"><?= e(employee_name($l)) ?></a><br><small class="text-muted-2"><?= e($l['type_name']) ?> · <?= e(uk_date($l['start_date'])) ?>–<?= e(uk_date($l['end_date'])) ?> · <?= e(days_label($l['days'])) ?></small></td>
            <td class="text-end"><?= $l['shift_clashes'] ? '<span class="badge text-bg-warning">Shift clash</span>' : '<span class="badge text-bg-info">Pending</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pendingLeave): ?><tr><td class="empty">No leave requests are waiting.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header">Absences today <a href="<?= e(url('/attendance/absences')) ?>">Absence report →</a></div>
      <table class="table">
        <thead><tr><th>Employee</th><th>Shift</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($absences as $a): ?>
          <tr><td><span class="idc"><?= e($a['employee_code']) ?></span> <?= e(employee_name($a)) ?></td><td><?= e(uk_time($a['start_at'])) ?>–<?= e(uk_time($a['end_at'])) ?></td><td><?= status_badge($a['day_status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$absences): ?><tr><td colspan="3" class="empty">No missed shifts so far today.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card">
      <div class="card-header">Headcount by department</div>
      <table class="table"><tbody>
        <?php foreach ($byDept as $b): ?><tr><td><?= e($b['name']) ?></td><td class="num"><?= e($b['n']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
</div>
