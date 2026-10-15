<?php $over = $weekMinutes > $limit; ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body d-flex flex-column gap-2">
      <small class="text-muted-2 text-uppercase" style="letter-spacing:.06em;font-size:.72rem">Today · <?= e(uk_now()->format('D d/m')) ?></small>
      <?php if ($todayShift): ?>
        <b class="h4 mb-0"><?= e(($todayShift['type_name'] ?: 'Shift') . ' ' . uk_time($todayShift['start_at']) . '–' . uk_time($todayShift['end_at'])) ?></b>
        <span><?= e($todayShift['location_name'] ?: '') ?> · <?= e($todayShift['break_minutes']) ?> min break</span>
      <?php else: ?>
        <b class="h4 mb-0">No shift rostered</b>
      <?php endif; ?>
      <?php if ($open): ?>
        <span class="text-success"><i class="bi bi-check-circle"></i> Checked in at <?= e(uk_time($open['check_in_at'])) ?><?= $open['status'] === 'late' ? ' (late)' : '' ?></span>
        <form method="post" action="<?= e(url('/my/attendance/check-out')) ?>"><?= csrf_field() ?><button class="btn btn-primary">Check out</button></form>
      <?php else: ?>
        <form method="post" action="<?= e(url('/my/attendance/check-in')) ?>"><?= csrf_field() ?><button class="btn btn-hivis">Check in</button></form>
      <?php endif; ?>
      <small class="text-muted-2">The time is taken from the server clock.</small>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="kpi h-100<?= $over ? ' alert-k' : '' ?>"><small>Scheduled this week</small><b class="<?= $over ? 'text-danger' : '' ?>"><?= e(hours($weekMinutes, 1)) ?>h</b>
      <?php if ($over): ?><span class="badge text-bg-warning"><?= $exception ? 'Extra hours approved by HR' : 'Your manager is reviewing' ?></span><?php else: ?><span class="badge text-bg-light border">limit <?= e(hours($limit, 1)) ?>h</span><?php endif; ?></div>
  </div>
  <div class="col-lg-4">
    <div class="kpi h-100"><small>Annual leave remaining</small><b><?= e(days_fmt($leave['remaining'])) ?> days</b>
      <span class="badge text-bg-light border">of <?= e(days_fmt($leave['entitlement'])) ?> · year ends <?= e(uk_date($leave['year_end'])) ?></span>
      <?php if ($leave['pending'] > 0): ?><span class="badge text-bg-info"><?= e(days_fmt($leave['pending'])) ?> pending</span><?php endif; ?></div>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header">Upcoming shifts <a href="<?= e(url('/my/shifts')) ?>">Calendar →</a></div>
      <table class="table"><tbody>
      <?php foreach ($upcoming as $s): ?>
        <tr><td><?= e(from_utc($s['start_at'])->format('D d/m')) ?></td><td><?= e(uk_time($s['start_at']) . '–' . uk_time($s['end_at'])) ?><?= $s['is_night'] ? ' ☾' : '' ?></td><td><?= e($s['location_name'] ?? '') ?></td><td class="num"><?= e(hours((int) $s['net_minutes'])) ?>h</td></tr>
      <?php endforeach; ?>
      <?php if (!$upcoming): ?><tr><td class="empty">No published shifts in the next two weeks.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
  <div class="col-lg-6">
    <div class="card mb-3"><div class="card-header">My payslips <a href="<?= e(url('/my/payslips')) ?>">All →</a></div>
      <table class="table"><tbody>
      <?php foreach ($payslips as $p): ?>
        <tr><td><b><?= e(month_name((int) $p['period_month']) . ' ' . $p['period_year']) ?></b></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'])) ?>" target="_blank" rel="noopener">View</a> <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'] . '?download=1')) ?>">Download PDF</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$payslips): ?><tr><td class="empty">No payslips yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    <div class="card"><div class="card-header">My leave requests <a href="<?= e(url('/my/leave')) ?>">+ Request leave</a></div>
      <table class="table"><tbody>
      <?php foreach ($requests as $r): ?>
        <tr><td><?= e($r['type_name']) ?> · <?= e(uk_date($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? '–' . e(uk_date($r['end_date'])) : '' ?><br><small class="text-muted-2"><?= e(days_label($r['days'])) ?></small></td><td class="text-end"><?= status_badge($r['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td class="empty">No leave requests.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
</div>
<?php if ($compliance['missing'] || $compliance['expired'] || $compliance['expiring']): ?>
  <div class="alert alert-warning mb-0">Some of your documents need attention (missing, expired or expiring soon). Please bring the originals to HR. <a href="<?= e(url('/my/documents')) ?>">See my documents</a></div>
<?php endif; ?>
<div class="alert alert-light border small mb-0">Your portal is read-only apart from checking in/out and requesting leave. To change your details, contact HR.</div>
