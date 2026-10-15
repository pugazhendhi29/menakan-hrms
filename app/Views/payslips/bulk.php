<form method="post" action="<?= e(url('/payslips/bulk/' . $token)) ?>">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-header">Check each match, then save the ticked rows as drafts</div>
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Save</th><th>File</th><th>Match</th><th>Employee</th><th>Month</th><th>Year</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it):
          $i = (int) $it['index'];
          $ok = !$it['error'];
          [$label, $cls] = $labels[$it['status']] ?? ['Invalid file', 'danger'];
          $tick = $ok && in_array($it['status'], ['matched'], true); ?>
        <tr class="<?= $ok ? ($it['status'] === 'matched' ? '' : 'row-dup') : 'row-err' ?>">
          <td><input class="form-check-input" type="checkbox" name="rows[<?= $i ?>][confirm]" value="1"<?= checked($tick) ?><?= $ok ? '' : ' disabled' ?> aria-label="Save this file"></td>
          <td class="small"><?= e($it['name']) ?></td>
          <td><span class="badge text-bg-<?= e($cls) ?>"><?= e($label) ?></span><?= $it['error'] ? '<div class="small text-danger">' . e($it['error']) . '</div>' : '' ?>
            <?= ($it['status'] ?? '') === 'duplicate_period' ? '<div class="small">Saving replaces the existing payslip (archived).</div>' : '' ?></td>
          <td><?php if ($ok): ?>
            <select class="form-select form-select-sm" name="rows[<?= $i ?>][employee_id]" aria-label="Employee">
              <option value="">Choose…</option>
              <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], $it['employee_id'] ?? '') ?><?= in_array((int) $e['id'], $it['candidates'] ?? [], true) && !$it['employee_id'] ? ' class="fw-bold"' : '' ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
            </select><?php endif; ?></td>
          <td><?php if ($ok): ?><select class="form-select form-select-sm" name="rows[<?= $i ?>][month]" aria-label="Month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= selected($m, $it['month'] ?? '') ?>><?= e(month_name($m)) ?></option><?php endfor; ?></select><?php endif; ?></td>
          <td><?php if ($ok): ?><input class="form-control form-control-sm" style="width:90px" name="rows[<?= $i ?>][year]" value="<?= e($it['year'] ?? date('Y')) ?>" inputmode="numeric" aria-label="Year"><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="d-flex gap-2 mt-2"><button class="btn btn-primary" type="submit">Save ticked payslips as drafts</button><a class="btn btn-outline-secondary" href="<?= e(url('/payslips/upload')) ?>">Start again</a></div>
  <p class="text-muted-2 small mt-2">Matched rows are ticked for you. Ambiguous, unknown and duplicate rows need you to choose and tick them. Nothing is visible to employees until you publish.</p>
</form>
