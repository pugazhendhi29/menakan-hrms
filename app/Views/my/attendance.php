<div class="d-flex flex-wrap gap-2 align-items-end">
  <form class="toolbar" method="get" action="<?= e(url('/my/attendance')) ?>">
    <div><label class="form-label" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label class="form-label" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-sm btn-primary">Show</button>
  </form>
  <div class="ms-auto">
    <?php if ($open): ?>
      <form method="post" action="<?= e(url('/my/attendance/check-out')) ?>" class="d-flex gap-2 align-items-center"><?= csrf_field() ?><span class="text-success">Checked in at <?= e(uk_time($open['check_in_at'])) ?></span><button class="btn btn-primary">Check out</button></form>
    <?php else: ?>
      <form method="post" action="<?= e(url('/my/attendance/check-in')) ?>"><?= csrf_field() ?><button class="btn btn-hivis">Check in</button></form>
    <?php endif; ?>
  </div>
</div>
<div class="card"><div class="card-header">Attendance <span class="fw-normal text-muted-2"><?= e(hours($total, 2)) ?> net hours in this period</span></div><div class="table-responsive"><table class="table">
  <thead><tr><th>Date</th><th>In</th><th>Out</th><th class="num">Break</th><th class="num">Net hrs</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $a): ?>
    <tr><td><?= e(uk_date($a['work_date'])) ?></td><td><?= e(uk_time($a['check_in_at'])) ?></td><td><?= e(uk_time($a['check_out_at'])) ?></td><td class="num"><?= e($a['break_minutes']) ?></td>
      <td class="num"><?= e($a['net_minutes'] === null ? '—' : hours((int) $a['net_minutes'])) ?></td><td><?= status_badge($a['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">No attendance in this period.</td></tr><?php endif; ?>
</tbody></table></div></div>
<p class="small text-muted-2 mb-0">If a time is wrong (for example you forgot to check out), tell your manager or HR. They will correct it and the change is recorded.</p>
