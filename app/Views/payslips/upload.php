<div class="alert alert-info mb-0"><i class="bi bi-envelope"></i> Each uploaded payslip is emailed straight away, as a PDF attachment, to the email address in the employee's Basic information.<?php if (App\Services\Mailer::isLogOnly()): ?> <b>Email is in test mode:</b> messages are written to <code>storage/logs/mail.log</code> and not delivered until the mailbox password (<code>SMTP_PASSWORD</code>) is added to <code>.env</code>.<?php endif; ?></div>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header">Single payslip</div><div class="card-body">
      <form method="post" action="<?= e(url('/payslips')) ?>" enctype="multipart/form-data" class="row g-3" novalidate>
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label req" for="employee_id">Employee</label>
          <select class="form-select<?= is_invalid('employee_id') ?>" id="employee_id" name="employee_id" required><option value="">Choose…</option>
            <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], old('employee_id', $employeeId)) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
          </select><?= field_error('employee_id') ?></div>
        <div class="col-6"><label class="form-label req" for="month">Month</label><select class="form-select" id="month" name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= selected($m, old('month', $month)) ?>><?= e(month_name($m)) ?></option><?php endfor; ?></select></div>
        <div class="col-6"><label class="form-label req" for="year">Year</label><select class="form-select" id="year" name="year"><?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 4; $y--): ?><option value="<?= $y ?>"<?= selected($y, old('year', $year)) ?>><?= $y ?></option><?php endfor; ?></select></div>
        <div class="col-12"><label class="form-label req" for="file">PDF file</label><input class="form-control" type="file" id="file" name="file" accept=".pdf,application/pdf" required><div class="form-text">PDF only, up to 10 MB.</div></div>
        <div class="col-12">
          <div class="form-check"><input class="form-check-input" type="checkbox" id="confirm_replace" name="confirm_replace" value="1"><label class="form-check-label" for="confirm_replace">Replace existing payslip for this period (the old one is archived)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="publish_now" name="publish_now" value="1"><label class="form-check-label" for="publish_now">Publish now (otherwise saved as a draft)</label></div>
        </div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Upload</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header">Bulk upload</div><div class="card-body">
      <form method="post" action="<?= e(url('/payslips/bulk')) ?>" enctype="multipart/form-data" class="d-flex flex-column gap-3">
        <?= csrf_field() ?>
        <div><label class="form-label req" for="files">PDF files</label><input class="form-control" type="file" id="files" name="files[]" accept=".pdf,application/pdf" multiple required></div>
        <p class="form-text mb-0"><?php [$px, $pd] = App\Services\EmployeeService::codeFormat(); $ex = App\Services\EmployeeService::formatCode($px, 12, $pd); ?>Name files like <code><?= e($ex) ?>_2026-09.pdf</code> (employee ID and pay period). <code><?= e($px) ?>12_2026-09.pdf</code> also works. You will check every suggested match before anything is saved. Up to 200 files.</p>
        <div><button class="btn btn-primary" type="submit">Upload and match</button></div>
      </form>
    </div></div>
  </div>
</div>
