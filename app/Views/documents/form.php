<?php $action = $doc ? url('/documents/' . $doc['id'] . '/replace') : url('/documents'); ?>
<div class="card" style="max-width:820px">
  <div class="card-header"><?= $doc ? 'Replace ' . e($doc['category_name']) . ' for ' . e(employee_name($doc)) : 'Upload a document' ?></div>
  <div class="card-body">
    <?php if ($doc): ?><div class="alert alert-info">The current file will be archived (kept, not deleted) and replaced by the new upload.</div><?php endif; ?>
    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="row g-3" novalidate>
      <?= csrf_field() ?>
      <?php if (!$doc): ?>
      <div class="col-md-6"><label class="form-label req" for="employee_id">Employee</label>
        <select class="form-select<?= is_invalid('employee_id') ?>" id="employee_id" name="employee_id" required><option value="">Choose…</option>
          <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= selected($emp['id'], old('employee_id', $employeeId)) ?>><?= e($emp['employee_code'] . ' ' . employee_name($emp)) ?></option><?php endforeach; ?>
        </select><?= field_error('employee_id') ?></div>
      <div class="col-md-6"><label class="form-label req" for="category_id">Category</label>
        <select class="form-select<?= is_invalid('category_id') ?>" id="category_id" name="category_id" required><option value="">Choose…</option>
          <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"<?= selected($c['id'], old('category_id')) ?>><?= e($c['name']) ?><?= $c['has_expiry'] ? ' (has expiry)' : '' ?></option><?php endforeach; ?>
        </select><?= field_error('category_id') ?></div>
      <?php endif; ?>
      <div class="col-md-8"><label class="form-label req" for="title">Title</label><input class="form-control<?= is_invalid('title') ?>" id="title" name="title" value="<?= e(old('title', $doc['title'] ?? '')) ?>" required maxlength="150"><?= field_error('title') ?></div>
      <div class="col-md-4"><label class="form-label" for="document_number">Document number</label><input class="form-control" id="document_number" name="document_number" value="<?= e(old('document_number', $doc['document_number'] ?? '')) ?>" maxlength="60"></div>
      <div class="col-md-4"><label class="form-label" for="issue_date">Issue date</label><input class="form-control<?= is_invalid('issue_date') ?>" type="date" id="issue_date" name="issue_date" value="<?= e(old('issue_date')) ?>"><?= field_error('issue_date') ?></div>
      <div class="col-md-4"><label class="form-label" for="expiry_date">Expiry date</label><input class="form-control<?= is_invalid('expiry_date') ?>" type="date" id="expiry_date" name="expiry_date" value="<?= e(old('expiry_date')) ?>"><?= field_error('expiry_date') ?></div>
      <div class="col-md-4"><label class="form-label req" for="file">File</label><input class="form-control" type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div>
      <div class="col-12 form-text">PDF, JPG or PNG up to 10 MB. Files are stored privately under a random name and are only available through the portal.</div>
      <div class="col-12"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" value="<?= e(old('notes')) ?>" maxlength="255"></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $doc ? 'Replace document' : 'Upload' ?></button><a class="btn btn-outline-secondary" href="<?= e(url($employeeId ? '/employees/' . $employeeId . '?tab=documents' : '/documents')) ?>">Cancel</a></div>
    </form>
  </div>
</div>
