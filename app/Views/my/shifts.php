<div class="d-flex align-items-center gap-2">
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/my/shifts?date=' . $prev)) ?>" aria-label="Previous month">‹</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/my/shifts')) ?>">Today</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/my/shifts?date=' . $next)) ?>" aria-label="Next month">›</a>
  <h2 class="h4 mb-0 ms-2"><?= e($heading) ?></h2>
</div>
<div class="card overflow-hidden"><div class="table-responsive"><div class="cal" style="min-width:760px">
  <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $h): ?><div class="h"><?= $h ?></div><?php endforeach; ?><div class="h">Week total</div>
  <?php foreach ($weeks as $w): $tot = $totals[$w]; ?>
    <?php foreach (\App\Services\WeekBoundary::daysOfWeek($w) as $d): ?>
      <div class="d<?= (int) substr($d, 5, 2) !== $month ? ' out' : '' ?><?= $d === uk_today() ? ' today' : '' ?>">
        <span class="dn"><?= e((int) substr($d, 8, 2)) ?></span>
        <?php if (isset($holidays[$d])): ?><span class="sh bh" title="<?= e($holidays[$d]) ?>">Bank holiday</span><?php endif; ?>
        <?php if (isset($leaveDays[$d])): ?><span class="sh lv"><?= e($leaveDays[$d]) ?></span><?php endif; ?>
        <?php foreach ($byDay[$d] ?? [] as $s): ?><span class="sh<?= $s['is_night'] ? ' n' : '' ?>" title="<?= e(($s['type_name'] ?? '') . ' · ' . ($s['location_name'] ?? '')) ?>"><?= e(uk_time($s['start_at']) . '–' . uk_time($s['end_at'])) ?><?= $s['is_night'] ? ' ☾' : '' ?></span><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="wk<?= $tot > $limit ? ' over' : '' ?>"><b><?= e(hours($tot, 1)) ?>h</b></div>
  <?php endforeach; ?>
</div></div></div>
<div class="card"><div class="card-header">Shift list</div><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Location</th><th class="num">Break</th><th class="num">Net hrs</th></tr></thead><tbody>
  <?php foreach ($shifts as $s): ?><tr><td><?= e(from_utc($s['start_at'])->format('D d/m/Y')) ?></td><td><?= e(uk_time($s['start_at']) . '–' . uk_time($s['end_at'])) ?></td><td><?= e($s['type_name'] ?? '—') ?></td><td><?= e($s['location_name'] ?? '—') ?></td><td class="num"><?= e($s['break_minutes']) ?></td><td class="num"><?= e(hours((int) $s['net_minutes'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$shifts): ?><tr><td colspan="6" class="empty">No published shifts this month.</td></tr><?php endif; ?>
</tbody></table></div></div>
