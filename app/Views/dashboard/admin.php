<?php
use App\Services\VisaReminder;

$hour = (int) uk_now()->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$deptName = '';
foreach ($departments as $d) {
    if ((int) $d['id'] === (int) $dept) {
        $deptName = $d['name'];
    }
}
$rate = $rostered ? (int) round(min($present, $rostered) / $rostered * 100) : null;
$visaStages = array_count_values(array_map(fn ($v) => (string) $v['stage'], $visas));

// A stat tile. $tone (alert|warn|ok) only shows when there is something to look at; zero stays calm.
$tile = function (string $href, string $icon, string $label, int|string $value, string $note, string $tone = '', string $extra = ''): string {
    $tone = ((int) $value > 0 || $tone === 'ok') ? $tone : 'calm';
    return '<a class="stat' . ($tone ? ' stat-' . $tone : '') . '" href="' . e($href) . '"><span class="stat-icon"><i class="bi bi-' . e($icon) . '"></i></span>'
        . '<span class="stat-body"><small>' . e($label) . '</small><b>' . e($value) . '</b><span class="stat-note">' . $note . '</span>' . $extra . '</span></a>';
};
// A card header: icon, title, count and a link.
$head = function (string $icon, string $title, ?int $count = null, string $href = '', string $link = '', string $tone = 'brand'): string {
    return '<div class="dash-head"><span class="dash-ico dash-ico-' . e($tone) . '"><i class="bi bi-' . e($icon) . '"></i></span><h2>' . e($title) . '</h2>'
        . ($count !== null ? '<span class="dash-count">' . e($count) . '</span>' : '')
        . ($href ? '<a class="ms-auto" href="' . e($href) . '">' . e($link) . ' <i class="bi bi-arrow-right"></i></a>' : '') . '</div>';
};
$empty = fn (string $icon, string $text) => '<div class="dash-empty"><i class="bi bi-' . e($icon) . '"></i><span>' . e($text) . '</span></div>';
?>
<div class="hello">
  <div>
    <h1><?= e($greeting) ?>, <?= e(explode(' ', App\Core\Auth::displayName())[0]) ?>!</h1>
    <p>Here's today's overview<?= $deptName ? ' for ' . e($deptName) : ' across all departments' ?>. Keep everyone safe and on the road.</p>
  </div>
  <div class="hello-side">
    <?= App\Core\View::partial('time_weather', []) ?>
  </div>
</div>

<form class="dash-filter toolbar no-print" method="get" action="<?= e(url('/dashboard')) ?>">
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

<section aria-labelledby="dash-today">
  <h2 class="dash-label" id="dash-today">Today</h2>
  <div class="stats">
    <?= $tile(url('/employees'), 'people', 'Employees', (int) $headcount['total'], e((int) $headcount['active']) . ' active', 'ok') ?>
    <?= $tile(url('/attendance?from=' . uk_today() . '&to=' . uk_today()), 'person-check', 'Present today', $present, 'of ' . e($rostered) . ' rostered' . ($rate !== null ? ' · ' . e($rate) . '%' : ''), 'ok',
        $rate !== null ? '<span class="stat-bar" role="presentation"><i style="width:' . $rate . '%"></i></span>' : '') ?>
    <?= $tile(url('/attendance/absences'), 'person-x', 'Absent today', $absentToday, 'unexplained', 'alert') ?>
    <?= $tile(url('/attendance?status=incomplete'), 'hourglass-split', 'Incomplete sessions', $incomplete, 'last 14 days', 'warn') ?>
  </div>
</section>

<section aria-labelledby="dash-action">
  <h2 class="dash-label" id="dash-action">Needs attention</h2>
  <div class="stats">
    <?= $tile(url('/leave?status=pending'), 'calendar2-check', 'Pending leave', $pendingCount, 'to review', 'warn') ?>
    <?= $tile(url('/shifts/exceptions?week=' . $week), 'stopwatch', 'Over ' . setting('weekly_hours_limit') . 'h', count($overLimit), 'w/c ' . e(uk_date($week)), 'warn') ?>
    <?= $tile('#visa-reminders', 'passport', 'Visas expiring', count($visas), '≤ 90 days or expired', 'alert') ?>
    <?= $tile(url('/documents?status=expiring'), 'file-earmark-text', 'Docs expiring', count($expiring), '≤ ' . e($warnDays) . ' days or expired', 'alert') ?>
    <?= $tile(url('/documents/matrix?only=missing'), 'file-earmark-x', 'Docs missing', $missingDocs, 'required', 'alert') ?>
  </div>
