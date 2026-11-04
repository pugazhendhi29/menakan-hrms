<?php
$errors = $GLOBALS['__form_errors'] ?? [];
$options = $people;
?>
<p class="text-muted-2">Each employee's reports go to the line manager set here, then up the chain to the Directors. This is the same "Reporting manager" as on the employee record and the organisation chart. Directors report to no one.</p>
<?php if ($issues): ?>
  <div class="alert alert-warning"><b><i class="bi bi-exclamation-triangle"></i> Needs attention</b><ul class="mb-0 mt-1"><?php foreach ($issues as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form class="toolbar mb-3" method="get" action="<?= e(url('/reporting/lines')) ?>">
  <div><label class="form-label" for="department_id">Department</label><select class="form-select form-select-sm" id="department_id" name="department_id" data-autosubmit>
    <option value="">All departments</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($d['id'], $dept) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
</form>
<form method="post" action="<?= e(url('/reporting/lines')) ?>">
  <?= csrf_field() ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Employee</th><th>Job title</th><th>Department</th><th style="min-width:260px">Reports to (line manager)</th></tr></thead>
        <tbody>
        <?php foreach ($people as $id => $p): if ($dept && (int) $p['department_id'] !== $dept) { continue; } $isDirector = $p['report_role'] === 'director'; $current = old('manager', [])[$id] ?? $p['line_manager_id']; ?>
          <tr>
            <td><div class="dash-who"><?= employee_avatar($p, 30) ?><span><?= e(employee_name($p)) ?><small class="d-block idc-plain"><?= e($p['employee_code']) ?></small></span></div></td>
            <td><?= e($p['job_title'] ?? '—') ?><?= $p['report_role'] ? '' : ' <span class="badge text-bg-warning">not approved</span>' ?></td>
            <td><?= e($p['department'] ?? '—') ?></td>
            <td>
              <?php if ($isDirector): ?>
                <span class="text-muted-2">Director: top of the chain</span><input type="hidden" name="manager[<?= (int) $id ?>]" value="">
              <?php else: ?>
                <select class="form-select form-select-sm<?= isset($errors["manager.$id"]) ? ' is-invalid' : '' ?>" name="manager[<?= (int) $id ?>]" aria-label="Line manager of <?= e(employee_name($p)) ?>">
                  <option value="">— No line manager —</option>
                  <?php foreach ($options as $mid => $m): if ($mid === $id) { continue; } ?><option value="<?= (int) $mid ?>"<?= selected($mid, $current) ?>><?= e(employee_name($m)) ?> · <?= e($m['job_title'] ?? '') ?></option><?php endforeach; ?>
                </select>
                <?= field_error("manager.$id") ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="<?= e(url('/reporting/hierarchy')) ?>">View hierarchy</a><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save reporting lines</button></div>
  </div>
</form>
