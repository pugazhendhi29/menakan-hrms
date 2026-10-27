<?php
$recipientType = (string) old('recipient_type', 'employee');
$oldIds = old('recipient_ids', []);
$picked = fn (string $type) => array_map('intval', (array) (is_array($oldIds) ? ($oldIds[$type] ?? []) : []));
?>
<a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= e(url('/my/news')) ?>"><i class="bi bi-arrow-left"></i> All news</a>
<form method="post" action="<?= e(url('/my/news/threads')) ?>" class="mail-reply" style="max-width:900px" novalidate>
  <?= csrf_field() ?>
  <h2 class="h5 mb-3"><i class="bi bi-pencil-square"></i> New thread</h2>
  <div class="row g-3">
    <fieldset class="col-12"><legend class="form-label req">To</legend>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="recipient_type" id="rt_employee" value="employee"<?= $recipientType === 'employee' ? ' checked' : '' ?> data-switch="recipient"><label class="form-check-label" for="rt_employee">Colleagues</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="recipient_type" id="rt_department" value="department"<?= $recipientType === 'department' ? ' checked' : '' ?> data-switch="recipient"><label class="form-check-label" for="rt_department">Departments</label></div>
      <?= field_error('recipient_type') ?>
      <div class="mt-2" data-switch-panel="recipient:employee"<?= $recipientType === 'employee' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="to_employee">Colleagues</label>
        <select class="form-select<?= is_invalid('recipient_ids') ?>" id="to_employee" name="recipient_ids[employee][]" multiple size="7">
          <?php foreach ($employees as $emp): if ((int) $emp['id'] === $me) { continue; } ?><option value="<?= e($emp['id']) ?>"<?= in_array((int) $emp['id'], $picked('employee'), true) ? ' selected' : '' ?>><?= e(employee_name($emp)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mt-2" data-switch-panel="recipient:department"<?= $recipientType === 'department' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="to_department">Departments</label>
        <select class="form-select<?= is_invalid('recipient_ids') ?>" id="to_department" name="recipient_ids[department][]" multiple size="5">
          <?php foreach ($departmentList as $d): ?><option value="<?= e($d['id']) ?>"<?= in_array((int) $d['id'], $picked('department'), true) ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?= field_error('recipient_ids') ?>
      <div class="form-text">Hold Ctrl (or Cmd) to choose several. A department reaches everyone in it.</div>
    </fieldset>
    <div class="col-12"><label class="form-label req" for="title">Subject</label>
      <input class="form-control<?= is_invalid('title') ?>" id="title" name="title" maxlength="200" required value="<?= e(old('title', '')) ?>"><?= field_error('title') ?></div>
    <div class="col-12"><label class="form-label req" for="body">Message</label>
      <textarea class="form-control<?= is_invalid('body') ?>" id="body" name="body" rows="8" required><?= e(old('body', '')) ?></textarea><?= field_error('body') ?></div>
  </div>
  <div class="d-flex align-items-center gap-2 mt-3">
    <span class="small text-muted-2">Sent now, from you. The people you choose can read it under News and are notified.</span>
    <button class="btn btn-primary ms-auto"><i class="bi bi-send"></i> Send</button>
  </div>
</form>
