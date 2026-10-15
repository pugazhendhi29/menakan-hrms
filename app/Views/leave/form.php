<div class="card" style="max-width:760px">
  <div class="card-header"><?= $admin ? 'Book leave for an employee' : 'Request leave' ?></div>
  <div class="card-body">
    <form method="post" action="<?= e($action) ?>" class="row g-3" novalidate>
      <?= csrf_field() ?>
      <?php if ($admin): ?>
      <div class="col-12"><label class="form-label req" for="employee_id">Employee</label>
        <select class="form-select<?= is_invalid('employee_id') ?>" id="employee_id" name="employee_id" required><option value="">Choose…</option>
          <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], old('employee_id', $employeeId)) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
        </select><?= field_error('employee_id') ?></div>
      <?php endif; ?>
      <div class="col-12"><label class="form-label req" for="leave_type_id">Leave type</label>
        <select class="form-select<?= is_invalid('leave_type_id') ?>" id="leave_type_id" name="leave_type_id" required>
          <?php foreach ($types as $t): if (!$admin && !$t['employee_can_request']) continue; ?><option value="<?= e($t['id']) ?>"<?= selected($t['id'], old('leave_type_id')) ?>><?= e($t['name']) ?><?= $t['deducts_balance'] ? ' (uses balance)' : '' ?></option><?php endforeach; ?>
        </select><?= field_error('leave_type_id') ?></div>
      <div class="col-sm-6"><label class="form-label req" for="start_date">First day</label><input class="form-control<?= is_invalid('start_date') ?>" type="date" id="start_date" name="start_date" value="<?= e(old('start_date')) ?>" required><?= field_error('start_date') ?>
        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="start_half" name="start_half" value="1"<?= checked(old('start_half')) ?>><label class="form-check-label" for="start_half">Afternoon only (half day)</label></div></div>
      <div class="col-sm-6"><label class="form-label req" for="end_date">Last day</label><input class="form-control<?= is_invalid('end_date') ?>" type="date" id="end_date" name="end_date" value="<?= e(old('end_date')) ?>" required><?= field_error('end_date') ?>
        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="end_half" name="end_half" value="1"<?= checked(old('end_half')) ?>><label class="form-check-label" for="end_half">Morning only (half day)</label></div></div>
      <div class="col-12"><label class="form-label" for="reason">Reason / notes</label><textarea class="form-control" id="reason" name="reason" rows="2" maxlength="500"><?= e(old('reason')) ?></textarea></div>
      <?php if ($admin): ?>
      <div class="col-sm-6"><label class="form-label" for="requested_on">Requested Date</label><input class="form-control<?= is_invalid('requested_on') ?>" type="date" id="requested_on" name="requested_on" value="<?= e(old('requested_on', uk_today())) ?>" max="<?= e(uk_today()) ?>"><?= field_error('requested_on') ?><div class="form-text">The date the employee asked for this leave.</div></div>
      <div class="col-sm-6"><label class="form-label" for="approved_on">Approved Date</label><input class="form-control<?= is_invalid('approved_on') ?>" type="date" id="approved_on" name="approved_on" value="<?= e(old('approved_on')) ?>" max="<?= e(uk_today()) ?>"><?= field_error('approved_on') ?><div class="form-text">Fill in to record the leave as approved on that date. Leave blank to keep it pending.</div></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="approve_now" name="approve_now" value="1"<?= checked(old('approve_now')) ?>><label class="form-check-label" for="approve_now">Approve now (approved date = today if left blank)</label></div></div>
      <?php endif; ?>
      <div class="col-12 form-text">Days are counted on working days in the work pattern; weekends and bank holidays are not deducted. A request cannot cross the end of the leave year.</div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $admin ? 'Book leave' : 'Send request' ?></button></div>
    </form>
  </div>
</div>
