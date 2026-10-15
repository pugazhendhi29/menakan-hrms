<?php use App\Services\P60Service; ?>
<div class="card" style="max-width:720px"><div class="card-header">Replace P60 · <?= e($row['employee_name']) ?> (<?= e($row['employee_code']) ?>) · <?= e(P60Service::label((int) $row['tax_year_start'])) ?></div>
  <div class="card-body">
    <div class="alert alert-info">The current file (uploaded <?= e(uk_datetime($row['created_at'])) ?>) will be archived, not deleted, and the replacement is recorded in the audit log.</div>
    <form method="post" action="<?= e(url('/p60/' . $row['id'] . '/replace')) ?>" enctype="multipart/form-data" class="d-flex flex-column gap-3" novalidate>
      <?= csrf_field() ?>
      <div><label class="form-label req" for="file">New P60 PDF</label><input class="form-control" type="file" id="file" name="file" accept=".pdf,application/pdf" required></div>
      <div class="d-flex gap-2"><button class="btn btn-primary" type="submit">Replace P60</button><a class="btn btn-outline-secondary" href="<?= e(url('/p60')) ?>">Cancel</a></div>
    </form>
  </div>
</div>
