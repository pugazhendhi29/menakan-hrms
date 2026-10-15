<?php
$q = fn (array $o) => e(url('/shifts?' . http_build_query(array_filter(array_merge(['view' => $view, 'date' => $date] + $filters, $o)))));
$chip = function (array $s, bool $over = false, bool $showName = false): string {
    $cls = $s['status'] === 'draft' ? 'sh dr' : ($over ? 'sh x' : ($s['is_night'] ? 'sh n' : 'sh'));
    $label = uk_time($s['start_at']) . '–' . uk_time($s['end_at']) . ($s['is_night'] ? ' ☾' : '');
    if ($showName) {
        $label = $s['first_name'] . ' ' . mb_substr($s['last_name'], 0, 1) . ' · ' . $label;
    }
    $title = employee_name($s) . ' · ' . ($s['type_name'] ?? 'Shift') . ' · ' . ($s['location_name'] ?? '') . ' · ' . hours((int) $s['net_minutes']) . 'h net · ' . ucfirst($s['status']);
    return '<a class="' . $cls . '" href="' . e(url('/shifts/' . $s['id'] . '/edit')) . '" title="' . e($title) . '">' . e($label) . '</a>';
};
?>
<form class="filters toolbar" method="get" action="<?= e(url('/shifts')) ?>">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div><label class="form-label" for="date">Date</label><input class="form-control form-control-sm" type="date" id="date" name="date" value="<?= e($date) ?>" data-autosubmit></div>
  <div><label class="form-label" for="employee_id">Employee</label>
    <select class="form-select form-select-sm" id="employee_id" name="employee_id" data-autosubmit><option value="">All employees</option>
      <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], $filters['employee_id']) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="department_id">Department</label>
    <select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $filters['department_id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="location_id">Location</label>
    <select class="form-select form-select-sm" id="location_id" name="location_id" data-autosubmit><option value="">All</option>
      <?php foreach ($locations as $l): ?><option value="<?= e($l['id']) ?>"<?= selected($l['id'], $filters['location_id']) ?>><?= e($l['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <div class="btn-group btn-group-sm" role="group" aria-label="View">
      <a class="btn btn-outline-secondary<?= $view === 'day' ? ' active' : '' ?>" href="<?= $q(['view' => 'day']) ?>">Day</a>
      <a class="btn btn-outline-secondary<?= $view === 'week' ? ' active' : '' ?>" href="<?= $q(['view' => 'week']) ?>">Week roster</a>
      <a class="btn btn-outline-secondary<?= $view === 'month' ? ' active' : '' ?>" href="<?= $q(['view' => 'month']) ?>">Month</a>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts/export?' . http_build_query(['from' => $view === 'month' ? $weeks[0] ?? $date : ($week ?? $date), 'to' => $view === 'month' ? date('Y-m-d', strtotime(end($weeks) . ' +6 days')) : ($view === 'week' ? $days[6] : $date)] + array_filter($filters)))) ?>"><i class="bi bi-download"></i> Export Excel</a>
    <a class="btn btn-sm btn-hivis" href="<?= e(url('/shifts/create?' . http_build_query(['employee_id' => $filters['employee_id'], 'date' => $date]))) ?>">+ Add shift</a>
  </div>
</form>

<div class="d-flex align-items-center gap-2">
  <a class="btn btn-sm btn-outline-secondary" href="<?= $q(['date' => $prev]) ?>" aria-label="Previous">‹</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= $q(['date' => uk_today()]) ?>">Today</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= $q(['date' => $next]) ?>" aria-label="Next">›</a>
  <h2 class="h4 mb-0 ms-2"><?= e($heading) ?><?= $employee ? ' · ' . e(employee_name($employee)) . ' <span class="idc text-muted-2">' . e($employee['employee_code']) . '</span>' : '' ?></h2>
  <?php if ($view === 'week'): ?>
  <form class="ms-auto" method="post" action="<?= e(url('/shifts/publish')) ?>" data-confirm="Publish all draft shifts in this week<?= $filters['department_id'] ? ' for this department' : '' ?>? Employees will be notified.">
    <?= csrf_field() ?><input type="hidden" name="date" value="<?= e($week) ?>"><input type="hidden" name="department_id" value="<?= e($filters['department_id']) ?>"><input type="hidden" name="employee_id" value="<?= e($filters['employee_id']) ?>">
    <button class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Publish week's drafts</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($view === 'day'): ?>
<div class="card"><div class="table-responsive"><table class="table">
  <thead><tr><th>Time</th><th>Employee</th><th>Department</th><th>Type</th><th>Location</th><th class="num">Net hrs</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($shifts as $s): ?>
    <tr><td class="text-nowrap"><?= e(uk_time($s['start_at']) . '–' . uk_time($s['end_at'])) ?><?= $s['is_night'] ? ' ☾' : '' ?></td><td><span class="idc"><?= e($s['employee_code']) ?></span> <?= e(employee_name($s)) ?></td>
      <td><?= e($s['department']) ?></td><td><?= e($s['type_name'] ?: '—') ?></td><td><?= e($s['location_name'] ?: '—') ?></td><td class="num"><?= e(hours((int) $s['net_minutes'])) ?></td><td><?= status_badge($s['status']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts/' . $s['id'] . '/edit')) ?>">Edit</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$shifts): ?><tr><td colspan="8" class="empty">No shifts on this day.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($view === 'week'): ?>
<div class="card"><div class="table-responsive"><table class="table table-sm roster align-top">
  <thead><tr><th>Employee</th>
    <?php foreach ($days as $d): ?><th class="<?= $d === uk_today() ? 'text-dark' : '' ?>"><?= e(date('D d/m', strtotime($d))) ?><?= isset($holidays[$d]) ? ' <span class="badge text-bg-light border">BH</span>' : '' ?></th><?php endforeach; ?>
    <th class="num">Week total</th></tr></thead>
  <tbody>
  <?php foreach ($people as $p): $over = $p['minutes'] > $p['limit']; $approved = isset($exceptions[$p['id']]); ?>
    <tr>
      <td><a href="<?= e(url('/shifts?view=month&employee_id=' . $p['id'] . '&date=' . $week)) ?>"><?= e(employee_name($p)) ?></a><br><small class="text-muted-2"><?= e($p['employee_code']) ?> · <?= e($p['department']) ?></small></td>
      <?php foreach ($days as $d): ?>
        <td>
          <?php foreach ($p['cells'][$d] as $s) echo $chip($s, $over && !$approved); ?>
          <?php if (isset($p['leave'][$d])): ?><span class="sh lv"><?= e($p['leave'][$d]) ?></span><?php endif; ?>
          <?php if (!$p['cells'][$d] && !isset($p['leave'][$d])): ?><a class="text-muted-2 small text-decoration-none" href="<?= e(url('/shifts/create?employee_id=' . $p['id'] . '&date=' . $d)) ?>" aria-label="Add shift">+</a><?php endif; ?>
        </td>
      <?php endforeach; ?>
      <td class="num text-nowrap"><b class="<?= $over ? ($approved ? 'text-warning' : 'text-danger') : '' ?>"><?= e(hours($p['minutes'], 1)) ?>h</b><br>
        <small class="text-muted-2">limit <?= e(hours($p['limit'], 1)) ?></small><?= $over ? '<br><span class="badge text-bg-' . ($approved ? 'warning">approved' : 'danger">+' . e(hours($p['minutes'] - $p['limit'], 1)) . 'h over') . '</span>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$people): ?><tr><td colspan="9" class="empty">No employees match.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php else: ?>
<div class="card overflow-hidden"><div class="table-responsive"><div class="cal" style="min-width:860px">
  <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $h): ?><div class="h"><?= $h ?></div><?php endforeach; ?><div class="h"><?= $employee ? 'Week total' : 'Scheduled' ?></div>
  <?php foreach ($weeks as $w): $tot = $weekTotals[$w]; $over = $employee && $tot['minutes'] > $limit; ?>
    <?php foreach (\App\Services\WeekBoundary::daysOfWeek($w) as $d): $out = (int) substr($d, 5, 2) !== $month; $list = $byDay[$d] ?? []; ?>
      <div class="d<?= $out ? ' out' : '' ?><?= $d === uk_today() ? ' today' : '' ?>">
        <span class="dn"><?= e((int) substr($d, 8, 2)) ?></span>
        <?php if (isset($holidays[$d])): ?><span class="sh bh" title="<?= e($holidays[$d]) ?>">Bank holiday</span><?php endif; ?>
        <?php if (isset($leaveDays[$d])): ?><span class="sh lv"><?= e($leaveDays[$d]) ?></span><?php endif; ?>
        <?php foreach (array_slice($list, 0, $employee ? 4 : 3) as $s) echo $chip($s, $over, !$employee); ?>
        <?php if (!$employee && count($list) > 3): ?><a class="small" href="<?= $q(['view' => 'day', 'date' => $d]) ?>">+<?= count($list) - 3 ?> more</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="wk<?= $over ? ' over' : '' ?>"><b><?= e(hours($tot['minutes'], 1)) ?>h</b><small><?= e($tot['count']) ?> shift<?= $tot['count'] === 1 ? '' : 's' ?></small>
      <?php if ($over): ?><span class="badge text-bg-danger">+<?= e(hours($tot['minutes'] - $limit, 1)) ?>h over</span><?php endif; ?></div>
  <?php endforeach; ?>
</div></div></div>
<?php endif; ?>
<div class="d-flex flex-wrap gap-2 align-items-center small">
  <span class="sh d-inline-block">Day shift</span><span class="sh n d-inline-block">Night, crosses midnight ☾</span><span class="sh dr d-inline-block">Draft</span><span class="sh lv d-inline-block">Approved leave</span><span class="sh x d-inline-block">In over-limit week</span>
  <span class="text-muted-2">Week totals are net of unpaid breaks and use Monday–Sunday weeks, so days from neighbouring months count towards the row they sit in. A shift counts in the week it starts.</span>
</div>
