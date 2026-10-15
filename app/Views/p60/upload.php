<div class="alert alert-info"><i class="bi bi-envelope"></i> Each uploaded P60 is emailed straight away, as a PDF attachment from hr@menakantransport.com, to the email address in the employee's Basic information.<?php if (App\Services\Mailer::isLogOnly()): ?> <b>Email is in test mode:</b> messages are written to <code>storage/logs/mail.log</code> and not delivered.<?php endif; ?></div>
<?php use App\Services\P60Service; ?>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header">Upload one P60</div><div class="card-body">
      <form method="post" action="<?= e(url('/p60')) ?>" enctype="multipart/form-data" class="row g-3" novalidate>
        <?= csrf_field() ?>
        <div class="col-sm-6"><label class="form-label req" for="employee_code">Employee ID</label>
          <input class="form-control idc<?= is_invalid('employee_code') ?>" id="employee_code" name="employee_code" value="<?= e(old('employee_code', $employeeCode)) ?>" list="employee-ids" autocomplete="off" required placeholder="e.g. A001">
          <datalist id="employee-ids"><?php foreach ($employees as $emp): ?><option value="<?= e($emp['employee_code']) ?>"><?= e(employee_name($emp)) ?></option><?php endforeach; ?></datalist>
          <?= field_error('employee_code') ?></div>
        <div class="col-sm-6"><label class="form-label req" for="tax_year">Tax year</label>
          <select class="form-select<?= is_invalid('tax_year') ?>" id="tax_year" name="tax_year">
            <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= selected($y, old('tax_year', $years[0])) ?>><?= e(P60Service::label($y) . '  (' . P60Service::range($y) . ')') ?></option><?php endforeach; ?>
          </select><?= field_error('tax_year') ?></div>
        <div class="col-12"><label class="form-label req" for="file">P60 PDF</label><input class="form-control" type="file" id="file" name="file" accept=".pdf,application/pdf" required><div class="form-text">PDF only, up to 10 MB. Stored privately under a random name.</div></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="replace" name="replace" value="1"><label class="form-check-label" for="replace">Replace existing P60 for this employee and tax year (the old one is archived and the replacement is logged)</label></div></div>
        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Upload P60</button> <a class="btn btn-outline-secondary" href="<?= e(url('/p60')) ?>">Cancel</a></div>
      </form>
    </div></div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header">Bulk upload for one tax year</div><div class="card-body">
      <form method="post" action="<?= e(url('/p60/bulk')) ?>" enctype="multipart/form-data" class="d-flex flex-column gap-3" novalidate>
        <?= csrf_field() ?>
        <div><label class="form-label req" for="bulk_year">Tax year</label>
          <select class="form-select" id="bulk_year" name="tax_year"><?php foreach ($years as $y): ?><option value="<?= $y ?>"><?= e(P60Service::label($y) . '  (' . P60Service::range($y) . ')') ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label req" for="files">P60 PDFs</label><input class="form-control" type="file" id="files" name="files[]" accept=".pdf,application/pdf" multiple required></div>
        <p class="form-text mb-0">Each file name must start with the Employee ID, e.g. <code>A001.pdf</code> or <code>A001_P60.pdf</code>. Files for an unknown Employee ID, or for someone who already has a P60 for that year, are reported and not saved.</p>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="bulk_replace" name="replace" value="1"><label class="form-check-label" for="bulk_replace">Replace existing P60s for this tax year</label></div>
        <div><button class="btn btn-primary" type="submit">Upload all</button></div>
      </form>
    </div></div>
  </div>
</div>
