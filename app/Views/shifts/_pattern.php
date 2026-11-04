<?php
/** Shift Import page: create shifts from a pattern (employees, date range, weekdays, times). Expects $pattern = [employees, types, locations]. */
$chosen = array_map('intval', (array) old('employee_ids', []));
$days = array_map('intval', (array) old('weekdays', [1, 2, 3, 4, 5]));
?>
<div class="card mt-3" id="pattern">
  <div class="dash-head"><span class="dash-ico"><i class="bi bi-calendar-plus"></i></span><h2>Create shifts by pattern</h2></div>
  <div class="card-body">
    <p class="small text-muted-2">Choose the employees, the dates and the days of the week, and the same shift is created on every matching day. Bank holidays are left free (the day stays a bank holiday), as are days on approved leave and days that already have a shift.</p>
    <form method="post" action="<?= e(url('/shift-import/pattern')) ?>" class="row g-3" novalidate>
      <?= csrf_field() ?>
      <div class="col-lg-5"><label class="form-label req" for="p_employees">Employees</label>
        <select class="form-select<?= is_invalid('employee_ids') ?>" id="p_employees" name="employee_ids[]" multiple size="9" required>
          <?php foreach ($pattern['employees'] as $emp): ?><option value="<?= e($emp['id']) ?>"<?= in_array((int) $emp['id'], $chosen, true) ? ' selected' : '' ?>><?= e($emp['employee_code'] . ' ' . employee_name($emp)) ?></option><?php endforeach; ?>
        </select><?= field_error('employee_ids') ?>
        <div class="form-text">Hold Ctrl (or Cmd) to choose more than one.</div></div>
      <div class="col-lg-7">
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label req" for="p_from">Start date</label><input class="form-control<?= is_invalid('from') ?>" type="date" id="p_from" name="from" value="<?= e(old('from', uk_today())) ?>" required><?= field_error('from') ?></div>
          <div class="col-sm-6"><label class="form-label req" for="p_to">End date</label><input class="form-control<?= is_invalid('to') ?>" type="date" id="p_to" name="to" value="<?= e(old('to')) ?>" required><?= field_error('to') ?></div>
          <div class="col-12"><span class="form-label req d-block">Days of the week</span>
            <div class="d-flex flex-wrap gap-2<?= is_invalid('weekdays') ?>">
              <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $l): ?>
                <input class="btn-check" type="checkbox" id="p_day<?= $n ?>" name="weekdays[]" value="<?= $n ?>"<?= in_array($n, $days, true) ? ' checked' : '' ?>>
                <label class="btn btn-sm btn-outline-primary" for="p_day<?= $n ?>"><?= $l ?></label>
              <?php endforeach; ?>
            </div><?= field_error('weekdays') ?></div>
          <div class="col-sm-6"><label class="form-label" for="p_type">Shift type</label>
            <select class="form-select" id="p_type" name="shift_type_id" data-shift-type><option value="">Custom</option>
              <?php foreach ($pattern['types'] as $t): ?><option value="<?= e($t['id']) ?>" data-start="<?= e(substr($t['default_start'], 0, 5)) ?>" data-end="<?= e(substr($t['default_end'], 0, 5)) ?>" data-break="<?= e($t['default_break_minutes']) ?>"<?= selected($t['id'], old('shift_type_id')) ?>><?= e($t['name'] . ' (' . substr($t['default_start'], 0, 5) . '–' . substr($t['default_end'], 0, 5) . ')') ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-6"><label class="form-label" for="p_location">Location</label>
            <select class="form-select" id="p_location" name="location_id"><option value="">—</option>
              <?php foreach ($pattern['locations'] as $l): ?><option value="<?= e($l['id']) ?>"<?= selected($l['id'], old('location_id')) ?>><?= e($l['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-4"><label class="form-label req" for="p_start">Start</label><input class="form-control<?= is_invalid('start_time') ?>" type="time" id="p_start" name="start_time" value="<?= e(old('start_time', '09:00')) ?>" required><?= field_error('start_time') ?></div>
          <div class="col-4"><label class="form-label req" for="p_end">End</label><input class="form-control<?= is_invalid('end_time') ?>" type="time" id="p_end" name="end_time" value="<?= e(old('end_time', '17:00')) ?>" required><?= field_error('end_time') ?></div>
          <div class="col-4"><label class="form-label req" for="p_break">Break (min)</label><input class="form-control<?= is_invalid('break_minutes') ?>" id="p_break" name="break_minutes" inputmode="numeric" value="<?= e(old('break_minutes', '30')) ?>" required><?= field_error('break_minutes') ?></div>
          <div class="col-12 form-text mt-0">UK local time. An end time at or before the start time means the shift finishes the next day.</div>
        </div>
      </div>
      <div class="col-sm-6"><label class="form-label" for="p_status">Status</label>
        <select class="form-select" id="p_status" name="status">
          <option value="published"<?= selected('published', old('status', 'published')) ?>>Published</option>
          <option value="draft"<?= selected('draft', old('status', 'published')) ?>>Draft (not visible to the employee)</option>
        </select></div>
      <div class="col-sm-6"><label class="form-label" for="p_notes">Notes</label><input class="form-control" id="p_notes" name="notes" value="<?= e(old('notes')) ?>" maxlength="255"></div>
      <div class="col-12">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="p_over" name="approve_over_limit" value="1" data-reveal="#p_override"<?= checked((bool) old('approve_over_limit')) ?>>
          <label class="form-check-label" for="p_over">Approve hours over the weekly limit if a week exceeds it</label></div>
        <div id="p_override" class="mt-2"><label class="form-label" for="p_reason">Reason for approving extra hours</label>
          <input class="form-control" id="p_reason" name="override_reason" value="<?= e(old('override_reason')) ?>" maxlength="255" placeholder="e.g. Covering sickness, agreed with the driver"></div>
      </div>
      <div class="col-12"><button class="btn btn-primary" type="submit" data-confirm="Create these shifts?"><i class="bi bi-calendar-plus"></i> Create shifts</button></div>
    </form>
  </div>
</div>
