<?php
/** Bulk document upload: step 1 uploads many files to a private staging area, step 2 maps each one to an employee and category. */
?>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-files"></i></span><h2>1 · Upload files</h2></div>
      <div class="card-body">
        <form method="post" action="<?= e(url('/documents/bulk')) ?>" enctype="multipart/form-data" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <label class="dropzone" for="files" data-dropzone>
            <i class="bi bi-cloud-arrow-up"></i>
            <span class="dropzone-title" data-dropzone-name>Drop the documents here</span>
            <span class="dropzone-hint">or <u>choose files</u> · PDF, JPG or PNG, up to 10 MB each, <?= (int) $maxFiles ?> files at a time</span>
            <input class="visually-hidden" type="file" id="files" name="files[]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple required>
          </label>
          <button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Upload</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-signpost-split"></i></span><h2>How it works</h2></div>
      <div class="card-body small">
        <ol class="mb-2 ps-3">
          <li>Upload the files. They are kept privately and not added to anyone's record yet.</li>
          <li>For each file choose the employee and category (and the expiry date where needed), then save.</li>
        </ol>
        <p class="mb-0 text-muted-2">Name files with the employee ID or name and the document type, e.g. <code>A005_passport.pdf</code> or <code>John Smith - BRP.jpg</code>, and they are matched for you. Unsaved files are cleared after two days.</p>
      </div>
    </div>
  </div>
</div>

<?php if ($rows): ?>
<form method="post" action="<?= e(url('/documents/bulk/save')) ?>" class="card" novalidate>
  <?= csrf_field() ?>
  <div class="dash-head"><span class="dash-ico"><i class="bi bi-diagram-3"></i></span><h2>2 · Map the files</h2><span class="dash-count"><?= count($rows) ?></span>
    <button class="btn btn-sm btn-outline-danger ms-auto" type="submit" formaction="<?= e(url('/documents/bulk/discard')) ?>" formnovalidate data-confirm="Remove all uploaded files that are not saved yet?"><i class="bi bi-x-lg"></i> Remove all</button></div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>File</th><th>Employee</th><th>Category</th><th>Title</th><th>Document no.</th><th>Issue date</th><th>Expiry date</th><th>Skip</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $token => $row): $in = $row['input']; $n = 'rows[' . $token . ']'; ?>
        <tr<?= !empty($row['error']) ? ' class="table-danger"' : '' ?>>
          <td style="min-width:170px"><a href="<?= e(url('/documents/bulk/file/' . $token)) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark<?= $row['ext'] === 'pdf' ? '-pdf' : '-image' ?>"></i> <?= e($row['name']) ?></a>
            <br><small class="text-muted-2"><?= e(number_format($row['size'] / 1024)) ?> KB</small>
            <?php if (!empty($row['error'])): ?><br><small class="text-danger"><?= e($row['error']) ?></small><?php endif; ?></td>
          <td style="min-width:200px"><select class="form-select form-select-sm" name="<?= e($n) ?>[employee_id]" aria-label="Employee"><option value="">Choose…</option>
            <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= selected($emp['id'], (string) $in['employee_id']) ?>><?= e($emp['employee_code'] . ' ' . employee_name($emp)) ?></option><?php endforeach; ?>
          </select></td>
          <td style="min-width:170px"><select class="form-select form-select-sm" name="<?= e($n) ?>[category_id]" aria-label="Category"><option value="">Choose…</option>
            <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"<?= selected($c['id'], (string) $in['category_id']) ?>><?= e($c['name']) ?><?= $c['has_expiry'] ? ' (expiry)' : '' ?></option><?php endforeach; ?>
          </select></td>
          <td style="min-width:160px"><input class="form-control form-control-sm" name="<?= e($n) ?>[title]" value="<?= e($in['title']) ?>" maxlength="150" aria-label="Title"></td>
          <td style="min-width:110px"><input class="form-control form-control-sm" name="<?= e($n) ?>[document_number]" value="<?= e($in['document_number'] ?? '') ?>" maxlength="60" aria-label="Document number"></td>
          <td><input class="form-control form-control-sm" type="date" name="<?= e($n) ?>[issue_date]" value="<?= e($in['issue_date'] ?? '') ?>" aria-label="Issue date"></td>
          <td><input class="form-control form-control-sm" type="date" name="<?= e($n) ?>[expiry_date]" value="<?= e($in['expiry_date'] ?? '') ?>" aria-label="Expiry date"></td>
          <td class="text-center"><input class="form-check-input" type="checkbox" name="<?= e($n) ?>[skip]" value="1"<?= checked(($in['skip'] ?? '') === '1') ?> aria-label="Skip this file for now"></td>
          <td><button class="btn btn-sm btn-link text-danger" type="submit" formaction="<?= e(url('/documents/bulk/discard')) ?>" formnovalidate name="token" value="<?= e($token) ?>" title="Remove this file" aria-label="Remove this file"><i class="bi bi-trash"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle"></i> Save documents</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('/documents')) ?>">Back to documents</a>
    <span class="form-text ms-auto">Saved documents are pending verification, and each employee is notified. Rows with a problem stay here with the reason.</span>
  </div>
</form>
<?php endif; ?>
