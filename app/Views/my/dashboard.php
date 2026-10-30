<?php
/** Employee dashboard. Expects $me, $todayShift, $open, $weekMinutes, $limit, $exception, $leave, $upcoming, $payslips, $requests, $compliance. */
$over = $weekMinutes > $limit;
$hour = (int) uk_now()->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$latest = $payslips[0] ?? null;
$docsAttention = $compliance['missing'] || $compliance['expired'] || $compliance['expiring'];
?>
<div class="hello">
  <div>
    <h1><?= e($greeting) ?>, <?= e($me['first_name']) ?>!</h1>
    <p>Here's your overview for today. Stay safe and keep up the great work!</p>
  </div>
  <div class="hello-side">
    <?= App\Core\View::partial('time_weather', []) ?>
  </div>
</div>

<div class="stats">
  <a class="stat stat-alert" href="<?= e(url('/my/attendance')) ?>">
    <span class="stat-icon"><i class="bi bi-clock"></i></span>
    <span class="stat-body"><small>Today's attendance</small>
      <b><?= $open ? e(uk_time($open['check_in_at'])) : '—' ?></b>
      <?php if ($open): ?><span class="stat-pill<?= $open['status'] === 'late' ? ' warn' : '' ?>"><?= $open['status'] === 'late' ? 'Clocked in · late' : 'Clocked in · on time' ?></span>
      <?php else: ?><span class="stat-note"><?= $todayShift ? 'Shift starts ' . e(uk_time($todayShift['start_at'])) : 'No shift rostered today' ?></span><?php endif; ?>
    </span>
  </a>
  <a class="stat<?= $over ? ' stat-warn' : '' ?>" href="<?= e(url('/my/shifts')) ?>" style="--tone:#2563c9;--tone-soft:rgba(37,99,201,.12)">
    <span class="stat-icon"><i class="bi bi-calendar3-week"></i></span>
    <span class="stat-body"><small>This week</small><b><?= e(hours($weekMinutes, 1)) ?>h</b>
      <?php if ($over): ?><span class="stat-pill warn"><?= $exception ? 'Extra hours approved' : 'Manager reviewing' ?></span>
      <?php else: ?><span class="stat-note">Limit <?= e(hours($limit, 1)) ?>h</span><?php endif; ?>
    </span>
  </a>
  <a class="stat stat-ok" href="<?= e(url('/my/leave')) ?>">
    <span class="stat-icon"><i class="bi bi-sun"></i></span>
    <span class="stat-body"><small>Leave balance</small><b><?= e(days_fmt($leave['remaining'])) ?> days</b>
      <span class="stat-note">Annual leave<?= $leave['pending'] > 0 ? ' · ' . e(days_fmt($leave['pending'])) . ' pending' : '' ?></span>
    </span>
  </a>
  <a class="stat" href="<?= e(url('/my/payslips')) ?>" style="--tone:#c47d06;--tone-soft:rgba(245,158,11,.15)">
    <span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
    <span class="stat-body"><small>Latest payslip</small><b><?= $latest ? e(mb_substr(month_name((int) $latest['period_month']), 0, 3) . ' ' . $latest['period_year']) : '—' ?></b>
      <span class="stat-note"><?= $latest ? 'View or download' : 'No payslips yet' ?></span>
    </span>
  </a>
</div>

