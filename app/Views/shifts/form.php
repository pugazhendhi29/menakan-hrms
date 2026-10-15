<?php
$s = $shift;
$start = $s ? from_utc($s['start_at']) : null;
$end = $s ? from_utc($s['end_at']) : null;
$action = $s ? url('/shifts/' . $s['id']) : url('/shifts');
?>
<div class="row g-3">
<div class="col-xl-8">
<div class="card">
  <div class="card-header"><?= $s ? 'Edit shift for ' . e($s['employee_code'] . ' ' . employee_name($s)) : 'New shift' ?></div>
  <div class="card-body">
    <form method="post" action="<?= e($action) ?>" class="row g-3" novalidate>
      <?= csrf_field() ?>
      <div class="col-md-6"><label class="form-label req" for="employee_id">Employee</label>
        <select class="form-select<?= is_invalid('employee_id') ?>" id="employee_id" name="employee_id" required><option value="">Choose…</option>
          <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], old('employee_id', $s['employee_id'] ?? $employeeId)) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
        </select><?= field_error('employee_id') ?></div>
      <div class="col-md-6"><label class="form-label" for="shift_type_id">Shift type</label>
        <select class="form-select" id="shift_type_id" name="shift_type_id" data-shift-type><option value="">Custom</option>
          <?php foreach ($types as $t): ?><option value="<?= e($t['id']) ?>" data-start="<?= e(substr($t['default_start'], 0, 5)) ?>" data-end="<?= e(substr($t['default_end'], 0, 5)) ?>" data-break="<?= e($t['default_break_minutes']) ?>"<?= selected($t['id'], old('shift_type_id', $s['shift_type_id'] ?? ($defaultType['id'] ?? ''))) ?>><?= e($t['name'] . ' (' . substr($t['default_start'], 0, 5) . '–' . substr($t['default_end'], 0, 5) . ')') ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label req" for="date">Start date</label><input class="form-control<?= is_invalid('date') ?>" type="date" id="date" name="date" value="<?= e(old('date', $start ? $start->format('Y-m-d') : $date)) ?>" required><?= field_error('date') ?></div>
      <div class="col-md-3"><label class="form-label req" for="start_time">Start</label><input class="form-control<?= is_invalid('start_time') ?>" type="time" id="start_time" name="start_time" value="<?= e(old('start_time', $start ? $start->format('H:i') : ($defaultType ? substr($defaultType['default_start'], 0, 5) : '06:00'))) ?>" required><?= field_error('start_time') ?></div>
      <div class="col-md-3"><label class="form-label req" for="end_time">End</label><input class="form-control<?= is_invalid('end_time') ?>" type="time" id="end_time" name="end_time" value="<?= e(old('end_time', $end ? $end->format('H:i') : ($defaultType ? substr($defaultType['default_end'], 0, 5) : '14:00'))) ?>" required><?= field_error('end_time') ?></div>
      <div class="col-md-2"><label class="form-label req" for="break_minutes">Break (min)</label><input class="form-control<?= is_invalid('break_minutes') ?>" id="break_minutes" name="break_minutes" inputmode="numeric" value="<?= e(old('break_minutes', $s['break_minutes'] ?? ($defaultType['default_break_minutes'] ?? 30))) ?>" required><?= field_error('break_minutes') ?></div>
      <div class="col-12 form-text mt-0">UK local time. An end time at or before the start time means the shift finishes the next day.</div>
      <div class="col-md-6"><label class="form-label" for="location_id">Location</label>
        <select class="form-select" id="location_id" name="location_id"><option value="">—</option>
          <?php foreach ($locations as $l): ?><option value="<?= e($l['id']) ?>"<?= selected($l['id'], old('location_id', $s['location_id'] ?? '')) ?>><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-6"><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
          <option value="draft"<?= selected('draft', old('status', $s['status'] ?? 'draft')) ?>>Draft (not visible to the employee)</option>
          <option value="published"<?= selected('published', old('status', $s['status'] ?? '')) ?>>Published</option>
        </select></div>
      <div class="col-12"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" value="<?= e(old('notes', $s['notes'] ?? '')) ?>" maxlength="255"></div>
      <div class="col-12">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="approve_over_limit" name="approve_over_limit" value="1" data-reveal="#override-box"<?= checked(old('approve_over_limit') || isset(($GLOBALS['__form_errors'] ?? [])['override_reason'])) ?>>
          <label class="form-check-label" for="approve_over_limit">Approve hours over the weekly limit if this shift exceeds it</label></div>
        <div id="override-box" class="mt-2"><label class="form-label" for="override_reason">Reason for approving extra hours</label>
          <input class="form-control<?= is_invalid('override_reason') ?>" id="override_reason" name="override_reason" value="<?= e(old('override_reason')) ?>" maxlength="255" placeholder="e.g. Covering sickness, agreed with the driver"><?= field_error('override_reason') ?></div>
      </div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Save shift</button><a class="btn btn-outline-secondary" href="<?= e(url('/shifts')) ?>">Back to calendar</a></div>
    </form>
    <?php if ($s && $s['status'] !== 'cancelled'): ?>
      <hr><form method="post" action="<?= e(url('/shifts/' . $s['id'] . '/cancel')) ?>" data-confirm="Cancel this shift? A published shift's employee will be notified."><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">Cancel shift</button></form>
    <?php endif; ?>
  </div>
</div>
</div>
<div class="col-xl-4">
  <div class="card"><div class="card-header">Rules applied</div><div class="card-body small">
    <ul class="mb-0 ps-3">
      <li>Overlapping shifts for the same employee are blocked.</li>
      <li>Net hours = end − start − unpaid break, measured in real time (clock changes included).</li>
      <li>Weekly limit order: employee override → contract hours → company default (<?= e(setting('weekly_hours_limit')) ?>h). Going over needs an approval with a reason.</li>
      <li>Working Time warnings: <?= e(setting('daily_rest_hours')) ?>h daily rest and a <?= e(setting('wtd_average_limit_hours')) ?>h average over <?= e(setting('wtd_reference_weeks')) ?> weeks.</li>
      <li>Only published shifts are visible to employees and count for absence.</li>
    </ul>
  </div></div>
</div>
</div>
