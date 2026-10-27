<form class="filters toolbar" method="get" action="<?= e(url('/attendance')) ?>">
  <div><label class="form-label" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
  <div><label class="form-label" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  <div><label class="form-label" for="employee_id">Employee</label>
    <select class="form-select form-select-sm" id="employee_id" name="employee_id"><option value="">All</option>
      <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], $request->str('employee_id')) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id"><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $request->str('department_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="status">Status</label>
    <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
      <?php foreach (['present' => 'Present', 'late' => 'Late', 'incomplete' => 'Incomplete'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $request->str('status')) ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="source">Source</label>
    <select class="form-select form-select-sm" id="source" name="source"><option value="">All</option>
      <?php foreach (['web' => 'Web check-in', 'import' => 'Import', 'manual' => 'Manual', 'rota' => 'From rota'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $request->str('source')) ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="approved">Approved</label>
    <select class="form-select form-select-sm" id="approved" name="approved"><option value="">Any</option><option value="0"<?= selected('0', $request->str('approved')) ?>>Not approved</option><option value="1"<?= selected('1', $request->str('approved')) ?>>Approved</option></select></div>
  <button class="btn btn-sm btn-primary">Filter</button>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/attendance/absences')) ?>">Absences</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/attendance/export?' . http_build_query($request->query + ['format' => 'xlsx']))) ?>"><i class="bi bi-download"></i> Excel</a>
    <a class="btn btn-sm btn-hivis" href="<?= e(url('/attendance/create')) ?>">+ Add record</a>
  </div>
</form>
<div class="kpis">
  <div class="kpi"><small>Records</small><b><?= e($totals['n']) ?></b></div>
  <div class="kpi"><small>Net hours</small><b><?= e(hours((int) $totals['mins'], 1)) ?></b></div>
  <div class="kpi warn-k"><small>Late</small><b><?= e((int) $totals['late']) ?></b></div>
  <div class="kpi alert-k"><small>Incomplete</small><b><?= e((int) $totals['incomplete']) ?></b></div>
</div>
<form method="post" action="<?= e(url('/attendance/approve')) ?>">
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e('/attendance' . ($request->query ? '?' . http_build_query($request->query) : '')) ?>">
  <div class="card"><div class="table-responsive"><table class="table table-hover">
    <thead><tr><th><input class="form-check-input" type="checkbox" data-check-all="ids[]" aria-label="Select all"></th><th>Employee</th><th>Date</th><th>Shift</th><th>In</th><th>Out</th><th class="num">Break</th><th class="num">Net hrs</th><th>Status</th><th>Source</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= e($a['id']) ?>"<?= $a['is_approved'] || !$a['check_out_at'] ? ' disabled' : '' ?> aria-label="Select record"></td>
        <td><span class="idc"><?= e($a['employee_code']) ?></span> <?= e(employee_name($a)) ?></td>
        <td class="text-nowrap"><?= e(uk_date($a['work_date'])) ?></td>
        <td class="text-nowrap text-muted-2"><?= $a['shift_start'] ? e(uk_time($a['shift_start']) . '–' . uk_time($a['shift_end'])) : '—' ?></td>
        <td><?= e(uk_time($a['check_in_at'])) ?></td>
        <td><?= e(uk_time($a['check_out_at'])) ?><?= $a['check_out_at'] && from_utc($a['check_out_at'])->format('Y-m-d') !== $a['work_date'] ? ' <small>(+1)</small>' : '' ?></td>
        <td class="num"><?= e($a['break_minutes']) ?></td><td class="num"><?= e(hours($a['net_minutes'] === null ? null : (int) $a['net_minutes'])) ?></td>
        <td><?= status_badge($a['status']) ?><?= $a['is_approved'] ? ' <i class="bi bi-check-circle text-success" title="Approved"></i>' : '' ?></td>
        <td><?= $a['source'] === 'rota' ? '<span class="badge text-bg-info">From rota</span>' : e(ucfirst($a['source'])) ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/attendance/' . $a['id'])) ?>">View</a> <?php if ($a['locked']): ?><span class="btn btn-sm btn-outline-secondary disabled" title="From the rota: can only be changed on a leave day"><i class="bi bi-lock"></i></span><?php else: ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/attendance/' . $a['id'] . '/edit')) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="empty">No attendance in this period.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="px-3 py-2 border-top"><button class="btn btn-sm btn-primary" type="submit">Approve selected</button> <small class="text-muted-2 ms-2">Approved records can't be overwritten by imports.</small></div>
  <?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
  </div>
</form>
