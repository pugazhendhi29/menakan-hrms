<?php
$in = $a ? from_utc($a['check_in_at']) : null;
$out = $a && $a['check_out_at'] ? from_utc($a['check_out_at']) : null;
$action = $a ? url('/attendance/' . $a['id']) : url('/attendance');
?>
<div class="card" style="max-width:760px">
  <div class="card-header"><?= $a ? 'Correct record for ' . e($a['employee_code'] . ' ' . employee_name($a)) : 'New attendance record' ?></div>
  <div class="card-body">
    <?php if ($a && $a['is_approved']): ?><div class="alert alert-warning">This record is approved. Corrections are allowed but are recorded with your reason.</div><?php endif; ?>
    <form method="post" action="<?= e($action) ?>" class="row g-3" novalidate>
      <?= csrf_field() ?>
      <?php if (!$a): ?>
      <div class="col-12"><label class="form-label req" for="employee_id">Employee</label>
        <select class="form-select<?= is_invalid('employee_id') ?>" id="employee_id" name="employee_id" required><option value="">Choose…</option>
          <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], old('employee_id', $employeeId)) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
        </select><?= field_error('employee_id') ?></div>
      <?php endif; ?>
      <div class="col-sm-4"><label class="form-label req" for="work_date">Work date</label><input class="form-control<?= is_invalid('work_date') ?>" type="date" id="work_date" name="work_date" value="<?= e(old('work_date', $a['work_date'] ?? uk_today())) ?>" max="<?= e(uk_today()) ?>" required><?= field_error('work_date') ?></div>
      <div class="col-sm-3"><label class="form-label req" for="check_in">Check in</label><input class="form-control<?= is_invalid('check_in') ?>" type="time" id="check_in" name="check_in" value="<?= e(old('check_in', $in ? $in->format('H:i') : '')) ?>" required><?= field_error('check_in') ?></div>
      <div class="col-sm-3"><label class="form-label" for="check_out">Check out</label><input class="form-control<?= is_invalid('check_out') ?>" type="time" id="check_out" name="check_out" value="<?= e(old('check_out', $out ? $out->format('H:i') : '')) ?>"><?= field_error('check_out') ?></div>
      <div class="col-sm-2"><label class="form-label" for="break_minutes">Break (min)</label><input class="form-control<?= is_invalid('break_minutes') ?>" id="break_minutes" name="break_minutes" inputmode="numeric" value="<?= e(old('break_minutes', $a['break_minutes'] ?? 30)) ?>"><?= field_error('break_minutes') ?></div>
      <div class="col-12 form-text mt-0">Times are UK local. A check-out earlier than the check-in is treated as the next day (night shift).</div>
      <div class="col-12"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" value="<?= e(old('notes', $a['notes'] ?? '')) ?>" maxlength="255"></div>
      <div class="col-12"><label class="form-label req" for="reason">Reason for this change</label><input class="form-control<?= is_invalid('reason') ?>" id="reason" name="reason" value="<?= e(old('reason')) ?>" maxlength="255" required placeholder="e.g. Forgot to check out; confirmed with supervisor"><?= field_error('reason') ?></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Save</button><a class="btn btn-outline-secondary" href="<?= e(url($a ? '/attendance/' . $a['id'] : '/attendance')) ?>">Cancel</a></div>
    </form>
  </div>
</div>
