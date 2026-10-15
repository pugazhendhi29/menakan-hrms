<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><?= e($r['type_name']) ?> · <?= e($r['employee_code'] . ' ' . employee_name($r)) ?> <?= status_badge($r['status']) ?></div>
      <div class="card-body"><dl class="dl-grid">
        <dt>Dates</dt><dd><?= e(uk_date($r['start_date'])) ?><?= $r['start_half'] ? ' (afternoon)' : '' ?> to <?= e(uk_date($r['end_date'])) ?><?= $r['end_half'] ? ' (morning)' : '' ?></dd>
        <dt>Working days</dt><dd><?= e(days_label($r['days'])) ?> <small class="text-muted-2">(weekends, non-working days and bank holidays excluded)</small></dd>
        <dt>Reason</dt><dd><?= e($r['reason'] ?: '—') ?></dd>
        <dt>Requested date</dt><dd><?= e(uk_date($r['requested_on'])) ?> <small class="text-muted-2">(entered <?= e(uk_datetime($r['created_at'])) ?> by <?= e($r['requested_by_name'] ?? '—') ?>)</small></dd>
        <dt>Approved date</dt><dd><?= $r['approved_on'] ? e(uk_date($r['approved_on'])) : '—' ?></dd>
        <?php if ($r['decided_at']): ?><dt>Decision</dt><dd><?= e(ucfirst($r['status'])) ?> by <?= e($r['decided_by_name'] ?? '—') ?> on <?= e(uk_datetime($r['decided_at'])) ?><?= $r['decision_note'] ? ' · ' . e($r['decision_note']) : '' ?></dd><?php endif; ?>
      </dl></div>
    </div>
    <?php if ($r['status'] === 'pending'): ?>
      <?php if ($own): ?>
        <div class="alert alert-info">This is your own request. Another administrator must approve or reject it.</div>
      <?php else: ?>
      <div class="card"><div class="card-header">Decision</div><div class="card-body">
        <form method="post" class="d-flex flex-column gap-2">
          <?= csrf_field() ?>
          <div class="row g-2">
            <div class="col-sm-8"><label class="form-label" for="note">Note to the employee (required when rejecting)</label><input class="form-control" id="note" name="note" maxlength="500"></div>
            <div class="col-sm-4"><label class="form-label" for="approved_on">Approved Date</label><input class="form-control" type="date" id="approved_on" name="approved_on" value="<?= e(uk_today()) ?>" min="<?= e($r['requested_on'] ?? '') ?>" max="<?= e(uk_today()) ?>"></div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-success" formaction="<?= e(url('/leave/' . $r['id'] . '/approve')) ?>">Approve</button>
            <button class="btn btn-outline-danger" formaction="<?= e(url('/leave/' . $r['id'] . '/reject')) ?>">Reject</button>
          </div>
        </form>
      </div></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?>
      <form method="post" action="<?= e(url('/leave/' . $r['id'] . '/cancel')) ?>" data-confirm="Cancel this leave? Deducted days will be returned to the balance."><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Cancel this leave</button></form>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <?php if ($summary): ?>
    <div class="card mb-3"><div class="card-header">Annual leave <?= e($summary['year']) ?></div><div class="card-body"><dl class="dl-grid">
      <dt>Entitlement</dt><dd><?= e(days_fmt($summary['entitlement'])) ?> days</dd>
      <dt>Remaining</dt><dd><?= e(days_fmt($summary['remaining'])) ?> days</dd>
      <dt>Pending requests</dt><dd><?= e(days_fmt($summary['pending'])) ?> days</dd>
    </dl></div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header">Rostered shifts in this period <?= $clashes ? '<span class="badge text-bg-warning">' . count($clashes) . ' clash</span>' : '' ?></div>
      <ul class="list-group list-group-flush">
      <?php foreach ($clashes as $s): ?><li class="list-group-item d-flex justify-content-between"><span><?= e(uk_datetime($s['start_at'])) ?>–<?= e(uk_time($s['end_at'])) ?></span><span><?= status_badge($s['status']) ?> <a href="<?= e(url('/shifts/' . $s['id'] . '/edit')) ?>">Edit</a></span></li><?php endforeach; ?>
      <?php if (!$clashes): ?><li class="list-group-item text-muted-2">No shifts are rostered on these days.</li><?php endif; ?>
      </ul></div>
    <div class="card"><div class="card-header">Others in the department already off</div>
      <ul class="list-group list-group-flush">
      <?php foreach ($overlapping as $o): ?><li class="list-group-item"><?= e(employee_name($o)) ?> · <?= e($o['type_name']) ?> · <?= e(uk_date($o['start_date'])) ?>–<?= e(uk_date($o['end_date'])) ?></li><?php endforeach; ?>
      <?php if (!$overlapping): ?><li class="list-group-item text-muted-2">Nobody else in the department is off.</li><?php endif; ?>
      </ul></div>
  </div>
</div>