<div class="row g-3">
  <div class="col-xl-8 d-flex flex-column gap-3">
    <div class="card">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-clock-history"></i></span><h2>Today<?= $todayShift ? ' · ' . e($todayShift['type_name'] ?: 'Shift') : '' ?></h2></div>
      <div class="card-body d-flex flex-wrap align-items-center gap-3 pt-2">
        <div class="flex-grow-1">
          <?php if ($todayShift): ?>
            <div class="h4 mb-1"><?= e(uk_time($todayShift['start_at']) . ' – ' . uk_time($todayShift['end_at'])) ?></div>
            <div class="text-muted-2"><?= e($todayShift['location_name'] ?: 'No location set') ?> · <?= e($todayShift['break_minutes']) ?> min break</div>
          <?php else: ?>
            <div class="h5 mb-1">No shift rostered today</div>
          <?php endif; ?>
          <?php if ($open): ?><div class="text-success small mt-1"><i class="bi bi-check-circle"></i> Checked in at <?= e(uk_time($open['check_in_at'])) ?><?= $open['status'] === 'late' ? ' (late)' : '' ?></div><?php endif; ?>
          <small class="text-muted-2 d-block mt-1">The time is taken from the server clock.</small>
        </div>
        <?php if ($open): ?>
          <form method="post" action="<?= e(url('/my/attendance/check-out')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-lg px-4"><i class="bi bi-clock"></i> Check out</button></form>
        <?php else: ?>
          <form method="post" action="<?= e(url('/my/attendance/check-in')) ?>"><?= csrf_field() ?><button class="btn btn-hivis btn-lg px-4"><i class="bi bi-clock"></i> Check in</button></form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-lightning"></i></span><h2>Quick actions</h2></div>
      <div class="qa-grid">
        <a class="qa green" href="<?= e(url('/my/leave')) ?>"><span class="qa-ico"><i class="bi bi-sun"></i></span><b>Apply for leave</b><small>Request time off</small><i class="bi bi-arrow-right qa-go"></i></a>
        <a class="qa" href="<?= e(url('/my/payslips')) ?>"><span class="qa-ico"><i class="bi bi-cash-stack"></i></span><b>View payslips</b><small>Salary &amp; deductions</small><i class="bi bi-arrow-right qa-go"></i></a>
        <a class="qa blue" href="<?= e(url('/my/shifts')) ?>"><span class="qa-ico"><i class="bi bi-calendar3-week"></i></span><b>My shifts</b><small>Rota calendar</small><i class="bi bi-arrow-right qa-go"></i></a>
        <a class="qa amber" href="<?= e(url('/my/documents')) ?>"><span class="qa-ico"><i class="bi bi-folder2-open"></i></span><b>My documents</b><small>Licences &amp; ID</small><i class="bi bi-arrow-right qa-go"></i></a>
        <a class="qa purple" href="<?= e(url('/my/news')) ?>"><span class="qa-ico"><i class="bi bi-megaphone"></i></span><b>Company news</b><small>Announcements</small><i class="bi bi-arrow-right qa-go"></i></a>
        <a class="qa blue" href="<?= e(url('/my/id-card')) ?>"><span class="qa-ico"><i class="bi bi-person-badge"></i></span><b>My ID card</b><small>Show your card</small><i class="bi bi-arrow-right qa-go"></i></a>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="card h-100">
          <div class="dash-head"><span class="dash-ico"><i class="bi bi-cash-stack"></i></span><h2>My payslips</h2><a class="ms-auto" href="<?= e(url('/my/payslips')) ?>">View all</a></div>
          <?php if ($payslips): ?>
          <ul class="dash-list">
            <?php foreach ($payslips as $p): ?>
              <li><span class="file-badge"><i class="bi bi-file-earmark-pdf"></i></span><div class="dash-main"><b><?= e(month_name((int) $p['period_month']) . ' ' . $p['period_year']) ?></b><small>Payslip PDF</small></div>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'])) ?>" target="_blank" rel="noopener" aria-label="View"><i class="bi bi-eye"></i></a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'] . '?download=1')) ?>" aria-label="Download PDF"><i class="bi bi-download"></i></a></li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?><div class="dash-empty"><i class="bi bi-inbox"></i><span>No payslips yet.</span></div><?php endif; ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="dash-head"><span class="dash-ico"><i class="bi bi-sun"></i></span><h2>My leave requests</h2><a class="ms-auto" href="<?= e(url('/my/leave')) ?>">Request leave</a></div>
          <?php if ($requests): ?>
          <ul class="dash-list">
            <?php foreach ($requests as $r): ?>
              <li><div class="dash-main"><b><?= e($r['type_name']) ?></b><small><?= e(uk_date($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? '–' . e(uk_date($r['end_date'])) : '' ?> · <?= e(days_label($r['days'])) ?></small></div><?= status_badge($r['status']) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?><div class="dash-empty"><i class="bi bi-sun"></i><span>No leave requests.</span></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 d-flex flex-column gap-3">
    <div class="card">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-calendar3"></i></span><h2>Upcoming shifts</h2><a class="ms-auto" href="<?= e(url('/my/shifts')) ?>">View all</a></div>
      <?php if ($upcoming): ?>
      <ul class="date-rows">
        <?php foreach ($upcoming as $s): $d = from_utc($s['start_at']); ?>
          <li><span class="date-badge"><b><?= e($d->format('j')) ?></b><small><?= e($d->format('M')) ?></small></span>
            <div class="dash-main"><b><?= e($d->format('l')) ?> · <?= e(uk_time($s['start_at']) . '–' . uk_time($s['end_at'])) ?><?= $s['is_night'] ? ' ☾' : '' ?></b><small><?= e($s['location_name'] ?? 'No location') ?></small></div>
            <span class="badge text-bg-info"><?= e(hours((int) $s['net_minutes'])) ?>h</span></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><div class="dash-empty"><i class="bi bi-calendar3"></i><span>No published shifts in the next two weeks.</span></div><?php endif; ?>
    </div>

    <?php if ($docsAttention): ?>
    <div class="card">
      <div class="dash-head"><span class="dash-ico dash-ico-alert"><i class="bi bi-exclamation-triangle"></i></span><h2>Documents need attention</h2></div>
      <div class="card-body pt-2"><p class="mb-2 text-muted-2">Some of your documents are missing, expired or expiring soon. Please bring the originals to HR.</p><a class="btn btn-sm btn-hivis" href="<?= e(url('/my/documents')) ?>">See my documents</a></div>
    </div>
    <?php endif; ?>

    <div class="promo" aria-hidden="true">
      <div><b>Safe Drivers<br>Efficient Operations<br>Stronger Together</b><small>Menakan Transport Ltd</small></div>
    </div>
  </div>
</div>

<p class="small text-muted-2 mb-0"><i class="bi bi-info-circle"></i> Your portal is read-only apart from checking in/out and requesting leave. To change your details, contact HR.</p>
