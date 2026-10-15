<div class="kpis">
  <div class="kpi"><small>Entitlement <?= e($summary['year']) ?></small><b><?= e(days_fmt($summary['entitlement'])) ?></b><span class="badge text-bg-light border">includes <?= e(days_fmt($summary['bank_holidays'])) ?> bank holidays</span></div>
  <div class="kpi ok-k"><small>Remaining</small><b><?= e(days_fmt($summary['remaining'])) ?></b></div>
  <div class="kpi warn-k"><small>Pending approval</small><b><?= e(days_fmt($summary['pending'])) ?></b></div>
  <div class="kpi"><small>Available to request</small><b><?= e(days_fmt($summary['available'])) ?></b></div>
</div>
<div class="row g-3">
  <div class="col-xl-6">
    <?= App\Core\View::render('leave/form', ['types' => $types, 'admin' => false, 'action' => url('/my/leave'), 'employees' => [], 'employeeId' => null], null) ?>
    <div class="card mt-3"><div class="card-header">Upcoming bank holidays</div><ul class="list-group list-group-flush">
      <?php foreach ($holidays as $h): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($h['name']) ?></span><span><?= e(uk_date($h['holiday_date'])) ?></span></li><?php endforeach; ?>
    </ul></div>
  </div>
  <div class="col-xl-6">
    <div class="card"><div class="card-header">My requests</div><div class="table-responsive"><table class="table">
      <thead><tr><th>Type</th><th>Dates</th><th class="num">Days</th><th>Requested</th><th>Approved</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($requests as $r): ?>
        <tr><td><?= e($r['type_name']) ?></td><td class="text-nowrap"><?= e(uk_date($r['start_date'])) ?>–<?= e(uk_date($r['end_date'])) ?></td><td class="num"><?= e(days_fmt($r['days'])) ?></td><td class="text-nowrap"><?= e(uk_date($r['requested_on'])) ?></td><td class="text-nowrap"><?= e(uk_date($r['approved_on'])) ?></td>
          <td><?= status_badge($r['status']) ?><?= $r['decision_note'] && $r['status'] === 'rejected' ? '<br><small>' . e($r['decision_note']) . '</small>' : '' ?></td>
          <td class="text-end"><?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= e(url('/my/leave/' . $r['id'] . '/cancel')) ?>" data-confirm="Cancel this request?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Cancel</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="7" class="empty">No requests yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
</div>
