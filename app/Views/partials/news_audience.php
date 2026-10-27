<?php
/** Audience picker shared by News announcements and threads. Needs: $post (audience, audience_ids), $legend, $note, $employees, $departmentList, $teams; optional $options. */
use App\Services\NewsService;

$audience = (string) old('audience', $post['audience']);
$oldIds = old('audience_ids', null);
$chosen = fn (string $kind) => array_map('intval', is_array($oldIds) ? (array) ($oldIds[$kind] ?? []) : ($post['audience'] === $kind ? NewsService::ids($post['audience_ids']) : []));
?>
    <fieldset class="col-12"><legend class="form-label req"><?= e($legend) ?></legend>
      <?php foreach ($options ?? NewsService::AUDIENCES as $k => $v): ?>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="audience" id="aud_<?= $k ?>" value="<?= $k ?>"<?= $audience === $k ? ' checked' : '' ?> data-audience><label class="form-check-label" for="aud_<?= $k ?>"><?= e($v) ?></label></div>
      <?php endforeach; ?>
      <?= field_error('audience') ?>
      <div class="mt-2" data-audience-panel="employees"<?= $audience === 'employees' ? '' : ' hidden' ?>>
        <label class="form-label" for="ids_employees">Employees</label>
        <select class="form-select<?= is_invalid('audience_ids') ?>" id="ids_employees" name="audience_ids[employees][]" multiple size="8">
          <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= in_array((int) $emp['id'], $chosen('employees'), true) ? ' selected' : '' ?>><?= e(employee_name($emp) . ' (' . $emp['employee_code'] . ')') ?></option><?php endforeach; ?>
        </select><div class="form-text">Hold Ctrl (or Cmd) to choose several.</div></div>
      <div class="mt-2" data-audience-panel="departments"<?= $audience === 'departments' ? '' : ' hidden' ?>>
        <label class="form-label" for="ids_departments">Departments</label>
        <select class="form-select<?= is_invalid('audience_ids') ?>" id="ids_departments" name="audience_ids[departments][]" multiple size="6">
          <?php foreach ($departmentList as $d): ?><option value="<?= e($d['id']) ?>"<?= in_array((int) $d['id'], $chosen('departments'), true) ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mt-2" data-audience-panel="teams"<?= $audience === 'teams' ? '' : ' hidden' ?>>
        <label class="form-label" for="ids_teams">Teams</label>
        <select class="form-select<?= is_invalid('audience_ids') ?>" id="ids_teams" name="audience_ids[teams][]" multiple size="6">
          <?php foreach ($teams as $t): ?><option value="<?= e($t['id']) ?>"<?= in_array((int) $t['id'], $chosen('teams'), true) ? ' selected' : '' ?>><?= e(employee_name($t) . "'s team (" . ((int) $t['members'] + 1) . ' people)') ?></option><?php endforeach; ?>
        </select><div class="form-text">A team is a reporting manager and the employees who report to them (from the organisation chart).<?= $teams ? '' : ' No teams yet: set reporting managers on employee records.' ?></div></div>
      <?= field_error('audience_ids') ?>
      <div class="form-text"><?= e($note) ?></div>
    </fieldset>