</section>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card h-100">
      <?= $head('bar-chart-line', 'Attendance · last 7 days') ?>
      <div class="card-body" style="height:280px"><canvas data-chart="<?= e(json_encode($chart)) ?>" aria-label="Rostered versus attended employees per day for the last 7 days" role="img"></canvas></div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100">
      <?= $head('person-x', 'Absences today', count($absences), url('/attendance/absences'), 'Report', $absences ? 'alert' : 'ok') ?>
      <?php if ($absences): ?>
      <ul class="dash-list dash-scroll">
        <?php foreach ($absences as $a): ?>
          <li><?= employee_avatar($a, 34) ?><div class="dash-main"><b><?= e(employee_name($a)) ?></b><small><span class="idc"><?= e($a['employee_code']) ?></span> Shift <?= e(uk_time($a['start_at'])) ?>–<?= e(uk_time($a['end_at'])) ?></small></div><?= status_badge($a['day_status']) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><?= $empty('check2-circle', 'No missed shifts so far today.') ?><?php endif; ?>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card h-100">
      <?= $head('stopwatch', 'Weekly hours exceptions · w/c ' . uk_date($week), count($overLimit), url('/shifts/exceptions?week=' . $week), 'Open report', $overLimit ? 'warn' : 'ok') ?>
      <?php if ($overLimit): ?>
      <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Employee</th><th>Dept</th><th class="num">Scheduled</th><th class="num">Limit</th><th class="num">Excess</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($overLimit, 0, 8) as $o): ?>
          <tr>
            <td><div class="dash-who"><?= employee_avatar($o, 30) ?><span><?= e(employee_name($o)) ?><small class="d-block idc-plain"><?= e($o['employee_code']) ?></small></span></div></td>
            <td><?= e($o['department']) ?></td>
            <td class="num"><?= e(hours($o['scheduled'], 1)) ?>h</td>
            <td class="num"><?= e(hours($o['limit'], 1)) ?>h</td>
            <td class="num"><?= $o['exception'] ? '<span class="badge text-bg-warning">+' . e(hours($o['excess'], 1)) . 'h · approved</span>' : '<span class="badge text-bg-danger">+' . e(hours($o['excess'], 1)) . 'h</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/shifts?view=week&employee_id=' . $o['id'] . '&date=' . $week)) ?>"><?= $o['exception'] ? 'View' : 'Review' ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?><?= $empty('check2-circle', 'No one is over their weekly limit this week.') ?><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card h-100">
      <?= $head('calendar2-check', 'Pending leave', $pendingCount, url('/leave?status=pending'), 'Approve', $pendingCount ? 'warn' : 'ok') ?>
      <?php if ($pendingLeave): ?>
      <ul class="dash-list">
        <?php foreach ($pendingLeave as $l): ?>
          <li><?= employee_avatar($l, 34) ?>
            <div class="dash-main"><a href="<?= e(url('/leave/' . $l['id'])) ?>"><b><?= e(employee_name($l)) ?></b></a><small><?= e($l['type_name']) ?> · <?= e(uk_date($l['start_date'])) ?>–<?= e(uk_date($l['end_date'])) ?> · <?= e(days_label($l['days'])) ?></small></div>
            <?= $l['shift_clashes'] ? '<span class="badge text-bg-warning">Shift clash</span>' : '<span class="badge text-bg-info">Pending</span>' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><?= $empty('inbox', 'No leave requests are waiting.') ?><?php endif; ?>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card h-100" id="visa-reminders">
      <?= $head('passport', 'Visa expiry reminders', count($visas), '', '', $visas ? 'alert' : 'ok') ?>
      <div class="dash-stages">
        <?php foreach ([90 => 'info', 60 => 'warning', 30 => 'danger', 0 => 'danger'] as $s => $cls): ?>
          <span class="dash-stage dash-stage-<?= $cls ?>"><b><?= e($visaStages[(string) $s] ?? 0) ?></b><?= $s ? e($s) . ' days' : 'Expired' ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($visas): ?>
      <ul class="dash-list dash-scroll">
        <?php foreach ($visas as $v):
            $cls = match ($v['stage']) { 0, 30 => 'danger', 60 => 'warning', default => 'info' }; ?>
          <li><?= employee_avatar($v, 34) ?>
            <div class="dash-main"><a href="<?= e(url('/employees/' . $v['id'])) ?>"><b><?= e(employee_name($v)) ?></b></a><small><?= e($v['visa_type'] ?: 'Visa') ?> · ends <?= e(uk_date($v['visa_end_date'])) ?></small></div>
            <div class="dash-side"><span class="badge text-bg-<?= $cls ?>"><?= e(VisaReminder::stageLabel($v['stage'])) ?></span><small><?= $v['days_left'] < 0 ? e(days_label(-$v['days_left'])) . ' ago' : e(days_label($v['days_left'])) . ' left' ?></small></div></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><?= $empty('shield-check', 'No visas end in the next 90 days.') ?><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <?= $head('file-earmark-text', 'Documents expiring soon', count($expiring), url('/documents?status=expiring'), 'All documents', $expiring ? 'alert' : 'ok') ?>
      <?php if ($expiring): ?>
      <ul class="dash-list dash-scroll">
        <?php foreach (array_slice($expiring, 0, 8) as $d):
            $days = (int) floor((strtotime($d['expiry_date']) - strtotime(uk_today())) / 86400); ?>
          <li><?= employee_avatar(['first_name' => $d['first_name'], 'last_name' => $d['last_name']], 34) ?>
            <div class="dash-main"><a href="<?= e(url('/employees/' . $d['employee_id'] . '?tab=documents')) ?>"><b><?= e(employee_name($d)) ?></b></a><small><?= e($d['category_name']) ?> · expires <?= e(uk_date($d['expiry_date'])) ?></small></div>
            <?= $days < 0 ? '<span class="badge text-bg-danger">Expired ' . e(days_label(-$days)) . ' ago</span>' : '<span class="badge text-bg-' . ($days <= 14 ? 'danger' : 'warning') . '">' . e(days_label($days)) . '</span>' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><?= $empty('shield-check', 'No documents expire in the next ' . $warnDays . ' days.') ?><?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <?= $head('diagram-3', 'Headcount by department', (int) $headcount['total']) ?>
      <?php $maxDept = max(1, ...array_map(fn ($b) => (int) $b['n'], $byDept ?: [['n' => 1]])); ?>
      <div class="dash-depts">
        <?php foreach ($byDept as $b): ?>
          <div class="dash-dept"><span><?= e($b['name']) ?></span><b><?= e($b['n']) ?></b><span class="stat-bar"><i style="width:<?= (int) round((int) $b['n'] / $maxDept * 100) ?>%"></i></span></div>
        <?php endforeach; ?>
        <?php if (!$byDept): ?><?= $empty('diagram-3', 'No departments yet. Add them in Settings.') ?><?php endif; ?>
      </div>
    </div>
  </div>
</div>
