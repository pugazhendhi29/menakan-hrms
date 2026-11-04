<?php
/** Shift Import page: delete the chosen employees' shifts in a date range. Expects $pattern = [employees, ...]. */
$chosenDel = array_map('intval', (array) old('employee_ids', []));
?>
<div class="card mt-3 border-danger-subtle" id="delete-shifts">
  <div class="dash-head"><span class="dash-ico dash-ico-warn"><i class="bi bi-calendar-x"></i></span><h2>Delete shifts</h2></div>
  <div class="card-body">
    <p class="small text-muted-2">Deletes every shift of the chosen employees that starts between the two dates. The attendance recorded from those shifts goes too and the days become days off. Employees are not notified; each deleted shift is kept in the audit log. This cannot be undone.</p>
    <form method="post" action="<?= e(url('/shift-import/delete')) ?>" class="row g-3" novalidate>
      <?= csrf_field() ?>
      <div class="col-lg-5"><label class="form-label req" for="d_employees">Employees</label>
        <select class="form-select<?= is_invalid('employee_ids') ?>" id="d_employees" name="employee_ids[]" multiple size="7" required>
          <?php foreach ($pattern['employees'] as $emp): ?><option value="<?= e($emp['id']) ?>"<?= in_array((int) $emp['id'], $chosenDel, true) ? ' selected' : '' ?>><?= e($emp['employee_code'] . ' ' . employee_name($emp)) ?></option><?php endforeach; ?>
        </select><?= field_error('employee_ids') ?>
        <div class="form-text">Hold Ctrl (or Cmd) to choose more than one.</div></div>
      <div class="col-lg-7">
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label req" for="d_from">From</label><input class="form-control<?= is_invalid('from') ?>" type="date" id="d_from" name="from" value="<?= e(old('from', uk_today())) ?>" required><?= field_error('from') ?></div>
          <div class="col-sm-6"><label class="form-label req" for="d_to">To</label><input class="form-control<?= is_invalid('to') ?>" type="date" id="d_to" name="to" value="<?= e(old('to')) ?>" required><?= field_error('to') ?></div>
          <div class="col-sm-6"><label class="form-label" for="d_status">Which shifts</label>
            <select class="form-select" id="d_status" name="status">
              <option value="all"<?= selected('all', old('status', 'all')) ?>>All (draft and published)</option>
              <option value="draft"<?= selected('draft', old('status', 'all')) ?>>Drafts only</option>
              <option value="published"<?= selected('published', old('status', 'all')) ?>>Published only</option>
            </select></div>
          <div class="col-sm-6 d-flex align-items-end"><button class="btn btn-danger w-100" type="submit" data-confirm="Delete all matching shifts for the chosen employees? This cannot be undone."><i class="bi bi-trash"></i> Delete shifts</button></div>
        </div>
      </div>
    </form>
  </div>
</div>
